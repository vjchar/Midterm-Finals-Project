<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";
$user = require_customer();
$bookings = bookings_for_user($user["id"]);
$statusFilter = trim((string) ($_GET["status"] ?? "all"));
$allowedStatuses = array_merge(["all"], booking_statuses());
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = "all";
}
$visibleBookings = $statusFilter === "all"
    ? $bookings
    : array_values(array_filter($bookings, static fn(array $booking): bool => $booking["status"] === $statusFilter));
$pageTitle = "My Bookings | VJ Car Rental";
require dirname(__DIR__, 2) . "/includes/header.php";
?>
<section class="page-hero page-hero--compact pattern-layer">
    <div class="container">
        <span class="section-kicker">Reservation history</span>
        <h1>My bookings</h1>
        <p>Every booking shows the most useful next action, so you can continue without searching through the site.</p>
        <a class="btn btn-outline mt-3" href="saved-bookings.php"><i class="bi bi-bookmark"></i> Saved Bookings</a>
    </div>
</section>
<section class="content-section">
    <div class="container">
        <div class="booking-filter-tabs" role="navigation" aria-label="Booking status filters">
            <?php foreach ($allowedStatuses as $status): ?>
                <a class="<?= $statusFilter === $status ? "active" : "" ?>" href="my-bookings.php?status=<?= urlencode($status) ?>"><?= escape_html(humanize_label($status)) ?></a>
            <?php endforeach; ?>
        </div>

        <?php if (!$visibleBookings): ?>
            <div class="empty-state">
                <i class="bi bi-calendar2-x"></i>
                <h2>No <?= $statusFilter === "all" ? "" : escape_html(status_label($statusFilter)) . " " ?>bookings found</h2>
                <p>Your reservations will appear here after you complete a booking.</p>
                <a class="btn btn-primary" href="vehicles.php">Browse Vehicles</a>
            </div>
        <?php else: ?>
            <div class="booking-list booking-list--guided">
                <?php foreach ($visibleBookings as $booking): ?>
                    <?php $journey = booking_next_step($booking); ?>
                    <article class="booking-list-item booking-list-item--guided">
                        <a class="booking-list-item__main" href="booking-view.php?reference=<?= urlencode($booking["reference"]) ?>">
                            <img src="assets/images/cars/<?= escape_html($booking["vehicle_image"]) ?>" alt="<?= escape_html($booking["vehicle_name"]) ?>">
                            <span>
                                <small><?= escape_html($booking["reference"]) ?></small>
                                <strong><?= escape_html($booking["vehicle_name"]) ?></strong>
                                <em><i class="bi bi-calendar3"></i> <?= date("M j, Y g:i A", strtotime($booking["pickup_at"])) ?> – <?= date("M j, Y g:i A", strtotime($booking["return_at"])) ?></em>
                                <em><i class="bi bi-geo-alt"></i> <?= escape_html($booking["pickup_location"]) ?></em>
                            </span>
                            <b><?= money((int) $booking["total"]) ?><small>rental total</small></b>
                            <strong class="status-badge status-badge--<?= status_class($booking["status"]) ?>"><?= escape_html(humanize_label($booking["status"])) ?></strong>
                        </a>
                        <div class="booking-list-item__next">
                            <span><small>Next step</small><strong><?= escape_html($journey["title"]) ?></strong></span>
                            <?php if ($journey["no_action"]): ?>
                                <em><i class="bi bi-clock-history"></i> No action required</em>
                            <?php endif; ?>
                            <a class="btn <?= $journey["action_required"] ? "btn-primary" : "btn-outline" ?> btn-sm" href="<?= escape_html($journey["target_url"]) ?>"><?= escape_html($journey["button_label"]) ?></a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
