<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";
require_once dirname(__DIR__, 2) . "/includes/components/vehicle-card.php";

$vehicles = vehicle_all();

$allowedCategories = ["All", "Luxury", "SUV", "Pickup", "Van", "EV"];
$allowedFuelTypes = ["All", "Gasoline", "Diesel", "Electric", "Hybrid"];
$allowedTransmissions = ["All", "Automatic", "Manual"];
$allowedSortOptions = [
    "featured",
    "price-asc",
    "price-desc",
    "newest",
    "name-asc",
];

$availableBrands = array_values(array_unique(array_column($vehicles, "brand")));
sort($availableBrands, SORT_NATURAL | SORT_FLAG_CASE);

$selectedCategory = (string) ($_GET["category"] ?? "All");
$selectedBrand = (string) ($_GET["brand"] ?? "All");
$selectedFuelType = (string) ($_GET["fuel"] ?? "All");
$selectedTransmission = (string) ($_GET["transmission"] ?? "All");
$selectedSortOption = (string) ($_GET["sort"] ?? "featured");
$vehicleSearchTerm = trim((string) ($_GET["search"] ?? ""));
$minimumSeatCount = max(0, (int) ($_GET["seats"] ?? 0));
$maximumDailyRate = max(0, (int) ($_GET["max_price"] ?? 0));

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


if (!in_array($selectedSortOption, $allowedSortOptions, true)) {
    $selectedSortOption = "featured";
}

$filteredVehicles = array_values(
    array_filter($vehicles, static function (array $catalogVehicle) use (
        $selectedCategory,
        $selectedBrand,
        $selectedFuelType,
        $selectedTransmission,
        $vehicleSearchTerm,
        $minimumSeatCount,
        $maximumDailyRate,
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
    "max_price" => $maximumDailyRate,
    "sort" => $selectedSortOption,
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
    "Browse and filter the complete VJ Car Rental vehicle catalog.";

require dirname(__DIR__, 2) . "/includes/header.php";
?>

<section class="page-hero pattern-layer">
    <div class="container">
        <span class="section-kicker">Explore our fleet</span>
        <h1>Find your exact car</h1>
        <p>
            Filter by category, brand, seating, fuel, price, and transmission.
            Browse our complete collection and find a vehicle that fits your trip.
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
                <h2>Explore vehicles for your next trip</h2>
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
            <span>Complete VJ Car Rental vehicle catalog</span>
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

<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
