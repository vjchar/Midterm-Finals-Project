<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";

$authenticatedUser = require_auth();
$vehicles = vehicle_all();
if (!$vehicles) {
    flash("danger", "No vehicles are available for booking.");
    redirect("vehicles.php");
}

$selectedVehicleSlug =
    (string) ($_POST["vehicle"] ?? ($_GET["vehicle"] ?? $vehicles[0]["slug"]));
$pickupDateValue = (string) ($_POST["pickup"] ?? ($_GET["pickup"] ?? ""));
$returnDateValue = (string) ($_POST["return"] ?? ($_GET["return"] ?? ""));
$pickupTimeValue = (string) ($_POST["pickup_time"] ?? "09:00");
$returnTimeValue = (string) ($_POST["return_time"] ?? "09:00");
$selectedLocation = (string) ($_POST["location"] ?? "");

if ($pickupDateValue !== "" && !valid_date($pickupDateValue)) {
    $pickupDateValue = "";
}
if ($returnDateValue !== "" && !valid_date($returnDateValue)) {
    $returnDateValue = "";
}

$selectedVehicle = vehicle_find($selectedVehicleSlug) ?? $vehicles[0];
$bookingValidationErrors = [];
$bookingEstimate = null;
$estimateAvailabilityMessage =
    "Enter complete trip details. Your estimate updates automatically.";
$estimateAvailabilityClass = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        require_csrf();
        $requestedFormAction =
            (string) ($_POST["form_action"] ?? "create_booking");

        $bookingEstimate = parse_booking_input($_POST);
        $selectedVehicle = $bookingEstimate["vehicle"];

        if (
            !vehicle_available(
                (int) $selectedVehicle["id"],
                (string) $bookingEstimate["pickup_at"],
                (string) $bookingEstimate["return_at"],
            )
        ) {
            throw new RuntimeException(
                "This vehicle is already reserved during part of the selected schedule.",
            );
        }

        if ($requestedFormAction === "preview") {
            $estimateAvailabilityMessage =
                "Available for the selected schedule. This estimate was calculated by PHP on the server.";
            $estimateAvailabilityClass = "is-success";
        } else {
            if (!isset($_POST["terms"])) {
                throw new InvalidArgumentException(
                    "Review and accept the rental terms before continuing.",
                );
            }

            $createdBooking = create_booking(
                (int) $authenticatedUser["id"],
                $_POST,
            );
            flash("success", "Booking created successfully.");
            redirect(
                "booking-confirmation.php?reference=" .
                    urlencode((string) $createdBooking["reference"]),
            );
        }
    } catch (Throwable $exception) {
        $bookingValidationErrors[] = user_facing_error_message($exception);
        $estimateAvailabilityMessage = user_facing_error_message($exception);
        $estimateAvailabilityClass = "is-error";
    }
}

$estimatedRentalDays = (int) ($bookingEstimate["days"] ?? 1);
$estimatedRentalSubtotal =
    (int) ($bookingEstimate["subtotal"] ?? $selectedVehicle["price"]);
$estimatedRentalTotal =
    (int) ($bookingEstimate["total"] ?? $selectedVehicle["price"]);

$selectedVehicleImageFilename = basename((string) $selectedVehicle["image"]);
$selectedVehicleImageRelativePath =
    "assets/images/cars/" . $selectedVehicleImageFilename;
$selectedVehicleImageSource = is_file(
    dirname(__DIR__, 2) . "/" . $selectedVehicleImageRelativePath,
)
    ? $selectedVehicleImageRelativePath
    : "assets/images/placeholders/vehicle-placeholder.svg";

$pageTitle = "Book a Car | VJ Car Rental";
$pageDescription =
    "Create a VJ Car Rental reservation with server-verified availability and pricing.";
$pageScripts = ["assets/js/booking-vehicle-preview.js"];

require dirname(__DIR__, 2) . "/includes/header.php";
?>
<section class="page-hero page-hero--compact pattern-layer">
    <div class="container">
        <span class="section-kicker">Secure reservation</span>
        <h1>Build your rental</h1>
        <p>
            Choose your vehicle, dates, and branch. PHP verifies availability
            and final pricing before the reservation is saved.
        </p>
    </div>
</section>

<section class="content-section booking-section">
    <div class="container">
        <div class="booking-progress" aria-label="Booking steps">
            <div class="active"><span>1</span><strong>Build rental</strong></div>
            <i></i>
            <div><span>2</span><strong>Confirm booking</strong></div>
            <i></i>
            <div><span>3</span><strong>Track reservation</strong></div>
        </div>

        <div class="row g-4 g-xl-5 align-items-start">
            <div class="col-lg-7">
                <form
                    class="form booking-form"
                    id="bookingForm"
                    method="post"
                    data-estimate-url="booking-estimate.php"
                    novalidate>
                    <?= csrf_field() ?>

                    <?php foreach ($bookingValidationErrors as $validationError): ?>
                        <div class="alert alert-danger" role="alert">
                            <?= escape_html($validationError) ?>
                        </div>
                    <?php endforeach; ?>

                    <div class="form-heading">
                        <span class="section-kicker">Trip and vehicle</span>
                        <h2>Choose your exact schedule</h2>
                        <p>Your signed-in account will own this reservation.</p>
                    </div>

                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label" for="vehicleChoice">Vehicle</label>
                            <select class="form-select" id="vehicleChoice" name="vehicle" required>
                                <?php foreach ($vehicles as $vehicleOption): ?>
                                    <?php
                                    $vehicleOptionImageRelativePath =
                                        "assets/images/cars/" .
                                        basename((string) $vehicleOption["image"]);
                                    $vehicleOptionImageSource = is_file(
                                        ROOT . "/" . $vehicleOptionImageRelativePath,
                                    )
                                        ? $vehicleOptionImageRelativePath
                                        : "assets/images/placeholders/vehicle-placeholder.svg";
                                    ?>
                                    <option
                                        value="<?= escape_html($vehicleOption["slug"]) ?>"
                                        data-name="<?= escape_html($vehicleOption["name"]) ?>"
                                        data-image="<?= escape_html($vehicleOptionImageSource) ?>"
                                        data-category="<?= escape_html($vehicleOption["category"]) ?>"
                                        data-rating="Catalog"
                                        data-seats="<?= (int) $vehicleOption["seats"] ?> seats"
                                        data-transmission="<?= escape_html($vehicleOption["transmission"]) ?>"
                                        data-fuel="<?= escape_html($vehicleOption["fuel"]) ?>"
                                        data-price="<?= escape_html(money((int) $vehicleOption["price"])) ?>"
                                        data-deposit=""
                                        data-details-url="vehicle-details.php?vehicle=<?= urlencode($vehicleOption["slug"]) ?>"
                                        <?= $vehicleOption["slug"] === $selectedVehicle["slug"] ? "selected" : "" ?>>
                                        <?= escape_html($vehicleOption["name"]) ?> —
                                        <?= money((int) $vehicleOption["price"]) ?>/day
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text">
                                The vehicle information, availability, and rental estimate update automatically.
                            </small>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="pickupDate">Pick-up date</label>
                            <input class="form-control" id="pickupDate" name="pickup" type="date"
                                   min="<?= date("Y-m-d") ?>"
                                   value="<?= escape_html($pickupDateValue) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="pickupTime">Pick-up time</label>
                            <input class="form-control" id="pickupTime" name="pickup_time" type="time"
                                   value="<?= escape_html($pickupTimeValue) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="returnDate">Return date</label>
                            <input class="form-control" id="returnDate" name="return" type="date"
                                   min="<?= escape_html($pickupDateValue !== "" ? $pickupDateValue : date("Y-m-d")) ?>"
                                   value="<?= escape_html($returnDateValue) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="returnTime">Return time</label>
                            <input class="form-control" id="returnTime" name="return_time" type="time"
                                   value="<?= escape_html($returnTimeValue) ?>" required>
                        </div>
                    </div>

                    <div id="bookingVehicleMessage" class="availability-inline <?= escape_html($estimateAvailabilityClass) ?>" role="status">
                        <i class="bi bi-calendar-check"></i>
                        <span><?= escape_html($estimateAvailabilityMessage) ?></span>
                    </div>

                    <hr>
                    <div class="form-heading form-heading--section">
                        <span class="section-kicker">Pickup and return</span>
                        <h2>Choose your service branch</h2>
                    </div>

                    <input type="hidden" name="pickup_method" value="Branch pickup">

                    <div class="row g-3 mt-1">
                        <div class="col-12">
                            <label class="form-label" for="pickupLocation">Service branch</label>
                            <select class="form-select" id="pickupLocation" name="location" required>
                                <option value="">Select a location</option>
                                <?php foreach (booking_locations() as $bookingLocation): ?>
                                    <option value="<?= escape_html($bookingLocation) ?>"
                                        <?= $selectedLocation === $bookingLocation ? "selected" : "" ?>>
                                        <?= escape_html($bookingLocation) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <label class="form-label mt-4" for="specialRequests">Special requests</label>
                    <textarea class="form-control" id="specialRequests" name="special_requests"
                              rows="4" maxlength="2000"
                              placeholder="Trip information or accessibility request"><?= escape_html(
                                  (string) ($_POST["special_requests"] ?? ""),
                              ) ?></textarea>

                    <div class="form-check mt-4">
                        <input class="form-check-input" id="bookingTerms" name="terms"
                               type="checkbox" value="1" required
                               <?= isset($_POST["terms"]) ? "checked" : "" ?>>
                        <label class="form-check-label" for="bookingTerms">
                            I reviewed the selected vehicle, schedule, and rental total.
                        </label>
                    </div>

                    <div class="booking-form-actions">
                        <button class="btn btn-outline btn-lg"
                                id="bookingEstimateButton"
                                type="submit" name="form_action" value="preview" formnovalidate>
                            Recalculate estimate <i class="bi bi-calculator"></i>
                        </button>
                        <button class="btn btn-primary btn-lg"
                                type="submit" name="form_action" value="create_booking">
                            Create booking <i class="bi bi-arrow-right"></i>
                        </button>
                    </div>
                    <small class="display-note">
                        <i class="bi bi-shield-lock"></i>
                        PHP recalculates the price and checks reservation conflicts before saving.
                    </small>
                </form>
            </div>

            <div class="col-lg-5">
                <aside class="booking-summary-card sticky-lg-top"
                       data-booking-vehicle-preview aria-live="polite">
                    <span class="section-kicker">Server estimate</span>
                    <img id="bookingVehiclePreviewImage"
                         src="<?= escape_html($selectedVehicleImageSource) ?>"
                         alt="<?= escape_html($selectedVehicle["name"]) ?>">
                    <h2>
                        <a id="bookingVehiclePreviewLink"
                           href="vehicle-details.php?vehicle=<?= urlencode($selectedVehicle["slug"]) ?>">
                            <?= escape_html($selectedVehicle["name"]) ?>
                        </a>
                    </h2>
                    <div class="summary-category-row">
                        <span id="bookingVehiclePreviewCategory" class="summary-category">
                            <?= escape_html($selectedVehicle["category"]) ?>
                        </span>
                    </div>
                    <div class="summary-specs">
                        <span><i class="bi bi-people"></i><b id="bookingVehiclePreviewSeats"><?= (int) $selectedVehicle["seats"] ?> seats</b></span>
                        <span><i class="bi bi-gear"></i><b id="bookingVehiclePreviewTransmission"><?= escape_html($selectedVehicle["transmission"]) ?></b></span>
                        <span><i class="bi bi-fuel-pump"></i><b id="bookingVehiclePreviewFuel"><?= escape_html($selectedVehicle["fuel"]) ?></b></span>
                    </div>

                    <hr>
                    <div class="summary-line">
                        <span>Daily rental</span>
                        <strong id="bookingVehiclePreviewPrice"><?= money((int) $selectedVehicle["price"]) ?></strong>
                    </div>
                    <div class="summary-line">
                        <span>Rental days</span>
                        <strong id="bookingVehiclePreviewDays"><?= $estimatedRentalDays ?></strong>
                    </div>
                    <div class="summary-line">
                        <span>Rental subtotal</span>
                        <strong id="bookingVehiclePreviewSubtotal"><?= money($estimatedRentalSubtotal) ?></strong>
                    </div>
                    <div class="summary-total">
                        <span>Estimated rental total</span>
                        <strong id="bookingVehiclePreviewTotal"><?= money($estimatedRentalTotal) ?></strong>
                    </div>

                    <div class="customer-summary">
                        <span><small>Booking for</small><strong><?= escape_html($authenticatedUser["name"]) ?></strong></span>
                        <span><small>Email</small><strong><?= escape_html($authenticatedUser["email"]) ?></strong></span>
                    </div>
                    <a id="bookingVehiclePreviewDetailsLink"
                       class="summary-details-link"
                       href="vehicle-details.php?vehicle=<?= urlencode($selectedVehicle["slug"]) ?>">
                        View vehicle details <i class="bi bi-arrow-up-right"></i>
                    </a>
                </aside>
            </div>
        </div>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
