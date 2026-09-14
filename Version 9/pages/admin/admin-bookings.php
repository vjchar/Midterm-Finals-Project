<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";
$admin = require_admin();
$allowedTransitions = booking_transitions();
$errors = [];
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        require_csrf();
        $booking = booking_find_by_reference(post_string("reference"));
        if (!$booking) {
            throw new RuntimeException("Booking not found.");
        }
        $newStatus = post_string("status");
        if (
            !in_array(
                $newStatus,
                $allowedTransitions[$booking["status"]] ?? [],
                true,
            )
        ) {
            throw new RuntimeException(
                "That status transition is not allowed.",
            );
        }
        update_booking_status(
            $booking,
            $newStatus,
            $admin["id"],
            post_string("admin_notes"),
        );
        flash("success", "Booking status updated to " . $newStatus . ".");
        redirect(
            "admin-bookings.php?reference=" . urlencode($booking["reference"]),
        );
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
$availableTransitions = $selected
    ? array_values(
        array_diff($allowedTransitions[$selected["status"]] ?? [], [
            "active",
            "returned",
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
                    : "" ?>" href="admin-bookings.php?status=<?= $filter ?>"><?= ucfirst(
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
    ucfirst($booking["status"]),
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
                <?php if ($availableTransitions): ?>
                    <form method="post" class="row g-3 mt-2"><?= csrf_field() ?><input type="hidden" name="reference" value="<?= escape_html(
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
                                    <option value="<?= $next ?>"><?= ucfirst(
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
