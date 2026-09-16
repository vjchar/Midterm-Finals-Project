<?php

declare(strict_types=1);


/**
 * FILE: pages/admin/admin-reports.php
 * FILE PURPOSE: Administrator reporting and operational summary page.
 * USED BY: Authenticated administrators using the corresponding management section.
 * RESPONSIBILITY: Loads the required application/services, handles only page-level request orchestration, and renders the user interface; reusable business/database logic belongs in services.
 *
 * Maintenance note: Keep this file focused on the responsibility described above.
 */
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

$report = admin_report_data($from, $to);
$rows = $report["rows"];
$adjustmentRows = $report["adjustment_rows"];
$pendingAdjustments = $report["pending_adjustments"];
$activatedExtensionValue = $report["activated_extension_value"];
$pendingModifications = $report["pending_modifications"];
$cancellationCount = $report["cancellation_count"];
$statusCounts = $report["status_counts"];
$bookingValue = $report["booking_value"];
$verifiedRevenue = $report["verified_revenue"];
$refundTotal = $report["refund_total"];
$netRevenue = $report["net_revenue"];
$finance = $report["finance"];
$refundByType = $report["refund_by_type"];
$pendingRefundAmount = $report["pending_refund_amount"];
$refundSource = $report["refund_source"];
$settlementFinance = $report["settlement_finance"];
$securityDepositRefunds = $report["security_deposit_refunds"];
$depositsHeld = $report["deposits_held"];
$rentalRevenue = $report["rental_revenue"];
$topVehicles = $report["top_vehicles"];
$maxRentals = $report["max_rentals"];

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
        "Refunds",
        "Net Verified Payments",
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
            $row["refunded_amount"],
            (int) $row["paid_amount"] - (int) $row["refunded_amount"],
        ]);
    }

    fclose($output);
    exit();
}

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
                <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
                <span><strong><?= money($refundTotal) ?></strong><small>Processed refunds</small></span>
            </article>
            <article>
                <i class="bi bi-graph-up-arrow" aria-hidden="true"></i>
                <span><strong><?= money($netRevenue) ?></strong><small>Net verified payments</small></span>
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
            <article><span>Pending booking modifications</span><strong><?= $pendingModifications ?></strong></article>
            <article><span>Cancellation requests</span><strong><?= $cancellationCount ?></strong></article>
        </div>
        <div class="rental-adjustment-report-summary mt-3">
            <article><span>Rental revenue</span><strong><?= money($rentalRevenue) ?></strong></article>
            <article><span>Security deposits collected</span><strong><?= money((int) $finance["deposits_collected"]) ?></strong></article>
            <article><span>Deposits held</span><strong><?= money($depositsHeld) ?></strong></article>
            <article><span>Deposit deductions retained</span><strong><?= money((int) $settlementFinance["deductions_retained"]) ?></strong></article>
            <article><span>Security deposit refunds</span><strong><?= money($securityDepositRefunds) ?></strong></article>
            <article><span>Outstanding receivables</span><strong><?= money((int) $settlementFinance["outstanding_receivables"]) ?></strong></article>
            <article><span>Pending refund amount</span><strong><?= money($pendingRefundAmount) ?></strong></article>
            <article><span>Processed refunds</span><strong><?= money($refundTotal) ?></strong></article>
        </div>
        <div class="rental-adjustment-report-summary mt-3">
            <?php foreach (refund_type_labels() as $refundTypeKey => $refundTypeLabel): ?>
                <article><span><?= escape_html($refundTypeLabel) ?></span><strong><?= money((int) ($refundByType[$refundTypeKey] ?? 0)) ?></strong></article>
            <?php endforeach; ?>
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
                        <th>Refunded</th>
                        <th>Net</th>
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
                                <small> to <?= date("M j, Y", strtotime($row["return_at"])) ?>
                                    <?php if ($row["original_return_at"] && $row["original_return_at"] !== $row["return_at"]): ?>
                                        · originally <?= date("M j, Y", strtotime($row["original_return_at"])) ?>
                                    <?php endif; ?>
                                </small>
                            </td>
                            <td>
                                <span class="status-badge status-badge--<?= status_class(
                                                                            $row["status"],
                                                                        ) ?>">
                                    <?= escape_html(humanize_label($row["status"])) ?>
                                </span>
                            </td>
                            <td><?= money((int) $row["total"]) ?></td>
                            <td><?= money((int) $row["paid_amount"]) ?></td>
                            <td><?= money((int) $row["refunded_amount"]) ?></td>
                            <td><?= money(max(0, (int) $row["paid_amount"] - (int) $row["refunded_amount"])) ?></td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="9">No booking records in this period.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>