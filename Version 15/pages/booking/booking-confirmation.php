<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";
$user = require_customer();
$reference = trim((string) ($_GET["reference"] ?? ""));
$booking = booking_find_by_reference($reference);
if (!$booking || (int) $booking["user_id"] !== (int) $user["id"]) {
    flash("danger", "That booking could not be found.");
    redirect("my-bookings.php");
}
$days = max(1, (int) ceil((strtotime($booking["return_at"]) - strtotime($booking["pickup_at"])) / 86400));
$journey = booking_next_step($booking);
$pageTitle = "Booking Confirmation | VJ Car Rental";
$pageDescription = "Review your booking and continue directly to the next required step.";
require dirname(__DIR__, 2) . "/includes/header.php";
?>
<section class="page-hero page-hero--compact pattern-layer">
    <div class="container">
        <span class="section-kicker">Booking successful</span>
        <h1>Your booking was created</h1>
        <p>Your reservation is saved. Follow the guided steps below so you always know exactly what to do next.</p>
    </div>
</section>
<section class="content-section confirmation-page">
    <div class="container">
        <?php render_booking_progress($journey); ?>
        <?php render_booking_next_step($journey, "What’s next?"); ?>

        <div class="confirmation-banner">
            <i class="bi bi-check2-circle"></i>
            <div>
                <small>Booking reference</small>
                <h2><?= escape_html($booking["reference"]) ?></h2>
                <p>Step 1 complete · Booking status: <?= escape_html(humanize_label($booking["status"])) ?></p>
            </div>
        </div>

        <div class="row g-4 align-items-start">
            <div class="col-lg-7">
                <article class="confirmation-card">
                    <div class="confirmation-vehicle">
                        <img src="assets/images/cars/<?= escape_html($booking["vehicle_image"]) ?>" alt="<?= escape_html($booking["vehicle_name"]) ?>">
                        <div>
                            <span class="section-kicker"><?= escape_html($booking["vehicle_category"]) ?></span>
                            <h2><?= escape_html($booking["vehicle_name"]) ?></h2>
                            <p>Your vehicle is reserved against the requested schedule while you complete only the requirements that are still unfinished. Already-verified documents stay complete.</p>
                        </div>
                    </div>
                    <div class="confirmation-details">
                        <span><small>Pick-up</small><strong><?= date("M j, Y g:i A", strtotime($booking["pickup_at"])) ?></strong></span>
                        <span><small>Return</small><strong><?= date("M j, Y g:i A", strtotime($booking["return_at"])) ?></strong></span>
                        <span><small>Rental period</small><strong><?= $days ?> day<?= $days === 1 ? "" : "s" ?></strong></span>
                        <span><small>Method</small><strong><?= escape_html($booking["pickup_method"]) ?></strong></span>
                        <span><small>Branch</small><strong><?= escape_html($booking["pickup_location"]) ?></strong></span>
                        <span><small>Customer</small><strong><?= escape_html($booking["customer_name"]) ?></strong></span>
                    </div>
                </article>

                <article class="next-steps-card">
                    <span class="section-kicker">Guided booking journey</span>
                    <h2>From reservation to vehicle readiness</h2>
                    <ol>
                        <li><span>1</span><div><strong>Booking</strong><p>Your reservation has been created successfully.</p></div></li>
                        <li><span>2</span><div><strong>Payment</strong><p>Submit the required security-deposit payment for verification.</p></div></li>
                        <li><span>3</span><div><strong>Documents</strong><p>Upload your driver’s license and government-issued ID only when they are missing, rejected, expired, or otherwise need action.</p></div></li>
                        <li><span>4</span><div><strong>Verification</strong><p>The team verifies only requirements that are still pending. Previously verified documents are not reviewed again.</p></div></li>
                        <li><span>5</span><div><strong>Vehicle preparation</strong><p>After verification, wait for the Admin notification that your vehicle is physically ready for pickup or delivery.</p></div></li>
                    </ol>
                </article>
            </div>

            <div class="col-lg-5">
                <aside class="booking-summary-card">
                    <span class="section-kicker">Saved price breakdown</span>
                    <div class="summary-line"><span>Rental subtotal</span><strong><?= money($booking["subtotal"]) ?></strong></div>
                    <?php foreach ($booking["addons"] as $addon): ?>
                        <div class="summary-line">
                            <span><?= escape_html($addon["addon_name"]) . ((int) $addon["quantity"] > 1 ? " × " . (int) $addon["quantity"] : "") ?></span>
                            <strong><?= money((int) $addon["line_total"]) ?></strong>
                        </div>
                    <?php endforeach; ?>
                    <div class="summary-line"><span>Delivery</span><strong><?= money($booking["delivery_fee"]) ?></strong></div>
                    <?php if ($booking["discount"] > 0): ?>
                        <div class="summary-line summary-line--discount"><span><?= escape_html($booking["promo_code"]) ?> discount</span><strong>−<?= money($booking["discount"]) ?></strong></div>
                    <?php endif; ?>
                    <div class="summary-total"><span>Rental total</span><strong><?= money($booking["total"]) ?></strong></div>
                    <div class="summary-line"><span>Refundable deposit</span><strong><?= money($booking["deposit"]) ?></strong></div>
                    <small class="summary-deposit-note">The security deposit is separate from the rental total and is the first required payment step.</small>
                    <div class="d-grid gap-2 mt-4">
                        <a class="btn btn-primary btn-lg" href="<?= escape_html($journey["target_url"]) ?>">
                            <i class="bi bi-arrow-right-circle"></i> <?= escape_html($journey["button_label"]) ?>
                        </a>
                        <a class="btn btn-outline" href="booking-view.php?reference=<?= urlencode($booking["reference"]) ?>">View Booking Details</a>
                        <a class="btn btn-outline" href="my-bookings.php">View All Bookings</a>
                    </div>
                </aside>
            </div>
        </div>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
