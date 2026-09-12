<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";
require_once dirname(__DIR__, 2) . "/includes/components/vehicle-card.php";

$vehicles = vehicle_all();
$requestedVehicle = trim((string) ($_GET["vehicle"] ?? ""));
$selectedVehicle = $requestedVehicle !== "" ? vehicle_find($requestedVehicle) : null;

if (!$selectedVehicle) {
    header("Location: vehicles.php");
    exit();
}

$selectedVehicleImageFilename = basename((string) $selectedVehicle["image"]);
$selectedVehicleImageRelativePath =
    "assets/images/cars/" . $selectedVehicleImageFilename;
$selectedVehicleImageSource = is_file(
    ROOT . "/" . $selectedVehicleImageRelativePath,
)
    ? $selectedVehicleImageRelativePath
    : "assets/images/placeholders/vehicle-placeholder.svg";

$relatedVehicles = array_slice(
    array_values(
        array_filter(
            $vehicles,
            static fn(array $catalogVehicle): bool => $catalogVehicle[
                "category"
            ] === $selectedVehicle["category"] &&
                $catalogVehicle["slug"] !== $selectedVehicle["slug"],
        ),
    ),
    0,
    3,
);

$availabilityPickupDate = trim((string) ($_GET["pickup"] ?? ""));
$availabilityReturnDate = trim((string) ($_GET["return"] ?? ""));
$availabilityMessage = "";
$availabilityClass = "";
$vehicleIsAvailableForDates = null;

if ($availabilityPickupDate !== "" || $availabilityReturnDate !== "") {
    try {
        if (
            !valid_date($availabilityPickupDate) ||
            !valid_date($availabilityReturnDate)
        ) {
            throw new InvalidArgumentException(
                "Choose valid pick-up and return dates.",
            );
        }

        $availabilityPickupAt = new DateTimeImmutable(
            $availabilityPickupDate . " 09:00:00",
        );
        $availabilityReturnAt = new DateTimeImmutable(
            $availabilityReturnDate . " 09:00:00",
        );

        if ($availabilityPickupAt < new DateTimeImmutable("today")) {
            throw new InvalidArgumentException("Pick-up must be in the future.");
        }
        if ($availabilityReturnAt <= $availabilityPickupAt) {
            throw new InvalidArgumentException(
                "Return must be later than pick-up.",
            );
        }

        $vehicleIsAvailableForDates = vehicle_available(
            (int) $selectedVehicle["id"],
            $availabilityPickupAt->format("Y-m-d H:i:s"),
            $availabilityReturnAt->format("Y-m-d H:i:s"),
        );
        $availabilityMessage = $vehicleIsAvailableForDates
            ? "Available for the selected period."
            : "Already reserved during part of the selected period.";
        $availabilityClass = $vehicleIsAvailableForDates
            ? "is-success"
            : "is-error";
    } catch (Throwable $availabilityError) {
        $availabilityMessage = user_facing_error_message($availabilityError);
        $availabilityClass = "is-error";
    }
}

$pageTitle = $selectedVehicle["name"] . " Details | VJ Car Rental";
$pageDescription = $selectedVehicle["overview"];
require dirname(__DIR__, 2) . "/includes/header.php";
?>

<section class="page-hero page-hero--compact pattern-layer">
    <div class="container">
        <nav aria-label="Breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                <li class="breadcrumb-item"><a href="vehicles.php">Vehicles</a></li>
                <li class="breadcrumb-item active" aria-current="page"><?= escape_html(
                    $selectedVehicle["name"],
                ) ?></li>
            </ol>
        </nav>
        <span class="section-kicker">Vehicle profile</span>
        <h1><?= escape_html($selectedVehicle["name"]) ?></h1>
    </div>
</section>

<section class="content-section vehicle-detail-section">
    <div class="container">
        <div class="row g-4 g-xl-5 align-items-start">
            <div class="col-lg-7">
                <div class="detail-visual pattern-layer">
                    <div class="detail-gallery-frame">
                        <img
                            src="<?= escape_html($selectedVehicleImageSource) ?>"
                            alt="<?= escape_html(
                                $selectedVehicle["name"],
                            ) ?> exterior">
                    </div>
                </div>

                <div class="detail-highlights detail-highlights--six">
                    <article>
                        <i class="bi bi-people"></i>
                        <span><strong><?= (int) $selectedVehicle["seats"] ?> seats</strong><small>Passenger capacity</small></span>
                    </article>
                    <article>
                        <i class="bi bi-door-open"></i>
                        <span><strong><?= (int) $selectedVehicle["doors"] ?> doors</strong><small>Vehicle access</small></span>
                    </article>
                    <article>
                        <i class="bi bi-gear"></i>
                        <span><strong><?= escape_html($selectedVehicle["transmission"]) ?></strong><small>Transmission</small></span>
                    </article>
                    <article>
                        <i class="bi bi-fuel-pump"></i>
                        <span><strong><?= escape_html($selectedVehicle["fuel"]) ?></strong><small>Power source</small></span>
                    </article>
                    <article>
                        <i class="bi bi-suitcase-lg"></i>
                        <span><strong><?= escape_html($selectedVehicle["luggage"]) ?></strong><small>Luggage guide</small></span>
                    </article>
                    <article>
                        <i class="bi bi-speedometer2"></i>
                        <span><strong><?= (int) $selectedVehicle["daily_km"] ?> km/day</strong><small>Mileage allowance</small></span>
                    </article>
                </div>
            </div>

            <div class="col-lg-5">
                <aside class="detail-booking-card">
                    <span class="section-kicker"><?= escape_html(
                        $selectedVehicle["category"],
                    ) ?> rental</span>
                    <h2><?= escape_html($selectedVehicle["name"]) ?></h2>
                    <p><?= escape_html($selectedVehicle["description"]) ?></p>

                    <div class="detail-price">
                        <span>Starting from</span>
                        <strong>₱<?= number_format(
                            $selectedVehicle["price"],
                        ) ?><small>/ day</small></strong>
                    </div>

                    <ul class="check-list">
                        <?php foreach (
                            array_slice($selectedVehicle["inclusions"], 0, 4)
                            as $inclusion
                        ): ?>
                            <li><i class="bi bi-check2-circle"></i> <?= escape_html($inclusion) ?></li>
                        <?php endforeach; ?>
                    </ul>

                    <form class="booking-selector booking-selector--within-form" method="get" action="vehicle-details.php">
                        <input type="hidden" name="vehicle" value="<?= escape_html($selectedVehicle["slug"]) ?>">
                        <label>
                            <span>Pick-up date</span>
                            <input class="form-control" name="pickup" type="date"
                                   min="<?= date("Y-m-d") ?>"
                                   value="<?= escape_html($availabilityPickupDate) ?>" required>
                        </label>
                        <label>
                            <span>Return date</span>
                            <input class="form-control" name="return" type="date"
                                   min="<?= escape_html($availabilityPickupDate !== "" ? $availabilityPickupDate : date("Y-m-d")) ?>"
                                   value="<?= escape_html($availabilityReturnDate) ?>" required>
                        </label>
                        <button class="btn btn-outline" type="submit">Check Availability</button>
                    </form>

                    <?php if ($availabilityMessage !== ""): ?>
                        <div class="availability-inline <?= escape_html($availabilityClass) ?>" role="status">
                            <i class="bi bi-calendar-check"></i>
                            <span><?= escape_html($availabilityMessage) ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="booking-form-actions">
                        <a class="btn btn-primary"
                           href="booking.php?vehicle=<?= urlencode($selectedVehicle["slug"]) ?><?= $availabilityPickupDate !== "" ? "&amp;pickup=" . urlencode($availabilityPickupDate) : "" ?><?= $availabilityReturnDate !== "" ? "&amp;return=" . urlencode($availabilityReturnDate) : "" ?>">
                            Book This Vehicle <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                </aside>
            </div>
        </div>

        <div class="detail-copy-grid detail-copy-grid--v2">
            <article>
                <span class="section-kicker">Vehicle overview</span>
                <h2><?= escape_html($selectedVehicle["overview_title"]) ?></h2>
                <p><?= escape_html($selectedVehicle["overview"]) ?></p>
            </article>
            <article>
                <span class="section-kicker">Detailed description</span>
                <h2>Why choose this <?= escape_html(
                    strtolower($selectedVehicle["category"]),
                ) ?>?</h2>
                <p><?= escape_html($selectedVehicle["description"]) ?></p>
            </article>
        </div>

        <div class="row g-4 detail-information-row">
            <div class="col-lg-6">
                <article class="included-card included-card--expanded">
                    <span class="section-kicker">Rental package</span>
                    <h2>What is included</h2>
                    <div>
                        <?php foreach ($selectedVehicle["inclusions"] as $inclusion): ?>
                            <span><i class="bi bi-check-circle-fill"></i> <?= escape_html($inclusion) ?></span>
                        <?php endforeach; ?>
                    </div>
                </article>
            </div>
            <div class="col-lg-6">
                <article class="included-card included-card--expanded">
                    <span class="section-kicker">Equipment guide</span>
                    <h2>Vehicle features</h2>
                    <div>
                        <?php foreach ($selectedVehicle["features"] as $feature): ?>
                            <span><i class="bi bi-stars"></i> <?= escape_html($feature) ?></span>
                        <?php endforeach; ?>
                    </div>
                </article>
            </div>
        </div>

        <section class="rental-policy-grid">
            <article>
                <i class="bi bi-speedometer2"></i>
                <h3>Mileage policy</h3>
                <p><?= (int) $selectedVehicle["daily_km"] ?> km is included per rental day.</p>
            </article>
            <article>
                <i class="bi bi-shield-check"></i>
                <h3>Protection</h3>
                <p>Standard rental protection is included, subject to the signed agreement.</p>
            </article>
            <article>
                <i class="bi bi-fuel-pump"></i>
                <h3>Return condition</h3>
                <p>Return the vehicle with the agreed fuel or charge level and documented condition.</p>
            </article>
            <article>
                <i class="bi bi-headset"></i>
                <h3>Roadside support</h3>
                <p>Assistance instructions and emergency contacts are supplied with every rental.</p>
            </article>
        </section>

        <section class="related-vehicles-section">
            <div class="section-heading heading-with-action">
                <div>
                    <span class="section-kicker">You may also like</span>
                    <h2>Similar <?= escape_html($selectedVehicle["category"]) ?> options</h2>
                </div>
                <a href="vehicles.php?category=<?= urlencode(
                    $selectedVehicle["category"],
                ) ?>">View category</a>
            </div>

            <div class="row g-4">
                <?php foreach ($relatedVehicles as $relatedVehicle): ?>
                    <div class="col-lg-4 col-md-6">
                        <?php vehicle_card($relatedVehicle); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</section>

<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
