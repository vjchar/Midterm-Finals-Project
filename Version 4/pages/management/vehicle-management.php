<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";
require_admin();
require_once dirname(__DIR__, 2) . "/includes/vehicle-management-service.php";

$errors = [];
$editId =
    filter_input(INPUT_GET, "edit", FILTER_VALIDATE_INT) ?:
    (int) ($_POST["id"] ?? 0);
$editing = $editId ? vehicle_find($editId) : null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        $action = post_string("action", "save");
        if ($action === "delete") {
            require_csrf();
            $vehicleId = filter_var($_POST["vehicle_id"] ?? null, FILTER_VALIDATE_INT, [
                "options" => ["min_range" => 1],
            ]) ?: 0;
            if ($vehicleId < 1) {
                throw new VehicleValidationException("Choose a valid vehicle to delete.");
            }
            vehicle_delete(database(), $vehicleId);
            flash("success", "Vehicle deleted successfully.");
            redirect("vehicle-management.php");
        }
        if ($action !== "save") {
            throw new VehicleValidationException("Choose a valid vehicle action.");
        }
        $saveResult = vehicle_save_submission($_POST, $_FILES);
        flash(
            "success",
            $saveResult["created"] ? "Vehicle created." : "Vehicle updated.",
        );
        redirect("vehicle-management.php?edit=" . $saveResult["vehicle_id"]);
    } catch (VehicleValidationException $validationError) {
        $errors[] = $validationError->getMessage();
    } catch (Throwable $unexpectedError) {
        error_log(
            sprintf(
                "Vehicle save failed: %s: %s in %s:%d",
                $unexpectedError::class,
                $unexpectedError->getMessage(),
                $unexpectedError->getFile(),
                $unexpectedError->getLine(),
            ),
        );
        $errors[] = "The vehicle change could not be saved right now. Please try again.";
    }
}

$allVehicles = vehicle_all();
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
    $editing = vehicle_find($editId);
}

$formValue = static function (string $key, mixed $default = "") use ($editing): string {
    return escape_html($_POST[$key] ?? ($editing[$key] ?? $default));
};

$categoryChoices = ["Luxury", "SUV", "Pickup", "Van", "EV"];
$transmissionChoices = ["Automatic", "Manual"];
$fuelChoices = ["Gasoline", "Diesel", "Electric", "Hybrid"];
$previousVehiclePage = max(1, $vehiclePage - 1);
$nextVehiclePage = min($vehiclePageCount, $vehiclePage + 1);
$isFirstVehiclePage = $vehiclePage <= 1;
$isLastVehiclePage = $vehiclePage >= $vehiclePageCount;
$inclusionsValue = $_POST["inclusions"] ?? implode("\n", $editing["inclusions"] ?? []);
$featuresValue = $_POST["features"] ?? implode("\n", $editing["features"] ?? []);
$flashMessages = pull_flashes();

$pageTitle = "Vehicle Management | VJ Car Rental";
require dirname(__DIR__, 2) . "/includes/header.php";
?>
<section class="admin-page-heading">
    <div class="container">
        <div>
            <span class="section-kicker">Fleet content</span>
            <h1>Manage vehicles</h1>
            <p>
                Create and update vehicle records, images, pricing,
                specifications, inclusions, and descriptions.
            </p>
        </div>
        <a class="btn btn-primary" href="vehicle-management.php">Add Vehicle</a>
    </div>
</section>

<section class="content-section admin-section">
    <div class="container">
        <?php foreach ($flashMessages as $flashMessage): ?>
            <div class="alert alert-<?= escape_html($flashMessage["type"] === "success" ? "success" : "info") ?>" role="alert">
                <?= escape_html($flashMessage["message"] ?? "") ?>
            </div>
        <?php endforeach; ?>

        <div class="admin-split admin-split--vehicles">
            <div class="admin-list-stack">
                <div class="admin-table-wrap" aria-label="Vehicle records">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Vehicle</th>
                                <th>Category</th>
                                <th>Rate</th>
                                <th>Transmission</th>
                                <th><span class="visually-hidden">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($visibleVehicles as $item): ?>
                                <tr>
                                    <td>
                                        <strong><?= escape_html($item["name"]) ?></strong>
                                        <small><?= escape_html($item["slug"]) ?></small>
                                    </td>
                                    <td><?= escape_html($item["category"]) ?></td>
                                    <td><?= money($item["price"]) ?></td>
                                    <td><?= escape_html($item["transmission"]) ?></td>
                                    <td>
                                        <div class="admin-row-actions">
                                            <a class="btn btn-outline btn-sm" href="vehicle-details.php?vehicle=<?= urlencode($item["slug"]) ?>">View</a>
                                            <a class="btn btn-outline btn-sm" href="vehicle-management.php?edit=<?= (int) $item["id"] ?>">Edit</a>
                                            <form method="post">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="vehicle_id" value="<?= (int) $item["id"] ?>">
                                                <button class="btn btn-outline btn-sm" type="submit">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <nav class="admin-pagination" aria-label="Vehicle record pages">
                    <a class="<?= $isFirstVehiclePage ? "is-disabled" : "" ?>" href="vehicle-management.php?page=<?= $previousVehiclePage ?>" <?= $isFirstVehiclePage ? 'aria-disabled="true" tabindex="-1"' : "" ?>>
                        <i class="bi bi-arrow-left" aria-hidden="true"></i> Previous
                    </a>
                    <span>
                        Page <strong><?= $vehiclePage ?></strong> of <strong><?= $vehiclePageCount ?></strong>
                        <small><?= count($allVehicles) ?> vehicles</small>
                    </span>
                    <a class="<?= $isLastVehiclePage ? "is-disabled" : "" ?>" href="vehicle-management.php?page=<?= $nextVehiclePage ?>" <?= $isLastVehiclePage ? 'aria-disabled="true" tabindex="-1"' : "" ?>>
                        Next <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </a>
                </nav>
            </div>

            <form class="form admin-editor admin-editor--vehicle" method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int) ($editing["id"] ?? 0) ?>">

                <div class="admin-editor__heading">
                    <div>
                        <span class="section-kicker"><?= $editing ? "Edit vehicle" : "New vehicle" ?></span>
                        <h2><?= escape_html($editing["name"] ?? "Vehicle details") ?></h2>
                    </div>
                </div>

                <?php foreach ($errors as $error): ?>
                    <div class="alert alert-danger" role="alert"><?= escape_html($error) ?></div>
                <?php endforeach; ?>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="vehicleName">Name</label>
                        <input class="form-control" id="vehicleName" name="name" value="<?= $formValue("name") ?>" maxlength="180" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="vehicleSlug">URL slug</label>
                        <input class="form-control" id="vehicleSlug" name="slug" value="<?= $formValue("slug") ?>" maxlength="140" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="vehicleBrand">Brand</label>
                        <input class="form-control" id="vehicleBrand" name="brand" value="<?= $formValue("brand") ?>" maxlength="100" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="vehicleCategory">Category</label>
                        <select class="form-select" id="vehicleCategory" name="category" required>
                            <?php foreach ($categoryChoices as $choice): ?>
                                <option value="<?= escape_html($choice) ?>" <?= $formValue("category") === $choice ? "selected" : "" ?>><?= escape_html($choice) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="vehiclePrice">Daily rate</label>
                        <input class="form-control" id="vehiclePrice" name="price" type="number" min="500" max="100000" value="<?= $formValue("price", "5000") ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="vehicleDailyKm">Daily km</label>
                        <input class="form-control" id="vehicleDailyKm" name="daily_km" type="number" min="0" max="2000" value="<?= $formValue("daily_km", "200") ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="vehicleSeats">Seats</label>
                        <input class="form-control" id="vehicleSeats" name="seats" type="number" min="1" max="30" value="<?= $formValue("seats", "5") ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="vehicleDoors">Doors</label>
                        <input class="form-control" id="vehicleDoors" name="doors" type="number" min="1" max="8" value="<?= $formValue("doors", "4") ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="vehicleTransmission">Transmission</label>
                        <select class="form-select" id="vehicleTransmission" name="transmission" required>
                            <?php foreach ($transmissionChoices as $choice): ?>
                                <option value="<?= escape_html($choice) ?>" <?= $formValue("transmission", "Automatic") === $choice ? "selected" : "" ?>><?= escape_html($choice) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="vehicleFuel">Fuel</label>
                        <select class="form-select" id="vehicleFuel" name="fuel" required>
                            <?php foreach ($fuelChoices as $choice): ?>
                                <option value="<?= escape_html($choice) ?>" <?= $formValue("fuel", "Gasoline") === $choice ? "selected" : "" ?>><?= escape_html($choice) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="vehicleLuggage">Luggage guide</label>
                        <input class="form-control" id="vehicleLuggage" name="luggage" value="<?= $formValue("luggage", "3 bags") ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="vehicleImageFilename">Image filename</label>
                        <input class="form-control" id="vehicleImageFilename" name="image_filename" value="<?= $formValue("image") ?>" placeholder="vehicle-slug.png">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="vehicleImageUpload">Upload vehicle image</label>
                        <input class="form-control" id="vehicleImageUpload" name="vehicle_image" type="file" accept="image/png,image/jpeg,image/webp">
                        <small class="form-help">Use an existing filename from assets/images/cars or upload a new JPG, PNG, or WebP image.</small>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="vehicleOverviewTitle">Overview title</label>
                        <input class="form-control" id="vehicleOverviewTitle" name="overview_title" value="<?= $formValue("overview_title") ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="vehicleOverview">Overview</label>
                        <textarea class="form-control" id="vehicleOverview" name="overview" rows="4" required><?= $formValue("overview") ?></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="vehicleDescription">Description</label>
                        <textarea class="form-control" id="vehicleDescription" name="description" rows="4" required><?= $formValue("description") ?></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="vehicleInclusions">Inclusions</label>
                        <textarea class="form-control" id="vehicleInclusions" name="inclusions" rows="6" required><?= escape_html($inclusionsValue) ?></textarea>
                        <small class="form-help">Enter one inclusion per line.</small>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="vehicleFeatures">Features</label>
                        <textarea class="form-control" id="vehicleFeatures" name="features" rows="6" required><?= escape_html($featuresValue) ?></textarea>
                        <small class="form-help">Enter one feature per line.</small>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 mt-4">
                    <button class="btn btn-primary" type="submit"><?= $editing ? "Update Vehicle" : "Add Vehicle" ?></button>
                    <?php if ($editing): ?>
                        <a class="btn btn-outline" href="vehicle-management.php">Cancel Edit</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</section>

<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
