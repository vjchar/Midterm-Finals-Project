<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";
$user = require_auth();
$bookings = bookings_for_user((int) $user["id"]);
$statusFilter = trim((string) ($_GET["status"] ?? "all"));
$allowedStatuses = array_merge(["all"], booking_statuses());
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = "all";
}
$visibleBookings =
    $statusFilter === "all"
        ? $bookings
        : array_values(
            array_filter(
                $bookings,
                static fn(array $booking): bool =>
                    $booking["status"] === $statusFilter,
            ),
        );
$pageTitle = "My Bookings | VJ Car Rental";
require dirname(__DIR__, 2) . "/includes/header.php";
?>
<section class="page-hero page-hero--compact pattern-layer">
    <div class="container">
        <span class="section-kicker">Reservation history</span>
        <h1>My bookings</h1>
        <p>Open a reservation to review its schedule, total, status, or cancel when eligible.</p>
    </div>
</section>
<section class="content-section">
    <div class="container">
        <div class="booking-filter-tabs" role="navigation" aria-label="Booking status filters">
            <?php foreach ($allowedStatuses as $status): ?>
                <a class="<?= $statusFilter === $status ? "active" : "" ?>"
                   href="my-bookings.php?status=<?= urlencode($status) ?>">
                    <?= escape_html(ucfirst($status)) ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php if (!$visibleBookings): ?>
            <div class="empty-state">
                <i class="bi bi-calendar2-x"></i>
                <h2>No <?= $statusFilter === "all" ? "" : escape_html($statusFilter) . " " ?>bookings found</h2>
                <p>Your reservations will appear here after you complete a booking.</p>
                <a class="btn btn-primary" href="vehicles.php">Browse Vehicles</a>
            </div>
        <?php else: ?>
            <div class="booking-list">
                <?php foreach ($visibleBookings as $booking): ?>
                    <a class="booking-list-item"
                       href="booking-view.php?reference=<?= urlencode($booking["reference"]) ?>">
                        <img src="assets/images/cars/<?= escape_html($booking["vehicle_image"]) ?>"
                             alt="<?= escape_html($booking["vehicle_name"]) ?>">
                        <span>
                            <small><?= escape_html($booking["reference"]) ?></small>
                            <strong><?= escape_html($booking["vehicle_name"]) ?></strong>
                            <em><i class="bi bi-calendar3"></i>
                                <?= date("M j, Y g:i A", strtotime($booking["pickup_at"])) ?> –
                                <?= date("M j, Y g:i A", strtotime($booking["return_at"])) ?>
                            </em>
                            <em><i class="bi bi-geo-alt"></i> <?= escape_html($booking["pickup_location"]) ?></em>
                        </span>
                        <b><?= money((int) $booking["total"]) ?><small>rental total</small></b>
                        <strong class="status-badge status-badge--<?= status_class($booking["status"]) ?>">
                            <?= escape_html(ucfirst($booking["status"])) ?>
                        </strong>
                        <i class="bi bi-chevron-right"></i>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
