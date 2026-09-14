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
$modifications = booking_modifications_for_booking((int) $booking["id"]);
$unresolvedModification = unresolved_booking_modification((int) $booking["id"]);
$cancellationRequest = cancellation_request_for_booking((int) $booking["id"]);
$refunds = refunds_for_booking((int) $booking["id"]);
$refundSummary = refund_summary_for_booking((int) $booking["id"]);
$settlement = rental_settlement_for_booking((int) $booking["id"]);
$journey = booking_next_step($booking);
$days = max(
    1,
    (int) ceil(
        (strtotime($booking["return_at"]) - strtotime($booking["pickup_at"])) /
            86400,
    ),
);
$canModify = in_array($booking["status"], ["pending", "confirmed", "ready"], true);
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
            ) ?>"><?= escape_html(humanize_label($booking["status"])) ?></span>
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
        <?php render_booking_progress($journey); ?>
        <?php render_booking_next_step($journey, "Your booking journey"); ?>
        <div class="row g-4 g-xl-5 align-items-start">
            <div class="col-lg-7">
                <?php foreach ($errors as $error): ?>
                    <div class="alert alert-danger"><?= escape_html($error) ?></div>
                <?php endforeach; ?>
                <article class="confirmation-card" id="pickup-details">
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
                <article class="booking-management-panel" id="requirements">
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
                        <a class="btn btn-outline" href="documents.php?reference=<?= urlencode($booking["reference"]) ?>">
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
                        <h2>Need to change or cancel before pickup?</h2>
                        <p>Booking changes use tracked requests instead of directly rewriting your reservation. Availability, maintenance, pricing, and any refund are recalculated securely and reviewed before your confirmed booking changes.</p>
                        <?php if ($unresolvedModification): ?>
                            <div class="alert alert-info">A booking modification request is currently <strong><?= escape_html(status_label((string) $unresolvedModification["status"])) ?></strong>.</div>
                        <?php endif; ?>
                        <?php if (($cancellationRequest["status"] ?? null) === "pending"): ?>
                            <div class="alert alert-warning">Your cancellation request is awaiting administrator review.</div>
                        <?php endif; ?>
                        <div class="d-flex flex-wrap gap-2">
                            <?php if (!$unresolvedModification && (($cancellationRequest["status"] ?? null) !== "pending")): ?>
                                <a class="btn btn-primary" href="booking-modification.php?reference=<?= urlencode($booking["reference"]) ?>"><i class="bi bi-pencil-square"></i> Request Booking Modification</a>
                            <?php else: ?>
                                <a class="btn btn-outline" href="booking-modification.php?reference=<?= urlencode($booking["reference"]) ?>">View Modification</a>
                            <?php endif; ?>
                            <a class="btn btn-outline-danger" href="booking-cancellation.php?reference=<?= urlencode($booking["reference"]) ?>"><i class="bi bi-x-circle"></i> Cancellation & Refund</a>
                        </div>
                    </article>
                <?php elseif ($booking["status"] === "active"): ?>
                    <article class="booking-management-panel rental-adjustment-summary">
                        <span class="section-kicker">Flexible rental</span>
                        <h2>Need to change your return?</h2>
                        <p>You may request an earlier return or ask to extend this active rental. Extensions are checked against future reservations, maintenance, pricing, and payment requirements before activation.</p>
                        <?php if ($unresolvedAdjustment): ?>
                            <div class="alert alert-warning">
                                Your <?= escape_html(humanize_label((string) $unresolvedAdjustment["request_type"])) ?> request is currently <strong><?= escape_html(status_label((string) $unresolvedAdjustment["status"])) ?></strong>.
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
                        <?php if (!$settlement || $settlement["status"] !== "settled"): ?>
                            <span class="section-kicker">Financial settlement</span>
                            <h2>Final settlement still in progress</h2>
                            <p>Your rental is returned, but a refund or outstanding return balance still needs to be finalized before the verified review step opens.</p>
                        <?php else: ?>
                            <span class="section-kicker">Completed trip</span>
                            <h2>Share your experience</h2>
                            <?php if ($review): ?>
                                <p>Your review is currently <strong><?= escape_html(status_label((string) $review["status"])) ?></strong>.</p>
                            <?php else: ?>
                                <p>Your verified review helps future renters choose confidently.</p>
                                <a class="btn btn-primary" href="rate-trip.php?reference=<?= urlencode($booking["reference"]) ?>">Rate This Trip</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </article>
                <?php endif; ?>
                <?php if ($modifications): ?>
                    <article class="booking-management-panel">
                        <span class="section-kicker">Pre-pickup changes</span>
                        <h2>Modification history</h2>
                        <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Requested</th><th>Status</th><th>Price impact</th></tr></thead><tbody><?php foreach ($modifications as $item): ?><tr><td><?= date("M j, Y g:i A", strtotime($item["requested_at"])) ?></td><td><span class="status-badge status-badge--<?= status_class($item["status"]) ?>"><?= escape_html(humanize_label($item["status"])) ?></span></td><td><?= ((int) $item["price_difference"] >= 0 ? "+" : "−") . money(abs((int) $item["price_difference"])) ?></td></tr><?php endforeach; ?></tbody></table></div>
                    </article>
                <?php endif; ?>
                <?php if ($settlement): ?>
                    <article class="booking-management-panel" id="settlement">
                        <span class="section-kicker">Return settlement</span>
                        <h2>Security deposit settlement</h2>
                        <div class="payment-overview">
                            <article><span>Deposit held</span><strong><?= money((int) $settlement["deposit_paid"]) ?></strong></article>
                            <article><span>Damage</span><strong>−<?= money((int) $settlement["damage_charges"]) ?></strong></article>
                            <article><span>Fuel</span><strong>−<?= money((int) $settlement["fuel_charges"]) ?></strong></article>
                            <article><span>Late</span><strong>−<?= money((int) $settlement["late_charges"]) ?></strong></article>
                            <article><span>Other deductions</span><strong>−<?= money((int) $settlement["other_charges"]) ?></strong></article>
                            <article><span>Deposit refund</span><strong><?= money((int) $settlement["deposit_refund_amount"]) ?></strong></article>
                            <article><span>Outstanding balance</span><strong><?= money((int) $settlement["outstanding_balance"]) ?></strong></article>
                        </div>
                        <p class="display-note">Settlement status: <strong><?= escape_html(ucwords(str_replace("_", " ", (string) $settlement["status"]))) ?></strong><?php if ($settlement["finalized_at"]): ?> · finalized <?= date("M j, Y g:i A", strtotime($settlement["finalized_at"])) ?><?php endif; ?></p>
                    </article>
                <?php endif; ?>
                <?php if ($refunds || $booking["status"] === "cancelled"): ?>
                    <article class="booking-management-panel" id="refunds">
                        <span class="section-kicker">Refunds & financial adjustments</span>
                        <h2>Refund transaction history</h2>
                        <?php if (!$refunds): ?><p class="display-note">No refund transaction is recorded for this booking.</p><?php else: ?><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Type</th><th>Amount</th><th>Status</th><th>Processed</th><th>Reference</th></tr></thead><tbody><?php foreach ($refunds as $refund): ?><tr><td><?= escape_html(refund_type_label((string) $refund["refund_type"])) ?><small><?= escape_html((string) $refund["reason"]) ?></small></td><td><?= money((int) $refund["amount"]) ?></td><td><span class="status-badge status-badge--<?= status_class($refund["status"]) ?>"><?= escape_html(humanize_label($refund["status"])) ?></span></td><td><?= $refund["processed_at"] ? date("M j, Y g:i A", strtotime($refund["processed_at"])) : "—" ?></td><td><?= escape_html((string) ($refund["reference_number"] ?: "—")) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
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
                                $paymentSummary["extension_due"] +
                                (int) ($paymentSummary["modification_due"] ?? 0),
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
