<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";
$user = require_customer();
$reference = trim((string) ($_GET["reference"] ?? ($_POST["reference"] ?? "")));
$booking = $reference !== "" ? booking_find_by_reference($reference) : null;
if (!$booking || (int) $booking["user_id"] !== (int) $user["id"]) {
    http_response_code(404);
    $pageTitle = "Booking Not Found | VJ Car Rental";
    require dirname(__DIR__, 2) . "/includes/header.php";
    ?>
    <section class="content-section error-page">
        <div class="container">
            <i class="bi bi-calendar-x"></i>
            <h1>Booking not found</h1>
            <p>The reservation does not exist or is not available to this account.</p>
            <a class="btn btn-primary" href="my-bookings.php">My Bookings</a>
        </div>
    </section>
    <?php
    require dirname(__DIR__, 2) . "/includes/footer.php";
    exit();
}
$errors = [];
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        require_csrf();
        $action = post_string("action");
        if ($action === "cancel") {
            cancel_booking($booking, (int) $user["id"]);
            flash("success", "Booking cancelled successfully.");
        } elseif ($action === "reschedule") {
            $pickupAt =
                post_string("pickup") .
                " " .
                post_string("pickup_time", "09:00") .
                ":00";
            $returnAt =
                post_string("return") .
                " " .
                post_string("return_time", "09:00") .
                ":00";
            reschedule_booking($booking, $pickupAt, $returnAt, $user["id"]);
            flash("success", "Booking schedule and totals were updated.");
        } else {
            throw new InvalidArgumentException("Unknown booking action.");
        }
        redirect("booking-view.php?reference=" . urlencode($reference));
    } catch (Throwable $error) {
        $errors[] =  user_facing_error_message($error);
    }
}
$reviewCheck = database()->prepare(
    "SELECT id, status FROM reviews WHERE booking_id = ? LIMIT 1",
);
$reviewCheck->execute([$booking["id"]]);
$review = $reviewCheck->fetch();
$requirements = booking_requirements($booking);
$paymentSummary = $requirements["payments"];
$inspections = inspections_for_booking((int) $booking["id"]);
$adjustments = rental_adjustments_for_booking((int) $booking["id"]);
$unresolvedAdjustment = unresolved_rental_adjustment((int) $booking["id"]);
$days = max(
    1,
    (int) ceil(
        (strtotime($booking["return_at"]) - strtotime($booking["pickup_at"])) /
            86400,
    ),
);
$canModify = in_array($booking["status"], ["pending", "confirmed"], true);
$pageTitle = $booking["reference"] . " | VJ Car Rental";
require dirname(__DIR__, 2) . "/includes/header.php";
?>
<section class="page-hero page-hero--compact pattern-layer">
    <div class="container">
        <span class="section-kicker">Booking <?= escape_html(
            $booking["reference"],
        ) ?></span>
        <h1><?= escape_html($booking["vehicle_name"]) ?></h1>
        <div class="page-hero-meta">
            <span class="status-badge status-badge--<?= status_class(
                $booking["status"],
            ) ?>"><?= escape_html(ucfirst($booking["status"])) ?></span>
            <span>
                <i class="bi bi-calendar3"></i> Created <?= date(
                    "M j, Y",
                    strtotime($booking["created_at"]),
                ) ?></span>
        </div>
    </div>
</section>
<section class="content-section">
    <div class="container">
        <?php
        $workflow = [
            "pending" => "Verification",
            "confirmed" => "Confirmed",
            "ready" => "Ready",
            "active" => "On rental",
            "returned" => "Returned",
            "completed" => "Completed",
        ];
        $currentIndex = array_search(
            $booking["status"],
            array_keys($workflow),
            true,
        );
        ?>
        <?php if ($currentIndex !== false): ?>
            <div class="workflow-strip" aria-label="Rental progress">
                <?php foreach ($workflow as $status => $label): ?>
                    <?php
                    $index = array_search(
                        $status,
                        array_keys($workflow),
                        true,
                    );
                    ?>
                    <span class="<?= $status === $booking["status"]
                        ? "is-current"
                        : ($index < $currentIndex
                            ? "is-complete"
                            : "") ?>">
                        <?= escape_html($label) ?>
                    </span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="row g-4 g-xl-5 align-items-start">
            <div class="col-lg-7">
                <?php foreach ($errors as $error): ?>
                    <div class="alert alert-danger"><?= escape_html($error) ?></div>
                <?php endforeach; ?>
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
                            <a href="vehicle-details.php?vehicle=<?= urlencode(
                                $booking["vehicle_slug"],
                            ) ?>">View vehicle details</a>
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
                        <?php if ($booking["original_return_at"] && $booking["original_return_at"] !== $booking["return_at"]): ?>
                        <span>
                            <small>Original return</small>
                            <strong><?= date("M j, Y g:i A", strtotime($booking["original_return_at"])) ?></strong>
                        </span>
                        <?php endif; ?>
                        <span>
                            <small>Current scheduled return</small>
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
                            <small>Delivery address</small>
                            <strong><?= escape_html(
                                $booking["delivery_address"] ?:
                                "Not applicable",
                            ) ?></strong>
                        </span>
                    </div>
                    <?php if ($booking["special_requests"]): ?>
                        <div class="booking-note">
                            <strong>Special requests</strong>
                            <p><?= nl2br(
                                escape_html($booking["special_requests"]),
                            ) ?></p>
                        </div>
                    <?php endif; ?>
                </article>
                <article class="booking-management-panel">
                    <span class="section-kicker">Rental readiness</span>
                    <h2>Documents and payment</h2>
                    <div class="requirements-grid">
                        <div class="requirement-item<?= $requirements["license_approved"]
                            ? " is-complete"
                            : "" ?>">
                            <i class="bi <?= $requirements["license_approved"]
                                ? "bi-check-circle-fill"
                                : "bi-hourglass-split" ?>"></i>
                            <span>
                                <strong>Driver’s license</strong>
                                <small><?= $requirements["license_approved"]
                                    ? "Approved"
                                    : "Needs review" ?></small>
                            </span>
                        </div>
                        <div class="requirement-item<?= $requirements["id_approved"]
                            ? " is-complete"
                            : "" ?>">
                            <i class="bi <?= $requirements["id_approved"]
                                ? "bi-check-circle-fill"
                                : "bi-hourglass-split" ?>"></i>
                            <span>
                                <strong>Government ID</strong>
                                <small><?= $requirements["id_approved"]
                                    ? "Approved"
                                    : "Needs review" ?></small>
                            </span>
                        </div>
                        <div class="requirement-item<?= $requirements["deposit_paid"]
                            ? " is-complete"
                            : "" ?>">
                            <i class="bi <?= $requirements["deposit_paid"]
                                ? "bi-check-circle-fill"
                                : "bi-hourglass-split" ?>"></i>
                            <span>
                                <strong>Security deposit</strong>
                                <small><?= $requirements["deposit_paid"]
                                    ? "Verified"
                                    : money($paymentSummary["deposit_due"]) .
                                        " due" ?></small>
                            </span>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <a class="btn btn-outline" href="documents.php">
                            <i class="bi bi-person-vcard"></i>
                            My Documents
                        </a>
                        <a
                            class="btn btn-outline"
                            href="payments.php?reference=<?= urlencode(
                                $booking["reference"],
                            ) ?>"
                        >
                            <i class="bi bi-credit-card"></i>
                            Payments
                        </a>
                        <a
                            class="btn btn-outline"
                            href="invoice.php?reference=<?= urlencode(
                                $booking["reference"],
                            ) ?>"
                        >
                            <i class="bi bi-receipt"></i>
                            Invoice
                        </a>
                    </div>
                </article>
                <?php if ($canModify): ?>
                    <article class="booking-management-panel">
                        <span class="section-kicker">Reservation controls</span>
                        <h2>Change this booking</h2>
                        <p>Schedule changes are checked against live reservation dates. Online cancellation closes 24 hours before pick-up.</p>
                        <form method="post" class="reschedule-form">
                            <?= csrf_field() ?>
                            <input
                                type="hidden"
                                name="reference"
                                value="<?= escape_html($booking["reference"]) ?>"
                            >
                            <input type="hidden" name="action" value="reschedule">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="reschedulePickup">New pick-up date</label>
                                    <input class="form-control" id="reschedulePickup" name="pickup" type="date" value="<?= date(
                                        "Y-m-d",
                                        strtotime($booking["pickup_at"]),
                                    ) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="reschedulePickupTime">Pick-up time</label>
                                    <input class="form-control" id="reschedulePickupTime" name="pickup_time" type="time" value="<?= date(
                                        "H:i",
                                        strtotime($booking["pickup_at"]),
                                    ) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="rescheduleReturn">New return date</label>
                                    <input class="form-control" id="rescheduleReturn" name="return" type="date" value="<?= date(
                                        "Y-m-d",
                                        strtotime($booking["return_at"]),
                                    ) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="rescheduleReturnTime">Return time</label>
                                    <input class="form-control" id="rescheduleReturnTime" name="return_time" type="time" value="<?= date(
                                        "H:i",
                                        strtotime($booking["return_at"]),
                                    ) ?>" required>
                                </div>
                            </div>
                            <button class="btn btn-primary mt-3" type="submit">Check and Reschedule</button>
                        </form>
                        <form
                            method="post"
                            class="cancel-booking-form"
                            data-confirm="Cancel this booking? This action cannot be undone.">
                            <?= csrf_field() ?>
                            <input
                                type="hidden"
                                name="reference"
                                value="<?= escape_html($booking["reference"]) ?>"
                            >
                            <input type="hidden" name="action" value="cancel">
                            <button class="btn btn-outline-danger" type="submit">
                                <i class="bi bi-x-circle"></i>
                                Cancel Booking
                            </button>
                        </form>
                    </article>
                <?php elseif ($booking["status"] === "active"): ?>
                    <article class="booking-management-panel rental-adjustment-summary">
                        <span class="section-kicker">Flexible rental</span>
                        <h2>Need to change your return?</h2>
                        <p>You may request an earlier return or ask to extend this active rental. Extensions are checked against future reservations, maintenance, pricing, and payment requirements before activation.</p>
                        <?php if ($unresolvedAdjustment): ?>
                            <div class="alert alert-warning">
                                Your <?= escape_html(str_replace("_", " ", $unresolvedAdjustment["request_type"])) ?> request is currently <strong><?= escape_html($unresolvedAdjustment["status"]) ?></strong>.
                            </div>
                        <?php endif; ?>
                        <div class="d-flex flex-wrap gap-2">
                            <a class="btn btn-primary" href="rental-adjustment.php?reference=<?= urlencode($booking["reference"]) ?>"><i class="bi bi-clock-history"></i> Manage Return Schedule</a>
                            <?php if ((int) $paymentSummary["extension_due"] > 0): ?>
                                <a class="btn btn-outline" href="payments.php?reference=<?= urlencode($booking["reference"]) ?>">Pay Extension <?= money((int) $paymentSummary["extension_due"]) ?></a>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php elseif ($booking["status"] === "completed"): ?>
                    <article class="booking-management-panel">
                        <span class="section-kicker">Completed trip</span>
                        <h2>Share your experience</h2>
                        <?php if ($review): ?>
                            <p>Your review is currently <strong><?= escape_html(
                                $review["status"],
                            ) ?></strong>.</p>
                        <?php else: ?>
                            <p>Your verified review helps future renters choose confidently.</p>
                            <a class="btn btn-primary" href="rate-trip.php?reference=<?= urlencode(
                                $booking["reference"],
                            ) ?>">Rate This Trip</a>
                        <?php endif; ?>
                    </article>
                <?php endif; ?>
            </div>
            <div class="col-lg-5">
                <aside class="booking-summary-card sticky-lg-top">
                    <span class="section-kicker">Payment summary</span>
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
                    <div class="summary-line">
                        <span>Verified payments</span>
                        <strong><?= money(
                            $paymentSummary["paid_total"],
                        ) ?></strong>
                    </div>
                    <div class="summary-line">
                        <span>Current amount due</span>
                        <strong><?= money(
                            $paymentSummary["deposit_due"] +
                                $paymentSummary["rental_due"] +
                                $paymentSummary["extra_due"] +
                                $paymentSummary["extension_due"],
                        ) ?></strong>
                    </div>
                    <small class="summary-deposit-note">The security deposit is handled separately from the rental total.</small>
                    <hr>
                    <div class="customer-summary">
                        <span>
                            <small>Customer</small>
                            <strong><?= escape_html(
                                $booking["customer_name"],
                            ) ?></strong>
                        </span>
                        <span>
                            <small>Email</small>
                            <strong><?= escape_html(
                                $booking["customer_email"],
                            ) ?></strong>
                        </span>
                        <span>
                            <small>Phone</small>
                            <strong><?= escape_html(
                                $booking["customer_phone"] ?: "Not provided",
                            ) ?></strong>
                        </span>
                    </div>
                    <p class="print-instruction mt-3">
                        <i class="bi bi-printer"></i>
                        Use <strong>Ctrl + P</strong> to print or save this booking as a PDF.
                    </p>
                </aside>
            </div>
        </div>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
