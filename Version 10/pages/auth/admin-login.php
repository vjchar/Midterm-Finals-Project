<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";

if (admin()) {
    redirect("admin.php");
}

if (authenticated()) {
    redirect("account.php");
}

// Keep the former address working while all roles use one sign-in page.
redirect("login.php");
