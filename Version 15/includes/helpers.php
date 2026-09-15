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

function humanize_label(string $value): string
{
    $label = trim(str_replace(["_", "-"], " ", $value));
    $label = preg_replace("/\s+/", " ", $label) ?? $label;
    return ucwords($label);
}

function status_label(string $status): string
{
    return humanize_label($status);
}

function status_class(string $status): string
{
    return match ($status) {
        "confirmed",
        "completed",
        "converted",
        "approved",
        "activated",
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
        "active", "returned", "rented", "in_progress", "refunded", "processing" => "info",
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

/****************************************************************************
 * REUSABLE PRESENTATION HELPERS
 ****************************************************************************/

/**
 * Reusable customer-facing booking and vehicle UI components.
 */

/****************************************************************************
 * BOOKING PROGRESS COMPONENTS
 ****************************************************************************/

/**
 * Render the customer-facing five-step onboarding journey.
 */
function render_booking_progress(array $journey): void
{
    if (!$journey["step_index"] && !in_array($journey["stage"], ["preparing", "ready_pickup", "ready_delivery"], true)) {
        return;
    }
    ?>
    <nav class="journey-progress" aria-label="Booking progress">
        <?php foreach ($journey["steps"] as $number => $step): ?>
            <?php $state = $step["state"]; ?>
            <div class="journey-progress__step is-<?= escape_html($state) ?>" aria-current="<?= $state === "current" ? "step" : "false" ?>">
                <span class="journey-progress__number" aria-hidden="true"><?= $state === "complete" ? "✓" : (int) $number ?></span>
                <span class="journey-progress__label"><?= escape_html($step["label"]) ?></span>
            </div>
            <?php if ($number < count($journey["steps"])): ?>
                <i class="journey-progress__line" aria-hidden="true"></i>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <?php
}

/**
 * Render one consistent next-step / no-action card.
 */
function render_booking_next_step(array $journey, string $kicker = "Next step"): void
{
    $icon = match ($journey["stage"]) {
        "payment", "extension_payment", "return_payment" => "bi-credit-card",
        "documents" => "bi-person-vcard",
        "verification" => "bi-shield-check",
        "preparing" => "bi-tools",
        "ready_pickup" => "bi-key",
        "ready_delivery" => "bi-truck",
        "active" => "bi-car-front",
        "review" => "bi-star",
        "closed" => "bi-x-circle",
        default => "bi-arrow-right-circle",
    };
    ?>
    <article class="journey-next journey-next--<?= escape_html($journey["severity"]) ?>">
        <div class="journey-next__icon"><i class="bi <?= escape_html($icon) ?>"></i></div>
        <div class="journey-next__body">
            <span class="section-kicker"><?= escape_html($kicker) ?></span>
            <h2><?= escape_html($journey["title"]) ?></h2>
            <p><?= escape_html($journey["message"]) ?></p>
            <?php if ($journey["no_action"]): ?>
                <strong class="journey-next__quiet"><i class="bi bi-clock-history"></i> No Action Required Right Now</strong>
            <?php endif; ?>
        </div>
        <?php if ($journey["button_label"] !== ""): ?>
            <a class="btn <?= $journey["action_required"] ? "btn-primary" : "btn-outline" ?>" href="<?= escape_html($journey["target_url"]) ?>">
                <?= escape_html($journey["button_label"]) ?>
            </a>
        <?php endif; ?>
    </article>
    <?php
}

/****************************************************************************
 * VEHICLE CARD COMPONENT
 ****************************************************************************/

/**
 * Return the signed-in customer's saved vehicle slugs once per request.
 *
 * Administrators do not own a customer favorites list, so they receive an
 * empty result rather than being offered customer-only controls.
 *
 * @return list<string>
 */
function customer_favorite_vehicle_slugs(): array
{
    static $favoriteVehicleSlugs = null;

    if (is_array($favoriteVehicleSlugs)) {
        return $favoriteVehicleSlugs;
    }

    $authenticatedUser = current_user();
    if (($authenticatedUser["role"] ?? null) !== "customer") {
        $favoriteVehicleSlugs = [];
        return $favoriteVehicleSlugs;
    }

    $favoriteVehicleSlugs = favorite_slugs(
        (int) $authenticatedUser["id"],
    );

    return $favoriteVehicleSlugs;
}

/**
 * Render one reusable vehicle catalog card.
 *
 * @param array{
 *     slug: string,
 *     name: string,
 *     category: string,
 *     image: string,
 *     price: int,
 *     seats: int,
 *     transmission: string,
 *     availability_status: string,
 *     rating: float,
 *     review_count: int
 * } $vehicle
 */
function vehicle_card(array $vehicle, bool $compact = false): void
{
    $compactCardClass = $compact ? " vehicle-card--compact" : "";
    $currentPageName = basename($_SERVER["PHP_SELF"] ?? "vehicles.php");
    $currentQueryString = trim((string) ($_SERVER["QUERY_STRING"] ?? ""));
    $currentRequestPath = $currentPageName;

    if ($currentQueryString !== "") {
        $currentRequestPath .= "?" . $currentQueryString;
    }

    $authenticatedUser = current_user();
    $isCustomer = ($authenticatedUser["role"] ?? null) === "customer";
    $isAnonymousVisitor = $authenticatedUser === null;
    $showComparisonLink = !$compact && $currentPageName === "vehicles.php";
    $vehicleIsFavorite = in_array(
        $vehicle["slug"],
        customer_favorite_vehicle_slugs(),
        true,
    );
    $favoriteActionLabel = $vehicleIsFavorite
        ? "Remove {$vehicle["name"]} from favorites"
        : "Save {$vehicle["name"]} to favorites";

    $vehicleImageFilename = basename((string) $vehicle["image"]);
    $vehicleImageRelativePath = "assets/images/cars/" . $vehicleImageFilename;
    $vehicleImageSource = is_file(ROOT . "/" . $vehicleImageRelativePath)
        ? $vehicleImageRelativePath
        : "assets/images/placeholders/vehicle-placeholder.svg";
    ?>
    <article class="vehicle-card<?= $compactCardClass ?>">
        <?php if ($isCustomer): ?>
            <form class="favorite-form" method="post" action="favorite-action.php">
                <?= csrf_field() ?>
                <input
                    type="hidden"
                    name="vehicle"
                    value="<?= escape_html($vehicle["slug"]) ?>"
                >
                <input
                    type="hidden"
                    name="return_to"
                    value="<?= escape_html($currentRequestPath) ?>"
                >
                <button
                    class="favorite-button<?= $vehicleIsFavorite ? " is-active" : "" ?>"
                    type="submit"
                    aria-label="<?= escape_html($favoriteActionLabel) ?>"
                >
                    <i
                        class="bi <?= $vehicleIsFavorite ? "bi-heart-fill" : "bi-heart" ?>"
                        aria-hidden="true"
                    ></i>
                </button>
            </form>
        <?php elseif ($isAnonymousVisitor): ?>
            <a
                class="favorite-button"
                href="login.php?return_to=<?= urlencode($currentRequestPath) ?>"
                aria-label="Sign in to save <?= escape_html($vehicle["name"]) ?> to favorites"
            >
                <i class="bi bi-heart" aria-hidden="true"></i>
            </a>
        <?php endif; ?>

        <a
            class="vehicle-image-wrap"
            href="vehicle-details.php?vehicle=<?= urlencode($vehicle["slug"]) ?>"
        >
            <img
                src="<?= escape_html($vehicleImageSource) ?>"
                alt="<?= escape_html($vehicle["name"]) ?>"
                loading="lazy"
                decoding="async"
            >
        </a>

        <div class="vehicle-card-body">
            <div class="d-flex align-items-center justify-content-between gap-2">
                <span class="vehicle-category"><?= escape_html(
                    $vehicle["category"],
                ) ?></span>
                <span class="fleet-state fleet-state--<?= status_class(
                    $vehicle["availability_status"],
                ) ?>">
                    <?= escape_html(humanize_label($vehicle["availability_status"])) ?>
                </span>
            </div>

            <h3>
                <a href="vehicle-details.php?vehicle=<?= urlencode(
                    $vehicle["slug"],
                ) ?>">
                    <?= escape_html($vehicle["name"]) ?>
                </a>
            </h3>

            <?php if (!$compact): ?>
                <?php if ($vehicle["review_count"] > 0): ?>
                    <div
                        class="vehicle-rating"
                        aria-label="Rating <?= number_format(
                            $vehicle["rating"],
                            1,
                        ) ?> out of 5"
                    >
                        <i class="bi bi-star-fill" aria-hidden="true"></i>
                        <strong><?= number_format($vehicle["rating"], 1) ?></strong>
                        <span>(<?= (int) $vehicle["review_count"] ?>)</span>
                    </div>
                <?php else: ?>
                    <div class="vehicle-rating vehicle-rating--new">
                        <i class="bi bi-stars" aria-hidden="true"></i>
                        <strong>New to the fleet</strong>
                    </div>
                <?php endif; ?>

                <div class="vehicle-specs" aria-label="Vehicle specifications">
                    <span>
                        <i class="bi bi-people" aria-hidden="true"></i>
                        <?= (int) $vehicle["seats"] ?> seats
                    </span>
                    <span>
                        <i class="bi bi-gear" aria-hidden="true"></i>
                        <?= escape_html($vehicle["transmission"]) ?>
                    </span>
                </div>

                <?php if ($showComparisonLink): ?>
                    <a
                        class="compare-toggle"
                        href="compare.php?compare[]=<?= urlencode(
                            $vehicle["slug"],
                        ) ?>"
                    >
                        <i class="bi bi-columns-gap" aria-hidden="true"></i>
                        <span>Compare this vehicle</span>
                    </a>
                <?php endif; ?>
            <?php endif; ?>

            <div class="vehicle-price-row">
                <strong>
                    ₱<?= number_format($vehicle["price"]) ?><small>/ day</small>
                </strong>

                <?php if (!$compact): ?>
                    <a
                        href="booking.php?vehicle=<?= urlencode(
                            $vehicle["slug"],
                        ) ?>"
                        aria-label="Book <?= escape_html($vehicle["name"]) ?>"
                    >
                        <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </article>
    <?php
}
