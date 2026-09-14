<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";

$admin = require_admin();
$vehicles = vehicle_all();
$errors = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        require_csrf();
        $action = post_string("action");

        if ($action === "create") {
            $vehicleId = filter_var(
                $_POST["vehicle_id"] ?? null,
                FILTER_VALIDATE_INT,
            );
            $cost = filter_var($_POST["cost"] ?? 0, FILTER_VALIDATE_INT, [
                "options" => ["min_range" => 0, "max_range" => 10000000],
            ]);
            $startsAt = DateTimeImmutable::createFromFormat(
                "Y-m-d\TH:i",
                post_string("starts_at"),
            );

            if (
                !$vehicleId ||
                $cost === false ||
                !$startsAt ||
                mb_strlen(post_string("title")) < 3 ||
                mb_strlen(post_string("description")) < 5
            ) {
                throw new InvalidArgumentException(
                    "Complete all maintenance fields with valid values.",
                );
            }

            $currentTimestamp = date("Y-m-d H:i:s");
            $statement = database()->prepare(
                "INSERT INTO maintenance_records
                    (vehicle_id, title, description, status, starts_at, cost, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, 'scheduled', ?, ?, ?, ?, ?)",
            );
            $statement->execute([
                $vehicleId,
                mb_substr(post_string("title"), 0, 160),
                mb_substr(post_string("description"), 0, 3000),
                $startsAt->format("Y-m-d H:i:s"),
                $cost,
                $admin["id"],
                $currentTimestamp,
                $currentTimestamp,
            ]);
            write_audit(
                "maintenance_created",
                "maintenance_record",
                (int) database()->lastInsertId(),
            );
        } elseif ($action === "status") {
            $recordId = filter_var(
                $_POST["record_id"] ?? null,
                FILTER_VALIDATE_INT,
            );
            $status = post_string("status");

            if (
                !$recordId ||
                !in_array(
                    $status,
                    ["scheduled", "in_progress", "completed", "cancelled"],
                    true,
                )
            ) {
                throw new InvalidArgumentException(
                    "Choose a valid maintenance update.",
                );
            }

            $select = database()->prepare(
                "SELECT * FROM maintenance_records WHERE id = ?",
            );
            $select->execute([$recordId]);
            $record = $select->fetch();
            if (!$record) {
                throw new RuntimeException("Maintenance record not found.");
            }

            $currentTimestamp = date("Y-m-d H:i:s");
            $update = database()->prepare(
                "UPDATE maintenance_records SET status = ?, ends_at = ?, updated_at = ? WHERE id = ?",
            );
            $update->execute([
                $status,
                in_array($status, ["completed", "cancelled"], true)
                    ? $currentTimestamp
                    : null,
                $currentTimestamp,
                $recordId,
            ]);

            if ($status === "in_progress") {
                database()
                    ->prepare(
                        "UPDATE vehicles SET availability_status = 'maintenance', updated_at = ? WHERE id = ?",
                    )
                    ->execute([$currentTimestamp, $record["vehicle_id"]]);
            } elseif (in_array($status, ["completed", "cancelled"], true)) {
                database()
                    ->prepare(
                        "UPDATE vehicles SET availability_status = 'available', updated_at = ? WHERE id = ?",
                    )
                    ->execute([$currentTimestamp, $record["vehicle_id"]]);
                sync_vehicle_status((int) $record["vehicle_id"]);
            }

            write_audit(
                "maintenance_status_updated",
                "maintenance_record",
                $recordId,
                ["status" => $status],
            );
        }

        flash("success", "Maintenance record saved.");
        redirect("admin-maintenance.php");
    } catch (Throwable $error) {
        $errors[] = user_facing_error_message($error);
    }
}

$records = database()
    ->query(
        'SELECT m.*, v.name AS vehicle_name, u.name AS creator_name
     FROM maintenance_records m
     JOIN vehicles v ON v.id = m.vehicle_id
     JOIN users u ON u.id = m.created_by
     ORDER BY m.created_at DESC',
    )
    ->fetchAll();

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
