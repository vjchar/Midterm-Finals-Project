<?php

declare(strict_types=1);

const APP_NAME = "VJ Car Rental";
const APP_TIMEZONE = "Asia/Manila";

// Version 3 introduces the vehicle database. These values match the
// project's local XAMPP/MySQL configuration style from Final(10).
defined("DB_HOST") || define("DB_HOST", "127.0.0.1");
defined("DB_PORT") || define("DB_PORT", 3306);
defined("DB_NAME") || define("DB_NAME", "vj_car_rental_v3");
defined("DB_USER") || define("DB_USER", "root");
defined("DB_PASSWORD") || define("DB_PASSWORD", "");
