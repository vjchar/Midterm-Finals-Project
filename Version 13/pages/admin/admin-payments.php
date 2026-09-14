<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";
$admin = require_admin();
$errors = [];
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        require_csrf();
        $adminAction = post_string("admin_action", "payment_status");
        if ($adminAction === "refund_status") {
            $refundId = filter_var($_POST["refund_id"] ?? null, FILTER_VALIDATE_INT);
            if (!$refundId) {
                throw new InvalidArgumentException("Choose a valid refund record.");
            }
            review_refund(
                (int) $refundId,
                post_string("refund_status"),
                (int) $admin["id"],
                post_string("refund_notes"),
                post_string("refund_reference"),
            );
            flash("success", "Refund status updated.");
            redirect("admin-payments.php?refund_status=" . urlencode((string) ($_GET["refund_status"] ?? "all")));
        }

        $paymentId = filter_var(
            $_POST["payment_id"] ?? null,
            FILTER_VALIDATE_INT,
        );
        if (!$paymentId) {
            throw new InvalidArgumentException("Choose a valid payment.");
        }
        review_payment(
            $paymentId,
            post_string("status"),
            post_string("notes"),
            $admin["id"],
        );
        flash("success", "Payment status updated.");
        redirect("admin-payments.php");
    } catch (Throwable $error) {
        $errors[] = user_facing_error_message($error);
    }
}
$status = trim((string) ($_GET["status"] ?? "all"));
if (
    !in_array($status, ["all", "pending", "paid", "failed", "refunded"], true)
) {
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
$sql .=
    ' ORDER BY CASE p.status WHEN \'pending\' THEN 0 ELSE 1 END, p.created_at DESC';
$statement = database()->prepare($sql);
$statement->execute($params);
$payments = $statement->fetchAll();
$refundStatus = trim((string) ($_GET["refund_status"] ?? "all"));
if (!in_array($refundStatus, array_merge(["all"], refund_statuses()), true)) {
    $refundStatus = "all";
}
$refundQuery = trim((string) ($_GET["refund_q"] ?? ""));
$refunds = admin_refunds($refundStatus, $refundQuery);
$pageTitle = "Payment Management | VJ Car Rental";
require dirname(__DIR__, 2) . "/includes/header.php";
require dirname(__DIR__, 2) . "/includes/admin-nav.php";
?>
<section class="admin-page-heading">
    <div class="container">
        <div>
            <span class="section-kicker">Financial controls</span>
            <h1>Payment verification</h1>
            <p>Verify deposits, rental balances, and return charges without pretending to process an external payment gateway.</p>
        </div>
        <a class="btn btn-primary" href="admin-reports.php">Open Reports</a>
    </div>
</section>
<section class="content-section admin-section">
    <div class="container">
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-danger"><?= escape_html($error) ?></div>
        <?php endforeach; ?>

        <div class="admin-filter-tabs">
            <?php foreach (["all", "pending", "paid", "failed", "refunded"] as $filter): ?>
                <a
                    class="<?= $status === $filter ? "active" : "" ?>"
                    href="admin-payments.php?status=<?= $filter ?>"
                >
                    <?= ucfirst($filter) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Booking</th>
                        <th>Customer</th>
                        <th>Payment</th>
                        <th>Reference / proof</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $payment): ?>
                        <tr>
                            <td>
                                <a href="admin-bookings.php?reference=<?= urlencode(
                                    $payment["booking_reference"],
                                ) ?>">
                                    <strong><?= escape_html($payment["booking_reference"]) ?></strong>
                                </a>
                                <small><?= escape_html($payment["vehicle_name"]) ?></small>
                            </td>
                            <td>
                                <?= escape_html($payment["customer_name"]) ?>
                                <small><?= escape_html($payment["customer_email"]) ?></small>
                            </td>
                            <td>
                                <strong><?= money((int) $payment["amount"]) ?></strong>
                                <small>
                                    <?= escape_html(
                                        ucwords(
                                            str_replace(
                                                "_",
                                                " ",
                                                $payment["payment_type"],
                                            ),
                                        ),
                                    ) ?> ·
                                    <?= escape_html(
                                        ucwords(
                                            str_replace(
                                                "_",
                                                " ",
                                                $payment["method"],
                                            ),
                                        ),
                                    ) ?>
                                </small>
                            </td>
                            <td>
                                <?= escape_html(
                                    $payment["transaction_reference"] ?:
                                        "No reference",
                                ) ?>
                                <?php if ($payment["proof_filename"]): ?>
                                    <small>
                                        <a
                                            href="secure-file.php?type=payment&amp;id=<?= (int) $payment["id"] ?>"
                                            target="_blank"
                                            rel="noopener"
                                        >
                                            Open payment proof
                                        </a>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge status-badge--<?= status_class(
                                    $payment["status"],
                                ) ?>">
                                    <?= escape_html(ucfirst($payment["status"])) ?>
                                </span>
                                <?php if ($payment["paid_at"]): ?>
                                    <small><?= date(
                                        "M j, Y g:i A",
                                        strtotime($payment["paid_at"]),
                                    ) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form class="admin-inline-form" method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="admin_action" value="payment_status">
                                    <input
                                        type="hidden"
                                        name="payment_id"
                                        value="<?= (int) $payment["id"] ?>"
                                    >
                                    <select class="form-select form-select-sm" name="status" required>
                                        <option value="">Status</option>
                                        <option value="paid">Paid</option>
                                        <option value="failed">Failed</option>
                                    </select>
                                    <input
                                        class="form-control form-control-sm"
                                        name="notes"
                                        maxlength="2000"
                                        placeholder="Internal note"
                                    >
                                    <button class="btn btn-primary btn-sm" type="submit">Save</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$payments): ?>
                        <tr>
                            <td colspan="6">No payments match this filter.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="container">
        <article class="admin-editor mt-5" id="refund-history">
            <div class="admin-editor__heading">
                <div><span class="section-kicker">Cancellation & credits</span><h2>Refund transaction history</h2><p>Every refund remains linked to its original verified payment. Gross payments stay intact for audit history while processed refunds reduce net revenue.</p></div>
            </div>
            <form class="booking-selector mb-3" method="get">
                <label for="refundStatus">Refund status</label>
                <select class="form-select" id="refundStatus" name="refund_status">
                    <option value="all">All</option>
                    <?php foreach (refund_statuses() as $refundFilter): ?><option value="<?= escape_html($refundFilter) ?>" <?= $refundStatus === $refundFilter ? "selected" : "" ?>><?= escape_html(ucfirst($refundFilter)) ?></option><?php endforeach; ?>
                </select>
                <label class="visually-hidden" for="refundQuery">Search refunds</label>
                <input class="form-control" id="refundQuery" name="refund_q" value="<?= escape_html($refundQuery) ?>" placeholder="Booking, customer, vehicle">
                <button class="btn btn-outline" type="submit">Filter Refunds</button>
            </form>
            <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Booking</th><th>Customer</th><th>Refund</th><th>Status</th><th>Reference</th><th>Action</th></tr></thead><tbody>
            <?php foreach ($refunds as $refund): ?>
                <tr>
                    <td><a href="admin-bookings.php?reference=<?= urlencode($refund["booking_reference"]) ?>"><strong><?= escape_html($refund["booking_reference"]) ?></strong></a><small><?= escape_html($refund["vehicle_name"]) ?></small></td>
                    <td><?= escape_html($refund["customer_name"]) ?><small><?= escape_html($refund["customer_email"]) ?></small></td>
                    <td><strong><?= money((int) $refund["amount"]) ?></strong><small>From <?= escape_html(ucwords(str_replace("_", " ", $refund["payment_type"]))) ?> payment of <?= money((int) $refund["payment_amount"]) ?></small></td>
                    <td><span class="status-badge status-badge--<?= status_class($refund["status"]) ?>"><?= escape_html(ucfirst($refund["status"])) ?></span><?php if ($refund["processed_at"]): ?><small><?= date("M j, Y g:i A", strtotime($refund["processed_at"])) ?></small><?php endif; ?></td>
                    <td><?= escape_html((string) ($refund["reference_number"] ?: "—")) ?></td>
                    <td>
                        <?php if (!in_array($refund["status"], ["refunded","rejected"], true)): ?>
                        <form class="admin-inline-form" method="post">
                            <?= csrf_field() ?><input type="hidden" name="admin_action" value="refund_status"><input type="hidden" name="refund_id" value="<?= (int) $refund["id"] ?>">
                            <select class="form-select form-select-sm" name="refund_status" required><option value="">Status</option><option value="approved">Approve</option><option value="processing">Processing</option><option value="refunded">Refunded</option><option value="rejected">Reject</option><option value="failed">Failed</option></select>
                            <input class="form-control form-control-sm" name="refund_reference" maxlength="120" placeholder="Refund reference">
                            <input class="form-control form-control-sm" name="refund_notes" maxlength="2000" placeholder="Admin note">
                            <button class="btn btn-primary btn-sm" type="submit">Save</button>
                        </form>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$refunds): ?><tr><td colspan="6">No refunds match this filter.</td></tr><?php endif; ?>
            </tbody></table></div>
        </article>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
