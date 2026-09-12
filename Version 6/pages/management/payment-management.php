<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";
$admin = require_admin();
$errors = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        require_csrf();
        $paymentId = filter_var($_POST["payment_id"] ?? null, FILTER_VALIDATE_INT);
        if (!$paymentId) {
            throw new InvalidArgumentException("Choose a valid payment.");
        }
        review_payment($paymentId, post_string("status"), post_string("notes"), (int) $admin["id"]);
        flash("success", "Payment status updated.");
        redirect("payment-management.php");
    } catch (Throwable $error) {
        $errors[] = user_facing_error_message($error);
    }
}

$status = trim((string) ($_GET["status"] ?? "all"));
if (!in_array($status, ["all", "pending", "paid", "failed"], true)) {
    $status = "all";
}
$sql =
    "SELECT p.*, b.reference AS booking_reference, u.name AS customer_name,
            u.email AS customer_email, v.name AS vehicle_name
     FROM payments p
     JOIN bookings b ON b.id = p.booking_id
     JOIN users u ON u.id = p.user_id
     JOIN vehicles v ON v.id = b.vehicle_id";
$params = [];
if ($status !== "all") {
    $sql .= " WHERE p.status = ?";
    $params[] = $status;
}
$sql .= " ORDER BY CASE p.status WHEN 'pending' THEN 0 ELSE 1 END, p.created_at DESC";
$statement = database()->prepare($sql);
$statement->execute($params);
$payments = $statement->fetchAll();
$pageTitle = "Payment Management | VJ Car Rental";
require dirname(__DIR__, 2) . "/includes/header.php";
?>
<section class="admin-page-heading">
    <div class="container"><div>
        <span class="section-kicker">Financial controls</span>
        <h1>Payment verification</h1>
        <p>Verify customer-submitted rental payments without pretending to process an external payment gateway.</p>
    </div></div>
</section>
<section class="content-section admin-section">
    <div class="container">
        <?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= escape_html($error) ?></div><?php endforeach; ?>
        <div class="admin-filter-tabs">
            <?php foreach (["all", "pending", "paid", "failed"] as $filter): ?>
                <a class="<?= $status === $filter ? "active" : "" ?>" href="payment-management.php?status=<?= $filter ?>"><?= ucfirst($filter) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead><tr><th>Booking</th><th>Customer</th><th>Payment</th><th>Reference / proof</th><th>Status</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach ($payments as $payment): ?>
                        <tr>
                            <td><a href="booking-management.php?reference=<?= urlencode($payment["booking_reference"]) ?>"><strong><?= escape_html($payment["booking_reference"]) ?></strong></a><small><?= escape_html($payment["vehicle_name"]) ?></small></td>
                            <td><?= escape_html($payment["customer_name"]) ?><small><?= escape_html($payment["customer_email"]) ?></small></td>
                            <td><strong><?= money((int) $payment["amount"]) ?></strong><small>Rental balance · <?= escape_html(ucwords(str_replace("_", " ", $payment["method"]))) ?></small></td>
                            <td><?= escape_html($payment["transaction_reference"] ?: "No reference") ?><?php if ($payment["proof_filename"]): ?><small><a href="secure-file.php?type=payment&amp;id=<?= (int) $payment["id"] ?>" target="_blank" rel="noopener">Open payment proof</a></small><?php endif; ?></td>
                            <td><span class="status-badge status-badge--<?= status_class($payment["status"]) ?>"><?= escape_html(ucfirst($payment["status"])) ?></span><?php if ($payment["paid_at"]): ?><small><?= date("M j, Y g:i A", strtotime($payment["paid_at"])) ?></small><?php endif; ?></td>
                            <td>
                                <?php if ($payment["status"] === "pending"): ?>
                                    <form class="admin-inline-form" method="post">
                                        <?= csrf_field() ?><input type="hidden" name="payment_id" value="<?= (int) $payment["id"] ?>">
                                        <select class="form-select form-select-sm" name="status" required><option value="">Status</option><option value="paid">Paid</option><option value="failed">Failed</option></select>
                                        <input class="form-control form-control-sm" name="notes" maxlength="2000" placeholder="Internal note">
                                        <button class="btn btn-primary btn-sm" type="submit">Save</button>
                                    </form>
                                <?php else: ?><small>Reviewed</small><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$payments): ?><tr><td colspan="6">No payments match this filter.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
