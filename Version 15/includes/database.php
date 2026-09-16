<?php

declare(strict_types=1);


/**
 * FILE: includes/database.php
 * FILE PURPOSE: PDO database connection and shared low-level database utilities.
 * USED BY: Domain services that need MySQL access.
 * RESPONSIBILITY: Creates and reuses the PDO connection with safe settings; business-specific queries should remain in services.
 *
 * Maintenance note: Keep this file focused on the responsibility described above.
 */
/**
 * Return the process-local PDO connection used by the application services.
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

/** Return the connected PDO driver name for diagnostics. */
function database_driver_name(): string
{
    return (string) database()->getAttribute(PDO::ATTR_DRIVER_NAME);
}
