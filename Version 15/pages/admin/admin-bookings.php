<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";
$admin = require_admin();
$allowedTransitions = booking_transitions();
$errors = [];
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        require_csrf();
        $adminAction = post_string("admin_action", "booking_status");
        if ($adminAction === "modification_review") {
            $modificationId = filter_var($_POST["modification_id"] ?? null, FILTER_VALIDATE_INT);
            if (!$modificationId) {
                throw new InvalidArgumentException("Choose a valid modification request.");
            }
            review_booking_modification((int) $modificationId, post_string("decision"), (int) $admin["id"], post_string("admin_note"));
            flash("success", "Booking modification request reviewed.");
            redirect("admin-bookings.php#modification-queue");
        }
        if ($adminAction === "cancellation_review") {
            $cancellationId = filter_var($_POST["cancellation_id"] ?? null, FILTER_VALIDATE_INT);
            if (!$cancellationId) {
                throw new InvalidArgumentException("Choose a valid cancellation request.");
            }
            review_booking_cancellation((int) $cancellationId, post_string("decision"), (int) $admin["id"], post_string("admin_note"));
            flash("success", "Cancellation request reviewed.");
            redirect("admin-bookings.php#cancellation-queue");
        }

        $booking = booking_find_by_reference(post_string("reference"));
        if (!$booking) {
            throw new RuntimeException("Booking not found.");
        }
        $newStatus = post_string("status");
        if (!in_array($newStatus, $allowedTransitions[$booking["status"]] ?? [], true)) {
            throw new RuntimeException("That status transition is not allowed.");
        }
        update_booking_status($booking, $newStatus, $admin["id"], post_string("admin_notes"));
        if ($newStatus === "ready") {
            $readyLabel = $booking["pickup_method"] === "Vehicle delivery" ? "Ready for Delivery" : "Ready for Pickup";
            flash("success", $readyLabel . ". The customer was notified.");
        } else {
            flash("success", "Booking status updated to " . status_label($newStatus) . ".");
        }
        redirect("admin-bookings.php?reference=" . urlencode($booking["reference"]));
    } catch (Throwable $error) {
        $errors[] = user_facing_error_message($error);
    }
}
$statusFilter = trim((string) ($_GET["status"] ?? "all"));
$validFilters = array_merge(["all"], booking_statuses());
if (!in_array($statusFilter, $validFilters, true)) {
    $statusFilter = "all";
}
$sql =
    "SELECT b.*, v.name AS vehicle_name, u.name AS customer_name, u.email AS customer_email FROM bookings b JOIN vehicles v ON v.id=b.vehicle_id JOIN users u ON u.id=b.user_id";
$params = [];
if ($statusFilter !== "all") {
    $sql .= " WHERE b.status = ?";
    $params[] = $statusFilter;
}
$sql .= " ORDER BY b.created_at DESC";
$statement = database()->prepare($sql);
$statement->execute($params);
$bookings = $statement->fetchAll();
$selected = isset($_GET["reference"])
    ? booking_find_by_reference((string) $_GET["reference"])
    : null;
$selectedRequirements = $selected ? booking_requirements($selected) : null;
$pendingModifications = admin_booking_modifications("pending");
$pendingCancellations = admin_cancellation_requests("pending");
$selectedModification = $selected ? unresolved_booking_modification((int) $selected["id"]) : null;
$selectedCancellation = $selected ? cancellation_request_for_booking((int) $selected["id"]) : null;
$selectedCancellationPending = $selectedCancellation && $selectedCancellation["status"] === "pending";
$readyTransitionAvailable = $selected
    ? in_array("ready", $allowedTransitions[$selected["status"]] ?? [], true)
        && !$selectedModification
        && !$selectedCancellationPending
    : false;
$availableTransitions = $selected
    ? array_values(
        array_diff($allowedTransitions[$selected["status"]] ?? [], [
            "active",
            "returned",
            "ready",
            "cancelled",
        ]),
    )
    : [];
$pageTitle = "Manage Bookings | VJ Car Rental";
require dirname(__DIR__, 2) . "/includes/header.php";
require dirname(__DIR__, 2) . "/includes/admin-nav.php";
?>
<section class="admin-page-heading">
    <div class="container">
        <div>
            <span class="section-kicker">Reservations</span>
            <h1>Manage bookings</h1>
            <p>Approve reservations, prepare them for pickup, and hand active rentals to the Rental Desk using controlled status transitions.</p>
        </div>
    </div>
</section>
<section class="content-section admin-section">
    <div class="container">
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-danger"><?= escape_html($error) ?></div>
        <?php endforeach; ?>
        <div class="admin-filter-tabs">
            <?php foreach ($validFilters as $filter): ?>
                <a class="<?= $statusFilter === $filter
                    ? "active"
                    : "" ?>" href="admin-bookings.php?status=<?= $filter ?>"><?= humanize_label(
    $filter,
) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Customer</th>
                        <th>Vehicle</th>
                        <th>Schedule</th>
                        <th>Status</th>
                        <th>Total</th>
                        <th>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $booking): ?>
                        <tr>
                            <td>
                                <strong><?= escape_html(
                                    $booking["reference"],
                                ) ?></strong>
                            </td>
                            <td><?= escape_html(
                                $booking["customer_name"],
                            ) ?><small><?= escape_html(
    $booking["customer_email"],
) ?></small>
                            </td>
                            <td><?= escape_html($booking["vehicle_name"]) ?></td>
                            <td><?= date(
                                "M j, Y",
                                strtotime($booking["pickup_at"]),
                            ) ?><small>to <?= date(
    "M j, Y",
    strtotime($booking["return_at"]),
) ?></small>
                            </td>
                            <td>
                                <span class="status-badge status-badge--<?= status_class(
                                    $booking["status"],
                                ) ?>"><?= escape_html(
    humanize_label($booking["status"]),
) ?></span>
                            </td>
                            <td><?= money((int) $booking["total"]) ?></td>
                            <td>
                                <a class="btn btn-outline btn-sm" href="admin-bookings.php?status=<?= urlencode(
                                    $statusFilter,
                                ) ?>&amp;reference=<?= urlencode(
    $booking["reference"],
) ?>">Open</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="admin-ops-queues mt-4">
            <article class="admin-editor" id="modification-queue">
                <div class="admin-editor__heading"><div><span class="section-kicker">Pre-pickup changes</span><h2>Pending booking modifications</h2><p>Requested changes do not alter the authoritative booking until approval and any required additional payment are completed.</p></div><span class="dashboard-queue__count"><?= count($pendingModifications) ?></span></div>
                <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Booking</th><th>Customer</th><th>Requested change</th><th>Price impact</th><th>Action</th></tr></thead><tbody>
                <?php foreach ($pendingModifications as $modification): $requestedData = json_decode((string) $modification["requested_data"], true) ?: []; ?>
                    <tr>
                        <td><a href="admin-bookings.php?reference=<?= urlencode($modification["booking_reference"]) ?>"><strong><?= escape_html($modification["booking_reference"]) ?></strong></a><small><?= escape_html($modification["current_vehicle_name"]) ?></small></td>
                        <td><?= escape_html($modification["customer_name"]) ?><small><?= escape_html($modification["customer_email"]) ?></small></td>
                        <td><strong><?= escape_html((string) ($requestedData["vehicle_name"] ?? $modification["requested_vehicle_name"] ?? "Vehicle")) ?></strong><small><?= !empty($requestedData["pickup_at"]) ? date("M j, Y g:i A", strtotime($requestedData["pickup_at"])) : "—" ?> → <?= !empty($requestedData["return_at"]) ? date("M j, Y g:i A", strtotime($requestedData["return_at"])) : "—" ?></small><small><?= escape_html((string) ($requestedData["pickup_method"] ?? "")) ?></small></td>
                        <td><?= ((int) $modification["price_difference"] >= 0 ? "+" : "−") . money(abs((int) $modification["price_difference"])) ?></td>
                        <td><form class="admin-inline-form" method="post"><?= csrf_field() ?><input type="hidden" name="admin_action" value="modification_review"><input type="hidden" name="modification_id" value="<?= (int) $modification["id"] ?>"><select class="form-select form-select-sm" name="decision" required><option value="">Decision</option><option value="approved">Approve</option><option value="rejected">Reject</option></select><input class="form-control form-control-sm" name="admin_note" maxlength="2000" placeholder="Admin note"><button class="btn btn-primary btn-sm" type="submit">Review</button></form></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$pendingModifications): ?><tr><td colspan="5">No pending booking modifications.</td></tr><?php endif; ?>
                </tbody></table></div>
            </article>

            <article class="admin-editor mt-4" id="cancellation-queue">
                <div class="admin-editor__heading"><div><span class="section-kicker">Cancellation control</span><h2>Pending cancellations</h2><p>Approve only eligible pre-pickup cancellations. Refund estimates are recalculated when the request is reviewed.</p></div><span class="dashboard-queue__count"><?= count($pendingCancellations) ?></span></div>
                <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Booking</th><th>Customer</th><th>Reason</th><th>Refund estimate</th><th>Action</th></tr></thead><tbody>
                <?php foreach ($pendingCancellations as $cancellation): ?>
                    <tr><td><a href="admin-bookings.php?reference=<?= urlencode($cancellation["booking_reference"]) ?>"><strong><?= escape_html($cancellation["booking_reference"]) ?></strong></a><small><?= escape_html($cancellation["vehicle_name"]) ?></small></td><td><?= escape_html($cancellation["customer_name"]) ?><small><?= escape_html($cancellation["customer_email"]) ?></small></td><td><?= escape_html((string) $cancellation["reason"]) ?></td><td><strong><?= money((int) $cancellation["refundable_amount"]) ?></strong><small><?= money((int) $cancellation["non_refundable_amount"]) ?> non-refundable</small></td><td><form class="admin-inline-form" method="post"><?= csrf_field() ?><input type="hidden" name="admin_action" value="cancellation_review"><input type="hidden" name="cancellation_id" value="<?= (int) $cancellation["id"] ?>"><select class="form-select form-select-sm" name="decision" required><option value="">Decision</option><option value="approved">Approve</option><option value="rejected">Reject</option></select><input class="form-control form-control-sm" name="admin_note" maxlength="2000" placeholder="Admin note"><button class="btn btn-primary btn-sm" type="submit">Review</button></form></td></tr>
                <?php endforeach; ?>
                <?php if (!$pendingCancellations): ?><tr><td colspan="5">No pending cancellation requests.</td></tr><?php endif; ?>
                </tbody></table></div>
            </article>
        </div>

        <?php if ($selected): ?>
            <div class="admin-editor">
                <div class="admin-editor__heading">
                    <div>
                        <span class="section-kicker">Booking <?= escape_html(
                            $selected["reference"],
                        ) ?></span>
                        <h2><?= escape_html($selected["vehicle_name"]) ?></h2>
                    </div>
                    <a href="booking-view.php?reference=<?= urlencode(
                        $selected["reference"],
                    ) ?>">Full booking view</a>
                </div>
                <div class="confirmation-details">
                    <span>
                        <small>Customer</small>
                        <strong><?= escape_html($selected["customer_name"]) ?></strong>
                    </span>
                    <span>
                        <small>Email</small>
                        <strong><?= escape_html(
                            $selected["customer_email"],
                        ) ?></strong>
                    </span>
                    <span>
                        <small>Pick-up</small>
                        <strong><?= date(
                            "M j, Y g:i A",
                            strtotime($selected["pickup_at"]),
                        ) ?></strong>
                    </span>
                    <span>
                        <small>Return</small>
                        <strong><?= date(
                            "M j, Y g:i A",
                            strtotime($selected["return_at"]),
                        ) ?></strong>
                    </span>
                </div>
                <?php if ($selectedRequirements): ?>
                    <div class="requirements-grid mt-3" aria-label="Confirmation requirements">
                        <div class="requirement-item<?= $selectedRequirements[
                            "license_approved"
                        ]
                            ? " is-complete"
                            : "" ?>"><i class="bi <?= $selectedRequirements[
    "license_approved"
]
    ? "bi-check-circle-fill"
    : "bi-x-circle-fill" ?>"></i><span><strong>Driver’s license</strong><small><?= $selectedRequirements[
    "license_approved"
]
    ? "Approved"
    : "Needs approval" ?></small></span></div>
                        <div class="requirement-item<?= $selectedRequirements[
                            "id_approved"
                        ]
                            ? " is-complete"
                            : "" ?>"><i class="bi <?= $selectedRequirements[
    "id_approved"
]
    ? "bi-check-circle-fill"
    : "bi-x-circle-fill" ?>"></i><span><strong>Government ID</strong><small><?= $selectedRequirements[
    "id_approved"
]
    ? "Approved"
    : "Needs approval" ?></small></span></div>
                        <div class="requirement-item<?= $selectedRequirements[
                            "deposit_paid"
                        ]
                            ? " is-complete"
                            : "" ?>"><i class="bi <?= $selectedRequirements[
    "deposit_paid"
]
    ? "bi-check-circle-fill"
    : "bi-x-circle-fill" ?>"></i><span><strong>Security deposit</strong><small><?= $selectedRequirements[
    "deposit_paid"
]
    ? "Verified"
    : money($selectedRequirements["payments"]["deposit_due"]) .
        " due" ?></small></span></div>
                    </div>
                    <?php if (
                        !$selectedRequirements["ready_for_confirmation"] &&
                        $selected["status"] === "pending"
                    ): ?><p class="display-note mt-3"><a href="admin-documents.php?user=<?= (int) $selected[
    "user_id"
] ?>">Review documents</a> and <a href="admin-payments.php?booking=<?= urlencode(
    $selected["reference"],
) ?>">verify the deposit</a> before confirmation.</p><?php endif; ?>
                <?php endif; ?>

                <?php if ($selectedModification): ?>
                    <div class="alert alert-info mt-3">Booking modification request: <strong><?= escape_html(humanize_label($selectedModification["status"])) ?></strong>. <?php if ($selectedModification["status"] === "approved" && (int) $selectedModification["price_difference"] > 0): ?>Additional payment of <?= money((int) $selectedModification["price_difference"]) ?> is required before activation.<?php endif; ?></div>
                <?php endif; ?>
                <?php if ($selectedCancellation): ?>
                    <div class="alert alert-warning mt-3">Cancellation request: <strong><?= escape_html(humanize_label($selectedCancellation["status"])) ?></strong>.</div>
                <?php endif; ?>

                <?php if ($readyTransitionAvailable): ?>
                    <?php
                    $readyForDelivery = $selected["pickup_method"] === "Vehicle delivery";
                    $readyActionLabel = $readyForDelivery ? "Mark Ready for Delivery" : "Mark Ready for Pickup";
                    ?>
                    <article class="admin-ready-card mt-4">
                        <div>
                            <span class="section-kicker">Vehicle preparation</span>
                            <h3><?= escape_html($readyActionLabel) ?></h3>
                            <p>Payment and required documents are verified. Confirm this only after the vehicle is physically prepared and operationally ready. The customer will be notified immediately.</p>
                            <div class="admin-ready-card__meta">
                                <span><small>Fulfillment</small><strong><?= escape_html($selected["pickup_method"]) ?></strong></span>
                                <span><small><?= $readyForDelivery ? "Delivery location" : "Pickup branch" ?></small><strong><?= escape_html($readyForDelivery ? ($selected["delivery_address"] ?: "Delivery address on booking") : $selected["pickup_location"]) ?></strong></span>
                            </div>
                        </div>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="admin_action" value="booking_status">
                            <input type="hidden" name="reference" value="<?= escape_html($selected["reference"]) ?>">
                            <input type="hidden" name="status" value="ready">
                            <input type="hidden" name="admin_notes" value="<?= escape_html($selected["admin_notes"] ?? "") ?>">
                            <button class="btn btn-primary" type="submit"><i class="bi <?= $readyForDelivery ? "bi-truck" : "bi-key" ?>"></i> <?= escape_html($readyActionLabel) ?></button>
                        </form>
                    </article>
                <?php endif; ?>

                <?php if ($availableTransitions): ?>
                    <form method="post" class="row g-3 mt-2"><?= csrf_field() ?><input type="hidden" name="admin_action" value="booking_status"><input type="hidden" name="reference" value="<?= escape_html(
    $selected["reference"],
) ?>">
                        <div class="col-md-4">
                            <label class="form-label" for="adminBookingStatus">Next status</label>
                            <select class="form-select" id="adminBookingStatus" name="status" required>
                                <option value="">Choose action</option>
                                <?php foreach (
                                    $availableTransitions
                                    as $next
                                ): ?>
                                    <option value="<?= $next ?>"><?= humanize_label(
    $next,
) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label" for="adminBookingNotes">Internal notes</label>
                            <input class="form-control" id="adminBookingNotes" name="admin_notes" value="<?= escape_html(
                                $selected["admin_notes"],
                            ) ?>" maxlength="3000">
                        </div>
                        <div class="col-12">
                            <button class="btn btn-primary" type="submit">Update Booking Status</button>
                        </div>
                    </form>
                <?php elseif (
                    in_array($selected["status"], ["ready", "active"], true)
                ): ?>
                    <p class="display-note">Continue this booking through the <a href="admin-rentals.php?reference=<?= urlencode(
                        $selected["reference"],
                    ) ?>">Rental Desk</a> to record the vehicle inspection.</p>
                <?php else: ?>
                    <p class="display-note">This booking has reached a final status.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
