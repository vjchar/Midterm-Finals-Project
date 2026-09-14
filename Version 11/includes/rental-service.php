<?php

declare(strict_types=1);

/**
 * Compatibility facade for rental operations.
 *
 * Bootstrap and existing pages continue loading this established path. The
 * implementation now lives in cohesive service modules, loaded in dependency
 * order so every existing global vj_* function remains available unchanged.
 */
require_once __DIR__ . "/services/notification-service.php";
require_once __DIR__ . "/services/document-service.php";
require_once __DIR__ . "/services/payment-service.php";
require_once __DIR__ . "/services/rental-lifecycle-service.php";
require_once __DIR__ . "/services/rental-adjustment-service.php";
