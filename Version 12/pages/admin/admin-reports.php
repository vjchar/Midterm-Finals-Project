<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";

$admin = require_admin();
$from = (string) ($_GET["from"] ?? date("Y-m-01"));
$to = (string) ($_GET["to"] ?? date("Y-m-d"));

if (!valid_date($from) || !valid_date($to) || $from > $to) {
    $from = date("Y-m-01");
    $to = date("Y-m-d");
}

$fromAt = $from . " 00:00:00";
$toAt = $to . " 23:59:59";

$reportQuery = <<<'SQL'
SELECT
    b.reference,
    b.status,
    b.pickup_at,
    b.original_return_at,
    b.return_at,
    b.total,
    v.name AS vehicle_name,
    u.name AS customer_name,
    COALESCE(
        SUM(CASE WHEN p.status = 'paid' THEN p.amount ELSE 0 END),
        0
    ) AS paid_amount
FROM bookings AS b
JOIN vehicles AS v ON v.id = b.vehicle_id
JOIN users AS u ON u.id = b.user_id
LEFT JOIN payments AS p ON p.booking_id = b.id
WHERE b.created_at BETWEEN ? AND ?
GROUP BY b.id
ORDER BY b.created_at DESC
SQL;

$statement = database()->prepare($reportQuery);
$statement->execute([$fromAt, $toAt]);
$rows = $statement->fetchAll();
$adjustmentReportStatement = database()->prepare(
    "SELECT request_type,status,COUNT(*) AS total,COALESCE(SUM(price_difference),0) AS value FROM rental_adjustment_requests WHERE created_at BETWEEN ? AND ? GROUP BY request_type,status"
);
$adjustmentReportStatement->execute([$fromAt,$toAt]);
$adjustmentRows = $adjustmentReportStatement->fetchAll();
$pendingAdjustments = 0;
$activatedExtensionValue = 0;
foreach ($adjustmentRows as $adjustmentRow) {
    if ($adjustmentRow["status"] === "pending") { $pendingAdjustments += (int)$adjustmentRow["total"]; }
    if ($adjustmentRow["request_type"] === "extension" && in_array($adjustmentRow["status"],["activated","completed"],true)) { $activatedExtensionValue += (int)$adjustmentRow["value"]; }
}

if (($_GET["export"] ?? "") === "csv") {
    $fileName = "rental-report-{$from}-to-{$to}.csv";

    header("Content-Type: text/csv; charset=UTF-8");
    header('Content-Disposition: attachment; filename="' . $fileName . '"');

    $output = fopen("php://output", "wb");
    fputcsv($output, [
        "Reference",
        "Customer",
        "Vehicle",
        "Pickup",
        "Original Return",
        "Current Return",
        "Status",
        "Booking Total",
        "Verified Payments",
    ]);

    foreach ($rows as $row) {
        fputcsv($output, [
            $row["reference"],
            $row["customer_name"],
            $row["vehicle_name"],
            $row["pickup_at"],
            $row["original_return_at"] ?: $row["return_at"],
            $row["return_at"],
            $row["status"],
            $row["total"],
            $row["paid_amount"],
        ]);
    }

    fclose($output);
    exit();
}

$statusCounts = [];
$bookingValue = 0;
$verifiedRevenue = 0;

foreach ($rows as $row) {
    $status = $row["status"];
    $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
    $bookingValue += (int) $row["total"];
    $verifiedRevenue += (int) $row["paid_amount"];
}

$fleetPerformanceQuery = <<<'SQL'
SELECT
    v.name,
    COUNT(b.id) AS rentals,
    COALESCE(SUM(b.total), 0) AS value
FROM vehicles AS v
LEFT JOIN bookings AS b
    ON b.vehicle_id = v.id
    AND b.created_at BETWEEN ? AND ?
GROUP BY v.id
ORDER BY rentals DESC, value DESC
LIMIT 8
SQL;

$topStatement = database()->prepare($fleetPerformanceQuery);
$topStatement->execute([$fromAt, $toAt]);
$topVehicles = $topStatement->fetchAll();

$maxRentals = max([
    1,
    ...array_map(
        static fn(array $row): int => (int) $row["rentals"],
        $topVehicles,
    ),
]);

$pageTitle = "Reports | VJ Car Rental";

require dirname(__DIR__, 2) . "/includes/header.php";
require dirname(__DIR__, 2) . "/includes/admin-nav.php";
?>

<section class="admin-page-heading">
    <div class="container">
        <div>
            <span class="section-kicker">Business reporting</span>
            <h1>Rental reports</h1>
            <p>
                Filter operational results, print a clean summary,
                or export detailed rows to CSV.
            </p>
        </div>

        <div class="admin-page-actions">
            <a
                class="btn btn-outline-light"
                href="admin-reports.php?from=<?= urlencode(
                    $from,
                ) ?>&amp;to=<?= urlencode($to) ?>&amp;export=csv">
                <i class="bi bi-download" aria-hidden="true"></i>
                Export CSV
            </a>
            <p class="print-instruction print-instruction--light">
                <i class="bi bi-printer" aria-hidden="true"></i>
                Use <strong>Ctrl + P</strong> to print or save this report as a PDF.
            </p>
        </div>
    </div>
</section>

<section class="content-section admin-section">
    <div class="container">
        <form class="booking-selector" method="get">
            <label for="reportFrom">Report period</label>
            <input
                class="form-control"
                id="reportFrom"
                name="from"
                type="date"
                value="<?= escape_html($from) ?>"
                required>

            <span aria-hidden="true">to</span>

            <label class="visually-hidden" for="reportTo">Report end date</label>
            <input
                class="form-control"
                id="reportTo"
                name="to"
                type="date"
                value="<?= escape_html($to) ?>"
                required>

            <button class="btn btn-primary" type="submit">Apply</button>
        </form>

        <div class="admin-stats admin-stats--four">
            <article>
                <i class="bi bi-calendar-check" aria-hidden="true"></i>
                <span>
                    <strong><?= count($rows) ?></strong>
                    <small>Bookings</small>
                </span>
            </article>
            <article>
                <i class="bi bi-receipt" aria-hidden="true"></i>
                <span>
                    <strong><?= money($bookingValue) ?></strong>
                    <small>Booking value</small>
                </span>
            </article>
            <article>
                <i class="bi bi-cash-stack" aria-hidden="true"></i>
                <span>
                    <strong><?= money($verifiedRevenue) ?></strong>
                    <small>Verified payments</small>
                </span>
            </article>
            <article>
                <i class="bi bi-check2-circle" aria-hidden="true"></i>
                <span>
                    <strong><?= $statusCounts["completed"] ?? 0 ?></strong>
                    <small>Completed rentals</small>
                </span>
            </article>
        </div>

        <div class="rental-adjustment-report-summary">
            <article><span>Pending rental adjustments</span><strong><?= $pendingAdjustments ?></strong></article>
            <article><span>Activated extension value</span><strong><?= money($activatedExtensionValue) ?></strong></article>
        </div>

        <div class="dashboard-grid">
            <article class="dashboard-panel">
                <span class="section-kicker">Status mix</span>
                <h2>Booking pipeline</h2>

                <div class="report-bars">
                    <?php foreach (booking_statuses() as $status): ?>
                        <?php
                        $count = $statusCounts[$status] ?? 0;

                        if ($count === 0) {
                            continue;
                        }

                        $statusWidth = max(
                            4,
                            round(($count / max(1, count($rows))) * 100),
                        );
                        ?>
                        <div class="report-bar">
                            <span><?= escape_html(
                                ucwords(str_replace("_", " ", $status)),
                            ) ?></span>
                            <i style="width: <?= $statusWidth ?>%"></i>
                            <strong><?= $count ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="dashboard-panel">
                <span class="section-kicker">Fleet performance</span>
                <h2>Most booked vehicles</h2>

                <div class="report-bars">
                    <?php foreach ($topVehicles as $vehicle): ?>
                        <?php
                        $vehicleRentals = (int) $vehicle["rentals"];
                        $vehicleWidth = max(
                            4,
                            round(($vehicleRentals / $maxRentals) * 100),
                        );
                        ?>
                        <div class="report-bar">
                            <span><?= escape_html($vehicle["name"]) ?></span>
                            <i style="width: <?= $vehicleWidth ?>%"></i>
                            <strong><?= $vehicleRentals ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>
        </div>

        <div class="admin-table-wrap mt-4" aria-label="Rental report details">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Customer</th>
                        <th>Vehicle</th>
                        <th>Schedule</th>
                        <th>Status</th>
                        <th>Total</th>
                        <th>Paid</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= escape_html($row["reference"]) ?></td>
                            <td><?= escape_html($row["customer_name"]) ?></td>
                            <td><?= escape_html($row["vehicle_name"]) ?></td>
                            <td>
                                <?= date(
                                    "M j, Y",
                                    strtotime($row["pickup_at"]),
                                ) ?>
                                <small>
                                    to <?= date("M j, Y", strtotime($row["return_at"])) ?>
                                    <?php if ($row["original_return_at"] && $row["original_return_at"] !== $row["return_at"]): ?>
                                        · originally <?= date("M j, Y", strtotime($row["original_return_at"])) ?>
                                    <?php endif; ?>
                                </small>
                            </td>
                            <td>
                                <span class="status-badge status-badge--<?= status_class(
                                    $row["status"],
                                ) ?>">
                                    <?= escape_html(ucfirst($row["status"])) ?>
                                </span>
                            </td>
                            <td><?= money((int) $row["total"]) ?></td>
                            <td><?= money((int) $row["paid_amount"]) ?></td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="7">No booking records in this period.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
