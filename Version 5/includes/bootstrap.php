<?php

declare(strict_types=1);

if (defined("BOOTSTRAPPED")) {
    return;
}

define("BOOTSTRAPPED", true);
define("ROOT", dirname(__DIR__));

require_once __DIR__ . "/config.php";
date_default_timezone_set(APP_TIMEZONE);

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
    $sessionStartedAt = (int) ($_SESSION["session_started_at"] ?? $currentSessionTimestamp);

    if (
        $lastSessionActivity > 0 &&
        $currentSessionTimestamp - $lastSessionActivity > SESSION_IDLE_TIMEOUT_SECONDS
    ) {
        $_SESSION = [];
        session_regenerate_id(true);
        $sessionStartedAt = $currentSessionTimestamp;
    } elseif (
        $currentSessionTimestamp - $sessionStartedAt >= SESSION_REGENERATION_SECONDS
    ) {
        session_regenerate_id(true);
        $sessionStartedAt = $currentSessionTimestamp;
    }

    $_SESSION["last_activity_at"] = $currentSessionTimestamp;
    $_SESSION["session_started_at"] = $sessionStartedAt;
}

require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/booking-service.php";
