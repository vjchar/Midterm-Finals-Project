<?php

declare(strict_types=1);


/**
 * FILE: pages/admin/admin-rentals.php
 * FILE PURPOSE: Administrator rental lifecycle and active-rental management page.
 * USED BY: Authenticated administrators using the corresponding management section.
 * RESPONSIBILITY: Loads the required application/services, handles only page-level request orchestration, and renders the user interface; reusable business/database logic belongs in services.
 *
 * Maintenance note: Keep this file focused on the responsibility described above.
 */
require dirname(__DIR__, 2) . "/includes/bootstrap.php";

$admin = require_admin();
$reference = trim((string) ($_GET["reference"] ?? ($_POST["reference"] ?? "")));
$selected = $reference !== "" ? booking_find_by_reference($reference) : null;
$errors = [];

if ($_SERVER["REQUEST_METHOD"] === "POST" && $selected) {
    try {
        require_csrf();

        $action = post_string("action");

        if ($action === "review_adjustment") {
            $adjustmentId = filter_var($_POST["adjustment_id"] ?? null, FILTER_VALIDATE_INT);
            if (!$adjustmentId) {
                throw new InvalidArgumentException("Choose a valid rental adjustment request.");
            }
            review_rental_adjustment_request(
                (int) $adjustmentId,
                post_string("decision"),
                post_string("admin_note"),
                (int) $admin["id"],
            );
            flash("success", "Rental adjustment request reviewed.");
        } elseif ($action === "checkout") {
            checkout_booking($selected, $_POST, $admin["id"]);
            flash(
                "success",
                "Checkout inspection saved and rental activated.",
            );
        } elseif ($action === "checkin") {
            checkin_booking($selected, $_POST, $admin["id"]);
            flash(
                "success",
                "Return inspection saved. Review any charges before completion.",
            );
        } elseif ($action === "finalize_settlement") {
            finalize_return_settlement($selected, $_POST, (int) $admin["id"]);
            flash("success", "Return settlement finalized. Any deposit refund or remaining balance is now tracked.");
        } elseif ($action === "complete") {
            refresh_rental_settlement_status((int) $selected["id"]);
            $settlement = rental_settlement_for_booking((int) $selected["id"]);
            if (!$settlement) {
                throw new RuntimeException("Finalize the security-deposit settlement before completing this rental.");
            }
            if ($settlement["status"] !== "settled") {
                throw new RuntimeException("Finish the deposit refund or outstanding return balance before completing this rental.");
            }
            $summary = booking_payment_summary($selected);

            if (
                (int) $summary["deposit_due"] +
                (int) $summary["rental_due"] +
                (int) $summary["extra_due"] +
                (int) $summary["extension_due"] +
                (int) ($summary["modification_due"] ?? 0) > 0
            ) {
                throw new RuntimeException(
                    "Verify all payment obligations and outstanding return charges before completing this booking.",
                );
            }

            update_booking_status(
                $selected,
                "completed",
                $admin["id"],
                post_string("admin_notes"),
            );
            flash(
                "success",
                "Rental completed. The customer can now leave a verified review.",
            );
        } else {
            throw new InvalidArgumentException("Choose a valid rental action.");
        }

        redirect(
            "admin-rentals.php?reference=" . urlencode($selected["reference"]),
        );
    } catch (Throwable $error) {
        $errors[] = user_facing_error_message($error);
    }
}

$rentals = admin_rentals();
$adjustmentRequests = admin_rental_adjustments();

if ($selected) {
    $selected = booking_find_by_reference($selected["reference"]);
    $inspections = inspections_for_booking((int) $selected["id"]);
    $paymentSummary = booking_payment_summary($selected);
    $selectedAdjustments = rental_adjustments_for_booking((int) $selected["id"]);
    refresh_rental_settlement_status((int) $selected["id"]);
    $settlement = rental_settlement_for_booking((int) $selected["id"]);
    $settlementPreview = rental_settlement_preview($selected);
    $selectedRefunds = refunds_for_booking((int) $selected["id"]);
}

$pageTitle = "Rental Desk | VJ Car Rental";

require dirname(__DIR__, 2) . "/includes/header.php";
require dirname(__DIR__, 2) . "/includes/admin-nav.php";
?>

<section class="admin-page-heading">
    <div class="container">
        <div>
            <span class="section-kicker">Checkout and check-in</span>
            <h1>Rental Desk</h1>
            <p>
                Release prepared vehicles, capture odometer and fuel readings,
                inspect returns, and close fully paid rentals.
            </p>
        </div>
    </div>
</section>

<section class="content-section admin-section">
    <div class="container">
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-danger" role="alert">
                <?= escape_html($error) ?>
            </div>
        <?php endforeach; ?>

        <article class="admin-editor mb-4">
            <div class="admin-editor__heading">
                <div>
                    <span class="section-kicker">Flexible rental requests</span>
                    <h2>Rental adjustments</h2>
                    <p>Review early-return requests and extension requests. Availability is checked again when an extension is approved.</p>
                </div>
                <span class="status-badge status-badge--info"><?= count($adjustmentRequests) ?> open</span>
            </div>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Booking</th><th>Customer / vehicle</th><th>Request</th><th>Schedule</th><th>Price impact</th><th>Status</th><th>Review</th></tr></thead>
                    <tbody>
                    <?php foreach ($adjustmentRequests as $adjustment): ?>
                        <tr>
                            <td><a href="admin-rentals.php?reference=<?= urlencode($adjustment["reference"]) ?>"><strong><?= escape_html($adjustment["reference"]) ?></strong></a></td>
                            <td><?= escape_html($adjustment["customer_name"]) ?><small><?= escape_html($adjustment["vehicle_name"]) ?></small></td>
                            <td><?= escape_html(ucwords(str_replace("_", " ", $adjustment["request_type"]))) ?><small><?= escape_html($adjustment["customer_reason"] ?: "No reason provided") ?></small></td>
                            <td><?= date("M j, g:i A", strtotime($adjustment["original_return_at"])) ?><small>to <?= date("M j, g:i A", strtotime($adjustment["requested_return_at"])) ?></small></td>
                            <td><?= $adjustment["request_type"] === "extension" ? money((int) $adjustment["price_difference"]) : "—" ?></td>
                            <td><span class="status-badge status-badge--<?= status_class($adjustment["status"]) ?>"><?= escape_html(humanize_label($adjustment["status"])) ?></span></td>
                            <td>
                                <?php if ($adjustment["status"] === "pending"): ?>
                                <form method="post" class="admin-inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="reference" value="<?= escape_html($adjustment["reference"]) ?>">
                                    <input type="hidden" name="action" value="review_adjustment">
                                    <input type="hidden" name="adjustment_id" value="<?= (int) $adjustment["id"] ?>">
                                    <select class="form-select form-select-sm" name="decision" required><option value="">Decision</option><option value="approve">Approve</option><option value="reject">Reject</option></select>
                                    <input class="form-control form-control-sm" name="admin_note" maxlength="1500" placeholder="Admin note">
                                    <button class="btn btn-primary btn-sm" type="submit">Save</button>
                                </form>
                                <?php elseif ($adjustment["request_type"] === "extension"): ?>
                                    <small>Awaiting extension payment / activation</small>
                                <?php else: ?>
                                    <small>Early return acknowledged</small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$adjustmentRequests): ?><tr><td colspan="7">No open rental adjustment requests.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>

        <div class="admin-table-wrap" aria-label="Rentals requiring desk action">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Booking</th>
                        <th>Customer</th>
                        <th>Schedule</th>
                        <th>Fleet state</th>
                        <th>Status</th>
                        <th><span class="visually-hidden">Action</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rentals as $rental): ?>
                        <tr>
                            <td>
                                <strong><?= escape_html(
                                    $rental["reference"],
                                ) ?></strong>
                                <small><?= escape_html(
                                    $rental["vehicle_name"],
                                ) ?></small>
                            </td>
                            <td><?= escape_html($rental["customer_name"]) ?></td>
                            <td>
                                <?= date(
                                    "M j, g:i A",
                                    strtotime($rental["pickup_at"]),
                                ) ?>
                                <small>
                                    to <?= date(
                                        "M j, g:i A",
                                        strtotime($rental["return_at"]),
                                    ) ?>
                                </small>
                            </td>
                            <td>
                                <span class="status-badge status-badge--<?= status_class(
                                    $rental["availability_status"],
                                ) ?>">
                                    <?= escape_html(
                                        humanize_label($rental["availability_status"]),
                                    ) ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge status-badge--<?= status_class(
                                    $rental["status"],
                                ) ?>">
                                    <?= escape_html(humanize_label($rental["status"])) ?>
                                </span>
                            </td>
                            <td>
                                <a
                                    class="btn btn-outline btn-sm"
                                    href="admin-rentals.php?reference=<?= urlencode(
                                        $rental["reference"],
                                    ) ?>">
                                    Open
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$rentals): ?>
                        <tr>
                            <td colspan="6">No rentals currently need desk action.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if (
            $selected &&
            in_array($selected["status"], ["ready", "active", "returned"], true)
        ): ?>
            <article class="admin-editor mt-4">
                <div class="admin-editor__heading">
                    <div>
                        <span class="section-kicker">
                            <?= escape_html($selected["reference"]) ?>
                        </span>
                        <h2><?= escape_html($selected["vehicle_name"]) ?></h2>
                    </div>

                    <span class="status-badge status-badge--<?= status_class(
                        $selected["status"],
                    ) ?>">
                        <?= escape_html(humanize_label($selected["status"])) ?>
                    </span>
                </div>

                <?php if (!empty($selectedAdjustments)): ?>
                    <div class="rental-adjustment-history mb-4">
                        <h3>Return schedule history</h3>
                        <?php foreach ($selectedAdjustments as $adjustment): ?>
                            <div class="adjustment-history-item">
                                <strong><?= escape_html(ucwords(str_replace("_", " ", $adjustment["request_type"]))) ?></strong>
                                <span><?= date("M j, Y g:i A", strtotime($adjustment["original_return_at"])) ?> → <?= date("M j, Y g:i A", strtotime($adjustment["requested_return_at"])) ?></span>
                                <span class="status-badge status-badge--<?= status_class($adjustment["status"]) ?>"><?= escape_html(humanize_label($adjustment["status"])) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if (
                    in_array($selected["status"], ["ready", "active"], true)
                ): ?>
                    <?php
                    $action =
                        $selected["status"] === "ready"
                            ? "checkout"
                            : "checkin";
                    $actionLabel = humanize_label($action);
                    $actionIcon =
                        $action === "checkout" ? "key" : "clipboard-check";
                    $priorInspection = $inspections["checkout"] ?? null;
                    $minimumOdometer = $priorInspection
                        ? (int) $priorInspection["odometer"]
                        : 0;
                    ?>

                    <form method="post" class="row g-3 rental-inspection-form">
                        <?= csrf_field() ?>
                        <input
                            type="hidden"
                            name="reference"
                            value="<?= escape_html($selected["reference"]) ?>">
                        <input type="hidden" name="action" value="<?= $action ?>">

                        <div class="col-md-4">
                            <label class="form-label" for="odometer">Odometer (km)</label>
                            <input
                                class="form-control"
                                id="odometer"
                                name="odometer"
                                type="number"
                                min="<?= $minimumOdometer ?>"
                                max="2000000"
                                placeholder="Enter the dashboard reading"
                                aria-describedby="odometerHelp"
                                required>
                            <div class="form-text" id="odometerHelp">
                                <?php if ($priorInspection): ?>
                                    Return reading must be at least <?= number_format(
                                        $minimumOdometer,
                                    ) ?> km.
                                <?php else: ?>
                                    Enter the actual dashboard reading before releasing the vehicle.
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="fuelPercent">Fuel / charge (%)</label>
                            <input
                                class="form-control"
                                id="fuelPercent"
                                name="fuel_percent"
                                type="number"
                                min="0"
                                max="100"
                                value="100"
                                required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="bodyCondition">Vehicle condition</label>
                            <select
                                class="form-select"
                                id="bodyCondition"
                                name="body_condition"
                                required>
                                <option value="excellent">Excellent</option>
                                <option value="good" selected>Good</option>
                                <option value="fair">Fair</option>
                                <option value="damaged">Damaged</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="inspectionNotes">
                                Inspection notes
                            </label>
                            <textarea
                                class="form-control"
                                id="inspectionNotes"
                                name="notes"
                                rows="3"
                                maxlength="3000"></textarea>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="damageNotes">
                                Damage / exception notes
                            </label>
                            <textarea
                                class="form-control"
                                id="damageNotes"
                                name="damage_notes"
                                rows="3"
                                maxlength="3000"></textarea>
                        </div>

                        <?php if ($action === "checkin"): ?>
                            <div class="col-md-4">
                                <label class="form-label" for="extraCharges">
                                    Extra charges (PHP)
                                </label>
                                <input
                                    class="form-control"
                                    id="extraCharges"
                                    name="extra_charges"
                                    type="number"
                                    min="0"
                                    max="1000000"
                                    value="0"
                                    required>
                            </div>
                        <?php endif; ?>

                        <div class="col-12">
                            <button class="btn btn-primary" type="submit">
                                <i class="bi bi-<?= $actionIcon ?>" aria-hidden="true"></i>
                                Record <?= $actionLabel ?>
                            </button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="payment-overview">
                        <article><span>Security deposit held</span><strong><?= money((int) $settlementPreview["deposit_held"]) ?></strong></article>
                        <article><span>Return inspection charges</span><strong><?= money((int) $settlementPreview["inspection_charge"]) ?></strong></article>
                        <article><span>Outstanding return balance</span><strong><?= money((int) $paymentSummary["extra_due"]) ?></strong></article>
                    </div>

                    <?php if (!$settlement): ?>
                        <div class="alert alert-info">Break down the recorded return charge, then finalize settlement. The verified security deposit will be applied first; only any remaining balance becomes payable.</div>
                        <form method="post" class="row g-3 rental-settlement-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="reference" value="<?= escape_html($selected["reference"]) ?>">
                            <input type="hidden" name="action" value="finalize_settlement">
                            <div class="col-md-3"><label class="form-label" for="damageCharges">Damage charges</label><input class="form-control" id="damageCharges" name="damage_charges" type="number" min="0" value="0" required></div>
                            <div class="col-md-3"><label class="form-label" for="fuelCharges">Fuel charges</label><input class="form-control" id="fuelCharges" name="fuel_charges" type="number" min="0" value="0" required></div>
                            <div class="col-md-3"><label class="form-label" for="lateCharges">Late charges</label><input class="form-control" id="lateCharges" name="late_charges" type="number" min="0" value="0" required></div>
                            <div class="col-md-3"><label class="form-label" for="otherCharges">Other recorded charges</label><input class="form-control" id="otherCharges" name="other_charges" type="number" min="0" value="<?= (int) $settlementPreview["inspection_charge"] ?>" required></div>
                            <div class="col-12"><small class="display-note">These four amounts must total <?= money((int) $settlementPreview["inspection_charge"]) ?>, the charge already recorded during return inspection.</small></div>
                            <div class="col-12"><label class="form-label" for="settlementNotes">Settlement notes</label><textarea class="form-control" id="settlementNotes" name="settlement_notes" rows="3" maxlength="3000"></textarea></div>
                            <div class="col-12"><button class="btn btn-primary" type="submit">Finalize Return Settlement</button></div>
                        </form>
                    <?php else: ?>
                        <div class="admin-editor__heading mt-4"><div><span class="section-kicker">Security deposit settlement</span><h3>Final settlement</h3></div><span class="status-badge status-badge--<?= status_class((string) $settlement["status"]) ?>"><?= escape_html(ucwords(str_replace("_", " ", (string) $settlement["status"]))) ?></span></div>
                        <div class="payment-overview">
                            <article><span>Deposit paid/held</span><strong><?= money((int) $settlement["deposit_paid"]) ?></strong></article>
                            <article><span>Damage</span><strong><?= money((int) $settlement["damage_charges"]) ?></strong></article>
                            <article><span>Fuel</span><strong><?= money((int) $settlement["fuel_charges"]) ?></strong></article>
                            <article><span>Late</span><strong><?= money((int) $settlement["late_charges"]) ?></strong></article>
                            <article><span>Other</span><strong><?= money((int) $settlement["other_charges"]) ?></strong></article>
                            <article><span>Deposit refund</span><strong><?= money((int) $settlement["deposit_refund_amount"]) ?></strong></article>
                            <article><span>Outstanding balance</span><strong><?= money((int) $settlement["outstanding_balance"]) ?></strong></article>
                        </div>
                        <?php if ($selectedRefunds): ?>
                            <div class="admin-table-wrap mt-3"><table class="admin-table"><thead><tr><th>Refund type</th><th>Amount</th><th>Status</th><th>Reference</th></tr></thead><tbody>
                            <?php foreach ($selectedRefunds as $refund): ?>
                                <tr><td><?= escape_html(refund_type_label((string) $refund["refund_type"])) ?></td><td><?= money((int) $refund["amount"]) ?></td><td><?= escape_html(humanize_label((string) $refund["status"])) ?></td><td><?= escape_html((string) ($refund["reference_number"] ?: "—")) ?></td></tr>
                            <?php endforeach; ?>
                            </tbody></table></div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <form method="post" class="rental-completion-form mt-4">
                        <?= csrf_field() ?>
                        <input type="hidden" name="reference" value="<?= escape_html($selected["reference"]) ?>">
                        <input type="hidden" name="action" value="complete">
                        <label class="form-label" for="completionNotes">Completion notes</label>
                        <textarea class="form-control" id="completionNotes" name="admin_notes" rows="3" maxlength="3000"></textarea>
                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <button class="btn btn-primary" type="submit" <?= !$settlement || $settlement["status"] !== "settled" ? "disabled" : "" ?>>Complete Rental</button>
                            <a class="btn btn-outline" href="admin-payments.php">Review Payments & Refunds</a>
                        </div>
                        <?php if (!$settlement || $settlement["status"] !== "settled"): ?><small class="display-note">Complete the return settlement and any refund/outstanding balance first.</small><?php endif; ?>
                    </form>
                <?php endif; ?>
            </article>
        <?php endif; ?>
    </div>
</section>

<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
