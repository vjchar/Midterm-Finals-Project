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

$authenticatedUser = current_user();
$isCustomer = ($authenticatedUser["role"] ?? null) === "customer";
$isAnonymousVisitor = $authenticatedUser === null;
$authenticatedUserFavoriteSlugs = customer_favorite_vehicle_slugs();
$isSelectedVehicleFavorite = in_array(
    $selectedVehicle["slug"],
    $authenticatedUserFavoriteSlugs,
    true,
);

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

$approvedReviews = approved_reviews_for_vehicle((int) $selectedVehicle["id"]);
$averagesStatement = database()->prepare(
    "SELECT AVG(cleanliness) AS cleanliness, AVG(comfort) AS comfort, AVG(vehicle_condition) AS vehicle_condition, AVG(pickup_experience) AS pickup_experience, AVG(customer_support) AS customer_support FROM reviews WHERE vehicle_id = ? AND status = 'approved'",
);
$averagesStatement->execute([(int) $selectedVehicle["id"]]);
$ratingAverages = $averagesStatement->fetch() ?: [];

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
        <div class="page-hero-meta">
            <?php if ((int) $selectedVehicle["review_count"] > 0): ?>
                <span><i class="bi bi-star-fill"></i> <?= number_format((float) $selectedVehicle["rating"], 1) ?> from <?= (int) $selectedVehicle["review_count"] ?> verified review<?= (int) $selectedVehicle["review_count"] === 1 ? "" : "s" ?></span>
            <?php else: ?>
                <span><i class="bi bi-stars"></i> New vehicle - no reviews yet</span>
            <?php endif; ?>
            <span><i class="bi bi-calendar-check"></i> Availability checked by rental dates</span>
        </div>
    </div>
</section>

<section class="content-section vehicle-detail-section">
    <div class="container">
        <div class="row g-4 g-xl-5 align-items-start">
            <div class="col-lg-7">
                <div class="detail-visual pattern-layer">
                    <?php if ($isCustomer): ?>
                        <form class="favorite-form favorite-form--detail" method="post" action="favorite-action.php">
                            <?= csrf_field() ?>
                            <input type="hidden" name="vehicle" value="<?= escape_html($selectedVehicle["slug"]) ?>">
                            <input type="hidden" name="return_to" value="vehicle-details.php?vehicle=<?= escape_html($selectedVehicle["slug"]) ?>">
                            <button class="favorite-button favorite-button--large<?= $isSelectedVehicleFavorite ? " is-active" : "" ?>"
                                    type="submit"
                                    aria-label="<?= $isSelectedVehicleFavorite ? "Remove" : "Save" ?> <?= escape_html($selectedVehicle["name"]) ?> <?= $isSelectedVehicleFavorite ? "from" : "to" ?> favorites">
                                <i class="bi <?= $isSelectedVehicleFavorite ? "bi-heart-fill" : "bi-heart" ?>" aria-hidden="true"></i>
                            </button>
                        </form>
                    <?php elseif ($isAnonymousVisitor): ?>
                        <a class="favorite-button favorite-button--large"
                           href="login.php?return_to=<?= urlencode("vehicle-details.php?vehicle=" . $selectedVehicle["slug"]) ?>"
                           aria-label="Sign in to save <?= escape_html($selectedVehicle["name"]) ?> to favorites">
                            <i class="bi bi-heart" aria-hidden="true"></i>
                        </a>
                    <?php endif; ?>

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
                    <div class="detail-rating-row">
                        <?php if ((int) $selectedVehicle["review_count"] > 0): ?>
                            <span><i class="bi bi-star-fill"></i> <?= number_format((float) $selectedVehicle["rating"], 1) ?></span>
                            <small><?= (int) $selectedVehicle["review_count"] ?> verified review<?= (int) $selectedVehicle["review_count"] === 1 ? "" : "s" ?></small>
                        <?php else: ?>
                            <span><i class="bi bi-stars"></i> New</span>
                            <small>Be the first verified reviewer</small>
                        <?php endif; ?>
                    </div>
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
                    <a class="btn btn-outline w-100 mt-2" href="compare.php?vehicles=<?= urlencode($selectedVehicle["slug"]) ?>">Compare This Vehicle</a>
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

        <section class="reviews-section">
            <div class="section-heading heading-with-action">
                <div>
                    <span class="section-kicker">Verified customer feedback</span>
                    <h2>Trip ratings and reviews</h2>
                </div>
                <a class="btn btn-outline" href="rate-trip.php">Rate a Completed Trip</a>
            </div>
            <?php if (!$approvedReviews): ?>
                <div class="empty-state empty-state--compact">
                    <i class="bi bi-chat-square-heart"></i>
                    <h3>No approved reviews yet</h3>
                    <p>Only customers with completed bookings can submit a verified review.</p>
                </div>
            <?php else: ?>
                <div class="rating-summary-grid">
                    <article class="rating-score-card">
                        <strong><?= number_format((float) $selectedVehicle["rating"], 1) ?></strong>
                        <div class="stars" aria-label="Average verified rating">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
                        <span>Based on <?= (int) $selectedVehicle["review_count"] ?> verified review<?= (int) $selectedVehicle["review_count"] === 1 ? "" : "s" ?></span>
                    </article>
                    <div class="rating-bars">
                        <?php foreach ([
                            "cleanliness" => "Cleanliness",
                            "comfort" => "Comfort",
                            "vehicle_condition" => "Vehicle condition",
                            "pickup_experience" => "Pickup experience",
                            "customer_support" => "Customer support",
                        ] as $key => $label):
                            $score = round(((float) ($ratingAverages[$key] ?? 0)) * 20); ?>
                            <div>
                                <span><?= escape_html($label) ?></span>
                                <progress value="<?= $score ?>" max="100"><?= $score ?>%</progress>
                                <strong><?= number_format((float) ($ratingAverages[$key] ?? 0), 1) ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="row g-3 mt-2">
                    <?php foreach ($approvedReviews as $approvedReview): ?>
                        <div class="col-md-6">
                            <article class="review-card">
                                <div class="stars" aria-label="<?= (int) $approvedReview["overall"] ?> out of 5 stars"><?= str_repeat("&#9733;", (int) $approvedReview["overall"]) . str_repeat("&#9734;", 5 - (int) $approvedReview["overall"]) ?></div>
                                <h3><?= escape_html($approvedReview["title"]) ?></h3>
                                <p><?= escape_html($approvedReview["body"]) ?></p>
                                <span><i class="bi bi-patch-check-fill"></i> Verified rental &bull; <?= escape_html($approvedReview["reviewer_name"]) ?> &bull; <?= date("M Y", strtotime($approvedReview["created_at"])) ?></span>
                            </article>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

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
