<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";
$user = require_customer();
$bookings = bookings_for_user($user["id"]);
$counts = array_count_values(array_column($bookings, "status"));
$favoriteCount = count(favorite_slugs($user["id"]));
$documents = customer_documents($user["id"]);
$approvedDocumentCount = count(
    array_filter(
        $documents,
        static fn(array $document): bool => $document["status"] === "approved",
    ),
);
$unreadCount = unread_notification_count($user["id"]);
$pageTitle = "My Account | VJ Car Rental";
$pageDescription =
    "Manage your VJ Car Rental profile, bookings, favorites, and completed-trip reviews.";
require dirname(__DIR__, 2) . "/includes/header.php";
?>
<section class="account-hero pattern-layer">
    <div class="container">
        <div>
            <span class="section-kicker">Customer dashboard</span>
            <h1>Hello, <?= escape_html(explode(" ", $user["name"])[0]) ?></h1>
            <p>Manage every part of your VJ Car Rental journey from one secure account.</p>
        </div>
        <div class="account-hero__actions">
            <a class="btn btn-primary" href="booking.php">Book a Vehicle</a>
            <a class="btn btn-outline" href="profile.php">Edit Profile</a>
        </div>
    </div>
</section>
<section class="content-section account-section">
    <div class="container">
        <div class="account-stats">
            <article>
                <i class="bi bi-calendar2-check"></i>
                <strong><?= count($bookings) ?></strong>
                <span>Total bookings</span>
            </article>
            <article>
                <i class="bi bi-hourglass-split"></i>
                <strong><?= (int) (($counts["pending"] ?? 0) +
                    ($counts["confirmed"] ?? 0)) ?></strong>
                <span>Upcoming</span>
            </article>
            <article>
                <i class="bi bi-check2-circle"></i>
                <strong><?= (int) ($counts["completed"] ?? 0) ?></strong>
                <span>Completed</span>
            </article>
            <article>
                <i class="bi bi-heart"></i>
                <strong><?= $favoriteCount ?></strong>
                <span>Favorites</span>
            </article>
            <article>
                <i class="bi bi-person-vcard"></i>
                <strong><?= $approvedDocumentCount ?>/2</strong>
                <span>Verified documents</span>
            </article>
        </div>
        <div class="dashboard-grid">
            <section class="dashboard-panel dashboard-panel--wide">
                <div class="dashboard-panel__header">
                    <div>
                        <span class="section-kicker">Recent activity</span>
                        <h2>Your latest bookings</h2>
                    </div>
                    <a href="my-bookings.php">View all</a>
                </div>
                <?php if (!$bookings): ?>
                    <div class="dashboard-empty">
                        <i class="bi bi-car-front"></i>
                        <h3>No bookings yet</h3>
                        <p>Browse the fleet and reserve the right vehicle for your next trip.</p>
                        <a class="btn btn-primary" href="vehicles.php">Explore Vehicles</a>
                    </div>
                <?php else: ?>
                    <div class="booking-list booking-list--compact">
                        <?php foreach (
                            array_slice($bookings, 0, 4)
                            as $booking
                        ): ?>
                            <a class="booking-list-item" href="booking-view.php?reference=<?= urlencode(
                                $booking["reference"],
                            ) ?>">
                                <img src="assets/images/cars/<?= escape_html(
                                    $booking["vehicle_image"],
                                ) ?>" alt="<?= escape_html(
    $booking["vehicle_name"],
) ?>">
                                <span>
                                    <small><?= escape_html(
                                        $booking["reference"],
                                    ) ?></small>
                                    <strong><?= escape_html(
                                        $booking["vehicle_name"],
                                    ) ?></strong>
                                    <em><?= date(
                                        "M j, Y",
                                        strtotime($booking["pickup_at"]),
                                    ) ?> – <?= date(
     "M j, Y",
     strtotime($booking["return_at"]),
 ) ?></em>
                                </span>
                                <b class="status-badge status-badge--<?= status_class(
                                    $booking["status"],
                                ) ?>"><?= escape_html(
    ucfirst($booking["status"]),
) ?></b>
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
            <aside class="dashboard-panel dashboard-panel--wide dashboard-panel--tools">
                <span class="section-kicker">Quick actions</span>
                <h2>Customer tools</h2>
                <nav class="dashboard-links">
                    <a href="my-bookings.php">
                        <i class="bi bi-calendar3"></i>
                        <span>
                            <strong>My Bookings</strong>
                            <small>View, reschedule, or cancel</small>
                        </span>
                    </a>
                    <a href="favorites.php">
                        <i class="bi bi-heart"></i>
                        <span>
                            <strong>Favorites</strong>
                            <small>Your saved vehicles</small>
                        </span>
                    </a>
                    <a href="documents.php">
                        <i class="bi bi-person-vcard"></i>
                        <span>
                            <strong>Rental Documents</strong>
                            <small>License and ID verification</small>
                        </span>
                    </a>
                    <a href="payments.php">
                        <i class="bi bi-credit-card"></i>
                        <span>
                            <strong>Payments</strong>
                            <small>Submit and track payments</small>
                        </span>
                    </a>
                    <a href="notifications.php">
                        <i class="bi bi-bell"></i>
                        <span>
                            <strong>Notifications<?= $unreadCount
                                ? " (" . $unreadCount . ")"
                                : "" ?></strong>
                            <small>Booking and account updates</small>
                        </span>
                    </a>
                    <a href="rate-trip.php">
                        <i class="bi bi-star"></i>
                        <span>
                            <strong>Rate a Trip</strong>
                            <small>Review a completed rental</small>
                        </span>
                    </a>
                    <a href="contact.php">
                        <i class="bi bi-headset"></i>
                        <span>
                            <strong>Get Support</strong>
                            <small>Send a secure message</small>
                        </span>
                    </a>
                    <?php if ($user["role"] === "admin"): ?>
                        <a href="admin.php">
                            <i class="bi bi-speedometer2"></i>
                            <span>
                                <strong>Administration</strong>
                                <small>Open operations dashboard</small>
                            </span>
                        </a>
                    <?php endif; ?>
                </nav>
                <form method="post" action="logout.php" class="mt-3"><?= csrf_field() ?><button class="btn btn-outline w-100" type="submit">
                        <i class="bi bi-box-arrow-right"></i> Sign Out</button>
                </form>
            </aside>
        </div>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
