<?php

declare(strict_types=1);

require_once __DIR__ . "/bootstrap.php";

$pageTitle = $pageTitle ?? "VJ Car Rental";
$pageDescription =
    $pageDescription ??
    "Reliable, clean, and affordable car rentals for every journey.";
$currentPage = basename($_SERVER["PHP_SELF"] ?? "index.php");

$activeNav = match ($currentPage) {
    "vehicle-details.php", "compare.php", "favorites.php" => "vehicles.php",
    "booking-confirmation.php",
    "manage-booking.php",
    "rate-trip.php",
    "my-bookings.php",
    "booking-view.php",
    "payments.php",
    "invoice.php"
        => "booking.php",
    "faq.php" => "services.php",
    default => $currentPage,
};

$currentUser = current_user();
$isCustomer = ($currentUser["role"] ?? "") === "customer";
$unreadNotifications = $currentUser
    ? unread_notification_count((int) $currentUser["id"])
    : 0;

$isAdminLogin = $currentPage === "admin-login.php";
$isAdminRoute =
    in_array($currentPage, ["admin.php", "health.php"], true) ||
    (str_starts_with($currentPage, "admin-") && !$isAdminLogin);
$isAdminContext = $isAdminRoute && ($currentUser["role"] ?? "") === "admin";

$bodyClasses = array_filter([
    $isAdminContext ? "is-admin-context" : "",
    $isAdminLogin ? "is-admin-auth" : "",
]);

$flashMessages = pull_flashes();
$navItems = [
    "index.php" => "Home",
    "vehicles.php" => "Vehicles",
    "services.php" => "Services",
    "about.php" => "About Us",
    "contact.php" => "Contact",
];

$notificationLabel = "Notifications";
$accountDestination = "login.php";
$accountFirstName = "Sign In";

if ($currentUser) {
    if ($unreadNotifications > 0) {
        $notificationLabel .= " ({$unreadNotifications} unread)";
    }

    $accountDestination =
        $currentUser["role"] === "admin" ? "admin.php" : "account.php";
    $accountFirstName = explode(" ", trim($currentUser["name"]))[0];
}
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

<body
    class="<?= escape_html(implode(" ", $bodyClasses)) ?>"
    data-page="<?= escape_html($currentPage) ?>"
    data-auth="<?= $currentUser ? "authenticated" : "guest" ?>">
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

                <?php if ($currentUser): ?>
                    <a
                        class="notification-link"
                        href="notifications.php"
                        aria-label="<?= escape_html($notificationLabel) ?>">
                        <i class="bi bi-bell" aria-hidden="true"></i>

                        <?php if ($unreadNotifications > 0): ?>
                            <span><?= min(99, $unreadNotifications) ?></span>
                        <?php endif; ?>
                    </a>

                <?php endif; ?>

                <?php if ($currentUser): ?>
                    <a class="account-link" href="<?= escape_html($accountDestination) ?>">
                        <i class="bi bi-person-circle" aria-hidden="true"></i>
                        <span><?= escape_html($accountFirstName) ?></span>
                    </a>
                <?php else: ?>
                    <a class="account-link" href="login.php">
                        <i class="bi bi-person" aria-hidden="true"></i>
                        <span>Sign In</span>
                    </a>
                <?php endif; ?>

                <?php if (!$currentUser || $isCustomer): ?>
                    <?php $isBookingPage = $activeNav === "booking.php"; ?>
                    <a
                        class="mockup-book-button<?= $isBookingPage
                            ? " active"
                            : "" ?>"
                        href="booking.php"
                        <?= $isBookingPage ? 'aria-current="page"' : "" ?>>
                        Book a Car
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main id="main-content">
        <?php if (!admin_exists() && $currentPage !== "setup.php"): ?>
            <div class="setup-notice">
                <div class="container">
                    <i class="bi bi-shield-lock" aria-hidden="true"></i>
                    <span>First-time setup is required before launch.</span>
                    <a href="setup.php">Create administrator</a>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($flashMessages): ?>
            <div class="container flash-stack" aria-live="polite">
                <?php foreach ($flashMessages as $flash): ?>
                    <div class="alert alert-<?= escape_html(
                        $flash["type"],
                    ) ?>" role="alert">
                        <?= escape_html($flash["message"]) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
