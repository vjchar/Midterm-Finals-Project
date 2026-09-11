<?php

declare(strict_types=1);

/**
 * Return the process-local PDO connection used by the Version 4 vehicle and authentication repositories.
 */
function database(): PDO
{
    static $databaseConnection = null;
    if ($databaseConnection instanceof PDO) {
        return $databaseConnection;
    }

    $dataSourceName = sprintf(
        "mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4",
        DB_HOST,
        DB_PORT,
        DB_NAME,
    );
    $databaseConnection = new PDO(
        $dataSourceName,
        DB_USER,
        DB_PASSWORD,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ],
    );

    return $databaseConnection;
}

require_once __DIR__ . "/repositories/vehicle-repository.php";
require_once __DIR__ . "/repositories/booking-repository.php";
