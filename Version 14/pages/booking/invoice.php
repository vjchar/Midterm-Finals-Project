<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";
$user = require_customer();
$reference = trim((string) ($_GET["reference"] ?? ""));
$booking = $reference !== "" ? booking_find_by_reference($reference) : null;
if (!$booking || (int) $booking["user_id"] !== (int) $user["id"]) {
    http_response_code(404);
    $pageTitle = "Invoice Not Found | VJ Car Rental";
    require dirname(__DIR__, 2) . "/includes/header.php";
    ?>
    <section class="content-section error-page">
        <div class="container">
            <i class="bi bi-receipt-cutoff"></i>
            <h1>Invoice not found</h1>
            <p>The booking statement does not exist or is not available to this account.</p>
            <a class="btn btn-primary" href="my-bookings.php">My Bookings</a>
        </div>
    </section>
    <?php
    require dirname(__DIR__, 2) . "/includes/footer.php";
    exit();
}
$summary = booking_payment_summary($booking);
$adjustments = rental_adjustments_for_booking((int) $booking["id"]);
$extensionTotal = array_sum(array_map(static fn(array $item): int => $item["request_type"] === "extension" && in_array($item["status"], ["activated","completed"], true) ? (int) $item["price_difference"] : 0, $adjustments));
$refunds = refunds_for_booking((int) $booking["id"]);
$settlement = rental_settlement_for_booking((int) $booking["id"]);
$processedRefundTotal = array_sum(array_map(static fn(array $refund): int => $refund["status"] === "refunded" ? (int) $refund["amount"] : 0, $refunds));
$pendingRefundTotal = array_sum(array_map(static fn(array $refund): int => in_array($refund["status"], ["pending","approved","processing"], true) ? (int) $refund["amount"] : 0, $refunds));
$netVerifiedPayments = max(0, (int) $summary["paid_total"] - $processedRefundTotal);
$pageTitle = "Invoice " . $booking["reference"] . " | VJ Car Rental";
require dirname(__DIR__, 2) . "/includes/header.php";
?>
<section class="content-section invoice-page">
    <div class="container">
        <article class="invoice-card">
            <header class="invoice-header">
                <img src="assets/images/logo/Logo.png" alt="VJ Car Rental">
                <div>
                    <span>Rental statement</span>
                    <h1><?= escape_html($booking["reference"]) ?></h1>
                    <p>Issued <?= date("F j, Y") ?></p>
                </div>
            </header>
            <div class="invoice-parties">
                <div>
                    <small>Billed to</small>
                    <strong><?= escape_html($booking["customer_name"]) ?></strong>
                    <span><?= escape_html($booking["customer_email"]) ?></span>
                    <span><?= escape_html(
                        $booking["customer_phone"] ?: "No phone provided",
                    ) ?></span>
                </div>
                <div>
                    <small>Rental</small>
                    <strong><?= escape_html($booking["vehicle_name"]) ?></strong>
                    <span><?= date(
                        "M j, Y g:i A",
                        strtotime($booking["pickup_at"]),
                    ) ?></span>
                    <span>to <?= date(
                        "M j, Y g:i A",
                        strtotime($booking["return_at"]),
                    ) ?></span>
                </div>
            </div>
            <table class="invoice-table">
                <thead>
                    <tr>
                        <th>Description</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Vehicle rental subtotal</td>
                        <td><?= money($booking["subtotal"]) ?></td>
                    </tr>
                    <?php foreach ($booking["addons"] as $addon): ?>
                        <tr>
                            <td><?= escape_html(
                                $addon["addon_name"],
                            ) ?> × <?= (int) $addon["quantity"] ?></td>
                            <td><?= money((int) $addon["line_total"]) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <td>Delivery</td>
                        <td><?= money($booking["delivery_fee"]) ?></td>
                    </tr>
                    <?php if ($booking["discount"]): ?>
                        <tr>
                            <td>Promotion discount</td>
                            <td>−<?= money($booking["discount"]) ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($extensionTotal > 0): ?>
                    <tr>
                        <td>Approved extension adjustments <small>(included in current rental total)</small></td>
                        <td><?= money($extensionTotal) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="invoice-total">
                        <td>Current rental total</td>
                        <td><?= money($booking["total"]) ?></td>
                    </tr>
                    <tr>
                        <td>Refundable security deposit</td>
                        <td><?= money($booking["deposit"]) ?></td>
                    </tr>
                    <?php if ($settlement): ?>
                    <tr><td>Deposit deductions</td><td>−<?= money((int) $settlement["total_deductions"]) ?></td></tr>
                    <tr><td>Security deposit refund</td><td><?= money((int) $settlement["deposit_refund_amount"]) ?></td></tr>
                    <?php if ((int) $settlement["outstanding_balance"] > 0): ?><tr><td>Outstanding return balance</td><td><?= money((int) $settlement["outstanding_balance"]) ?></td></tr><?php endif; ?>
                    <?php endif; ?>
                    <tr>
                        <td>Verified payments (gross)</td>
                        <td>−<?= money($summary["paid_total"]) ?></td>
                    </tr>
                    <?php if ($processedRefundTotal > 0): ?>
                    <tr>
                        <td>Processed refunds</td>
                        <td>+<?= money($processedRefundTotal) ?></td>
                    </tr>
                    <tr>
                        <td>Net verified payments</td>
                        <td>−<?= money($netVerifiedPayments) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($pendingRefundTotal > 0): ?>
                    <tr>
                        <td>Refund pending / processing</td>
                        <td><?= money($pendingRefundTotal) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="invoice-balance">
                        <td>Current amount due</td>
                        <td><?= money($booking["status"] === "cancelled" ? 0 : (
                            $summary["deposit_due"] +
                                $summary["rental_due"] +
                                $summary["extra_due"] +
                                $summary["extension_due"] +
                                (int) ($summary["modification_due"] ?? 0)
                        )) ?></td>
                    </tr>
                </tbody>
            </table>
            <?php if ($refunds): ?>
            <div class="invoice-refunds">
                <h2>Refund history</h2>
                <table class="invoice-table"><thead><tr><th>Refund type</th><th>Amount</th><th>Status</th></tr></thead><tbody>
                <?php foreach ($refunds as $refund): ?><tr><td><?= escape_html(refund_type_label((string) $refund["refund_type"])) ?><small><?= escape_html((string) $refund["reason"]) ?></small></td><td><?= money((int) $refund["amount"]) ?><small><?= $refund["processed_at"] ? " · " . date("M j, Y", strtotime($refund["processed_at"])) : "" ?><?= $refund["reference_number"] ? " · " . escape_html($refund["reference_number"]) : "" ?></small></td><td><?= escape_html(ucfirst($refund["status"])) ?></td></tr><?php endforeach; ?>
                </tbody></table>
            </div>
            <?php endif; ?>
            <div class="invoice-footer">
                <p>
                    <strong>Status:</strong>
                    <?= escape_html(ucfirst($booking["status"])) ?>
                </p>
                <p>This statement reflects records in the VJ Car Rental system. Pending payments are excluded until verified.</p>
            </div>
            <div class="invoice-actions">
                <p class="print-instruction">
                    <i class="bi bi-printer"></i>
                    Use <strong>Ctrl + P</strong> to print or save this invoice as a PDF.
                </p>
                <a
                    class="btn btn-outline"
                    href="booking-view.php?reference=<?= urlencode(
                        $booking["reference"],
                    ) ?>"
                >
                    Back to Booking
                </a>
            </div>
        </article>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
