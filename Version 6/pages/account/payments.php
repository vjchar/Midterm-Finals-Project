<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";
$user = require_auth();
if (($user["role"] ?? "") === "admin") {
    redirect("payment-management.php");
}

$userBookings = bookings_for_user((int) $user["id"]);
$eligibleBookings = array_values(
    array_filter(
        $userBookings,
        static fn(array $booking): bool => in_array($booking["status"], ["pending", "confirmed"], true),
    ),
);

$selectedReference = trim((string) ($_GET["reference"] ?? ($_POST["reference"] ?? ($eligibleBookings[0]["reference"] ?? ""))));
$selectedBooking = $selectedReference !== "" ? booking_find_by_reference($selectedReference) : null;
if ($selectedBooking && (int) $selectedBooking["user_id"] !== (int) $user["id"]) {
    $selectedBooking = null;
}

$errors = [];
if ($_SERVER["REQUEST_METHOD"] === "POST" && $selectedBooking) {
    $uploadedProofFilename = "";
    try {
        require_csrf();

        if (
            isset($_FILES["payment_proof"]) &&
            ($_FILES["payment_proof"]["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
        ) {
            $uploadedProofFilename = upload_file(
                $_FILES["payment_proof"],
                ROOT . DIRECTORY_SEPARATOR . "storage" . DIRECTORY_SEPARATOR . "payment-proofs",
                [
                    "image/jpeg" => "jpg",
                    "image/png" => "png",
                    "application/pdf" => "pdf",
                ],
                5 * 1024 * 1024,
                "payment-" . $selectedBooking["reference"],
            );
        }

        create_payment_request(
            $selectedBooking,
            (int) $user["id"],
            post_string("method"),
            post_string("transaction_reference"),
            $uploadedProofFilename,
        );
        flash("success", "Payment submitted for administrator verification.");
        redirect("payments.php?reference=" . urlencode((string) $selectedBooking["reference"]));
    } catch (Throwable $exception) {
        if ($uploadedProofFilename !== "") {
            $uploadedProofPath = ROOT . DIRECTORY_SEPARATOR . "storage" . DIRECTORY_SEPARATOR . "payment-proofs" . DIRECTORY_SEPARATOR . basename($uploadedProofFilename);
            if (is_file($uploadedProofPath)) {
                unlink($uploadedProofPath);
            }
        }
        $errors[] = user_facing_error_message($exception);
    }
}

$paymentSummary = $selectedBooking ? booking_payment_summary($selectedBooking) : null;
$pageTitle = "Payments | VJ Car Rental";
require dirname(__DIR__, 2) . "/includes/header.php";
?>
<section class="page-hero page-hero--compact pattern-layer">
    <div class="container">
        <span class="section-kicker">Transparent payment tracking</span>
        <h1>Booking payments</h1>
        <p>Submit your rental payment reference or proof, then track administrator verification. This version records payments but does not charge cards online.</p>
    </div>
</section>
<section class="content-section operations-page">
    <div class="container">
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-danger" role="alert"><?= escape_html($error) ?></div>
        <?php endforeach; ?>

        <?php if (!$eligibleBookings): ?>
            <div class="empty-state">
                <i class="bi bi-receipt"></i>
                <h2>No payable bookings</h2>
                <p>Create an active booking before submitting a rental payment.</p>
                <a class="btn btn-primary" href="booking.php">Book a Vehicle</a>
            </div>
        <?php else: ?>
            <form class="booking-selector" method="get">
                <label for="paymentBooking">Booking</label>
                <select class="form-select" id="paymentBooking" name="reference" required>
                    <?php foreach ($eligibleBookings as $eligibleBooking): ?>
                        <option value="<?= escape_html($eligibleBooking["reference"]) ?>" <?= $selectedBooking && $eligibleBooking["reference"] === $selectedBooking["reference"] ? "selected" : "" ?>>
                            <?= escape_html($eligibleBooking["reference"] . " — " . $eligibleBooking["vehicle_name"]) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-outline" type="submit">Open booking</button>
            </form>

            <?php if ($selectedBooking && $paymentSummary): ?>
                <div class="payment-overview">
                    <article><span>Rental total</span><strong><?= money((int) $selectedBooking["total"]) ?></strong></article>
                    <article><span>Verified payments</span><strong><?= money((int) $paymentSummary["paid_total"]) ?></strong></article>
                    <article><span>Awaiting verification</span><strong><?= money((int) $paymentSummary["pending_total"]) ?></strong></article>
                    <article><span>Amount due</span><strong><?= money((int) $paymentSummary["amount_due"]) ?></strong></article>
                </div>

                <div class="row g-4 align-items-start">
                    <div class="col-lg-5">
                        <article class="operation-card">
                            <span class="section-kicker">Submit payment</span>
                            <h2><?= escape_html($selectedBooking["reference"]) ?></h2>
                            <?php if ($paymentSummary["amount_due"] === 0): ?>
                                <div class="alert alert-success">The rental total is fully verified.</div>
                            <?php elseif ($paymentSummary["pending_total"] > 0): ?>
                                <div class="alert alert-warning">A full rental payment is already awaiting administrator verification.</div>
                            <?php else: ?>
                                <form class="row g-3" method="post" enctype="multipart/form-data">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="reference" value="<?= escape_html($selectedBooking["reference"]) ?>">
                                    <div class="col-12">
                                        <label class="form-label" for="paymentAmount">Amount due</label>
                                        <input class="form-control" id="paymentAmount" value="<?= (int) $paymentSummary["amount_due"] ?>" readonly>
                                        <small class="text-muted">The server uses the booking total; this amount cannot be changed by the customer.</small>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label" for="paymentMethod">Method</label>
                                        <select class="form-select" id="paymentMethod" name="method" required>
                                            <option value="gcash">GCash</option>
                                            <option value="bank_transfer">Bank transfer</option>
                                            <option value="cash">Cash at branch</option>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label" for="transactionReference">Transaction reference</label>
                                        <input class="form-control" id="transactionReference" name="transaction_reference" maxlength="120" autocomplete="off">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label" for="paymentProof">Payment proof (optional JPG, PNG, or PDF)</label>
                                        <input class="form-control" id="paymentProof" name="payment_proof" type="file" accept=".jpg,.jpeg,.png,.pdf">
                                        <small class="text-muted">Maximum file size: 5 MB. Non-cash payments require a transaction reference or proof.</small>
                                    </div>
                                    <div class="col-12">
                                        <button class="btn btn-primary" type="submit">Submit Payment</button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </article>
                    </div>

                    <div class="col-lg-7">
                        <article class="operation-card">
                            <div class="operation-card__heading">
                                <div>
                                    <span class="section-kicker">Payment history</span>
                                    <h2>Recorded transactions</h2>
                                </div>
                                <a class="btn btn-outline btn-sm" href="invoice.php?reference=<?= urlencode($selectedBooking["reference"]) ?>">
                                    <i class="bi bi-receipt"></i> Invoice
                                </a>
                            </div>
                            <?php if (!$paymentSummary["payments"]): ?>
                                <p class="display-note">No payment has been submitted for this booking.</p>
                            <?php else: ?>
                                <div class="admin-table-wrap">
                                    <table class="admin-table">
                                        <thead><tr><th>Date</th><th>Method</th><th>Amount</th><th>Reference</th><th>Status</th><th>Proof</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($paymentSummary["payments"] as $payment): ?>
                                                <tr>
                                                    <td><?= date("M j, Y", strtotime($payment["created_at"])) ?></td>
                                                    <td><?= escape_html(ucwords(str_replace("_", " ", $payment["method"]))) ?></td>
                                                    <td><?= money((int) $payment["amount"]) ?></td>
                                                    <td><?= escape_html($payment["transaction_reference"] ?: "No reference") ?></td>
                                                    <td><span class="status-badge status-badge--<?= status_class($payment["status"]) ?>"><?= escape_html(ucfirst($payment["status"])) ?></span></td>
                                                    <td><?php if ($payment["proof_filename"]): ?><a href="secure-file.php?type=payment&amp;id=<?= (int) $payment["id"] ?>" target="_blank" rel="noopener">View</a><?php else: ?>—<?php endif; ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </article>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
