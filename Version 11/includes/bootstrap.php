<?php

declare(strict_types=1);

if (defined("BOOTSTRAPPED")) {
    return;
}
define("BOOTSTRAPPED", true);
define("ROOT", dirname(__DIR__));
require_once __DIR__ . "/config.php";

// Portable UTF-8 fallbacks for XAMPP/PHP installations where mbstring is
// disabled. Native mbstring functions remain preferred whenever available.
if (!function_exists("mb_strlen")) {
    function mb_strlen(string $value, ?string $encoding = null): int
    {
        if ($value === "") {
            return 0;
        }
        $matched = preg_match_all('/./us', $value, $characters);
        return $matched === false ? strlen($value) : $matched;
    }
}
if (!function_exists("mb_substr")) {
    function mb_substr(
        string $value,
        int $offset,
        ?int $length = null,
        ?string $encoding = null,
    ): string {
        if ($value === "") {
            return "";
        }
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            return $length === null
                ? substr($value, $offset)
                : substr($value, $offset, $length);
        }
        return implode(
            "",
            array_slice($characters, $offset, $length ?? null),
        );
    }
}

date_default_timezone_set(APP_TIMEZONE);
ini_set("display_errors", APP_DEBUG ? "1" : "0");
ini_set("log_errors", "1");

$storageDirectories = [
    ROOT . DIRECTORY_SEPARATOR . "storage",
    ROOT . DIRECTORY_SEPARATOR . "storage" . DIRECTORY_SEPARATOR . "logs",
    ROOT . DIRECTORY_SEPARATOR . "storage" . DIRECTORY_SEPARATOR . "reviews",
    ROOT .
    DIRECTORY_SEPARATOR .
    "storage" .
    DIRECTORY_SEPARATOR .
    "vehicle-uploads",
    ROOT .
    DIRECTORY_SEPARATOR .
    "storage" .
    DIRECTORY_SEPARATOR .
    "documents",
    ROOT .
    DIRECTORY_SEPARATOR .
    "storage" .
    DIRECTORY_SEPARATOR .
    "payment-proofs",
];
foreach ($storageDirectories as $directory) {
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }
}
ini_set(
    "error_log",
    ROOT .
        DIRECTORY_SEPARATOR .
        "storage" .
        DIRECTORY_SEPARATOR .
        "logs" .
        DIRECTORY_SEPARATOR .
        "app.log",
);

if (PHP_SAPI !== "cli") {
    set_exception_handler(static function (Throwable $error): never {
        error_log(
            sprintf(
                "Uncaught %s: %s in %s:%d\n%s",
                $error::class,
                $error->getMessage(),
                $error->getFile(),
                $error->getLine(),
                $error->getTraceAsString(),
            ),
        );

        if (!headers_sent()) {
            http_response_code(500);
        }

        if (APP_DEBUG) {
            echo '<pre style="white-space:pre-wrap">' .
                htmlspecialchars(
                    (string) $error,
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    "UTF-8",
                ) .
                "</pre>";
            exit();
        }
        require ROOT .
            DIRECTORY_SEPARATOR .
            "errors" .
            DIRECTORY_SEPARATOR .
            "500.php";
        exit();
    });
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off";
    ini_set("session.use_strict_mode", "1");
    session_name("car_session");
    session_set_cookie_params([
        "lifetime" => 0,
        "path" => "/",
        "secure" => $secure,
        "httponly" => true,
        "samesite" => "Lax",
    ]);
    session_start();

    $currentSessionTimestamp = time();
    $lastSessionActivity = (int) ($_SESSION["last_activity_at"] ?? 0);
    $sessionStartedAt = (int) (
        $_SESSION["session_started_at"] ?? $currentSessionTimestamp
    );

    if (
        $lastSessionActivity > 0 &&
        $currentSessionTimestamp - $lastSessionActivity >
            SESSION_IDLE_TIMEOUT_SECONDS
    ) {
        $_SESSION = [];
        session_regenerate_id(true);
        $sessionStartedAt = $currentSessionTimestamp;
    } elseif (
        $currentSessionTimestamp - $sessionStartedAt >=
        SESSION_REGENERATION_SECONDS
    ) {
        session_regenerate_id(true);
        $sessionStartedAt = $currentSessionTimestamp;
    }

    $_SESSION["last_activity_at"] = $currentSessionTimestamp;
    $_SESSION["session_started_at"] = $sessionStartedAt;
}

if (!headers_sent()) {
    $contentSecurityPolicy = [
        "default-src 'self'",
        "script-src 'self'",
        "style-src 'self' https://fonts.googleapis.com 'unsafe-inline'",
        "font-src 'self' https://fonts.gstatic.com data:",
        "img-src 'self' data:",
        "frame-src https://www.openstreetmap.org",
        "connect-src 'self'",
        "form-action 'self'",
        "base-uri 'self'",
        "object-src 'none'",
        "frame-ancestors 'self'",
    ];

    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: SAMEORIGIN");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header("Content-Security-Policy: " . implode("; ", $contentSecurityPolicy));
}

require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/booking-service.php";
require_once __DIR__ . "/rental-service.php";
require_once __DIR__ . "/services/booking-journey-service.php";
require_once __DIR__ . "/components/booking-progress.php";
