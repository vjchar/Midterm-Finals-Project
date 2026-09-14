<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";
require_once dirname(__DIR__, 2) . "/includes/components/vehicle-card.php";

$vehicles = vehicle_all();

$allowedCategories = ["All", "Luxury", "SUV", "Pickup", "Van", "EV"];
$allowedFuelTypes = ["All", "Gasoline", "Diesel", "Electric", "Hybrid"];
$allowedTransmissions = ["All", "Automatic", "Manual"];
$allowedFleetStatuses = [
    "All",
    "available",
    "reserved",
    "rented",
    "maintenance",
];
$allowedSortOptions = [
    "featured",
    "price-asc",
    "price-desc",
    "rating-desc",
    "rentals-desc",
    "newest",
    "name-asc",
];

$availableBrands = array_values(array_unique(array_column($vehicles, "brand")));
sort($availableBrands, SORT_NATURAL | SORT_FLAG_CASE);

$selectedCategory = (string) ($_GET["category"] ?? "All");
$selectedBrand = (string) ($_GET["brand"] ?? "All");
$selectedFuelType = (string) ($_GET["fuel"] ?? "All");
$selectedTransmission = (string) ($_GET["transmission"] ?? "All");
$selectedFleetStatus = (string) ($_GET["status"] ?? "All");
$selectedSortOption = (string) ($_GET["sort"] ?? "featured");
$vehicleSearchTerm = trim((string) ($_GET["search"] ?? ""));
$minimumSeatCount = max(0, (int) ($_GET["seats"] ?? 0));
$maximumDailyRate = max(0, (int) ($_GET["max_price"] ?? 0));
$availabilityPickupDate = trim((string) ($_GET["pickup"] ?? ""));
$availabilityReturnDate = trim((string) ($_GET["return"] ?? ""));

if (!in_array($selectedCategory, $allowedCategories, true)) {
    $selectedCategory = "All";
}

if (!in_array($selectedBrand, array_merge(["All"], $availableBrands), true)) {
    $selectedBrand = "All";
}

if (!in_array($selectedFuelType, $allowedFuelTypes, true)) {
    $selectedFuelType = "All";
}

if (!in_array($selectedTransmission, $allowedTransmissions, true)) {
    $selectedTransmission = "All";
}

if (!in_array($selectedFleetStatus, $allowedFleetStatuses, true)) {
    $selectedFleetStatus = "All";
}

if (!in_array($selectedSortOption, $allowedSortOptions, true)) {
    $selectedSortOption = "featured";
}

$availabilityStart = null;
$availabilityEnd = null;
$availabilityFilterMessage = "Choose both dates to filter by availability.";
$availabilityFilterClass = "";

if ($availabilityPickupDate !== "" || $availabilityReturnDate !== "") {
    if (
        valid_date($availabilityPickupDate) &&
        valid_date($availabilityReturnDate)
    ) {
        $availabilityStart = new DateTimeImmutable(
            $availabilityPickupDate . " 09:00:00",
        );
        $availabilityEnd = new DateTimeImmutable(
            $availabilityReturnDate . " 09:00:00",
        );

        if ($availabilityEnd <= $availabilityStart) {
            $availabilityStart = null;
            $availabilityEnd = null;
            $availabilityFilterMessage = "Return must be later than pick-up.";
            $availabilityFilterClass = " is-error";
        } else {
            $availabilityFilterMessage = sprintf(
                "Showing vehicles available from %s to %s.",
                $availabilityStart->format("M j"),
                $availabilityEnd->format("M j, Y"),
            );
            $availabilityFilterClass = " is-success";
        }
    } else {
        $availabilityFilterMessage = "Choose valid pick-up and return dates.";
        $availabilityFilterClass = " is-error";
    }
}

$filteredVehicles = array_values(
    array_filter($vehicles, static function (array $catalogVehicle) use (
        $selectedCategory,
        $selectedBrand,
        $selectedFuelType,
        $selectedTransmission,
        $selectedFleetStatus,
        $vehicleSearchTerm,
        $minimumSeatCount,
        $maximumDailyRate,
        $availabilityStart,
        $availabilityEnd,
    ): bool {
        $matchesSearch =
            $vehicleSearchTerm === "" ||
            stripos(
                $catalogVehicle["brand"] . " " . $catalogVehicle["name"],
                $vehicleSearchTerm,
            ) !== false;

        if (!$matchesSearch) {
            return false;
        }

        if (
            $selectedCategory !== "All" &&
            $catalogVehicle["category"] !== $selectedCategory
        ) {
            return false;
        }

        if (
            $selectedBrand !== "All" &&
            $catalogVehicle["brand"] !== $selectedBrand
        ) {
            return false;
        }

        if (
            $selectedFuelType !== "All" &&
            $catalogVehicle["fuel"] !== $selectedFuelType
        ) {
            return false;
        }

        if (
            $selectedTransmission !== "All" &&
            $catalogVehicle["transmission"] !== $selectedTransmission
        ) {
            return false;
        }

        if (
            $selectedFleetStatus !== "All" &&
            $catalogVehicle["availability_status"] !== $selectedFleetStatus
        ) {
            return false;
        }

        if (
            $minimumSeatCount > 0 &&
            (int) $catalogVehicle["seats"] < $minimumSeatCount
        ) {
            return false;
        }

        if (
            $maximumDailyRate > 0 &&
            (int) $catalogVehicle["price"] > $maximumDailyRate
        ) {
            return false;
        }

        if ($availabilityStart && $availabilityEnd) {
            return vehicle_available(
                (int) $catalogVehicle["id"],
                $availabilityStart->format("Y-m-d H:i:s"),
                $availabilityEnd->format("Y-m-d H:i:s"),
            );
        }

        return true;
    }),
);

usort($filteredVehicles, static function (
    array $firstVehicle,
    array $secondVehicle,
) use ($selectedSortOption): int {
    return match ($selectedSortOption) {
        "price-asc" => (int) $firstVehicle["price"] <=>
            (int) $secondVehicle["price"],
        "price-desc" => (int) $secondVehicle["price"] <=>
            (int) $firstVehicle["price"],
        "rating-desc" => (float) $secondVehicle["rating"] <=>
            (float) $firstVehicle["rating"],
        "rentals-desc" => (int) ($secondVehicle["rental_count"] ?? 0) <=>
            (int) ($firstVehicle["rental_count"] ?? 0),
        "newest" => strcmp(
            (string) ($secondVehicle["created_at"] ?? ""),
            (string) ($firstVehicle["created_at"] ?? ""),
        ),
        "name-asc" => strcasecmp(
            (string) $firstVehicle["name"],
            (string) $secondVehicle["name"],
        ),
        default => 0,
    };
});

$vehiclesPerPage = 12;
$totalFilteredVehicles = count($filteredVehicles);
$totalVehiclePages = max(
    1,
    (int) ceil($totalFilteredVehicles / $vehiclesPerPage),
);
$currentVehiclePage = min(
    $totalVehiclePages,
    max(1, (int) ($_GET["page"] ?? 1)),
);
$visibleVehicles = array_slice(
    $filteredVehicles,
    ($currentVehiclePage - 1) * $vehiclesPerPage,
    $vehiclesPerPage,
);

$activeFilterParameters = [
    "search" => $vehicleSearchTerm,
    "category" => $selectedCategory,
    "brand" => $selectedBrand,
    "fuel" => $selectedFuelType,
    "seats" => $minimumSeatCount,
    "transmission" => $selectedTransmission,
    "status" => $selectedFleetStatus,
    "max_price" => $maximumDailyRate,
    "sort" => $selectedSortOption,
    "pickup" => $availabilityPickupDate,
    "return" => $availabilityReturnDate,
];
$activeFilterParameters = array_filter(
    $activeFilterParameters,
    static fn(mixed $filterValue): bool => !in_array(
        $filterValue,
        ["", "All", 0],
        true,
    ),
);

$pageTitle = "Our Vehicles | VJ Car Rental";
$pageDescription =
    "Browse and filter 75 VJ Car Rental vehicles with server-checked date availability.";

require dirname(__DIR__, 2) . "/includes/header.php";
?>

<section class="page-hero pattern-layer">
    <div class="container">
        <span class="section-kicker">Explore the fleet</span>
        <h1>Find your exact car</h1>
        <p>
            Filter by category, brand, seating, fuel, price, and rental dates.
            Availability is checked against current reservations so you can choose with confidence.
        </p>
    </div>
</section>

<section class="content-section fleet-page">
    <div class="container">
        <div class="fleet-toolbar fleet-toolbar--v2">
            <div>
                <span class="section-kicker"><?= count(
                    $vehicles,
                ) ?> prepared vehicles</span>
                <h2>Available for your next trip</h2>
            </div>

            <div class="fleet-toolbar-actions">
                <a class="btn btn-outline" href="favorites.php">
                    <i class="bi bi-heart" aria-hidden="true"></i>
                    Favorites
                </a>
                <a class="btn btn-outline" href="compare.php">
                    <i class="bi bi-columns-gap" aria-hidden="true"></i>
                    Compare Cars
                </a>
            </div>
        </div>

        <form class="advanced-filters" method="get" action="vehicles.php">
            <div class="advanced-filters__header">
                <div>
                    <i class="bi bi-sliders" aria-hidden="true"></i>
                    <strong>Refine results</strong>
                </div>

                <a href="vehicles.php">Reset filters</a>
            </div>

            <div class="advanced-filters__grid">
                <div class="fleet-date-filter">
                    <span>Available for my dates</span>
                    <div>
                        <input
                            class="form-control"
                            type="date"
                            name="pickup"
                            min="<?= escape_html(date("Y-m-d")) ?>"
                            value="<?= escape_html($availabilityPickupDate) ?>"
                            aria-label="Availability pick-up date">
                        <input
                            class="form-control"
                            type="date"
                            name="return"
                            min="<?= escape_html(
                                $availabilityPickupDate ?: date("Y-m-d"),
                            ) ?>"
                            value="<?= escape_html($availabilityReturnDate) ?>"
                            aria-label="Availability return date">
                    </div>
                    <small class="fleet-filter-message<?= $availabilityFilterClass ?>">
                        <?= escape_html($availabilityFilterMessage) ?>
                    </small>
                </div>

                <label>
                    <span>Search</span>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="bi bi-search" aria-hidden="true"></i>
                        </span>
                        <input
                            class="form-control"
                            name="search"
                            type="search"
                            value="<?= escape_html($vehicleSearchTerm) ?>"
                            placeholder="Brand or model">
                    </div>
                </label>

                <label>
                    <span>Category</span>
                    <select class="form-select" name="category">
                        <?php foreach (
                            $allowedCategories
                            as $categoryOption
                        ): ?>
                            <option
                                value="<?= escape_html($categoryOption) ?>"
                                <?= $selectedCategory === $categoryOption
                                    ? "selected"
                                    : "" ?>>
                                <?= $categoryOption === "All"
                                    ? "All types"
                                    : escape_html($categoryOption) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    <span>Brand</span>
                    <select class="form-select" name="brand">
                        <option value="All">All brands</option>
                        <?php foreach ($availableBrands as $brandOption): ?>
                            <option
                                value="<?= escape_html($brandOption) ?>"
                                <?= $selectedBrand === $brandOption
                                    ? "selected"
                                    : "" ?>>
                                <?= escape_html($brandOption) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    <span>Fuel type</span>
                    <select class="form-select" name="fuel">
                        <?php foreach ($allowedFuelTypes as $fuelTypeOption): ?>
                            <option
                                value="<?= escape_html($fuelTypeOption) ?>"
                                <?= $selectedFuelType === $fuelTypeOption
                                    ? "selected"
                                    : "" ?>>
                                <?= $fuelTypeOption === "All"
                                    ? "All fuel types"
                                    : escape_html($fuelTypeOption) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    <span>Minimum seats</span>
                    <select class="form-select" name="seats">
                        <?php foreach (
                            [
                                0 => "Any capacity",
                                4 => "4+ seats",
                                5 => "5+ seats",
                                7 => "7+ seats",
                                10 => "10+ seats",
                                15 => "15 seats",
                            ]
                            as $seatCount => $seatLabel
                        ): ?>
                            <option
                                value="<?= $seatCount ?>"
                                <?= $minimumSeatCount === $seatCount
                                    ? "selected"
                                    : "" ?>>
                                <?= escape_html($seatLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    <span>Transmission</span>
                    <select class="form-select" name="transmission">
                        <?php foreach (
                            $allowedTransmissions
                            as $transmissionOption
                        ): ?>
                            <option
                                value="<?= escape_html($transmissionOption) ?>"
                                <?= $selectedTransmission ===
                                $transmissionOption
                                    ? "selected"
                                    : "" ?>>
                                <?= $transmissionOption === "All"
                                    ? "Any transmission"
                                    : escape_html($transmissionOption) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    <span>Fleet status</span>
                    <select class="form-select" name="status">
                        <?php foreach (
                            $allowedFleetStatuses
                            as $fleetStatusOption
                        ): ?>
                            <option
                                value="<?= escape_html($fleetStatusOption) ?>"
                                <?= $selectedFleetStatus === $fleetStatusOption
                                    ? "selected"
                                    : "" ?>>
                                <?= $fleetStatusOption === "All"
                                    ? "All operational states"
                                    : escape_html(
                                        ucwords(
                                            str_replace(
                                                "_",
                                                " ",
                                                $fleetStatusOption,
                                            ),
                                        ),
                                    ) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    <span>Maximum daily rate</span>
                    <select class="form-select" name="max_price">
                        <?php foreach (
                            [
                                0 => "Any price",
                                5000 => "Up to ₱5,000",
                                7500 => "Up to ₱7,500",
                                10000 => "Up to ₱10,000",
                                20000 => "Up to ₱20,000",
                                30000 => "Up to ₱30,000",
                            ]
                            as $dailyRate => $dailyRateLabel
                        ): ?>
                            <option
                                value="<?= $dailyRate ?>"
                                <?= $maximumDailyRate === $dailyRate
                                    ? "selected"
                                    : "" ?>>
                                <?= escape_html($dailyRateLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    <span>Sort results</span>
                    <select class="form-select" name="sort">
                        <?php $sortOptionLabels = [
                            "featured" => "Featured order",
                            "price-asc" => "Price: low to high",
                            "price-desc" => "Price: high to low",
                            "rating-desc" => "Highest verified rating",
                            "rentals-desc" => "Most rented",
                            "newest" => "Newest fleet entry",
                            "name-asc" => "Name A-Z",
                        ]; ?>
                        <?php foreach (
                            $sortOptionLabels
                            as $sortOptionValue => $sortOptionLabel
                        ): ?>
                            <option
                                value="<?= escape_html($sortOptionValue) ?>"
                                <?= $selectedSortOption === $sortOptionValue
                                    ? "selected"
                                    : "" ?>>
                                <?= escape_html($sortOptionLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <div class="advanced-filters__submit">
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        Apply Filters
                    </button>
                </div>
            </div>
        </form>

        <nav class="filter-pills fleet-filter-pills" aria-label="Vehicle categories">
            <?php foreach ($allowedCategories as $categoryOption): ?>
                <?php
                $categoryFilterParameters = $activeFilterParameters;
                $categoryFilterParameters["category"] = $categoryOption;
                unset($categoryFilterParameters["page"]);
                $isActiveCategory = $selectedCategory === $categoryOption;
                ?>
                <a
                    class="<?= $isActiveCategory ? "active" : "" ?>"
                    href="vehicles.php?<?= escape_html(
                        http_build_query($categoryFilterParameters),
                    ) ?>"
                    <?= $isActiveCategory ? 'aria-current="page"' : "" ?>>
                    <?= $categoryOption === "All"
                        ? "All Vehicles"
                        : escape_html($categoryOption) ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="fleet-results-header">
            <p><strong><?= $totalFilteredVehicles ?></strong> vehicles found</p>
            <span>Live fleet availability &bull; dates checked against current reservations</span>
        </div>

        <?php if (!$visibleVehicles): ?>
            <div class="empty-state">
                <i class="bi bi-car-front" aria-hidden="true"></i>
                <h3>No vehicles match those filters.</h3>
                <p>Reset a filter or try another brand, price, or seating capacity.</p>
                <a class="btn btn-primary" href="vehicles.php">Reset Filters</a>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($visibleVehicles as $visibleVehicle): ?>
                    <div class="col-xl-3 col-lg-4 col-md-6">
                        <?php vehicle_card($visibleVehicle); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($totalVehiclePages > 1): ?>
            <nav class="fleet-pagination" aria-label="Vehicle result pages">
                <?php
                $previousPageParameters = array_merge($activeFilterParameters, [
                    "page" => max(1, $currentVehiclePage - 1),
                ]);
                $nextPageParameters = array_merge($activeFilterParameters, [
                    "page" => min($totalVehiclePages, $currentVehiclePage + 1),
                ]);
                ?>

                <?php if ($currentVehiclePage > 1): ?>
                    <a href="vehicles.php?<?= escape_html(
                        http_build_query($previousPageParameters),
                    ) ?>">
                        <i class="bi bi-arrow-left" aria-hidden="true"></i>
                        <span>Previous</span>
                    </a>
                <?php else: ?>
                    <span class="is-disabled" aria-disabled="true">
                        <i class="bi bi-arrow-left" aria-hidden="true"></i>
                        <span>Previous</span>
                    </span>
                <?php endif; ?>

                <span>
                    Page <strong><?= $currentVehiclePage ?></strong>
                    of <strong><?= $totalVehiclePages ?></strong>
                </span>

                <?php if ($currentVehiclePage < $totalVehiclePages): ?>
                    <a href="vehicles.php?<?= escape_html(
                        http_build_query($nextPageParameters),
                    ) ?>">
                        <span>Next</span>
                        <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </a>
                <?php else: ?>
                    <span class="is-disabled" aria-disabled="true">
                        <span>Next</span>
                        <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </div>
</section>

<section class="content-section service-promise pattern-layer">
    <div class="container">
        <div class="trust-strip">
            <div>
                <i class="bi bi-calendar-check" aria-hidden="true"></i>
                <span>
                    <small>Date-based availability</small>
                    <strong>Conflicts checked against<br>saved reservations.</strong>
                </span>
            </div>
            <div>
                <i class="bi bi-columns-gap" aria-hidden="true"></i>
                <span>
                    <small>Easy comparison</small>
                    <strong>Compare up to three<br>vehicles side by side.</strong>
                </span>
            </div>
            <div>
                <i class="bi bi-receipt" aria-hidden="true"></i>
                <span>
                    <small>Server pricing</small>
                    <strong>Rates, deposits, extras,<br>and discounts verified.</strong>
                </span>
            </div>
            <div>
                <i class="bi bi-headset" aria-hidden="true"></i>
                <span>
                    <small>Account support</small>
                    <strong>Manage trips and request<br>help securely.</strong>
                </span>
            </div>
        </div>
    </div>
</section>

<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
