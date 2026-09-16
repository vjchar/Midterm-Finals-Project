<?php

declare(strict_types=1);


/**
 * FILE: pages/admin/admin-vehicles.php
 * FILE PURPOSE: Administrator vehicle inventory management page.
 * USED BY: Authenticated administrators using the corresponding management section.
 * RESPONSIBILITY: Loads the required application/services, handles only page-level request orchestration, and renders the user interface; reusable business/database logic belongs in services.
 *
 * Maintenance note: Keep this file focused on the responsibility described above.
 */
require dirname(__DIR__, 2) . "/includes/bootstrap.php";
require_once dirname(__DIR__, 2) . "/includes/admin-vehicle-service.php";
$admin = require_admin();
$errors = [];
$editId =
    filter_input(INPUT_GET, "edit", FILTER_VALIDATE_INT) ?:
    (int) ($_POST["id"] ?? 0);
$editing = $editId ? vehicle_find($editId, false) : null;
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        $saveResult = admin_vehicle_save_submission($_POST, $_FILES);
        flash(
            "success",
            $saveResult["created"] ? "Vehicle created." : "Vehicle updated.",
        );
        redirect("admin-vehicles.php?edit=" . $saveResult["vehicle_id"]);
    } catch (AdminVehicleValidationException $validationError) {
        $errors[] = $validationError->getMessage();
    } catch (Throwable $unexpectedError) {
        error_log(
            sprintf(
                "Admin vehicle save failed: %s: %s in %s:%d",
                $unexpectedError::class,
                $unexpectedError->getMessage(),
                $unexpectedError->getFile(),
                $unexpectedError->getLine(),
            ),
        );
        $errors[] = "The vehicle could not be saved right now. Please try again.";
    }
}
$allVehicles = vehicle_all(false);
$vehiclePage = max(1, (int) ($_GET["page"] ?? 1));
$vehiclesPerPage = 12;
$vehiclePageCount = max(1, (int) ceil(count($allVehicles) / $vehiclesPerPage));
$vehiclePage = min($vehiclePage, $vehiclePageCount);
$visibleVehicles = array_slice(
    $allVehicles,
    ($vehiclePage - 1) * $vehiclesPerPage,
    $vehiclesPerPage,
);
if ($editId) {
    $editing = vehicle_find($editId, false);
}

$formValue = static function (string $key, mixed $default = "") use (
    $editing,
): string {
    return escape_html($_POST[$key] ?? ($editing[$key] ?? $default));
};

$categoryChoices = ["Luxury", "SUV", "Pickup", "Van", "EV"];
$statusChoices = [
    "available",
    "maintenance",
    "unavailable",
];
$transmissionChoices = ["Automatic", "Manual"];
$fuelChoices = ["Gasoline", "Diesel", "Electric", "Hybrid"];

$previousVehiclePage = max(1, $vehiclePage - 1);
$nextVehiclePage = min($vehiclePageCount, $vehiclePage + 1);
$isFirstVehiclePage = $vehiclePage <= 1;
$isLastVehiclePage = $vehiclePage >= $vehiclePageCount;
$vehicleIsActive =
    isset($_POST["is_active"]) || (!$_POST && ($editing["is_active"] ?? true));
$inclusionsValue =
    $_POST["inclusions"] ?? implode("\n", $editing["inclusions"] ?? []);
$featuresValue =
    $_POST["features"] ?? implode("\n", $editing["features"] ?? []);

$pageTitle = "Manage Vehicles | VJ Car Rental";
require dirname(__DIR__, 2) . "/includes/header.php";
require dirname(__DIR__, 2) . "/includes/admin-nav.php";
?>
<section class="admin-page-heading">
    <div class="container">
        <div>
            <span class="section-kicker">Fleet content</span>
            <h1>Manage vehicles</h1>
            <p>
                Create and update bookable vehicle records, images, pricing,
                specifications, inclusions, and descriptions.
            </p>
        </div>

        <a class="btn btn-primary" href="admin-vehicles.php">Add Vehicle</a>
    </div>
</section>

<section class="content-section admin-section">
    <div class="container">
        <div class="admin-split admin-split--vehicles">
            <div class="admin-list-stack">
                <div class="admin-table-wrap" aria-label="Vehicle records">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Vehicle</th>
                                <th>Category</th>
                                <th>Rate</th>
                                <th>Status</th>
                                <th><span class="visually-hidden">Action</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($visibleVehicles as $item): ?>
                                <tr>
                                    <td>
                                        <strong><?= escape_html(
                                            $item["name"],
                                        ) ?></strong>
                                        <small><?= escape_html(
                                            $item["slug"],
                                        ) ?></small>
                                    </td>
                                    <td><?= escape_html($item["category"]) ?></td>
                                    <td><?= money($item["price"]) ?></td>
                                    <td>
                                        <span class="status-badge status-badge--<?= status_class(
                                            $item["availability_status"],
                                        ) ?>">
                                            <?= escape_html(
                                                humanize_label(
                                                    $item[
                                                        "availability_status"
                                                    ],
                                                ),
                                            ) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a
                                            class="btn btn-outline btn-sm"
                                            href="admin-vehicles.php?edit=<?= (int) $item[
                                                "id"
                                            ] ?>">
                                            Edit
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <nav class="admin-pagination" aria-label="Vehicle record pages">
                    <a
                        class="<?= $isFirstVehiclePage ? "is-disabled" : "" ?>"
                        href="admin-vehicles.php?page=<?= $previousVehiclePage ?>"
                        aria-label="Previous vehicle records page"
                        <?= $isFirstVehiclePage
                            ? 'aria-disabled="true" tabindex="-1"'
                            : "" ?>>
                        <i class="bi bi-arrow-left" aria-hidden="true"></i>
                        Previous
                    </a>

                    <span
                        aria-label="Page <?= $vehiclePage ?> of <?= $vehiclePageCount ?>. <?= count(
    $allVehicles,
) ?> vehicles total.">
                        Page <strong><?= $vehiclePage ?></strong>
                        of <strong><?= $vehiclePageCount ?></strong>
                        <small><?= count($allVehicles) ?> vehicles</small>
                    </span>

                    <a
                        class="<?= $isLastVehiclePage ? "is-disabled" : "" ?>"
                        href="admin-vehicles.php?page=<?= $nextVehiclePage ?>"
                        aria-label="Next vehicle records page"
                        <?= $isLastVehiclePage
                            ? 'aria-disabled="true" tabindex="-1"'
                            : "" ?>>
                        Next
                        <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </a>
                </nav>
            </div>

            <form
                class="form admin-editor admin-editor--vehicle"
                method="post"
                enctype="multipart/form-data"
               >
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) ($editing[
                    "id"
                ] ?? 0) ?>">

                <div class="admin-editor__heading">
                    <div>
                        <span class="section-kicker">
                            <?= $editing ? "Edit vehicle" : "New vehicle" ?>
                        </span>
                        <h2><?= escape_html(
                            $editing["name"] ?? "Vehicle details",
                        ) ?></h2>
                    </div>
                </div>

                <?php foreach ($errors as $error): ?>
                    <div class="alert alert-danger" role="alert">
                        <?= escape_html($error) ?>
                    </div>
                <?php endforeach; ?>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="vehicleName">Name</label>
                        <input
                            class="form-control"
                            id="vehicleName"
                            name="name"
                            value="<?= $formValue("name") ?>"
                            maxlength="180"
                            required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="vehicleSlug">URL slug</label>
                        <input
                            class="form-control"
                            id="vehicleSlug"
                            name="slug"
                            value="<?= $formValue("slug") ?>"
                            maxlength="140"
                            required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="vehicleBrand">Brand</label>
                        <input
                            class="form-control"
                            id="vehicleBrand"
                            name="brand"
                            value="<?= $formValue("brand") ?>"
                            maxlength="100"
                            required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="vehicleCategory">Category</label>
                        <select
                            class="form-select"
                            id="vehicleCategory"
                            name="category"
                            required>
                            <?php foreach ($categoryChoices as $choice): ?>
                                <option
                                    value="<?= escape_html($choice) ?>"
                                    <?= $formValue("category") === $choice
                                        ? "selected"
                                        : "" ?>>
                                    <?= escape_html($choice) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="vehiclePrice">Daily rate</label>
                        <input
                            class="form-control"
                            id="vehiclePrice"
                            name="price"
                            type="number"
                            min="500"
                            max="100000"
                            value="<?= $formValue("price", "5000") ?>"
                            required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="vehicleDeposit">Deposit</label>
                        <input
                            class="form-control"
                            id="vehicleDeposit"
                            name="deposit"
                            type="number"
                            min="0"
                            max="100000"
                            value="<?= $formValue("deposit", "5000") ?>"
                            required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="vehicleDailyKm">Daily km</label>
                        <input
                            class="form-control"
                            id="vehicleDailyKm"
                            name="daily_km"
                            type="number"
                            min="0"
                            max="2000"
                            value="<?= $formValue("daily_km", "200") ?>"
                            required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="vehicleSeats">Seats</label>
                        <input
                            class="form-control"
                            id="vehicleSeats"
                            name="seats"
                            type="number"
                            min="1"
                            max="30"
                            value="<?= $formValue("seats", "5") ?>"
                            required>
                    </div>

                    <div class="col-md-8">
                        <label class="form-label" for="vehicleStatus">Fleet status</label>
                        <select
                            class="form-select"
                            id="vehicleStatus"
                            name="availability_status"
                            aria-describedby="vehicleStatusHelp"
                            required>
                            <?php foreach ($statusChoices as $choice): ?>
                                <option
                                    value="<?= escape_html($choice) ?>"
                                    <?= $formValue(
                                        "availability_status",
                                        "available",
                                    ) === $choice
                                        ? "selected"
                                        : "" ?>>
                                    <?= escape_html(
                                        ucwords(str_replace("_", " ", $choice)),
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12">
                        <small class="form-help" id="vehicleStatusHelp">
                            Reserved and rented states are assigned automatically from booking
                            records. Use Maintenance for service downtime or Unavailable to
                            prevent new reservations.
                        </small>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="vehicleDoors">Doors</label>
                        <input
                            class="form-control"
                            id="vehicleDoors"
                            name="doors"
                            type="number"
                            min="1"
                            max="8"
                            value="<?= $formValue("doors", "4") ?>"
                            required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="vehicleTransmission">Transmission</label>
                        <select
                            class="form-select"
                            id="vehicleTransmission"
                            name="transmission">
                            <?php foreach ($transmissionChoices as $choice): ?>
                                <option
                                    value="<?= escape_html($choice) ?>"
                                    <?= $formValue(
                                        "transmission",
                                        "Automatic",
                                    ) === $choice
                                        ? "selected"
                                        : "" ?>>
                                    <?= escape_html($choice) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="vehicleFuel">Fuel</label>
                        <select class="form-select" id="vehicleFuel" name="fuel">
                            <?php foreach ($fuelChoices as $choice): ?>
                                <option
                                    value="<?= escape_html($choice) ?>"
                                    <?= $formValue("fuel", "Gasoline") ===
                                    $choice
                                        ? "selected"
                                        : "" ?>>
                                    <?= escape_html($choice) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="vehicleLuggage">Luggage guide</label>
                        <input
                            class="form-control"
                            id="vehicleLuggage"
                            name="luggage"
                            value="<?= $formValue("luggage", "3 bags") ?>"
                            required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="vehicleImageFilename">
                            Existing image filename
                        </label>
                        <input
                            class="form-control"
                            id="vehicleImageFilename"
                            name="image_filename"
                            value="<?= $formValue("image") ?>"
                            placeholder="vehicle-slug.png">
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="vehicleImageUpload">
                            Upload replacement image
                        </label>
                        <input
                            class="form-control"
                            id="vehicleImageUpload"
                            name="vehicle_image"
                            type="file"
                            accept="image/png,image/jpeg,image/webp"
                            aria-describedby="vehicleImageHelp">
                        <small class="form-help" id="vehicleImageHelp">
                            PNG, JPG, or WebP, up to 8 MB. Existing files are not
                            automatically deleted.
                        </small>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="vehicleOverviewTitle">
                            Overview heading
                        </label>
                        <input
                            class="form-control"
                            id="vehicleOverviewTitle"
                            name="overview_title"
                            value="<?= $formValue("overview_title") ?>"
                            maxlength="255"
                            required>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="vehicleOverview">Overview</label>
                        <textarea
                            class="form-control"
                            id="vehicleOverview"
                            name="overview"
                            rows="4"
                            required><?= $formValue("overview") ?></textarea>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="vehicleDescription">Description</label>
                        <textarea
                            class="form-control"
                            id="vehicleDescription"
                            name="description"
                            rows="4"
                            required><?= $formValue("description") ?></textarea>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="vehicleInclusions">
                            Inclusions—one per line
                        </label>
                        <textarea
                            class="form-control"
                            id="vehicleInclusions"
                            name="inclusions"
                            rows="7"
                            required><?= escape_html($inclusionsValue) ?></textarea>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="vehicleFeatures">
                            Features—one per line
                        </label>
                        <textarea
                            class="form-control"
                            id="vehicleFeatures"
                            name="features"
                            rows="7"
                            required><?= escape_html($featuresValue) ?></textarea>
                    </div>
                </div>

                <div class="form-check mt-3">
                    <input
                        class="form-check-input"
                        id="vehicleActive"
                        name="is_active"
                        type="checkbox"
                        value="1"
                        <?= $vehicleIsActive ? "checked" : "" ?>>
                    <label class="form-check-label" for="vehicleActive">
                        Active and bookable
                    </label>
                </div>

                <button class="btn btn-primary mt-4" type="submit">
                    <?= $editing ? "Save Vehicle" : "Create Vehicle" ?>
                </button>
            </form>
        </div>
    </div>
</section>

<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
