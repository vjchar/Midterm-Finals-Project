<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";

$admin = require_admin();
$reference = trim((string) ($_GET["reference"] ?? ($_POST["reference"] ?? "")));
$selected = $reference !== "" ? booking_find_by_reference($reference) : null;
$errors = [];

if ($_SERVER["REQUEST_METHOD"] === "POST" && $selected) {
    try {
        require_csrf();

        $action = post_string("action");

        if ($action === "checkout") {
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
        } elseif ($action === "complete") {
            $summary = booking_payment_summary($selected);

            if ($summary["rental_due"] + $summary["extra_due"] > 0) {
                throw new RuntimeException(
                    "Verify the rental balance and extra charges before completing this booking.",
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

$rentalQuery = <<<'SQL'
SELECT
    b.reference,
    b.status,
    b.pickup_at,
    b.return_at,
    v.name AS vehicle_name,
    v.availability_status,
    u.name AS customer_name
FROM bookings AS b
JOIN vehicles AS v ON v.id = b.vehicle_id
JOIN users AS u ON u.id = b.user_id
WHERE b.status IN ('ready', 'active', 'returned')
ORDER BY
    CASE b.status
        WHEN 'active' THEN 0
        WHEN 'ready' THEN 1
        ELSE 2
    END,
    b.pickup_at
SQL;

$rentals = database()->query($rentalQuery)->fetchAll();

if ($selected) {
    $selected = booking_find_by_reference($selected["reference"]);
    $inspections = inspections_for_booking((int) $selected["id"]);
    $paymentSummary = booking_payment_summary($selected);
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
                                        ucfirst($rental["availability_status"]),
                                    ) ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge status-badge--<?= status_class(
                                    $rental["status"],
                                ) ?>">
                                    <?= escape_html(ucfirst($rental["status"])) ?>
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
                        <?= escape_html(ucfirst($selected["status"])) ?>
                    </span>
                </div>

                <?php if (
                    in_array($selected["status"], ["ready", "active"], true)
                ): ?>
                    <?php
                    $action =
                        $selected["status"] === "ready"
                            ? "checkout"
                            : "checkin";
                    $actionLabel = ucfirst($action);
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
                        <article>
                            <span>Rental balance due</span>
                            <strong><?= money(
                                $paymentSummary["rental_due"],
                            ) ?></strong>
                        </article>
                        <article>
                            <span>Extra charges due</span>
                            <strong><?= money(
                                $paymentSummary["extra_due"],
                            ) ?></strong>
                        </article>
                        <article>
                            <span>Verified payments</span>
                            <strong><?= money(
                                $paymentSummary["paid_total"],
                            ) ?></strong>
                        </article>
                    </div>

                    <form method="post" class="rental-completion-form">
                        <?= csrf_field() ?>
                        <input
                            type="hidden"
                            name="reference"
                            value="<?= escape_html($selected["reference"]) ?>">
                        <input type="hidden" name="action" value="complete">

                        <label class="form-label" for="completionNotes">
                            Completion notes
                        </label>
                        <textarea
                            class="form-control"
                            id="completionNotes"
                            name="admin_notes"
                            rows="3"
                            maxlength="3000"></textarea>

                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <button class="btn btn-primary" type="submit">
                                Complete Rental
                            </button>
                            <a class="btn btn-outline" href="admin-payments.php">
                                Review Payments
                            </a>
                        </div>
                    </form>
                <?php endif; ?>
            </article>
        <?php endif; ?>
    </div>
</section>

<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
