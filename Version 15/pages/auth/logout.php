<?php

declare(strict_types=1);


/**
 * FILE: pages/auth/logout.php
 * FILE PURPOSE: Logout endpoint that clears the authenticated session and redirects safely.
 * USED BY: Visitors or users entering/leaving the authentication flow.
 * RESPONSIBILITY: Loads the required application/services, handles only page-level request orchestration, and renders the user interface; reusable business/database logic belongs in services.
 *
 * Maintenance note: Keep this file focused on the responsibility described above.
 */
require dirname(__DIR__, 2) . "/includes/bootstrap.php";
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    header("Allow: POST");
    exit("Method Not Allowed");
}
require_csrf();
logout();
header("Location: index.php", true, 303);
exit();
