<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";

$authenticatedUser = require_customer();
$userBookings = bookings_for_user((int) $authenticatedUser["id"]);
$eligibleBookings = array_values(
    array_filter(
        $userBookings,
        static fn(array $userBooking): bool => !in_array(
            $userBooking["status"],
            ["cancelled", "rejected", "no_show"],
            true,
        ),
    ),
);

$selectedBookingReference = trim(
    (string) ($_GET["reference"] ??
        ($_POST["reference"] ?? ($eligibleBookings[0]["reference"] ?? ""))),
);
$selectedBooking =
    $selectedBookingReference !== ""
        ? booking_find_by_reference($selectedBookingReference)
        : null;

if (
    $selectedBooking &&
    (int) $selectedBooking["user_id"] !== (int) $authenticatedUser["id"]
) {
    $selectedBooking = null;
}

$paymentValidationErrors = [];

if ($_SERVER["REQUEST_METHOD"] === "POST" && $selectedBooking) {
    $uploadedProofFilename = "";

    try {
        require_csrf();

        if (
            isset($_FILES["payment_proof"]) &&
            ($_FILES["payment_proof"]["error"] ?? UPLOAD_ERR_NO_FILE) !==
                UPLOAD_ERR_NO_FILE
        ) {
            $uploadedProofFilename = upload_file(
                $_FILES["payment_proof"],
                ROOT .
                    DIRECTORY_SEPARATOR .
                    "storage" .
                    DIRECTORY_SEPARATOR .
                    "payment-proofs",
                [
                    "image/jpeg" => "jpg",
                    "image/png" => "png",
                    "application/pdf" => "pdf",
                ],
                5 * 1024 * 1024,
                "payment-" . $selectedBooking["reference"],
            );
        }

        $submittedAmount = filter_var(
            $_POST["amount"] ?? null,
            FILTER_VALIDATE_INT,
        );
        if ($submittedAmount === false) {
            throw new InvalidArgumentException(
                "Enter a valid whole-peso amount.",
            );
        }

        $submittedPaymentType = post_string("payment_type");
        create_payment_request(
            $selectedBooking,
            (int) $authenticatedUser["id"],
            $submittedPaymentType,
            post_string("method"),
            (int) $submittedAmount,
            post_string("transaction_reference"),
            $uploadedProofFilename,
            filter_var($_POST["rental_adjustment_id"] ?? null, FILTER_VALIDATE_INT) ?: null,
            filter_var($_POST["booking_modification_id"] ?? null, FILTER_VALIDATE_INT) ?: null,
        );
        if ($submittedPaymentType === "extension") {
            flash("success", "Extension payment submitted for administrator verification.");
            redirect(
                "booking-view.php?reference=" . urlencode((string) $selectedBooking["reference"]),
            );
        }
        if ($submittedPaymentType === "modification") {
            flash("success", "Booking modification payment submitted for administrator verification.");
            redirect(
                "booking-view.php?reference=" . urlencode((string) $selectedBooking["reference"]),
            );
        }
        if ($submittedPaymentType === "extra_charge") {
            flash("success", "Return-balance payment submitted for administrator verification.");
            redirect(
                "booking-view.php?reference=" . urlencode((string) $selectedBooking["reference"]),
            );
        }
        $postPaymentJourney = booking_next_step($selectedBooking);
        flash(
            "success",
            $postPaymentJourney["stage"] === "documents"
                ? "Payment submitted. Your next unfinished step is to upload the required documents."
                : "Payment submitted. The system skipped any completed requirements and moved you to the next unfinished step.",
        );
        if ($postPaymentJourney["stage"] === "documents") {
            redirect(
                "documents.php?reference=" . urlencode((string) $selectedBooking["reference"]) . "&payment_submitted=1",
            );
        }
        redirect($postPaymentJourney["target_url"]);
    } catch (Throwable $exception) {
        if ($uploadedProofFilename !== "") {
            $uploadedProofPath =
                ROOT .
                DIRECTORY_SEPARATOR .
                "storage" .
                DIRECTORY_SEPARATOR .
                "payment-proofs" .
                DIRECTORY_SEPARATOR .
                basename($uploadedProofFilename);
            if (is_file($uploadedProofPath)) {
                unlink($uploadedProofPath);
            }
        }

        $paymentValidationErrors[] = user_facing_error_message($exception);
    }
}

$paymentSummary = $selectedBooking
    ? booking_payment_summary($selectedBooking)
    : null;
$approvedExtension = $selectedBooking ? approved_extension_for_booking((int) $selectedBooking["id"]) : null;
$approvedModification = $selectedBooking ? approved_modification_for_booking((int) $selectedBooking["id"]) : null;
$journey = $selectedBooking ? booking_next_step($selectedBooking) : null;
$customerRefunds = refunds_for_user((int) $authenticatedUser["id"]);
$pageTitle = "Payments & Refunds | VJ Car Rental";

require dirname(__DIR__, 2) . "/includes/header.php";
?>
<section class="page-hero page-hero--compact pattern-layer">
    <div class="container">
        <span class="section-kicker">Step 2 · Secure payment</span>
        <h1>Booking payments & refunds</h1>
        <p>
            Submit a payment reference or proof, then track administrator
            verification. This school build records payments but does not charge
            cards online.
        </p>
    </div>
</section>

<section class="content-section operations-page">
    <div class="container">
        <?php foreach ($paymentValidationErrors as $validationError): ?>
            <div class="alert alert-danger" role="alert">
                <?= escape_html($validationError) ?>
            </div>
        <?php endforeach; ?>

        <?php if ($journey): ?>
            <?php render_booking_progress($journey); ?>
            <?php if ($journey["stage"] !== "payment"): ?>
                <?php render_booking_next_step($journey, "Current booking step"); ?>
            <?php else: ?>
                <article class="journey-next journey-next--primary">
                    <div class="journey-next__icon"><i class="bi bi-credit-card"></i></div>
                    <div class="journey-next__body"><span class="section-kicker">Step 2 of 5</span><h2>Complete Payment</h2><p>Submit the required security deposit for booking <?= escape_html($selectedBooking["reference"]) ?>. After submission, the system will continue to your first unfinished requirement and will skip documents that are already verified.</p></div>
                </article>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!$eligibleBookings): ?>
            <div class="empty-state">
                <i class="bi bi-receipt"></i>
                <h2>No payable bookings</h2>
                <p>Create a booking before submitting a deposit or rental payment.</p>
                <a class="btn btn-primary" href="booking.php">Book a Vehicle</a>
            </div>
        <?php else: ?>
            <form class="booking-selector" method="get">
                <label for="paymentBooking">Booking</label>
                <select
                    class="form-select"
                    id="paymentBooking"
                    name="reference"
                    required
                >
                    <?php foreach ($eligibleBookings as $eligibleBooking): ?>
                        <option
                            value="<?= escape_html($eligibleBooking["reference"]) ?>"
                            <?= $selectedBooking &&
                            $eligibleBooking["reference"] ===
                                $selectedBooking["reference"]
                                ? "selected"
                                : "" ?>
                        >
                            <?= escape_html(
                                $eligibleBooking["reference"] .
                                    " — " .
                                    $eligibleBooking["vehicle_name"],
                            ) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-outline" type="submit">
                    Open booking
                </button>
            </form>

            <?php if ($selectedBooking && $paymentSummary): ?>
                <div class="payment-overview">
                    <article>
                        <span>Security deposit due</span>
                        <strong><?= money((int) $paymentSummary["deposit_due"]) ?></strong>
                    </article>
                    <article>
                        <span>Rental balance due</span>
                        <strong><?= money((int) $paymentSummary["rental_due"]) ?></strong>
                    </article>
                    <article>
                        <span>Extra charges due</span>
                        <strong><?= money((int) $paymentSummary["extra_due"]) ?></strong>
                    </article>
                    <article>
                        <span>Extension charge due</span>
                        <strong><?= money((int) $paymentSummary["extension_due"]) ?></strong>
                    </article>
                    <article>
                        <span>Modification charge due</span>
                        <strong><?= money((int) ($paymentSummary["modification_due"] ?? 0)) ?></strong>
                    </article>
                    <article>
                        <span>Verified payments</span>
                        <strong><?= money((int) $paymentSummary["paid_total"]) ?></strong>
                    </article>
                </div>

                <div class="row g-4 align-items-start">
                    <div class="col-lg-5">
                        <article class="operation-card">
                            <span class="section-kicker">Submit payment</span>
                            <h2><?= escape_html($selectedBooking["reference"]) ?></h2>

                            <?php if (
                                (int) $paymentSummary["deposit_due"] +
                                    (int) $paymentSummary["rental_due"] +
                                    (int) $paymentSummary["extra_due"] +
                                    (int) $paymentSummary["extension_due"] +
                                    (int) ($paymentSummary["modification_due"] ?? 0) === 0
                            ): ?>
                                <div class="alert alert-success">
                                    All current payment obligations are fully verified.
                                </div>
                            <?php else: ?>
                                <form
                                    class="row g-3"
                                    method="post"
                                    enctype="multipart/form-data"
                                >
                                    <?= csrf_field() ?>
                                    <input
                                        type="hidden"
                                        name="reference"
                                        value="<?= escape_html($selectedBooking["reference"]) ?>"
                                    >
                                    <?php if ($approvedExtension): ?>
                                        <input type="hidden" name="rental_adjustment_id" value="<?= (int) $approvedExtension["id"] ?>">
                                    <?php endif; ?>
                                    <?php if ($approvedModification): ?>
                                        <input type="hidden" name="booking_modification_id" value="<?= (int) $approvedModification["id"] ?>">
                                    <?php endif; ?>

                                    <div class="col-md-6">
                                        <label class="form-label" for="paymentType">
                                            Payment for
                                        </label>
                                        <select
                                            class="form-select"
                                            id="paymentType"
                                            name="payment_type"
                                            required
                                        >
                                            <?php if ((int) $paymentSummary["deposit_due"] > 0): ?><option value="deposit">Security deposit</option><?php endif; ?>
                                            <?php if ((int) $paymentSummary["rental_due"] > 0): ?><option value="balance">Rental balance</option><?php endif; ?>
                                            <?php if ((int) $paymentSummary["extra_due"] > 0): ?><option value="extra_charge">Outstanding return balance</option><?php endif; ?>
                                            <?php if ($approvedExtension && (int) $paymentSummary["extension_due"] > 0): ?>
                                                <option value="extension">Approved extension</option>
                                            <?php endif; ?>
                                            <?php if ($approvedModification && (int) ($paymentSummary["modification_due"] ?? 0) > 0): ?>
                                                <option value="modification">Approved booking modification</option>
                                            <?php endif; ?>
                                        </select>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label" for="paymentMethod">
                                            Method
                                        </label>
                                        <select
                                            class="form-select"
                                            id="paymentMethod"
                                            name="method"
                                            required
                                        >
                                            <option value="gcash">GCash</option>
                                            <option value="bank_transfer">Bank transfer</option>
                                            <option value="cash">Cash at branch</option>
                                        </select>
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label" for="paymentAmount">
                                            Amount (PHP)
                                        </label>
                                        <input
                                            class="form-control"
                                            id="paymentAmount"
                                            name="amount"
                                            type="number"
                                            min="1"
                                            step="1"
                                            required
                                        >
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label" for="transactionReference">
                                            Transaction reference
                                        </label>
                                        <input
                                            class="form-control"
                                            id="transactionReference"
                                            name="transaction_reference"
                                            maxlength="120"
                                            autocomplete="off"
                                        >
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label" for="paymentProof">
                                            Payment proof (optional JPG, PNG, or PDF)
                                        </label>
                                        <input
                                            class="form-control"
                                            id="paymentProof"
                                            name="payment_proof"
                                            type="file"
                                            accept=".jpg,.jpeg,.png,.pdf"
                                        >
                                    </div>

                                    <div class="col-12">
                                        <button class="btn btn-primary" type="submit">
                                            Submit Payment
                                        </button>
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
                                <a
                                    class="btn btn-outline btn-sm"
                                    href="invoice.php?reference=<?= urlencode($selectedBooking["reference"]) ?>"
                                >
                                    <i class="bi bi-receipt"></i>
                                    Invoice
                                </a>
                            </div>

                            <?php if (!$paymentSummary["payments"]): ?>
                                <p class="display-note">
                                    No payment has been submitted for this booking.
                                </p>
                            <?php else: ?>
                                <div class="admin-table-wrap">
                                    <table class="admin-table">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Type</th>
                                                <th>Method</th>
                                                <th>Amount</th>
                                                <th>Status</th>
                                                <th>Proof</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($paymentSummary["payments"] as $paymentRecord): ?>
                                                <tr>
                                                    <td>
                                                        <?= date(
                                                            "M j, Y",
                                                            strtotime($paymentRecord["created_at"]),
                                                        ) ?>
                                                    </td>
                                                    <td>
                                                        <?= escape_html(
                                                            ucwords(
                                                                str_replace(
                                                                    "_",
                                                                    " ",
                                                                    $paymentRecord["payment_type"],
                                                                ),
                                                            ),
                                                        ) ?>
                                                    </td>
                                                    <td>
                                                        <?= escape_html(
                                                            ucwords(
                                                                str_replace(
                                                                    "_",
                                                                    " ",
                                                                    $paymentRecord["method"],
                                                                ),
                                                            ),
                                                        ) ?>
                                                    </td>
                                                    <td>
                                                        <?= money((int) $paymentRecord["amount"]) ?>
                                                    </td>
                                                    <td>
                                                        <span class="status-badge status-badge--<?= status_class($paymentRecord["status"]) ?>">
                                                            <?= escape_html(ucfirst($paymentRecord["status"])) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php if ($paymentRecord["proof_filename"]): ?>
                                                            <a href="secure-file.php?type=payment&amp;id=<?= (int) $paymentRecord["id"] ?>">
                                                                View
                                                            </a>
                                                        <?php else: ?>
                                                            —
                                                        <?php endif; ?>
                                                    </td>
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

        <?php if ($customerRefunds): ?>
            <article class="operation-card mt-5" id="refund-history">
                <span class="section-kicker">Refund history</span>
                <h2>Your refunds</h2>
                <p>Track cancellation, payment-correction, security-deposit, and booking-modification refunds in one place.</p>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead><tr><th>Booking</th><th>Type</th><th>Amount</th><th>Status</th><th>Processed</th><th>Reference</th></tr></thead>
                        <tbody><?php foreach ($customerRefunds as $refund): ?><tr>
                            <td><a href="booking-view.php?reference=<?= urlencode($refund["booking_reference"]) ?>"><?= escape_html($refund["booking_reference"]) ?></a><small><?= escape_html($refund["vehicle_name"]) ?></small></td>
                            <td><?= escape_html(refund_type_label((string) $refund["refund_type"])) ?></td>
                            <td><?= money((int) $refund["amount"]) ?></td>
                            <td><span class="status-badge status-badge--<?= status_class($refund["status"]) ?>"><?= escape_html(ucfirst($refund["status"])) ?></span></td>
                            <td><?= $refund["processed_at"] ? date("M j, Y g:i A", strtotime($refund["processed_at"])) : "—" ?></td>
                            <td><?= escape_html((string) ($refund["reference_number"] ?: "—")) ?></td>
                        </tr><?php endforeach; ?></tbody>
                    </table>
                </div>
            </article>
        <?php endif; ?>
    </div>
</section>

<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
