<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";
$user = require_auth();
$reference = trim((string) ($_GET["reference"] ?? ""));
$booking = $reference !== "" ? booking_find_by_reference($reference) : null;
$isAdmin = ($user["role"] ?? "") === "admin";

if (!$booking || ((int) $booking["user_id"] !== (int) $user["id"] && !$isAdmin)) {
    http_response_code(404);
    $pageTitle = "Invoice Not Found | VJ Car Rental";
    require dirname(__DIR__, 2) . "/includes/header.php";
    ?>
    <section class="content-section error-page"><div class="container">
        <i class="bi bi-receipt-cutoff"></i><h1>Invoice not found</h1>
        <p>The booking statement does not exist or is not available to this account.</p>
        <a class="btn btn-primary" href="<?= $isAdmin ? "payment-management.php" : "my-bookings.php" ?>"><?= $isAdmin ? "Payment Management" : "My Bookings" ?></a>
    </div></section>
    <?php require dirname(__DIR__, 2) . "/includes/footer.php"; exit();
}

$summary = booking_payment_summary($booking);
$days = max(1, (int) ceil((strtotime($booking["return_at"]) - strtotime($booking["pickup_at"])) / 86400));
$pageTitle = "Invoice " . $booking["reference"] . " | VJ Car Rental";
require dirname(__DIR__, 2) . "/includes/header.php";
?>
<section class="content-section invoice-page">
    <div class="container">
        <article class="invoice-card">
            <header class="invoice-header">
                <img src="assets/images/logo/Logo.png" alt="VJ Car Rental">
                <div><span>Rental statement</span><h1><?= escape_html($booking["reference"]) ?></h1><p>Issued <?= date("F j, Y") ?></p></div>
            </header>
            <div class="invoice-parties">
                <div>
                    <small>Billed to</small>
                    <strong><?= escape_html($booking["customer_name"]) ?></strong>
                    <span><?= escape_html($booking["customer_email"]) ?></span>
                    <span><?= escape_html($booking["customer_phone"] ?: "No phone provided") ?></span>
                </div>
                <div>
                    <small>Rental</small>
                    <strong><?= escape_html($booking["vehicle_name"]) ?></strong>
                    <span><?= date("M j, Y g:i A", strtotime($booking["pickup_at"])) ?></span>
                    <span>to <?= date("M j, Y g:i A", strtotime($booking["return_at"])) ?></span>
                </div>
            </div>
            <table class="invoice-table">
                <thead><tr><th>Description</th><th>Amount</th></tr></thead>
                <tbody>
                    <tr><td>Vehicle rental — <?= $days ?> day<?= $days === 1 ? "" : "s" ?></td><td><?= money((int) $booking["subtotal"]) ?></td></tr>
                    <tr class="invoice-total"><td>Rental total</td><td><?= money((int) $booking["total"]) ?></td></tr>
                    <tr><td>Verified payments</td><td>−<?= money((int) $summary["paid_total"]) ?></td></tr>
                    <tr class="invoice-balance"><td>Current amount due</td><td><?= money((int) $summary["amount_due"]) ?></td></tr>
                </tbody>
            </table>
            <div class="invoice-footer">
                <p><strong>Booking status:</strong> <?= escape_html(ucfirst($booking["status"])) ?></p>
                <p><strong>Payment status:</strong> <?= escape_html(ucfirst($summary["status"])) ?></p>
                <p>This statement reflects VJ Car Rental records. Pending payments are excluded from the verified amount until reviewed by an administrator.</p>
            </div>
            <div class="invoice-actions">
                <p class="print-instruction"><i class="bi bi-printer"></i> Use <strong>Ctrl + P</strong> to print or save this invoice as a PDF.</p>
                <a class="btn btn-outline" href="booking-view.php?reference=<?= urlencode($booking["reference"]) ?>">Back to Booking</a>
            </div>
        </article>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
