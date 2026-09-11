<?php

declare(strict_types=1);

require_once __DIR__ . "/bootstrap.php";

$pageTitle = $pageTitle ?? "VJ Car Rental";
$pageDescription =
    $pageDescription ??
    "Reliable, clean, and affordable car rentals for every journey.";
$currentPage = basename($_SERVER["PHP_SELF"] ?? "index.php");
$activeNav = $currentPage === "vehicle-details.php" ? "vehicles.php" : $currentPage;
$currentUser = current_user();
$isAdmin = ($currentUser["role"] ?? "") === "admin";
$flashMessages = $flashMessages ?? pull_flashes();

$navItems = [
    "index.php" => "Home",
    "vehicles.php" => "Vehicles",
    "services.php" => "Services",
    "about.php" => "About Us",
    "contact.php" => "Contact",
];
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= escape_html($pageDescription) ?>">
    <meta name="theme-color" content="#0f2d5c">
    <title><?= escape_html($pageTitle) ?></title>

    <link rel="icon" href="favicon.ico" sizes="any">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@700;800&display=swap" rel="stylesheet">
    <link href="assets/css/vendor/bootstrap.css" rel="stylesheet">
    <link href="assets/css/vendor/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/tailwind.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link href="assets/css/operations.css" rel="stylesheet">
    <link href="assets/css/design-polish.css" rel="stylesheet">
    <link href="assets/css/vehicle-images.css?v=20260910-4" rel="stylesheet">
    <link href="assets/css/responsive-final.css" rel="stylesheet">
</head>

<body data-page="<?= escape_html($currentPage) ?>" data-auth="<?= $currentUser ? "authenticated" : "guest" ?>">
    <a class="skip-link" href="#main-content">Skip to content</a>

    <header class="site-header" id="siteHeader">
        <div class="container mockup-header__inner">
            <a class="mockup-logo" href="index.php" aria-label="VJ Car Rental home">
                <img src="assets/images/logo/Logo.png" alt="VJ Car Rental - Your Journey Starts Here" decoding="async" fetchpriority="high">
            </a>

            <input class="mockup-menu-control" id="siteMenuControl" type="checkbox" aria-controls="siteMenu" aria-expanded="false" aria-label="Open or close the page menu">
            <label class="mockup-menu-toggle" for="siteMenuControl" aria-hidden="true"><span></span><span></span><span></span></label>

            <div class="mockup-menu" id="siteMenu">
                <nav class="mockup-links" aria-label="Primary navigation">
                    <?php foreach ($navItems as $file => $label): ?>
                        <?php $isActiveLink = $activeNav === $file; ?>
                        <a class="mockup-link<?= $isActiveLink ? " active" : "" ?>" href="<?= escape_html($file) ?>" <?= $isActiveLink ? 'aria-current="page"' : "" ?>><?= escape_html($label) ?></a>
                    <?php endforeach; ?>
                </nav>

                <?php if ($currentUser): ?>
                    <?php if ($isAdmin): ?>
                        <a class="account-link" href="vehicle-management.php"><i class="bi bi-shield-lock" aria-hidden="true"></i><span>Vehicle Management</span></a>
                    <?php else: ?>
                        <span class="account-link" aria-label="Signed in user"><i class="bi bi-person-circle" aria-hidden="true"></i><span><?= escape_html(explode(" ", trim($currentUser["name"]))[0]) ?></span></span>
                    <?php endif; ?>
                    <form class="d-inline-flex m-0" action="logout.php" method="post">
                        <?= csrf_field() ?>
                        <button class="account-link" type="submit"><i class="bi bi-box-arrow-right" aria-hidden="true"></i><span>Logout</span></button>
                    </form>
                <?php else: ?>
                    <a class="account-link" href="login.php"><i class="bi bi-person" aria-hidden="true"></i><span>Sign In</span></a>
                    <a class="mockup-book-button" href="register.php">Register</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main id="main-content">
        <?php if ($flashMessages): ?>
            <div class="container flash-stack" aria-live="polite">
                <?php foreach ($flashMessages as $flash): ?>
                    <div class="alert alert-<?= escape_html($flash["type"]) ?>" role="alert"><?= escape_html($flash["message"]) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
