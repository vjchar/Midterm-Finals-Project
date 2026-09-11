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
    session_start();
}

require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/database.php";
