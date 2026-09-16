<?php

declare(strict_types=1);


/**
 * FILE: includes/config.php
 * FILE PURPOSE: Central application configuration.
 * USED BY: bootstrap.php and services that need environment, URL, storage, or payment settings.
 * RESPONSIBILITY: Stores configurable constants such as app settings, GCash details, bank account details, and runtime options in one place.
 *
 * Maintenance note: Keep this file focused on the responsibility described above.
 */
/**
 * Local XAMPP configuration for VJ Car Rental.
 *
 * This application uses no environment-variable loader. If XAMPP uses a
 * different MySQL password or port, change the values in this file.
 */
const APP_NAME = "VJ Car Rental";
const APP_VERSION = "Version 15";
defined("APP_URL") || define("APP_URL", "http://localhost/VJ-Car-Rental-v15");
const APP_TIMEZONE = "Asia/Manila";
const APP_MODE = "local";
const APP_DEBUG = false;
const APP_MAIL_FROM = "reservations@localhost";
const APP_KEY = "vj-school-final-2026-73f4bbd48a754f31a3a8c24025e90d67";
const SESSION_IDLE_TIMEOUT_SECONDS = 3600;
const SESSION_REGENERATION_SECONDS = 900;

defined("DB_HOST") || define("DB_HOST", "127.0.0.1");
defined("DB_PORT") || define("DB_PORT", 3306);
defined("DB_NAME") || define("DB_NAME", "vj_car_rental_v15");
defined("DB_USER") || define("DB_USER", "root");
defined("DB_PASSWORD") || define("DB_PASSWORD", "");

const PASSWORD_MIN_LENGTH = 8;
const BOOKING_DRAFT_TTL_DAYS = 7;

// Version 15 preserves the configurable cancellation/refund policy. Customer self-service cancellation
// follows the existing 24-hour booking cutoff. Verified payments are fully
// refundable before the cutoff; inside the cutoff only Admin can cancel and
// the default online-policy refund is zero unless a later policy is configured.
const CANCELLATION_CUSTOMER_CUTOFF_HOURS = 24;
const CANCELLATION_REFUND_CUTOFF_HOURS = 24;

// Manual payment account details. Replace these placeholders with the actual
// VJ Car Rental payment details before deployment.
const PAYMENT_GCASH_NAME = "VJ Car Rental";
const PAYMENT_GCASH_NUMBER = "09XX XXX XXXX";
const PAYMENT_BANK_NAME = "YOUR BANK";
const PAYMENT_BANK_ACCOUNT_NAME = "VJ Car Rental";
const PAYMENT_BANK_ACCOUNT_NUMBER = "XXXX XXXX XXXX";
