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
                    <tr class="invoice-total">
                        <td>Rental total</td>
                        <td><?= money($booking["total"]) ?></td>
                    </tr>
                    <tr>
                        <td>Refundable security deposit</td>
                        <td><?= money($booking["deposit"]) ?></td>
                    </tr>
                    <tr>
                        <td>Verified payments</td>
                        <td>−<?= money($summary["paid_total"]) ?></td>
                    </tr>
                    <tr class="invoice-balance">
                        <td>Current amount due</td>
                        <td><?= money(
                            $summary["deposit_due"] +
                                $summary["rental_due"] +
                                $summary["extra_due"],
                        ) ?></td>
                    </tr>
                </tbody>
            </table>
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
