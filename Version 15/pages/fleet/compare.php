<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";

$vehicles = vehicle_all();
$requested = [];
if (isset($_GET["compare"]) && is_array($_GET["compare"])) {
    $requested = array_map("strval", $_GET["compare"]);
} elseif (isset($_GET["vehicles"])) {
    $requested = explode(",", (string) $_GET["vehicles"]);
}
$requested = array_values(array_unique(array_filter($requested)));
$selectedVehicles = [];
foreach (array_slice($requested, 0, 3) as $slug) {
    foreach ($vehicles as $candidate) {
        if ($candidate["slug"] === $slug) {
            $selectedVehicles[] = $candidate;
            break;
        }
    }
}
if (!$selectedVehicles) {
    $selectedVehicles = array_slice($vehicles, 0, 3);
}
$lowestRate = min(array_column($selectedVehicles, "price"));
$highestSeats = max(array_column($selectedVehicles, "seats"));
$highestRating = max(array_column($selectedVehicles, "rating"));
$pageTitle = "Compare Vehicles | VJ Car Rental";
$pageDescription =
    "Compare VJ Car Rental vehicles by price, capacity, verified ratings, deposits, mileage, and inclusions.";
require dirname(__DIR__, 2) . "/includes/header.php";
?>

<section class="page-hero page-hero--compact pattern-layer">
    <div class="container">
        <span class="section-kicker">Vehicle comparison</span>
        <h1>Compare your best options</h1>
        <p>Review up to three cars side by side before choosing the right vehicle for your trip.</p>
    </div>
</section>
<section class="content-section compare-page">
    <div class="container">
        <form class="compare-picker" method="get" action="compare.php">
            <div>
                <span class="section-kicker">Change selection</span>
                <h2>Choose up to three vehicles</h2>
            </div>
            <div class="compare-picker__fields">
                <?php for ($slot = 0; $slot < 3; $slot++): ?>
                    <label>
                        Vehicle <?= $slot + 1 ?>
                        <select class="form-select" name="compare[]">
                            <option value="">None</option>
                            <?php foreach ($vehicles as $option): ?>
                                <option
                                    value="<?= htmlspecialchars($option["slug"]) ?>"
                                    <?= isset($selectedVehicles[$slot]) &&
                                    $selectedVehicles[$slot]["slug"] ===
                                        $option["slug"]
                                        ? "selected"
                                        : "" ?>
                                >
                                    <?= htmlspecialchars($option["name"]) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endfor; ?>
                <button class="btn btn-primary" type="submit">Update Comparison</button>
            </div>
        </form>
        <div class="compare-table-wrap">
            <table class="compare-table">
                <thead>
                    <tr>
                        <th scope="col">Feature</th>
                        <?php foreach ($selectedVehicles as $item): ?>
                            <th scope="col">
                                <img
                                    src="assets/images/cars/<?= htmlspecialchars(
                                        $item["image"],
                                    ) ?>"
                                    alt="<?= htmlspecialchars($item["name"]) ?>"
                                    loading="lazy"
                                >
                                <strong><?= htmlspecialchars($item["name"]) ?></strong>
                                <span><?= htmlspecialchars($item["category"]) ?></span>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <th scope="row">Daily rate</th>
                        <?php foreach ($selectedVehicles as $item): ?>
                            <td class="<?= $item["price"] === $lowestRate
                                ? "is-best"
                                : "" ?>">
                                <strong class="compare-price">₱<?= number_format(
                                    $item["price"],
                                ) ?></strong>
                                / day
                                <?= $item["price"] === $lowestRate
                                    ? '<small class="best-label">Lowest rate</small>'
                                    : "" ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th scope="row">Verified rating</th>
                        <?php foreach ($selectedVehicles as $item): ?>
                            <td class="<?= $highestRating > 0 &&
                            $item["rating"] === $highestRating
                                ? "is-best"
                                : "" ?>">
                                <?php if ($item["review_count"]): ?>
                                    <span class="summary-rating">
                                        <i class="bi bi-star-fill"></i> <?= number_format(
                                            $item["rating"],
                                            1,
                                        ) ?>
                                        (<?= (int) $item["review_count"] ?>)
                                    </span>
                                <?php else: ?>
                                    New—no reviews yet
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th scope="row">Availability</th>
                        <?php foreach ($selectedVehicles as $item): ?>
                            <td>
                                <span class="status-badge status-badge--<?= status_class(
                                    $item["availability_status"],
                                ) ?>">
                                    <?= escape_html(
                                        humanize_label($item["availability_status"]),
                                    ) ?>
                                </span>
                                <br>
                                <a href="vehicle-details.php?vehicle=<?= urlencode(
                                    $item["slug"],
                                ) ?>">
                                    Check rental dates
                                </a>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th scope="row">Seats / doors</th>
                        <?php foreach ($selectedVehicles as $item): ?>
                            <td class="<?= $item["seats"] === $highestSeats
                                ? "is-best"
                                : "" ?>">
                                <?= (int) $item["seats"] ?> seats •
                                <?= (int) $item["doors"] ?> doors
                                <?= $item["seats"] === $highestSeats
                                    ? '<small class="best-label">Most seats</small>'
                                    : "" ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th scope="row">Transmission</th>
                        <?php foreach ($selectedVehicles as $item): ?>
                            <td><?= htmlspecialchars(
                                $item["transmission"],
                            ) ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th scope="row">Fuel / power</th>
                        <?php foreach ($selectedVehicles as $item): ?>
                            <td><?= htmlspecialchars($item["fuel"]) ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th scope="row">Luggage guide</th>
                        <?php foreach ($selectedVehicles as $item): ?>
                            <td><?= htmlspecialchars($item["luggage"]) ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th scope="row">Mileage</th>
                        <?php foreach ($selectedVehicles as $item): ?>
                            <td><?= (int) $item["daily_km"] ?> km / day</td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th scope="row">Refundable deposit</th>
                        <?php foreach ($selectedVehicles as $item): ?>
                            <td>₱<?= number_format($item["deposit"]) ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th scope="row">Best suited for</th>
                        <?php foreach ($selectedVehicles as $item): ?>
                            <td><?= htmlspecialchars(
                                $item["overview_title"],
                            ) ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th scope="row">Actions</th>
                        <?php foreach ($selectedVehicles as $item): ?>
                            <td>
                                <a class="btn btn-primary btn-sm" href="booking.php?vehicle=<?= urlencode(
                                    $item["slug"],
                                ) ?>">Book</a>
                                <a class="btn btn-outline btn-sm mt-2" href="vehicle-details.php?vehicle=<?= urlencode(
                                    $item["slug"],
                                ) ?>">Details</a>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="display-note">
            <i class="bi bi-info-circle"></i> Prices and vehicle details are kept current. Availability is confirmed against existing reservations after you select your rental dates.
        </p>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
