<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";
$user = require_customer();
$reference = trim((string) ($_GET["reference"] ?? ""));
$booking = booking_find_by_reference($reference);
if (
    !$booking ||
    (int) $booking["user_id"] !== $user["id"]
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
        <p>The reservation is stored securely and is now awaiting staff confirmation. You can track it from your account.</p>
    </div>
</section>
<section class="content-section confirmation-page">
    <div class="container">
        <div class="booking-progress">
            <div class="active">
                <span>✓</span>
                <strong>Build rental</strong>
            </div>
            <i></i>
            <div class="active">
                <span>✓</span>
                <strong>Saved booking</strong>
            </div>
            <i></i>
            <div class="active">
                <span>3</span>
                <strong>Verification</strong>
            </div>
            <i></i>
            <div>
                <span>4</span>
                <strong>Pickup and trip</strong>
            </div>
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
                        <img src="assets/images/cars/<?= escape_html(
                            $booking["vehicle_image"],
                        ) ?>" alt="<?= escape_html($booking["vehicle_name"]) ?>">
                        <div>
                            <span class="section-kicker"><?= escape_html(
                                $booking["vehicle_category"],
                            ) ?></span>
                            <h2><?= escape_html($booking["vehicle_name"]) ?></h2>
                            <p>Your selected vehicle has been held against the requested schedule while the team reviews the reservation.</p>
                        </div>
                    </div>
                    <div class="confirmation-details">
                        <span>
                            <small>Pick-up</small>
                            <strong><?= date(
                                "M j, Y g:i A",
                                strtotime($booking["pickup_at"]),
                            ) ?></strong>
                        </span>
                        <span>
                            <small>Return</small>
                            <strong><?= date(
                                "M j, Y g:i A",
                                strtotime($booking["return_at"]),
                            ) ?></strong>
                        </span>
                        <span>
                            <small>Rental period</small>
                            <strong><?= $days ?> day<?= $days === 1
     ? ""
     : "s" ?></strong>
                        </span>
                        <span>
                            <small>Method</small>
                            <strong><?= escape_html(
                                $booking["pickup_method"],
                            ) ?></strong>
                        </span>
                        <span>
                            <small>Branch</small>
                            <strong><?= escape_html(
                                $booking["pickup_location"],
                            ) ?></strong>
                        </span>
                        <span>
                            <small>Customer</small>
                            <strong><?= escape_html(
                                $booking["customer_name"],
                            ) ?></strong>
                        </span>
                    </div>
                </article>
                <article class="next-steps-card">
                    <span class="section-kicker">What happens next</span>
                    <h2>Reservation process</h2>
                    <ol>
                        <li>
                            <span>1</span>
                            <div>
                                <strong>Staff review</strong>
                                <p>The team verifies the schedule, renter information, and selected extras.</p>
                            </div>
                        </li>
                        <li>
                            <span>2</span>
                            <div>
                                <strong>Confirmation and payment instructions</strong>
                                <p>You receive the approved status and payment instructions through the configured business channel.</p>
                            </div>
                        </li>
                        <li>
                            <span>3</span>
                            <div>
                                <strong>Vehicle release</strong>
                                <p>Bring the required documents and refundable security deposit.</p>
                            </div>
                        </li>
                        <li>
                            <span>4</span>
                            <div>
                                <strong>Verified review</strong>
                                <p>A completed booking unlocks the post-trip rating form.</p>
                            </div>
                        </li>
                    </ol>
                </article>
            </div>
            <div class="col-lg-5">
                <aside class="booking-summary-card">
                    <span class="section-kicker">Saved price breakdown</span>
                    <div class="summary-line">
                        <span>Rental subtotal</span>
                        <strong><?= money($booking["subtotal"]) ?></strong>
                    </div>
                    <?php foreach ($booking["addons"] as $addon): ?>
                        <div class="summary-line">
                            <span><?= escape_html($addon["addon_name"]) .
                                ((int) $addon["quantity"] > 1
                                    ? " × " . (int) $addon["quantity"]
                                    : "") ?></span>
                            <strong><?= money(
                                (int) $addon["line_total"],
                            ) ?></strong>
                        </div>
                    <?php endforeach; ?>
                    <div class="summary-line">
                        <span>Delivery</span>
                        <strong><?= money(
                            $booking["delivery_fee"],
                        ) ?></strong>
                    </div>
                    <?php if ($booking["discount"] > 0): ?>
                        <div class="summary-line summary-line--discount">
                            <span><?= escape_html(
                                $booking["promo_code"],
                            ) ?> discount</span>
                            <strong>−<?= money(
                                $booking["discount"],
                            ) ?></strong>
                        </div>
                    <?php endif; ?>
                    <div class="summary-total">
                        <span>Rental total</span>
                        <strong><?= money($booking["total"]) ?></strong>
                    </div>
                    <div class="summary-line">
                        <span>Refundable deposit</span>
                        <strong><?= money($booking["deposit"]) ?></strong>
                    </div>
                    <small class="summary-deposit-note">The deposit is separate from the rental total.</small>
                    <div class="d-grid gap-2 mt-4">
                        <a class="btn btn-primary" href="booking-view.php?reference=<?= urlencode(
                            $booking["reference"],
                        ) ?>">Manage This Booking</a>
                        <a class="btn btn-outline" href="my-bookings.php">View All Bookings</a>
                        <p class="print-instruction">
                            <i class="bi bi-printer"></i>
                            Use <strong>Ctrl + P</strong> to print or save this confirmation as a PDF.
                        </p>
                    </div>
                </aside>
            </div>
        </div>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
