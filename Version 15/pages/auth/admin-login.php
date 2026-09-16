<?php

declare(strict_types=1);


/**
 * FILE: pages/auth/admin-login.php
 * FILE PURPOSE: Administrator sign-in page.
 * USED BY: Visitors or users entering/leaving the authentication flow.
 * RESPONSIBILITY: Loads the required application/services, handles only page-level request orchestration, and renders the user interface; reusable business/database logic belongs in services.
 *
 * Maintenance note: Keep this file focused on the responsibility described above.
 */
require dirname(__DIR__, 2) . "/includes/bootstrap.php";

if (admin()) {
    redirect("admin.php");
}

if (authenticated()) {
    redirect("account.php");
}

// Keep the former address working while all roles use one sign-in page.
redirect("login.php");
