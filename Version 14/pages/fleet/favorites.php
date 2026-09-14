<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";
$authenticatedUser = require_customer();
require_once dirname(__DIR__, 2) . "/includes/components/vehicle-card.php";

$vehicles = vehicle_all();
$savedVehicleSlugs = favorite_slugs((int) $authenticatedUser["id"]);
$savedVehicles = array_values(
    array_filter(
        $vehicles,
        static fn(array $vehicle): bool => in_array(
            $vehicle["slug"],
            $savedVehicleSlugs,
            true,
        ),
    ),
);
$pageTitle = "Favorite Vehicles | VJ Car Rental";
$pageDescription =
    "View vehicles saved securely to your VJ Car Rental account.";
require dirname(__DIR__, 2) . "/includes/header.php";
?>
<section class="page-hero page-hero--compact pattern-layer">
    <div class="container">
        <span class="section-kicker">Saved to your account</span>
        <h1>Your favorite vehicles</h1>
        <p>Your shortlist follows your signed-in account across devices and is stored in the application database.</p>
    </div>
</section>
<section class="content-section favorites-page">
    <div class="container">
        <div class="fleet-toolbar">
            <div>
                <span class="section-kicker">Shortlist</span>
                <h2>
                    <span><?= count(
                        $savedVehicles,
                    ) ?></span> saved car<?= count($savedVehicles) === 1
    ? ""
    : "s" ?>
                </h2>
            </div>
            <a class="btn btn-outline" href="vehicles.php">Browse All Vehicles</a>
        </div>
        <div class="row g-4">
            <?php foreach ($savedVehicles as $savedVehicle): ?>
                <div class="col-xl-3 col-lg-4 col-md-6">
                    <?php vehicle_card($savedVehicle); ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (!$savedVehicles): ?>
        <div class="empty-state">
            <i class="bi bi-heart"></i>
            <h3>No favorites saved yet</h3>
            <p>Select the heart icon on a vehicle to build your account shortlist.</p>
            <a class="btn btn-primary" href="vehicles.php">Explore Vehicles</a>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
