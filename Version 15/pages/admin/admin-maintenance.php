<?php

declare(strict_types=1);


/**
 * FILE: pages/admin/admin-maintenance.php
 * FILE PURPOSE: Administrator vehicle maintenance record management page.
 * USED BY: Authenticated administrators using the corresponding management section.
 * RESPONSIBILITY: Loads the required application/services, handles only page-level request orchestration, and renders the user interface; reusable business/database logic belongs in services.
 *
 * Maintenance note: Keep this file focused on the responsibility described above.
 */
require dirname(__DIR__, 2) . "/includes/bootstrap.php";

$admin = require_admin();
$vehicles = vehicle_all();
$errors = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        require_csrf();
        $action = post_string("action");
        if ($action === "create") {
            create_maintenance_record((int) $admin["id"], $_POST);
        } elseif ($action === "status") {
            $recordId = filter_var(
                $_POST["record_id"] ?? null,
                FILTER_VALIDATE_INT,
            );
            if (!$recordId) {
                throw new InvalidArgumentException(
                    "Choose a valid maintenance update.",
                );
            }
            update_maintenance_status((int) $recordId, post_string("status"));
        } else {
            throw new InvalidArgumentException("Choose a valid maintenance action.");
        }
        flash("success", "Maintenance record saved.");
        redirect("admin-maintenance.php");
    } catch (Throwable $error) {
        $errors[] = user_facing_error_message($error);
    }
}

$records = maintenance_records();

$pageTitle = "Fleet Maintenance | VJ Car Rental";
require dirname(__DIR__, 2) . "/includes/header.php";
require dirname(__DIR__, 2) . "/includes/admin-nav.php";
?>

<section class="admin-page-heading">
    <div class="container">
        <div>
            <span class="section-kicker">Fleet safety</span>
            <h1>Maintenance records</h1>
            <p>Track scheduled and active vehicle service. Vehicles in maintenance are automatically removed from online availability.</p>
        </div>
    </div>
</section>

<section class="content-section admin-section">
    <div class="container">
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-danger"><?= escape_html($error) ?></div>
        <?php endforeach; ?>

        <article class="admin-editor mb-4">
            <span class="section-kicker">New service event</span>
            <h2>Schedule maintenance</h2>
            <form method="post" class="row g-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <div class="col-md-4">
                    <label class="form-label" for="maintenanceVehicle">Vehicle</label>
                    <select class="form-select" id="maintenanceVehicle" name="vehicle_id" required>
                        <?php foreach ($vehicles as $vehicle): ?>
                            <option value="<?= (int) $vehicle[
                                "id"
                            ] ?>"><?= escape_html($vehicle["name"]) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="maintenanceTitle">Service title</label>
                    <input class="form-control" id="maintenanceTitle" name="title" maxlength="160" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="maintenanceStartsAt">Starts</label>
                    <input class="form-control" id="maintenanceStartsAt" name="starts_at" type="datetime-local" required>
                </div>
                <div class="col-md-8">
                    <label class="form-label" for="maintenanceDescription">Description</label>
                    <textarea class="form-control" id="maintenanceDescription" name="description" rows="2" maxlength="3000" required></textarea>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="maintenanceCost">Estimated cost</label>
                    <input class="form-control" id="maintenanceCost" name="cost" type="number" min="0" value="0" required>
                </div>
                <div class="col-12">
                    <button class="btn btn-primary" type="submit">Create Record</button>
                </div>
            </form>
        </article>

        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Vehicle</th>
                        <th>Service</th>
                        <th>Schedule</th>
                        <th>Cost</th>
                        <th>Status</th>
                        <th>Update</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $record): ?>
                        <tr>
                            <td>
                                <strong><?= escape_html(
                                    $record["vehicle_name"],
                                ) ?></strong>
                                <small>Created by <?= escape_html(
                                    $record["creator_name"],
                                ) ?></small>
                            </td>
                            <td>
                                <?= escape_html($record["title"]) ?>
                                <small><?= escape_html(
                                    $record["description"],
                                ) ?></small>
                            </td>
                            <td><?= date(
                                "M j, Y g:i A",
                                strtotime($record["starts_at"]),
                            ) ?></td>
                            <td><?= money((int) $record["cost"]) ?></td>
                            <td>
                                <span class="status-badge status-badge--<?= status_class(
                                    $record["status"],
                                ) ?>">
                                    <?= escape_html(
                                        ucwords(
                                            str_replace(
                                                "_",
                                                " ",
                                                $record["status"],
                                            ),
                                        ),
                                    ) ?>
                                </span>
                            </td>
                            <td>
                                <form class="admin-inline-form" method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="status">
                                    <input type="hidden" name="record_id" value="<?= (int) $record[
                                        "id"
                                    ] ?>">
                                    <select class="form-select form-select-sm" name="status" aria-label="Update maintenance status for <?= escape_html(
                                        $record["vehicle_name"],
                                    ) ?>">
                                        <?php foreach (
                                            [
                                                "scheduled",
                                                "in_progress",
                                                "completed",
                                                "cancelled",
                                            ]
                                            as $status
                                        ): ?>
                                            <option value="<?= $status ?>" <?= $record[
    "status"
] === $status
    ? "selected"
    : "" ?>>
                                                <?= escape_html(
                                                    ucwords(
                                                        str_replace(
                                                            "_",
                                                            " ",
                                                            $status,
                                                        ),
                                                    ),
                                                ) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="btn btn-primary btn-sm" type="submit">Save</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$records): ?>
                        <tr>
                            <td colspan="6">No maintenance records yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
