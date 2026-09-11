<?php

declare(strict_types=1);

const APP_NAME = "VJ Car Rental";
const APP_TIMEZONE = "Asia/Manila";
const PASSWORD_MIN_LENGTH = 8;
const SESSION_IDLE_TIMEOUT_SECONDS = 3600;
const SESSION_REGENERATION_SECONDS = 900;

// Version 4 keeps the Version 3 vehicle database and adds authentication.
// These values match the project's local XAMPP/MySQL configuration style from Final(10).
defined("DB_HOST") || define("DB_HOST", "127.0.0.1");
defined("DB_PORT") || define("DB_PORT", 3306);
defined("DB_NAME") || define("DB_NAME", "vj_car_rental_v4");
defined("DB_USER") || define("DB_USER", "root");
defined("DB_PASSWORD") || define("DB_PASSWORD", "");
