<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";
$adminUser = require_admin();
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
            throw new RuntimeException("That status transition is not allowed.");
        }

        update_booking_status(
            $booking,
            $newStatus,
            (int) $adminUser["id"],
            post_string("admin_notes"),
        );
        flash("success", "Booking status updated to " . $newStatus . ".");
        redirect(
            "booking-management.php?reference=" .
                urlencode($booking["reference"]),
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
$bookings = booking_all($statusFilter);
$selected = isset($_GET["reference"])
    ? booking_find_by_reference((string) $_GET["reference"])
    : null;
$availableTransitions = $selected
    ? ($allowedTransitions[$selected["status"]] ?? [])
    : [];

$pageTitle = "Manage Bookings | VJ Car Rental";
require dirname(__DIR__, 2) . "/includes/header.php";
?>
<section class="admin-page-heading">
    <div class="container">
        <div>
            <span class="section-kicker">Reservations</span>
            <h1>Manage bookings</h1>
            <p>Review customer reservations and apply basic booking status updates.</p>
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
                <a class="<?= $statusFilter === $filter ? "active" : "" ?>"
                   href="booking-management.php?status=<?= urlencode($filter) ?>">
                    <?= ucfirst($filter) ?>
                </a>
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
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $booking): ?>
                        <tr>
                            <td><strong><?= escape_html($booking["reference"]) ?></strong></td>
                            <td><?= escape_html($booking["customer_name"]) ?><small><?= escape_html($booking["customer_email"]) ?></small></td>
                            <td><?= escape_html($booking["vehicle_name"]) ?></td>
                            <td>
                                <?= date("M j, Y", strtotime($booking["pickup_at"])) ?>
                                <small>to <?= date("M j, Y", strtotime($booking["return_at"])) ?></small>
                            </td>
                            <td>
                                <span class="status-badge status-badge--<?= status_class($booking["status"]) ?>">
                                    <?= escape_html(ucfirst($booking["status"])) ?>
                                </span>
                            </td>
                            <td><?= money((int) $booking["total"]) ?></td>
                            <td>
                                <a class="btn btn-outline btn-sm"
                                   href="booking-management.php?status=<?= urlencode($statusFilter) ?>&amp;reference=<?= urlencode($booking["reference"]) ?>">
                                    Open
                                </a>
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
                        <span class="section-kicker">Booking <?= escape_html($selected["reference"]) ?></span>
                        <h2><?= escape_html($selected["vehicle_name"]) ?></h2>
                    </div>
                    <a href="booking-view.php?reference=<?= urlencode($selected["reference"]) ?>">Full booking view</a>
                </div>

                <div class="confirmation-details">
                    <span><small>Customer</small><strong><?= escape_html($selected["customer_name"]) ?></strong></span>
                    <span><small>Email</small><strong><?= escape_html($selected["customer_email"]) ?></strong></span>
                    <span><small>Pick-up</small><strong><?= date("M j, Y g:i A", strtotime($selected["pickup_at"])) ?></strong></span>
                    <span><small>Return</small><strong><?= date("M j, Y g:i A", strtotime($selected["return_at"])) ?></strong></span>
                    <span><small>Total</small><strong><?= money((int) $selected["total"]) ?></strong></span>
                    <span><small>Status</small><strong><?= escape_html(ucfirst($selected["status"])) ?></strong></span>
                </div>

                <?php if ($availableTransitions): ?>
                    <form method="post" class="row g-3 mt-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="reference" value="<?= escape_html($selected["reference"]) ?>">
                        <div class="col-md-4">
                            <label class="form-label" for="adminBookingStatus">Next status</label>
                            <select class="form-select" id="adminBookingStatus" name="status" required>
                                <option value="">Choose action</option>
                                <?php foreach ($availableTransitions as $next): ?>
                                    <option value="<?= escape_html($next) ?>"><?= ucfirst($next) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label" for="adminBookingNotes">Internal notes</label>
                            <input class="form-control" id="adminBookingNotes" name="admin_notes"
                                   value="<?= escape_html($selected["admin_notes"] ?? "") ?>"
                                   maxlength="3000">
                        </div>
                        <div class="col-12">
                            <button class="btn btn-primary" type="submit">Update Booking Status</button>
                        </div>
                    </form>
                <?php else: ?>
                    <p class="display-note mt-3">This booking has reached a final status.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
