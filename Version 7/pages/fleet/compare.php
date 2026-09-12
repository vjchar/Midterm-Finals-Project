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
$pageTitle = "Compare Vehicles | VJ Car Rental";
$pageDescription =
    "Compare VJ Car Rental vehicles by price, capacity, transmission, fuel, mileage, and trip fit.";
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
                                <option value="<?= escape_html($option["slug"]) ?>"
                                    <?= isset($selectedVehicles[$slot]) && $selectedVehicles[$slot]["slug"] === $option["slug"] ? "selected" : "" ?>>
                                    <?= escape_html($option["name"]) ?>
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
                                <img src="assets/images/cars/<?= escape_html(basename((string) $item["image"])) ?>"
                                     alt="<?= escape_html($item["name"]) ?>" loading="lazy">
                                <strong><?= escape_html($item["name"]) ?></strong>
                                <span><?= escape_html($item["category"]) ?></span>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <th scope="row">Daily rate</th>
                        <?php foreach ($selectedVehicles as $item): ?>
                            <td class="<?= $item["price"] === $lowestRate ? "is-best" : "" ?>">
                                <strong class="compare-price">₱<?= number_format($item["price"]) ?></strong> / day
                                <?= $item["price"] === $lowestRate ? '<small class="best-label">Lowest rate</small>' : "" ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th scope="row">Brand</th>
                        <?php foreach ($selectedVehicles as $item): ?><td><?= escape_html($item["brand"]) ?></td><?php endforeach; ?>
                    </tr>
                    <tr>
                        <th scope="row">Category</th>
                        <?php foreach ($selectedVehicles as $item): ?><td><?= escape_html($item["category"]) ?></td><?php endforeach; ?>
                    </tr>
                    <tr>
                        <th scope="row">Rental availability</th>
                        <?php foreach ($selectedVehicles as $item): ?>
                            <td><a href="vehicle-details.php?vehicle=<?= urlencode($item["slug"]) ?>">Check rental dates</a></td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th scope="row">Seats / doors</th>
                        <?php foreach ($selectedVehicles as $item): ?>
                            <td class="<?= $item["seats"] === $highestSeats ? "is-best" : "" ?>">
                                <?= (int) $item["seats"] ?> seats • <?= (int) $item["doors"] ?> doors
                                <?= $item["seats"] === $highestSeats ? '<small class="best-label">Most seats</small>' : "" ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <tr><th scope="row">Transmission</th><?php foreach ($selectedVehicles as $item): ?><td><?= escape_html($item["transmission"]) ?></td><?php endforeach; ?></tr>
                    <tr><th scope="row">Fuel / power</th><?php foreach ($selectedVehicles as $item): ?><td><?= escape_html($item["fuel"]) ?></td><?php endforeach; ?></tr>
                    <tr><th scope="row">Luggage guide</th><?php foreach ($selectedVehicles as $item): ?><td><?= escape_html($item["luggage"]) ?></td><?php endforeach; ?></tr>
                    <tr><th scope="row">Mileage</th><?php foreach ($selectedVehicles as $item): ?><td><?= (int) $item["daily_km"] ?> km / day</td><?php endforeach; ?></tr>
                    <tr><th scope="row">Best suited for</th><?php foreach ($selectedVehicles as $item): ?><td><?= escape_html($item["overview_title"]) ?></td><?php endforeach; ?></tr>
                    <tr>
                        <th scope="row">Actions</th>
                        <?php foreach ($selectedVehicles as $item): ?>
                            <td>
                                <a class="btn btn-primary btn-sm" href="booking.php?vehicle=<?= urlencode($item["slug"]) ?>">Book</a>
                                <a class="btn btn-outline btn-sm mt-2" href="vehicle-details.php?vehicle=<?= urlencode($item["slug"]) ?>">Details</a>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="display-note">
            <i class="bi bi-info-circle"></i> Prices and vehicle details come from the database. Availability is checked against saved bookings after dates are selected.
        </p>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
