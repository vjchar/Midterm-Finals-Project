<?php

declare(strict_types=1);


/**
 * FILE: pages/booking/booking.php
 * FILE PURPOSE: Main customer booking form and booking-creation page.
 * USED BY: Customers progressing through booking, payment, rental, or post-trip workflows.
 * RESPONSIBILITY: Loads the required application/services, handles only page-level request orchestration, and renders the user interface; reusable business/database logic belongs in services.
 *
 * Maintenance note: Keep this file focused on the responsibility described above.
 */
require dirname(__DIR__, 2) . "/includes/bootstrap.php";

$authenticatedUser = require_customer();
$vehicles = vehicle_all();
$rentalAddOns = addon_all();
$draftId = max(0, (int) ($_POST["draft_id"] ?? ($_GET["draft"] ?? 0)));
$sourceDraftId = $draftId > 0 ? 0 : max(0, (int) ($_GET["source_draft"] ?? 0));
$activeDraft = null;
$sourceDraft = null;
$draftPreview = null;
$draftFormValues = [];
if ($draftId > 0) {
    $activeDraft = booking_draft_find_owned($draftId, (int) $authenticatedUser["id"], false);
    if (!$activeDraft) {
        flash("warning", "That saved booking is unavailable or has expired.");
        redirect("saved-bookings.php");
    }
    $draftPreview = booking_draft_preview($activeDraft);
    $draftFormValues = booking_draft_form_values($activeDraft);
} elseif ($sourceDraftId > 0) {
    $sourceDraft = booking_draft_find_owned($sourceDraftId, (int) $authenticatedUser["id"], true);
    if (!$sourceDraft) {
        flash("warning", "That saved booking could not be reused.");
        redirect("saved-bookings.php");
    }
    $draftPreview = booking_draft_preview($sourceDraft);
    $draftFormValues = booking_draft_form_values($sourceDraft);
}
if (($draftPreview["promo_warning"] ?? "") !== "") {
    $draftFormValues["promo"] = (string) ($draftPreview["effective_promo_code"] ?? "");
}
$selectedVehicleSlug = (string) ($_POST["vehicle"] ?? ($draftFormValues["vehicle"] ?? ($_GET["vehicle"] ?? "porsche-911")));
$pickupDateValue = (string) ($_POST["pickup"] ?? ($draftFormValues["pickup"] ?? ($_GET["pickup"] ?? "")));
$returnDateValue = (string) ($_POST["return"] ?? ($draftFormValues["return"] ?? ($_GET["return"] ?? "")));
$pickupTimeValue = (string) ($_POST["pickup_time"] ?? ($draftFormValues["pickup_time"] ?? "09:00"));
$returnTimeValue = (string) ($_POST["return_time"] ?? ($draftFormValues["return_time"] ?? "09:00"));
$selectedPickupMethod = (string) ($_POST["pickup_method"] ?? ($draftFormValues["pickup_method"] ?? "Branch pickup"));
$selectedLocation = (string) ($_POST["location"] ?? ($draftFormValues["location"] ?? ""));
$selectedAddOnKeys = is_array($_POST["addons"] ?? null)
    ? array_values(array_map("strval", $_POST["addons"]))
    : array_values(array_map("strval", $draftFormValues["addons"] ?? []));
$deliveryAddressValue = (string) ($_POST["delivery_address"] ?? ($draftFormValues["delivery_address"] ?? ""));
$promoCodeValue = (string) ($_POST["promo"] ?? ($draftFormValues["promo"] ?? ""));
$specialRequestsValue = (string) ($_POST["special_requests"] ?? ($draftFormValues["special_requests"] ?? ""));

if ($pickupDateValue !== "" && !valid_date($pickupDateValue)) {
    $pickupDateValue = "";
}
if ($returnDateValue !== "" && !valid_date($returnDateValue)) {
    $returnDateValue = "";
}

$selectedVehicle = vehicle_find($selectedVehicleSlug) ?? $vehicles[0];
$bookingValidationErrors = [];
$bookingEstimate = $draftPreview["details"] ?? null;
$estimateAvailabilityMessage = $activeDraft
    ? (($draftPreview["available"] ?? false)
        ? "Saved booking loaded. The vehicle is currently available; pricing has been recalculated."
        : (string) ($draftPreview["error"] ?? "Review this saved booking before continuing."))
    : "Enter complete trip details. Your estimate updates automatically.";
$estimateAvailabilityClass = $activeDraft
    ? (($draftPreview["available"] ?? false) ? "is-success" : "is-error")
    : "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        require_csrf();
        $requestedFormAction =
            (string) ($_POST["form_action"] ?? "create_booking");

        if ($requestedFormAction === "select_vehicle") {
            $estimateAvailabilityMessage =
                $selectedVehicle["name"] .
                " is now selected. Enter your schedule to check availability and calculate the total.";
            $estimateAvailabilityClass = "is-success";
        } else {
            $bookingEstimate = parse_booking_input($_POST);
            $selectedVehicle = $bookingEstimate["vehicle"];

            $vehicleIsAvailable = vehicle_available(
                (int) $selectedVehicle["id"],
                (string) $bookingEstimate["pickup_at"],
                (string) $bookingEstimate["return_at"],
            );
            if (!$vehicleIsAvailable) {
                throw new RuntimeException(
                    "This vehicle is already reserved during part of the selected schedule.",
                );
            }

            if ($requestedFormAction === "preview") {
                $estimateAvailabilityMessage =
                    "Available for the selected schedule. Your estimate has been updated.";
                $estimateAvailabilityClass = "is-success";
            } elseif ($requestedFormAction === "save_draft") {
                $savedDraft = save_booking_draft(
                    (int) $authenticatedUser["id"],
                    $_POST,
                    $draftId > 0 ? $draftId : null,
                );
                flash(
                    "success",
                    "Booking saved for later. The vehicle is not reserved until you proceed with the booking.",
                );
                redirect("saved-bookings.php?draft=" . (int) $savedDraft["id"] . "&saved=1");
            } else {
                if (!isset($_POST["terms"])) {
                    throw new InvalidArgumentException(
                        "Review and accept the rental terms before continuing.",
                    );
                }

                if ($draftId > 0) {
                    $savedDraft = save_booking_draft(
                        (int) $authenticatedUser["id"],
                        $_POST,
                        $draftId,
                    );
                    $createdBooking = convert_booking_draft(
                        (int) $savedDraft["id"],
                        (int) $authenticatedUser["id"],
                    );
                } else {
                    $createdBooking = create_booking(
                        (int) $authenticatedUser["id"],
                        $_POST,
                    );
                }
                flash(
                    "success",
                    "Booking created. Continue with the next unfinished step shown below.",
                );
                redirect(
                    "booking-confirmation.php?reference=" .
                        urlencode((string) $createdBooking["reference"]),
                );
            }
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
$estimatedAddOnTotal = (int) ($bookingEstimate["addons_total"] ?? 0);
$estimatedDeliveryFee = (int) ($bookingEstimate["delivery_fee"] ?? 0);
$estimatedDiscount = (int) ($bookingEstimate["discount"] ?? 0);
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
    "Create, save, resume, or confirm a VJ Car Rental booking with current availability and pricing.";
$pageScripts = ["assets/js/booking-vehicle-preview.js"];

require dirname(__DIR__, 2) . "/includes/header.php";
?>
<section class="page-hero page-hero--compact pattern-layer">
    <div class="container">
        <span class="section-kicker">Secure reservation</span>
        <h1>Build your rental</h1>
        <p>
            Choose your vehicle, dates, pickup method, and extras. PHP verifies
            availability and current pricing before you proceed or save the plan for later.
        </p>
    </div>
</section>

<section class="content-section booking-section">
    <div class="container">
        <div class="booking-progress" aria-label="Booking steps">
            <div class="active"><span>1</span><strong>Build rental</strong></div>
            <i></i>
            <div><span>2</span><strong>Documents &amp; deposit</strong></div>
            <i></i>
            <div><span>3</span><strong>Pickup &amp; trip</strong></div>
            <i></i>
            <div><span>4</span><strong>Return &amp; review</strong></div>
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
                    <?php if ($draftId > 0): ?>
                        <input type="hidden" name="draft_id" value="<?= (int) $draftId ?>">
                    <?php endif; ?>

                    <?php foreach (
                        $bookingValidationErrors
                        as $validationError
                    ): ?>
                        <div class="alert alert-danger" role="alert">
                            <?= escape_html($validationError) ?>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($activeDraft || $sourceDraft): ?>
                        <div class="alert alert-info">
                            <?php if ($activeDraft): ?>
                                <strong>Saved booking <?= escape_html((string) $activeDraft["reference"]) ?> loaded.</strong>
                            <?php else: ?>
                                <strong>Previous draft details loaded as a new booking plan.</strong>
                            <?php endif; ?>
                            Saving a draft does not reserve the vehicle. Availability and pricing are checked again before the booking is created.
                        </div>
                        <?php if (($draftPreview["promo_warning"] ?? "") !== ""): ?>
                            <div class="alert alert-warning"><?= escape_html((string) $draftPreview["promo_warning"]) ?></div>
                        <?php endif; ?>
                        <?php if (($draftPreview["details"] ?? null) && (int) $draftPreview["difference"] !== 0): ?>
                            <div class="alert alert-warning">
                                <strong>Price updated.</strong>
                                Saved estimate: <?= money((int) $draftPreview["saved_total"]) ?> ·
                                Current total: <?= money((int) $draftPreview["current_total"]) ?> ·
                                Difference: <?= ((int) $draftPreview["difference"] > 0 ? "+" : "−") . money(abs((int) $draftPreview["difference"])) ?>.
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <div class="form-heading">
                        <span class="section-kicker">Trip and vehicle</span>
                        <h2>Choose your exact schedule</h2>
                        <p>
                            Your account information is used for this reservation.
                            You can update it from <a href="profile.php">My Profile</a>.
                        </p>
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
                                        ROOT .
                                            "/" .
                                            $vehicleOptionImageRelativePath,
                                    )
                                        ? $vehicleOptionImageRelativePath
                                        : "assets/images/placeholders/vehicle-placeholder.svg";
                                    $vehicleOptionRating = $vehicleOption[
                                        "review_count"
                                    ]
                                        ? number_format(
                                            (float) $vehicleOption["rating"],
                                            1,
                                        )
                                        : "New";
                                    ?>
                                    <option
                                        value="<?= escape_html(
                                            $vehicleOption["slug"],
                                        ) ?>"
                                        data-name="<?= escape_html(
                                            $vehicleOption["name"],
                                        ) ?>"
                                        data-image="<?= escape_html(
                                            $vehicleOptionImageSource,
                                        ) ?>"
                                        data-category="<?= escape_html(
                                            $vehicleOption["category"],
                                        ) ?>"
                                        data-rating="<?= escape_html(
                                            $vehicleOptionRating,
                                        ) ?>"
                                        data-seats="<?= (int) $vehicleOption[
                                            "seats"
                                        ] ?> seats"
                                        data-transmission="<?= escape_html(
                                            $vehicleOption["transmission"],
                                        ) ?>"
                                        data-fuel="<?= escape_html(
                                            $vehicleOption["fuel"],
                                        ) ?>"
                                        data-price="<?= escape_html(
                                            money((int) $vehicleOption["price"]),
                                        ) ?>"
                                        data-deposit="<?= escape_html(
                                            money(
                                                (int) $vehicleOption["deposit"],
                                            ),
                                        ) ?>"
                                        data-details-url="vehicle-details.php?vehicle=<?= urlencode(
                                            $vehicleOption["slug"],
                                        ) ?>"
                                        <?= $vehicleOption["slug"] ===
                                        $selectedVehicle["slug"]
                                            ? "selected"
                                            : "" ?>
                                    >
                                        <?= escape_html($vehicleOption["name"]) ?> —
                                        <?= money(
                                            (int) $vehicleOption["price"],
                                        ) ?>/day
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text">
                                The photo, vehicle information, availability, and complete rental estimate update automatically.
                            </small>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="pickupDate">Pick-up date</label>
                            <input
                                class="form-control"
                                id="pickupDate"
                                name="pickup"
                                type="date"
                                min="<?= date("Y-m-d") ?>"
                                value="<?= escape_html($pickupDateValue) ?>"
                                required
                            >
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="pickupTime">Pick-up time</label>
                            <input
                                class="form-control"
                                id="pickupTime"
                                name="pickup_time"
                                type="time"
                                value="<?= escape_html($pickupTimeValue) ?>"
                                required
                            >
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="returnDate">Return date</label>
                            <input
                                class="form-control"
                                id="returnDate"
                                name="return"
                                type="date"
                                min="<?= escape_html(
                                    $pickupDateValue !== ""
                                        ? $pickupDateValue
                                        : date("Y-m-d"),
                                ) ?>"
                                value="<?= escape_html($returnDateValue) ?>"
                                required
                            >
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="returnTime">Return time</label>
                            <input
                                class="form-control"
                                id="returnTime"
                                name="return_time"
                                type="time"
                                value="<?= escape_html($returnTimeValue) ?>"
                                required
                            >
                        </div>
                    </div>

                    <div id="bookingVehicleMessage" class="availability-inline <?= escape_html(
                        $estimateAvailabilityClass,
                    ) ?>" role="status">
                        <i class="bi bi-calendar-check"></i>
                        <span><?= escape_html($estimateAvailabilityMessage) ?></span>
                    </div>

                    <hr>
                    <div class="form-heading form-heading--section">
                        <span class="section-kicker">Pickup and return</span>
                        <h2>How will you receive the car?</h2>
                    </div>

                    <div class="pickup-methods">
                        <label>
                            <input
                                type="radio"
                                name="pickup_method"
                                value="Branch pickup"
                                <?= $selectedPickupMethod === "Branch pickup"
                                    ? "checked"
                                    : "" ?>
                            >
                            <span>
                                <i class="bi bi-building"></i>
                                <strong>Branch pickup</strong>
                                <small>No delivery fee</small>
                            </span>
                        </label>
                        <label>
                            <input
                                type="radio"
                                name="pickup_method"
                                value="Vehicle delivery"
                                <?= $selectedPickupMethod === "Vehicle delivery"
                                    ? "checked"
                                    : "" ?>
                            >
                            <span>
                                <i class="bi bi-geo-alt"></i>
                                <strong>Vehicle delivery</strong>
                                <small>₱800 service fee</small>
                            </span>
                        </label>
                    </div>

                    <div class="row g-3 mt-1">
                        <div class="col-md-6">
                            <label class="form-label" for="pickupLocation">Service branch</label>
                            <select class="form-select" id="pickupLocation" name="location" required>
                                <option value="">Select a location</option>
                                <?php foreach (
                                    booking_locations()
                                    as $bookingLocation
                                ): ?>
                                    <option
                                        value="<?= escape_html($bookingLocation) ?>"
                                        <?= $selectedLocation ===
                                        $bookingLocation
                                            ? "selected"
                                            : "" ?>
                                    ><?= escape_html($bookingLocation) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="deliveryAddress">
                                Delivery address
                                <span class="optional-label">(delivery only)</span>
                            </label>
                            <input
                                class="form-control"
                                id="deliveryAddress"
                                name="delivery_address"
                                value="<?= escape_html(
                                    $deliveryAddressValue,
                                ) ?>"
                                maxlength="255"
                                placeholder="Street, barangay, city"
                            >
                        </div>
                    </div>

                    <hr>
                    <div class="form-heading form-heading--section">
                        <span class="section-kicker">Optional extras</span>
                        <h2>Customize your trip</h2>
                    </div>

                    <div class="addon-grid">
                        <?php foreach ($rentalAddOns as $rentalAddOn): ?>
                            <label class="addon-option">
                                <input
                                    type="checkbox"
                                    name="addons[]"
                                    value="<?= escape_html($rentalAddOn["key"]) ?>"
                                    <?= in_array(
                                        $rentalAddOn["key"],
                                        $selectedAddOnKeys,
                                        true,
                                    )
                                        ? "checked"
                                        : "" ?>
                                >
                                <span>
                                    <i class="bi <?= escape_html(
                                        $rentalAddOn["icon"],
                                    ) ?>"></i>
                                    <strong><?= escape_html(
                                        $rentalAddOn["name"],
                                    ) ?></strong>
                                    <small>
                                        <?= money(
                                            (int) $rentalAddOn["price"],
                                        ) ?> /
                                        <?= escape_html($rentalAddOn["billing"]) ?>
                                    </small>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="promo-box">
                        <label class="form-label" for="promoCode">Promotion code</label>
                        <input
                            class="form-control"
                            id="promoCode"
                            name="promo"
                            value="<?= escape_html(
                                $promoCodeValue,
                            ) ?>"
                            maxlength="40"
                            placeholder="Enter a valid code"
                        >
                        <small>
                            PHP checks the code when you update the estimate or create the booking.
                        </small>
                    </div>

                    <label class="form-label mt-4" for="specialRequests">Special requests</label>
                    <textarea
                        class="form-control"
                        id="specialRequests"
                        name="special_requests"
                        rows="4"
                        maxlength="2000"
                        placeholder="Accessibility request, delivery note, or trip information"
                    ><?= escape_html(
                        $specialRequestsValue,
                    ) ?></textarea>

                    <div class="requirements-checklist">
                        <h3><i class="bi bi-person-vcard"></i> Prepare before pickup</h3>
                        <ul>
                            <li>Valid driver’s license</li>
                            <li>Government-issued ID</li>
                            <li>Refundable security deposit</li>
                            <li>Signed rental agreement</li>
                        </ul>
                        <a href="faq.php#requirements">View complete requirements</a>
                    </div>

                    <div class="form-check mt-4">
                        <input
                            class="form-check-input"
                            id="bookingTerms"
                            name="terms"
                            type="checkbox"
                            value="1"
                            required
                            <?= isset($_POST["terms"]) ? "checked" : "" ?>
                        >
                        <label class="form-check-label" for="bookingTerms">
                            I reviewed the <a href="terms.php">rental terms</a>, pricing,
                            and selected inclusions.
                        </label>
                    </div>

                    <div class="booking-decision-card">
                        <div>
                            <span class="section-kicker">Ready to book?</span>
                            <h3>Proceed now or save your choices for later.</h3>
                            <p>A saved booking is only a draft. It does not reserve the vehicle or lock the current price.</p>
                        </div>
                        <div class="booking-form-actions">
                            <button
                                class="btn btn-outline btn-lg"
                                id="bookingEstimateButton"
                                type="submit"
                                name="form_action"
                                value="preview"
                                formnovalidate
                            >
                                Recalculate estimate <i class="bi bi-calculator"></i>
                            </button>
                            <button
                                class="btn btn-outline btn-lg"
                                type="submit"
                                name="form_action"
                                value="save_draft"
                                formnovalidate
                            >
                                <?= $draftId > 0 ? "Update Saved Booking" : "Save for Later" ?> <i class="bi bi-bookmark"></i>
                            </button>
                            <button
                                class="btn btn-primary btn-lg"
                                type="submit"
                                name="form_action"
                                value="create_booking"
                            >
                                Proceed with Booking <i class="bi bi-arrow-right"></i>
                            </button>
                        </div>
                    </div>
                    <small class="display-note">
                        <i class="bi bi-shield-lock"></i>
                        PHP recalculates all prices and checks reservation conflicts before saving.
                    </small>
                </form>
            </div>

            <div class="col-lg-5">
                <aside
                    class="booking-summary-card sticky-lg-top"
                    data-booking-vehicle-preview
                    aria-live="polite">
                    <span class="section-kicker">Booking estimate</span>
                    <img
                        id="bookingVehiclePreviewImage"
                        src="<?= escape_html($selectedVehicleImageSource) ?>"
                        alt="<?= escape_html($selectedVehicle["name"]) ?>"
                    >
                    <h2>
                        <a id="bookingVehiclePreviewLink" href="vehicle-details.php?vehicle=<?= urlencode(
                            $selectedVehicle["slug"],
                        ) ?>">
                            <?= escape_html($selectedVehicle["name"]) ?>
                        </a>
                    </h2>
                    <div class="summary-category-row">
                        <span id="bookingVehiclePreviewCategory" class="summary-category"><?= escape_html(
                            $selectedVehicle["category"],
                        ) ?></span>
                        <span class="summary-rating">
                            <i class="bi bi-star-fill"></i>
                            <b id="bookingVehiclePreviewRating">
                                <?= $selectedVehicle["review_count"]
                                    ? number_format(
                                        (float) $selectedVehicle["rating"],
                                        1,
                                    )
                                    : "New" ?>
                            </b>
                        </span>
                    </div>
                    <div class="summary-specs">
                        <span><i class="bi bi-people"></i><b id="bookingVehiclePreviewSeats"><?= (int) $selectedVehicle[
                            "seats"
                        ] ?> seats</b></span>
                        <span><i class="bi bi-gear"></i><b id="bookingVehiclePreviewTransmission"><?= escape_html(
                            $selectedVehicle["transmission"],
                        ) ?></b></span>
                        <span><i class="bi bi-fuel-pump"></i><b id="bookingVehiclePreviewFuel"><?= escape_html(
                            $selectedVehicle["fuel"],
                        ) ?></b></span>
                    </div>

                    <hr>
                    <div class="summary-line">
                        <span>Daily rental</span>
                        <strong id="bookingVehiclePreviewPrice"><?= money(
                            (int) $selectedVehicle["price"],
                        ) ?></strong>
                    </div>
                    <div class="summary-line">
                        <span>Rental days</span>
                        <strong id="bookingVehiclePreviewDays">
                            <?= $estimatedRentalDays ?>
                        </strong>
                    </div>
                    <div class="summary-line">
                        <span>Rental subtotal</span>
                        <strong id="bookingVehiclePreviewSubtotal"><?= money(
                            $estimatedRentalSubtotal,
                        ) ?></strong>
                    </div>
                    <div class="summary-line">
                        <span>Add-ons</span>
                        <strong id="bookingVehiclePreviewAddons">
                            <?= money($estimatedAddOnTotal) ?>
                        </strong>
                    </div>
                    <div class="summary-line">
                        <span>Delivery</span>
                        <strong id="bookingVehiclePreviewDelivery">
                            <?= money($estimatedDeliveryFee) ?>
                        </strong>
                    </div>
                    <div
                        class="summary-line summary-line--discount"
                        id="bookingVehiclePreviewPromotionRow"
                        <?= $estimatedDiscount > 0 ? "" : "hidden" ?>>
                        <span>Promotion</span>
                        <strong id="bookingVehiclePreviewDiscount">
                            −<?= money($estimatedDiscount) ?>
                        </strong>
                    </div>
                    <div class="summary-line">
                        <span>Refundable deposit</span>
                        <strong id="bookingVehiclePreviewDeposit"><?= money(
                            (int) $selectedVehicle["deposit"],
                        ) ?></strong>
                    </div>
                    <div class="summary-total">
                        <span>Estimated rental total</span>
                        <strong id="bookingVehiclePreviewTotal"><?= money(
                            $estimatedRentalTotal,
                        ) ?></strong>
                    </div>
                    <small class="summary-deposit-note">
                        The security deposit is separate from the rental total.
                    </small>

                    <div class="customer-summary">
                        <span><small>Booking for</small><strong><?= escape_html(
                            $authenticatedUser["name"],
                        ) ?></strong></span>
                        <span><small>Email</small><strong><?= escape_html(
                            $authenticatedUser["email"],
                        ) ?></strong></span>
                    </div>
                    <a
                        id="bookingVehiclePreviewDetailsLink"
                        class="summary-details-link"
                        href="vehicle-details.php?vehicle=<?= urlencode(
                            $selectedVehicle["slug"],
                        ) ?>"
                    >
                        View vehicle details <i class="bi bi-arrow-up-right"></i>
                    </a>
                </aside>
            </div>
        </div>
    </div>
</section>

<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
