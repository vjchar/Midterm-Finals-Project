<?php

declare(strict_types=1);

function escape_html(mixed $untrustedValue): string
{
    return htmlspecialchars(
        (string) $untrustedValue,
        ENT_QUOTES | ENT_SUBSTITUTE,
        "UTF-8",
    );
}

function url(string $relativePath = ""): string
{
    $configuredApplicationUrl = rtrim(APP_URL, "/");
    if ($configuredApplicationUrl !== "") {
        return $configuredApplicationUrl .
            ($relativePath !== "" ? "/" . ltrim($relativePath, "/") : "");
    }
    $requestUsesHttps =
        !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off";
    $requestHost = $_SERVER["HTTP_HOST"] ?? "localhost";
    $executedScriptPath = str_replace(
        "\\",
        "/",
        $_SERVER["SCRIPT_NAME"] ?? "/index.php",
    );
    $applicationBasePath = rtrim(
        str_replace(basename($executedScriptPath), "", $executedScriptPath),
        "/",
    );
    return ($requestUsesHttps ? "https://" : "http://") .
        $requestHost .
        $applicationBasePath .
        ($relativePath !== "" ? "/" . ltrim($relativePath, "/") : "");
}

function redirect(string $destinationPath, int $statusCode = 303): never
{
    if (preg_match("/^https?:\/\//i", $destinationPath)) {
        $destinationHost = parse_url($destinationPath, PHP_URL_HOST);
        if ($destinationHost !== ($_SERVER["HTTP_HOST"] ?? null)) {
            $destinationPath = "index.php";
        }
    }
    header("Location: " . $destinationPath, true, $statusCode);
    exit();
}

function safe_return_to(
    ?string $requestedReturnPath,
    string $fallbackPath = "account.php",
): string {
    $requestedReturnPath = trim((string) $requestedReturnPath);
    if (
        $requestedReturnPath === "" ||
        str_contains($requestedReturnPath, "\n") ||
        str_contains($requestedReturnPath, "\r") ||
        preg_match("/^[a-z][a-z0-9+.-]*:/i", $requestedReturnPath) ||
        str_starts_with($requestedReturnPath, "//")
    ) {
        return $fallbackPath;
    }
    return ltrim($requestedReturnPath, "/");
}

function csrf_token(): string
{
    if (empty($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION["csrf_token"];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' .
        escape_html(csrf_token()) .
        '">';
}

function verify_csrf(?string $submittedToken = null): bool
{
    $submittedToken ??=
        (string) ($_POST["csrf_token"] ??
            ($_SERVER["HTTP_X_CSRF_TOKEN"] ?? ""));
    return isset($_SESSION["csrf_token"]) &&
        $submittedToken !== "" &&
        hash_equals((string) $_SESSION["csrf_token"], $submittedToken);
}

function require_csrf(): void
{
    if (!verify_csrf()) {
        http_response_code(419);
        throw new RuntimeException(
            "Your session expired. Refresh the page and try again.",
        );
    }
}

function flash(string $type, string $message): void
{
    $_SESSION["flash_messages"][] = ["type" => $type, "message" => $message];
}

function pull_flashes(): array
{
    $messages = $_SESSION["flash_messages"] ?? [];
    unset($_SESSION["flash_messages"]);
    return is_array($messages) ? $messages : [];
}

function post_string(string $key, string $default = ""): string
{
    return trim((string) ($_POST[$key] ?? $default));
}

function valid_date(string $dateValue): bool
{
    $parsedDate = DateTimeImmutable::createFromFormat("!Y-m-d", $dateValue);
    return $parsedDate !== false && $parsedDate->format("Y-m-d") === $dateValue;
}

function booking_reference(): string
{
    return "VJ-" . date("ymd") . "-" . strtoupper(bin2hex(random_bytes(3)));
}

function status_class(string $status): string
{
    return match ($status) {
        "confirmed",
        "completed",
        "converted",
        "approved",
        "available",
        "read",
        "closed",
        "paid"
            => "success",
        "cancelled",
        "rejected",
        "inactive",
        "failed",
        "damaged",
        "unavailable",
        "expired"
            => "danger",
        "pending", "new", "reserved", "scheduled", "ready" => "warning",
        "active", "returned", "rented", "in_progress", "refunded" => "info",
        "no_show", "maintenance", "discarded" => "secondary",
        default => "secondary",
    };
}

function money(int|float $amount): string
{
    return "₱" . number_format((float) $amount, 0);
}

/**
 * Convert an exception into a safe message for a page, flash, or JSON response.
 *
 * Validation and business-rule exceptions contain deliberate customer-facing
 * wording. Database failures and unexpected programming errors are logged with
 * their diagnostic details and replaced with a neutral public message.
 */
function user_facing_error_message(
    Throwable $exception,
    string $fallbackMessage =
        "The request could not be completed. Please try again.",
): string {
    if ($exception instanceof PDOException) {
        error_log(
            sprintf(
                "Database request failed: %s in %s:%d",
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
            ),
        );

        return $fallbackMessage;
    }

    if (
        $exception instanceof InvalidArgumentException ||
        $exception instanceof RuntimeException
    ) {
        return $exception->getMessage();
    }

    error_log(
        sprintf(
            "Unexpected request error (%s): %s in %s:%d",
            $exception::class,
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
        ),
    );

    return $fallbackMessage;
}

function write_audit(
    string $action,
    string $entityType,
    ?int $entityId = null,
    array $metadata = [],
): void {
    try {
        $statement = database()->prepare(
            "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, metadata, ip_hash, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
        );
        $statement->execute([
            current_user()["id"] ?? null,
            $action,
            $entityType,
            $entityId,
            json_encode($metadata, JSON_UNESCAPED_SLASHES),
            hash("sha256", ($_SERVER["REMOTE_ADDR"] ?? "cli") . APP_KEY),
            date("Y-m-d H:i:s"),
        ]);
    } catch (Throwable $error) {
        error_log("Audit write failed: " . $error->getMessage());
    }
}

function upload_file(
    array $file,
    string $directory,
    array $allowedMimes,
    int $maxBytes,
    string $prefix,
): string {
    if (($file["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException("The upload could not be completed.");
    }

    if (($file["size"] ?? 0) < 1 || (int) $file["size"] > $maxBytes) {
        throw new RuntimeException("The uploaded file is larger than allowed.");
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file(
        (string) $file["tmp_name"],
    );
    if (!isset($allowedMimes[$mime])) {
        throw new RuntimeException("The uploaded file type is not allowed.");
    }

    if (
        !is_dir($directory) &&
        !mkdir($directory, 0775, true) &&
        !is_dir($directory)
    ) {
        throw new RuntimeException("The upload directory is unavailable.");
    }

    $filename =
        preg_replace("/[^a-z0-9-]+/i", "-", $prefix) .
        "-" .
        bin2hex(random_bytes(8)) .
        "." .
        $allowedMimes[$mime];
    if (
        !move_uploaded_file(
            (string) $file["tmp_name"],
            $directory . DIRECTORY_SEPARATOR . $filename,
        )
    ) {
        throw new RuntimeException("The uploaded file could not be saved.");
    }
    return $filename;
}

function send_password_reset(string $email, string $link): bool
{
    if (APP_MODE !== "production") {
        $_SESSION["local_reset_link"] = $link;
        return true;
    }
    $from = APP_MAIL_FROM;
    $subject = "Reset your VJ Car Rental password";
    $message =
        "A password reset was requested for your account.\n\n" .
        "Reset it here: {$link}\n\n" .
        "This link expires in one hour. If you did not request it, ignore this email.";
    return mail(
        $email,
        $subject,
        $message,
        "From: {$from}\r\nContent-Type: text/plain; charset=UTF-8",
    );
}
