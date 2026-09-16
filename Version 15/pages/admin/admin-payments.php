<?php

declare(strict_types=1);


/**
 * FILE: pages/admin/admin-payments.php
 * FILE PURPOSE: Administrator payment review, verification, and refund-management page.
 * USED BY: Authenticated administrators using the corresponding management section.
 * RESPONSIBILITY: Loads the required application/services, handles only page-level request orchestration, and renders the user interface; reusable business/database logic belongs in services.
 *
 * Maintenance note: Keep this file focused on the responsibility described above.
 */
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
            redirect("admin-payments.php#refund-history");
        }
        if ($adminAction === "create_payment_refund") {
            $paymentId = filter_var($_POST["payment_id"] ?? null, FILTER_VALIDATE_INT);
            $refundAmount = filter_var($_POST["refund_amount"] ?? null, FILTER_VALIDATE_INT);
            if (!$paymentId || $refundAmount === false) {
                throw new InvalidArgumentException("Choose a verified payment and valid refund amount.");
            }
            create_payment_refund(
                (int) $paymentId,
                (int) $refundAmount,
                post_string("refund_reason"),
                post_string("refund_notes"),
                (int) $admin["id"],
            );
            flash("success", "Payment refund created and added to Refund Management.");
            redirect("admin-payments.php#refund-history");
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
$payments = admin_payments($status);
$refundStatus = trim((string) ($_GET["refund_status"] ?? "all"));
if (!in_array($refundStatus, array_merge(["all"], refund_statuses()), true)) {
    $refundStatus = "all";
}
$refundQuery = trim((string) ($_GET["refund_q"] ?? ""));
$refundType = trim((string) ($_GET["refund_type"] ?? "all"));
if (!in_array($refundType, array_merge(["all"], array_keys(refund_type_labels())), true)) {
    $refundType = "all";
}
$refunds = admin_refunds($refundStatus, $refundQuery, $refundType);
$pageTitle = "Payments & Refund Management | VJ Car Rental";
require dirname(__DIR__, 2) . "/includes/header.php";
require dirname(__DIR__, 2) . "/includes/admin-nav.php";
?>
<section class="admin-page-heading">
    <div class="container">
        <div>
            <span class="section-kicker">Financial controls</span>
            <h1>Payments & refund management</h1>
            <p>Verify customer payments and manage all four refund types through one auditable financial workflow.</p>
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
                    <?= humanize_label($filter) ?>
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
                                    <?= escape_html(payment_method_label((string) $payment["method"])) ?>
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
                                    <?= escape_html(humanize_label($payment["status"])) ?>
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
                                <?php if ($payment["status"] === "paid" && refundable_balance_for_payment((int) $payment["id"]) > 0): ?>
                                    <details class="refund-create-details mt-2">
                                        <summary>Create Payment Refund</summary>
                                        <form class="admin-inline-form mt-2" method="post">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="admin_action" value="create_payment_refund">
                                            <input type="hidden" name="payment_id" value="<?= (int) $payment["id"] ?>">
                                            <input class="form-control form-control-sm" name="refund_amount" type="number" min="1" max="<?= refundable_balance_for_payment((int) $payment["id"]) ?>" placeholder="Amount (max <?= money(refundable_balance_for_payment((int) $payment["id"])) ?>)" required>
                                            <input class="form-control form-control-sm" name="refund_reason" maxlength="2000" placeholder="Reason: duplicate, overpayment, correction..." required>
                                            <input class="form-control form-control-sm" name="refund_notes" maxlength="2000" placeholder="Admin note">
                                            <button class="btn btn-outline btn-sm" type="submit">Create Refund</button>
                                        </form>
                                    </details>
                                <?php endif; ?>
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
        <article class="admin-editor refund-management-panel mt-5" id="refund-history">
            <div class="admin-editor__heading">
                <div><span class="section-kicker">Unified financial controls</span><h2>Refund Management</h2><p>Cancellation, payment-correction, security-deposit, and modification refunds share one traceable workflow tied to verified payments.</p></div>
            </div>
            <form class="booking-selector mb-3" method="get">
                <label for="refundStatus">Refund status</label>
                <select class="form-select" id="refundStatus" name="refund_status">
                    <option value="all">All</option>
                    <?php foreach (refund_statuses() as $refundFilter): ?><option value="<?= escape_html($refundFilter) ?>" <?= $refundStatus === $refundFilter ? "selected" : "" ?>><?= escape_html(humanize_label($refundFilter)) ?></option><?php endforeach; ?>
                </select>
                <label for="refundType">Refund type</label>
                <select class="form-select" id="refundType" name="refund_type">
                    <option value="all">All refund types</option>
                    <?php foreach (refund_type_labels() as $typeKey => $typeLabel): ?><option value="<?= escape_html($typeKey) ?>" <?= $refundType === $typeKey ? "selected" : "" ?>><?= escape_html($typeLabel) ?></option><?php endforeach; ?>
                </select>
                <label class="visually-hidden" for="refundQuery">Search refunds</label>
                <input class="form-control" id="refundQuery" name="refund_q" value="<?= escape_html($refundQuery) ?>" placeholder="Booking, customer, vehicle, refund reference">
                <button class="btn btn-outline" type="submit">Filter Refunds</button>
            </form>
            <div class="admin-table-wrap"><table class="admin-table refund-management-table"><thead><tr><th>Booking</th><th>Customer</th><th>Refund type</th><th class="refund-amount-column">Amount / source</th><th>Status</th><th>Reference</th><th>Action</th></tr></thead><tbody>
            <?php foreach ($refunds as $refund): ?>
                <tr>
                    <td><a href="admin-bookings.php?reference=<?= urlencode($refund["booking_reference"]) ?>"><strong><?= escape_html($refund["booking_reference"]) ?></strong></a><small><?= escape_html($refund["vehicle_name"]) ?></small></td>
                    <td><?= escape_html($refund["customer_name"]) ?><small><?= escape_html($refund["customer_email"]) ?></small></td>
                    <td>
                        <strong><?= escape_html(refund_type_label((string) $refund["refund_type"])) ?></strong>
                        <small><?= escape_html((string) $refund["reason"]) ?></small>
                        <details class="refund-create-details mt-2">
                            <summary>Refund details</summary>
                            <small>Original payment: <?= money((int) $refund["payment_amount"]) ?></small>
                            <small>Remaining refundable: <?= money(refundable_balance_for_payment((int) $refund["payment_id"])) ?></small>
                            <?php if ($refund["admin_note"]): ?><small>Admin note: <?= escape_html((string) $refund["admin_note"]) ?></small><?php endif; ?>
                            <?php $refundAudit = refund_audit_history((int) $refund["id"]); ?>
                            <?php if ($refundAudit): ?><small>History:</small><?php foreach ($refundAudit as $auditItem): ?><small><?= date("M j, Y g:i A", strtotime($auditItem["created_at"])) ?> · <?= escape_html(ucwords(str_replace("_", " ", (string) $auditItem["action"]))) ?><?= $auditItem["actor_name"] ? " · " . escape_html($auditItem["actor_name"]) : "" ?></small><?php endforeach; ?><?php endif; ?>
                        </details>
                    </td>
                    <td class="refund-amount-column"><strong><?= money((int) $refund["amount"]) ?></strong><small>From <?= escape_html(ucwords(str_replace("_", " ", $refund["payment_type"]))) ?> payment of <?= money((int) $refund["payment_amount"]) ?></small><small>Remaining refundable on payment: <?= money(refundable_balance_for_payment((int) $refund["payment_id"])) ?></small></td>
                    <td><span class="status-badge status-badge--<?= status_class($refund["status"]) ?>"><?= escape_html(humanize_label($refund["status"])) ?></span><?php if ($refund["processed_at"]): ?><small><?= date("M j, Y g:i A", strtotime($refund["processed_at"])) ?></small><?php endif; ?></td>
                    <td><?= escape_html((string) ($refund["reference_number"] ?: "—")) ?></td>
                    <td>
                        <?php
                        $availableRefundTransitions = match ((string) $refund["status"]) {
                            "pending" => (string) $refund["refund_type"] === "security_deposit" ? ["approved"] : ["approved", "rejected", "cancelled"],
                            "approved" => (string) $refund["refund_type"] === "security_deposit" ? ["processing"] : ["processing", "rejected"],
                            "processing" => ["refunded", "failed"],
                            "failed" => (string) $refund["refund_type"] === "security_deposit" ? ["processing"] : ["processing", "rejected"],
                            default => [],
                        };
                        ?>
                        <?php if ($availableRefundTransitions): ?>
                        <form class="admin-inline-form" method="post">
                            <?= csrf_field() ?><input type="hidden" name="admin_action" value="refund_status"><input type="hidden" name="refund_id" value="<?= (int) $refund["id"] ?>">
                            <select class="form-select form-select-sm" name="refund_status" required>
                                <option value="">Action</option>
                                <?php foreach ($availableRefundTransitions as $transition): ?><option value="<?= escape_html($transition) ?>"><?= escape_html(match ($transition) { "approved" => "Approve", "processing" => "Mark Processing", "refunded" => "Mark Refunded", "rejected" => "Reject", "failed" => "Mark Failed", "cancelled" => "Cancel Refund", default => humanize_label($transition) }) ?></option><?php endforeach; ?>
                            </select>
                            <input class="form-control form-control-sm" name="refund_reference" maxlength="120" placeholder="Refund reference (auto-generated if blank on completion)">
                            <input class="form-control form-control-sm" name="refund_notes" maxlength="2000" placeholder="Admin note">
                            <button class="btn btn-primary btn-sm" type="submit">Save</button>
                        </form>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$refunds): ?><tr><td colspan="7">No refunds match this filter.</td></tr><?php endif; ?>
            </tbody></table></div>
        </article>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
