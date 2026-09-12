<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";
$user = require_auth();
$reference = trim((string) ($_GET["reference"] ?? ""));
$booking = booking_find_by_reference($reference);

if (
    !$booking ||
    ((int) $booking["user_id"] !== (int) $user["id"] &&
        ($user["role"] ?? "") !== "admin")
) {
    flash("danger", "That booking could not be found.");
    redirect("my-bookings.php");
}

$days = max(
    1,
    (int) ceil(
        (strtotime($booking["return_at"]) - strtotime($booking["pickup_at"])) /
            86400,
    ),
);

$pageTitle = "Booking Confirmation | VJ Car Rental";
$pageDescription = "Review the saved VJ Car Rental booking confirmation.";
require dirname(__DIR__, 2) . "/includes/header.php";
?>
<section class="page-hero page-hero--compact pattern-layer">
    <div class="container">
        <span class="section-kicker">Saved reservation</span>
        <h1>Your booking was created</h1>
        <p>
            The reservation is stored securely and is now awaiting staff confirmation.
            You can track it from My Bookings.
        </p>
    </div>
</section>
<section class="content-section confirmation-page">
    <div class="container">
        <div class="booking-progress">
            <div class="active"><span>✓</span><strong>Build rental</strong></div>
            <i></i>
            <div class="active"><span>✓</span><strong>Saved booking</strong></div>
            <i></i>
            <div><span>3</span><strong>Staff confirmation</strong></div>
        </div>
        <div class="confirmation-banner">
            <i class="bi bi-check2-circle"></i>
            <div>
                <small>Booking reference</small>
                <h2><?= escape_html($booking["reference"]) ?></h2>
                <p>Status: <?= escape_html(ucfirst($booking["status"])) ?></p>
            </div>
        </div>
        <div class="row g-4 align-items-start">
            <div class="col-lg-7">
                <article class="confirmation-card">
                    <div class="confirmation-vehicle">
                        <img src="assets/images/cars/<?= escape_html($booking["vehicle_image"]) ?>"
                             alt="<?= escape_html($booking["vehicle_name"]) ?>">
                        <div>
                            <span class="section-kicker"><?= escape_html($booking["vehicle_category"]) ?></span>
                            <h2><?= escape_html($booking["vehicle_name"]) ?></h2>
                            <p>Your requested schedule has been saved for staff review.</p>
                        </div>
                    </div>
                    <div class="confirmation-details">
                        <span><small>Pick-up</small><strong><?= date("M j, Y g:i A", strtotime($booking["pickup_at"])) ?></strong></span>
                        <span><small>Return</small><strong><?= date("M j, Y g:i A", strtotime($booking["return_at"])) ?></strong></span>
                        <span><small>Rental period</small><strong><?= $days ?> day<?= $days === 1 ? "" : "s" ?></strong></span>
                        <span><small>Branch</small><strong><?= escape_html($booking["pickup_location"]) ?></strong></span>
                        <span><small>Customer</small><strong><?= escape_html($booking["customer_name"]) ?></strong></span>
                        <span><small>Status</small><strong><?= escape_html(ucfirst($booking["status"])) ?></strong></span>
                    </div>
                </article>
            </div>
            <div class="col-lg-5">
                <aside class="booking-summary-card">
                    <span class="section-kicker">Saved price breakdown</span>
                    <div class="summary-line">
                        <span>Rental subtotal</span>
                        <strong><?= money($booking["subtotal"]) ?></strong>
                    </div>
                    <div class="summary-total">
                        <span>Rental total</span>
                        <strong><?= money($booking["total"]) ?></strong>
                    </div>
                    <div class="d-grid gap-2 mt-4">
                        <a class="btn btn-primary"
                           href="payments.php?reference=<?= urlencode($booking["reference"]) ?>">
                            Proceed to Payment
                        </a>
                        <a class="btn btn-outline"
                           href="booking-view.php?reference=<?= urlencode($booking["reference"]) ?>">
                            View This Booking
                        </a>
                        <a class="btn btn-outline" href="my-bookings.php">View All Bookings</a>
                    </div>
                </aside>
            </div>
        </div>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
