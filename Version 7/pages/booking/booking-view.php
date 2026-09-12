<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";
$user = require_auth();
$reference = trim((string) ($_GET["reference"] ?? ($_POST["reference"] ?? "")));
$booking = $reference !== "" ? booking_find_by_reference($reference) : null;
$isAdmin = ($user["role"] ?? "") === "admin";

if (
    !$booking ||
    ((int) $booking["user_id"] !== (int) $user["id"] && !$isAdmin)
) {
    http_response_code(404);
    $pageTitle = "Booking Not Found | VJ Car Rental";
    require dirname(__DIR__, 2) . "/includes/header.php";
    ?>
    <section class="content-section error-page">
        <div class="container">
            <i class="bi bi-calendar-x"></i>
            <h1>Booking not found</h1>
            <p>The reservation does not exist or is not available to this account.</p>
            <a class="btn btn-primary"
               href="<?= $isAdmin ? "booking-management.php" : "my-bookings.php" ?>">
                <?= $isAdmin ? "Booking Management" : "My Bookings" ?>
            </a>
        </div>
    </section>
    <?php
    require dirname(__DIR__, 2) . "/includes/footer.php";
    exit();
}

$paymentSummary = booking_payment_summary($booking);
$errors = [];
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        require_csrf();
        $action = post_string("action");
        if ($action !== "cancel" || $isAdmin) {
            throw new InvalidArgumentException("Unknown booking action.");
        }
        if ((int) $booking["user_id"] !== (int) $user["id"]) {
            throw new RuntimeException("This booking does not belong to your account.");
        }
        if ((int) $paymentSummary["paid_total"] > 0) {
            throw new RuntimeException("Bookings with verified payments require staff assistance to cancel.");
        }
        cancel_booking($booking, (int) $user["id"]);
        flash("success", "Booking cancelled successfully.");
        redirect("booking-view.php?reference=" . urlencode($reference));
    } catch (Throwable $error) {
        $errors[] = user_facing_error_message($error);
    }
}

$booking = booking_find_by_reference($reference) ?? $booking;
$paymentSummary = booking_payment_summary($booking);
$days = max(
    1,
    (int) ceil(
        (strtotime($booking["return_at"]) - strtotime($booking["pickup_at"])) /
            86400,
    ),
);
$canCancel =
    !$isAdmin &&
    in_array($booking["status"], ["pending", "confirmed"], true) &&
    (int) $paymentSummary["paid_total"] === 0;

$pageTitle = $booking["reference"] . " | VJ Car Rental";
require dirname(__DIR__, 2) . "/includes/header.php";
?>
<section class="page-hero page-hero--compact pattern-layer">
    <div class="container">
        <span class="section-kicker">Booking <?= escape_html($booking["reference"]) ?></span>
        <h1><?= escape_html($booking["vehicle_name"]) ?></h1>
        <div class="page-hero-meta">
            <span class="status-badge status-badge--<?= status_class($booking["status"]) ?>">
                <?= escape_html(ucfirst($booking["status"])) ?>
            </span>
            <span><i class="bi bi-calendar3"></i>
                Created <?= date("M j, Y", strtotime($booking["created_at"])) ?></span>
        </div>
    </div>
</section>
<section class="content-section">
    <div class="container">
        <div class="row g-4 g-xl-5 align-items-start">
            <div class="col-lg-7">
                <?php foreach ($errors as $error): ?>
                    <div class="alert alert-danger"><?= escape_html($error) ?></div>
                <?php endforeach; ?>
                <article class="confirmation-card">
                    <div class="confirmation-vehicle">
                        <img src="assets/images/cars/<?= escape_html($booking["vehicle_image"]) ?>"
                             alt="<?= escape_html($booking["vehicle_name"]) ?>">
                        <div>
                            <span class="section-kicker"><?= escape_html($booking["vehicle_category"]) ?></span>
                            <h2><?= escape_html($booking["vehicle_name"]) ?></h2>
                            <a href="vehicle-details.php?vehicle=<?= urlencode($booking["vehicle_slug"]) ?>">
                                View vehicle details
                            </a>
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
                    <?php if ($booking["special_requests"]): ?>
                        <div class="booking-note">
                            <strong>Special requests</strong>
                            <p><?= nl2br(escape_html($booking["special_requests"])) ?></p>
                        </div>
                    <?php endif; ?>
                </article>

                <?php if (!$isAdmin && (int) $paymentSummary["paid_total"] > 0): ?>
                    <div class="alert alert-info">This booking has a verified payment. Contact VJ Car Rental for cancellation assistance; Version 6 does not include refund processing.</div>
                <?php endif; ?>

                <?php if ($canCancel): ?>
                    <article class="booking-management-panel">
                        <span class="section-kicker">Reservation controls</span>
                        <h2>Cancel this booking</h2>
                        <p>Online cancellation closes 24 hours before pick-up.</p>
                        <form method="post" class="cancel-booking-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="reference"
                                   value="<?= escape_html($booking["reference"]) ?>">
                            <input type="hidden" name="action" value="cancel">
                            <button class="btn btn-outline-danger" type="submit">
                                <i class="bi bi-x-circle"></i>
                                Cancel Booking
                            </button>
                        </form>
                    </article>
                <?php endif; ?>
            </div>
            <div class="col-lg-5">
                <aside class="booking-summary-card sticky-lg-top">
                    <span class="section-kicker">Booking summary</span>
                    <div class="summary-line">
                        <span>Rental subtotal</span>
                        <strong><?= money($booking["subtotal"]) ?></strong>
                    </div>
                    <div class="summary-total">
                        <span>Rental total</span>
                        <strong><?= money($booking["total"]) ?></strong>
                    </div>
                    <div class="summary-line">
                        <span>Verified payments</span>
                        <strong><?= money((int) $paymentSummary["paid_total"]) ?></strong>
                    </div>
                    <div class="summary-line">
                        <span>Payment status</span>
                        <strong><?= escape_html(ucfirst($paymentSummary["status"])) ?></strong>
                    </div>
                    <div class="summary-line">
                        <span>Amount due</span>
                        <strong><?= money((int) $paymentSummary["amount_due"]) ?></strong>
                    </div>
                    <div class="d-grid gap-2 mt-3">
                        <?php if (!$isAdmin): ?>
                            <a class="btn btn-primary" href="payments.php?reference=<?= urlencode($booking["reference"]) ?>">Payment Details</a>
                        <?php endif; ?>
                        <a class="btn btn-outline" href="invoice.php?reference=<?= urlencode($booking["reference"]) ?>">Invoice</a>
                    </div>
                    <hr>
                    <div class="customer-summary">
                        <span><small>Customer</small><strong><?= escape_html($booking["customer_name"]) ?></strong></span>
                        <span><small>Email</small><strong><?= escape_html($booking["customer_email"]) ?></strong></span>
                        <span><small>Phone</small><strong><?= escape_html($booking["customer_phone"] ?: "Not provided") ?></strong></span>
                    </div>
                    <?php if ($isAdmin): ?>
                        <a class="btn btn-outline mt-3"
                           href="booking-management.php?reference=<?= urlencode($booking["reference"]) ?>">
                            Back to Booking Management
                        </a>
                    <?php endif; ?>
                </aside>
            </div>
        </div>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
