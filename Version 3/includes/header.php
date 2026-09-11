<?php

declare(strict_types=1);

require_once __DIR__ . "/bootstrap.php";

$pageTitle = $pageTitle ?? "VJ Car Rental";
$pageDescription =
    $pageDescription ??
    "Reliable, clean, and affordable car rentals for every journey.";
$currentPage = basename($_SERVER["PHP_SELF"] ?? "index.php");
$activeNav = $currentPage;

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
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@700;800&display=swap"
        rel="stylesheet">
    <link href="assets/css/vendor/bootstrap.css" rel="stylesheet">
    <link href="assets/css/vendor/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/tailwind.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link href="assets/css/operations.css" rel="stylesheet">
    <link href="assets/css/design-polish.css" rel="stylesheet">
    <link href="assets/css/vehicle-images.css?v=20260910-4" rel="stylesheet">
    <link href="assets/css/responsive-final.css" rel="stylesheet">
</head>

<body data-page="<?= escape_html($currentPage) ?>">
    <a class="skip-link" href="#main-content">Skip to content</a>

    <header class="site-header" id="siteHeader">
        <div class="container mockup-header__inner">
            <a
                class="mockup-logo"
                href="index.php"
                aria-label="VJ Car Rental home">
                <img
                    src="assets/images/logo/Logo.png"
                    alt="VJ Car Rental - Your Journey Starts Here"
                    decoding="async"
                    fetchpriority="high">
            </a>

            <input
                class="mockup-menu-control"
                id="siteMenuControl"
                type="checkbox"
                aria-controls="siteMenu"
                aria-expanded="false"
                aria-label="Open or close the page menu">

            <label
                class="mockup-menu-toggle"
                for="siteMenuControl"
                aria-hidden="true">
                <span></span>
                <span></span>
                <span></span>
            </label>

            <div class="mockup-menu" id="siteMenu">
                <nav class="mockup-links" aria-label="Primary navigation">
                    <?php foreach ($navItems as $file => $label): ?>
                        <?php $isActiveLink = $activeNav === $file; ?>
                        <a
                            class="mockup-link<?= $isActiveLink
                                ? " active"
                                : "" ?>"
                            href="<?= escape_html($file) ?>"
                            <?= $isActiveLink ? 'aria-current="page"' : "" ?>>
                            <?= escape_html($label) ?>
                        </a>
                    <?php endforeach; ?>
                </nav>

            </div>
        </div>
    </header>

    <main id="main-content">
