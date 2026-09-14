<?php

declare(strict_types=1);
$adminPage = basename($_SERVER["PHP_SELF"] ?? "admin.php");
$adminLinks = [
    "admin.php" => ["Dashboard", "bi-speedometer2"],
    "admin-bookings.php" => ["Bookings", "bi-calendar2-check"],
    "admin-rentals.php" => ["Rental Desk", "bi-key"],
    "admin-payments.php" => ["Payments", "bi-credit-card"],
    "admin-documents.php" => ["Documents", "bi-person-vcard"],
    "admin-vehicles.php" => ["Vehicles", "bi-car-front"],
    "admin-maintenance.php" => ["Maintenance", "bi-tools"],
    "admin-users.php" => ["Users", "bi-people"],
    "admin-reviews.php" => ["Reviews", "bi-star"],
    "admin-messages.php" => ["Messages", "bi-envelope"],
    "admin-promos.php" => ["Promotions", "bi-ticket-perforated"],
    "admin-addons.php" => ["Add-ons", "bi-plus-circle"],
    "admin-reports.php" => ["Reports", "bi-bar-chart-line"],
    "notifications.php" => ["Notifications", "bi-bell"],
    "health.php" => ["System", "bi-heart-pulse"],
];
?>
<input
    class="admin-nav-control"
    id="adminNavigationControl"
    type="checkbox"
    aria-controls="adminSidebar"
    aria-expanded="false"
    aria-label="Open or close the administration menu">

<label class="admin-nav-toggle" for="adminNavigationControl">
    <i class="bi bi-list"></i>
    <span>Admin menu</span>
</label>

<aside class="admin-nav" id="adminSidebar" aria-label="Administration sections">
    <div class="admin-nav__brand">
        <a href="admin.php">
            <img src="assets/images/logo/Logo.png" alt="VJ Car Rental">
            <span>Operations Center</span>
        </a>
    </div>

    <nav class="admin-nav__links">
        <?php foreach ($adminLinks as $file => [$label, $icon]): ?>
            <a
                class="<?= $adminPage === $file ? "active" : "" ?>"
                href="<?= $file ?>">
                <i class="bi <?= $icon ?>" aria-hidden="true"></i>
                <span><?= $label ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="admin-nav__footer">
        <a href="index.php">
            <i class="bi bi-box-arrow-up-right"></i>
            <span>View customer site</span>
        </a>
        <form method="post" action="logout.php">
            <?= csrf_field() ?>
            <button type="submit">
                <i class="bi bi-box-arrow-right"></i>
                <span>Sign out</span>
            </button>
        </form>
    </div>
</aside>

<label
    class="admin-nav-backdrop"
    for="adminNavigationControl"
    aria-label="Close administration menu"></label>
