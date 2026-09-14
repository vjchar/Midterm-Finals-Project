<?php

declare(strict_types=1);

/**
 * Direct XAMPP configuration for the school-final project.
 *
 * This application uses no environment-variable loader. If XAMPP uses a
 * different MySQL password or port, change the values in this file.
 */
const APP_NAME = "VJ Car Rental";
const APP_VERSION = "Version 9";
defined("APP_URL") || define("APP_URL", "http://localhost/VJ-Car-Rental-v9");
const APP_TIMEZONE = "Asia/Manila";
const APP_MODE = "local";
const APP_DEBUG = false;
const APP_MAIL_FROM = "reservations@localhost";
const APP_KEY = "vj-school-final-2026-73f4bbd48a754f31a3a8c24025e90d67";
const SESSION_IDLE_TIMEOUT_SECONDS = 3600;
const SESSION_REGENERATION_SECONDS = 900;

defined("DB_HOST") || define("DB_HOST", "127.0.0.1");
defined("DB_PORT") || define("DB_PORT", 3306);
defined("DB_NAME") || define("DB_NAME", "vj_car_rental_v9");
defined("DB_USER") || define("DB_USER", "root");
defined("DB_PASSWORD") || define("DB_PASSWORD", "");

const PASSWORD_MIN_LENGTH = 8;
