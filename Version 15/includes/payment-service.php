<?php

declare(strict_types=1);


/**
 * FILE: includes/payment-service.php
 * FILE PURPOSE: Payment, payment-proof, verification, refund, and payment-detail service.
 * USED BY: Customer payment pages, invoices, admin payment verification, and secure payment-proof access.
 * RESPONSIBILITY: Owns payment records, GCash/bank method details, proof handling, verification, summaries, refunds, and payment-related persistence.
 *
 * Maintenance note: Keep this file focused on the responsibility described above.
 */
/**
 * Payments, payment verification, payment-account instructions, and refunds.
 */

/****************************************************************************
 * PAYMENTS AND PAYMENT VERIFICATION
 ****************************************************************************/

/**
 * @return list<array<string, mixed>>
 */
function payments_for_booking(int $bookingId): array
{
    $statement = database()->prepare(
        'SELECT p.*, u.name AS customer_name, recorder.name AS recorder_name,
                COALESCE((SELECT SUM(r.amount) FROM payment_refunds r
                          WHERE r.payment_id=p.id AND r.status=\'refunded\'
                            AND r.refund_type IN (\'payment_correction\',\'booking_modification\')),0) AS corrective_refunded_amount
         FROM payments p
         JOIN users u ON u.id = p.user_id LEFT JOIN users recorder ON recorder.id = p.recorded_by
         WHERE p.booking_id = ? ORDER BY p.created_at DESC',
    );
    $statement->execute([$bookingId]);
    return $statement->fetchAll();
}

/**
 * Summarize paid and outstanding amounts for one booking.
 *
 * @param array{id: int|string, deposit: int|string, total: int|string} $booking
 * @return array{
 *     payments: list<array<string, mixed>>,
 *     deposit_due: int,
 *     rental_due: int,
 *     extra_due: int,
 *     extension_due: int,
 *     paid_total: int
 * }
 */
function booking_payment_summary(array $booking): array
{
    $payments = payments_for_booking((int) $booking["id"]);
    $paidDeposit = 0;
    $paidRental = 0;
    $paidExtra = 0;
    $paidExtension = 0;
    $paidModification = 0;
    $extraDue = 0;
    foreach ($payments as $payment) {
        $grossAmount = (int) $payment["amount"];
        $correctiveRefund = (int) ($payment["corrective_refunded_amount"] ?? 0);
        $amount = max(0, $grossAmount - $correctiveRefund);
        if ($payment["payment_type"] === "extra_charge") {
            $extraDue += $grossAmount;
        }
        if ($payment["status"] !== "paid") {
            continue;
        }
        if ($payment["payment_type"] === "deposit") {
            $paidDeposit += $amount;
        } elseif ($payment["payment_type"] === "balance") {
            $paidRental += $amount;
        } elseif ($payment["payment_type"] === "extra_charge") {
            $paidExtra += $amount;
        } elseif ($payment["payment_type"] === "extension") {
            $paidExtension += $amount;
        } elseif ($payment["payment_type"] === "modification") {
            $paidModification += $amount;
        }
    }
    $extensionStatement = database()->prepare(
        "SELECT r.id, r.price_difference,
                COALESCE(SUM(CASE WHEN p.status = 'paid' THEN p.amount ELSE 0 END),0) AS paid_amount
         FROM rental_adjustment_requests r
         LEFT JOIN payments p ON p.rental_adjustment_id = r.id AND p.payment_type = 'extension'
         WHERE r.booking_id = ? AND r.request_type = 'extension' AND r.status = 'approved'
         GROUP BY r.id, r.price_difference"
    );
    $extensionStatement->execute([(int) $booking["id"]]);
    $extensionDueTotal = 0;
    foreach ($extensionStatement->fetchAll() as $extensionRow) {
        $extensionDueTotal += max(0, (int) $extensionRow["price_difference"] - (int) $extensionRow["paid_amount"]);
    }
    $modificationStatement = database()->prepare(
        "SELECT m.id, m.price_difference,
                COALESCE(SUM(CASE WHEN p.status = 'paid' THEN p.amount ELSE 0 END),0) AS paid_amount
         FROM booking_modification_requests m
         LEFT JOIN payments p ON p.booking_modification_id = m.id AND p.payment_type = 'modification'
         WHERE m.booking_id = ? AND m.status = 'approved' AND m.price_difference > 0
         GROUP BY m.id, m.price_difference"
    );
    $modificationStatement->execute([(int) $booking["id"]]);
    $modificationDueTotal = 0;
    foreach ($modificationStatement->fetchAll() as $modificationRow) {
        $modificationDueTotal += max(0, (int) $modificationRow["price_difference"] - (int) $modificationRow["paid_amount"]);
    }
    $settlement = function_exists('rental_settlement_for_booking') ? rental_settlement_for_booking((int) $booking["id"]) : null;
    $extraObligation = $settlement ? (int) $settlement["outstanding_balance"] : $extraDue;
    return [
        "payments" => $payments,
        "deposit_due" => max(0, (int) $booking["deposit"] - $paidDeposit),
        "rental_due" => max(0, (int) $booking["total"] - $paidRental - $paidExtension - $paidModification),
        "extra_due" => max(0, $extraObligation - $paidExtra),
        "extension_due" => max(0, $extensionDueTotal - $paidExtension),
        "modification_due" => max(0, $modificationDueTotal - $paidModification),
        "paid_total" => $paidDeposit + $paidRental + $paidExtra + $paidExtension + $paidModification,
    ];
}

/**
 * Create a customer-submitted deposit or balance payment request.
 *
 * @param array{id: int|string, user_id: int|string, reference: string, deposit: int|string, total: int|string} $booking
 */
function create_payment_request(
    array $booking,
    int $userId,
    string $type,
    string $method,
    int $amount,
    string $reference,
    string $proofFilename = "",
    ?int $rentalAdjustmentId = null,
    ?int $bookingModificationId = null,
): int {
    if ((int) $booking["user_id"] !== $userId) {
        throw new RuntimeException(
            "This booking does not belong to your account.",
        );
    }
    $validType = in_array($type, ["deposit", "balance", "extension", "modification", "extra_charge"], true);
    $validMethod = in_array($method, ["gcash", "bank_transfer", "cash"], true);
    if (!$validType || !$validMethod) {
        throw new InvalidArgumentException(
            "Choose a valid payment type and method.",
        );
    }
    $summary = booking_payment_summary($booking);
    $maximum = match ($type) {
        "deposit" => $summary["deposit_due"],
        "balance" => $summary["rental_due"],
        "extension" => $summary["extension_due"],
        "modification" => $summary["modification_due"],
        "extra_charge" => $summary["extra_due"],
    };
    if ($type !== "extension") {
        $rentalAdjustmentId = null;
    }
    if ($type !== "modification") {
        $bookingModificationId = null;
    }
    if ($type === "extension") {
        if (!$rentalAdjustmentId) {
            throw new InvalidArgumentException("Choose an approved extension request.");
        }
        $adjustment = rental_adjustment_find($rentalAdjustmentId);
        if (!$adjustment || (int) $adjustment["booking_id"] !== (int) $booking["id"] ||
            (int) $adjustment["user_id"] !== $userId || $adjustment["request_type"] !== "extension" ||
            $adjustment["status"] !== "approved") {
            throw new RuntimeException("That extension is not awaiting payment.");
        }
        $paid = database()->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE rental_adjustment_id=? AND payment_type='extension' AND status IN ('pending','paid')");
        $paid->execute([$rentalAdjustmentId]);
        $maximum = max(0, (int) $adjustment["price_difference"] - (int) $paid->fetchColumn());
        if ($amount !== $maximum || $maximum < 1) {
            throw new InvalidArgumentException("Submit the exact outstanding extension amount.");
        }
    } elseif ($type === "modification") {
        if (!$bookingModificationId) {
            throw new InvalidArgumentException("Choose an approved booking modification.");
        }
        $modification = booking_modification_find($bookingModificationId);
        if (!$modification || (int) $modification["booking_id"] !== (int) $booking["id"] ||
            (int) $modification["user_id"] !== $userId || $modification["status"] !== "approved" ||
            (int) $modification["price_difference"] <= 0) {
            throw new RuntimeException("That booking modification is not awaiting payment.");
        }
        $paid = database()->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE booking_modification_id=? AND payment_type='modification' AND status IN ('pending','paid')");
        $paid->execute([$bookingModificationId]);
        $maximum = max(0, (int) $modification["price_difference"] - (int) $paid->fetchColumn());
        if ($amount !== $maximum || $maximum < 1) {
            throw new InvalidArgumentException("Submit the exact outstanding booking modification amount.");
        }
    } elseif ($type === "extra_charge") {
        if ($booking["status"] !== "returned" && $booking["status"] !== "completed") {
            throw new RuntimeException("Return-charge payments are available only after vehicle check-in and settlement.");
        }
        $pendingExtra = database()->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE booking_id=? AND payment_type='extra_charge' AND status='pending'");
        $pendingExtra->execute([(int) $booking["id"]]);
        $maximum = max(0, (int) $summary["extra_due"] - (int) $pendingExtra->fetchColumn());
        if ($amount < 1 || $amount > $maximum) {
            throw new InvalidArgumentException("Enter an amount no greater than the outstanding return balance of " . money($maximum) . ".");
        }
    } elseif ($amount < 1 || $amount > $maximum) {
        throw new InvalidArgumentException(
            "Enter an amount no greater than the current amount due.",
        );
    }
    $hasReference = mb_strlen(trim($reference)) >= 4;
    if ($method !== "cash" && !$hasReference) {
        throw new InvalidArgumentException(
            "Enter the transaction/reference number from your payment.",
        );
    }
    if ($method !== "cash" && $proofFilename === "") {
        throw new InvalidArgumentException(
            "Upload your payment proof before submitting the payment.",
        );
    }
    $currentTimestamp = date("Y-m-d H:i:s");
    $statement = database()->prepare(
        "INSERT INTO payments (booking_id, rental_adjustment_id, booking_modification_id, user_id, payment_type, method, amount, transaction_reference, proof_filename, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)",
    );
    $statement->execute([
        (int) $booking["id"],
        $rentalAdjustmentId,
        $bookingModificationId,
        $userId,
        $type,
        $method,
        $amount,
        mb_substr(trim($reference), 0, 120),
        $proofFilename,
        $currentTimestamp,
        $currentTimestamp,
    ]);
    $paymentId = (int) database()->lastInsertId();
    if ($type === "extension") {
        $customerPaymentTitle = "Extension Payment Submitted";
        $customerPaymentMessage = "Your extension payment for booking " . $booking["reference"] . " is awaiting verification.";
    } elseif ($type === "modification") {
        $customerPaymentTitle = "Modification Payment Submitted";
        $customerPaymentMessage = "Your additional payment for the approved booking changes on " . $booking["reference"] . " is awaiting verification.";
    } else {
        $nextJourney = booking_next_step($booking);
        if ($nextJourney["stage"] === "documents") {
            $customerPaymentTitle = "Payment Submitted — Upload Documents";
            $customerPaymentMessage = "Your payment for booking " . $booking["reference"] . " was submitted and is awaiting verification. Next step: upload your required documents.";
        } elseif ($nextJourney["stage"] === "verification") {
            $customerPaymentTitle = "Payment Submitted — Verification Pending";
            $customerPaymentMessage = "Your payment for booking " . $booking["reference"] . " was submitted. Your current requirements are now awaiting verification.";
        } else {
            $customerPaymentTitle = "Payment Submitted";
            $customerPaymentMessage = "Your payment for booking " . $booking["reference"] . " was submitted. " . $nextJourney["message"];
        }
    }
    notify_user(
        $userId,
        $customerPaymentTitle,
        $customerPaymentMessage,
        "payment",
        (int) $booking["id"],
    );
    notify_admins(
        "Payment awaiting verification",
        "A " . str_replace("_", " ", $type) . " payment was submitted for booking " . $booking["reference"] . ".",
        "payment",
        (int) $booking["id"],
    );
    write_audit("payment_submitted", "payment", $paymentId, [
        "booking_id" => (int) $booking["id"],
        "rental_adjustment_id" => $rentalAdjustmentId,
        "booking_modification_id" => $bookingModificationId,
    ]);
    return $paymentId;
}

/**
 * Apply an administrator's review result to one payment request.
 */
function review_payment(
    int $paymentId,
    string $status,
    string $notes,
    int $adminId,
): void {
    if (!in_array($status, ["paid", "failed"], true)) {
        throw new InvalidArgumentException("Choose a valid payment status.");
    }
    $databaseConnection = database();
    $databaseConnection->beginTransaction();
    try {
        $selectSql = "SELECT p.*, b.reference FROM payments p JOIN bookings b ON b.id = p.booking_id WHERE p.id = ? LIMIT 1";
        if ($databaseConnection->getAttribute(PDO::ATTR_DRIVER_NAME) === "mysql") {
            $selectSql .= " FOR UPDATE";
        }
        $select = $databaseConnection->prepare($selectSql);
        $select->execute([$paymentId]);
        $payment = $select->fetch();
        if (!$payment) {
            throw new RuntimeException("Payment not found.");
        }
        if ($status === "paid" && $payment["payment_type"] === "extension") {
            if (!(int) ($payment["rental_adjustment_id"] ?? 0)) {
                throw new RuntimeException("This extension payment is missing its rental adjustment link.");
            }
            $adjustment = rental_adjustment_find((int) $payment["rental_adjustment_id"]);
            if (!$adjustment || $adjustment["status"] !== "approved") {
                throw new RuntimeException("This extension is no longer awaiting activation.");
            }
            assert_extension_available($adjustment, (string) $adjustment["requested_return_at"]);
        }
        if ($status === "paid" && $payment["payment_type"] === "modification") {
            if (!(int) ($payment["booking_modification_id"] ?? 0)) {
                throw new RuntimeException("This modification payment is missing its booking modification link.");
            }
            $modification = booking_modification_find((int) $payment["booking_modification_id"]);
            if (!$modification || $modification["status"] !== "approved") {
                throw new RuntimeException("This booking modification is no longer awaiting activation.");
            }
        }
        $currentTimestamp = date("Y-m-d H:i:s");
        $statement = $databaseConnection->prepare(
            "UPDATE payments SET status = ?, notes = ?, recorded_by = ?, paid_at = ?, updated_at = ? WHERE id = ?",
        );
        $paidAt = $status === "paid" ? $currentTimestamp : null;
        $statement->execute([
            $status,
            mb_substr(trim($notes), 0, 2000),
            $adminId,
            $paidAt,
            $currentTimestamp,
            $paymentId,
        ]);
        if ($status === "paid" && $payment["payment_type"] === "extension") {
            activate_extension_request((int) $payment["rental_adjustment_id"], $adminId);
        }
        if ($status === "paid" && $payment["payment_type"] === "modification") {
            activate_booking_modification((int) $payment["booking_modification_id"], $adminId);
        }
        if ($status === "paid" && $payment["payment_type"] === "extra_charge") {
            refresh_rental_settlement_status((int) $payment["booking_id"]);
        }
        $updatedBooking = booking_find_by_reference((string) $payment["reference"]);
        if ($status === "paid" && $updatedBooking) {
            if (confirm_booking_when_requirements_complete($updatedBooking, $adminId)) {
                $updatedBooking = booking_find_by_reference((string) $payment["reference"]);
            }
        }
        $nextJourney = $updatedBooking ? booking_next_step($updatedBooking) : null;
        if ($status === "failed") {
            $paymentTitle = "Payment Update Required";
            $paymentMessage = "Your payment for booking " . $payment["reference"] . " could not be verified. Review the payment and submit an updated payment if required.";
        } elseif ($status === "paid") {
            $paymentTitle = "Payment Verified";
            $paymentMessage = "Your " . str_replace("_", " ", (string) $payment["payment_type"]) . " payment for booking " . $payment["reference"] . " was verified.";
            if ($nextJourney && $nextJourney["stage"] === "documents") {
                $paymentMessage .= " Next step: upload your required documents.";
            } elseif ($nextJourney && $nextJourney["stage"] === "verification") {
                $paymentMessage .= " Your submitted requirements are now in verification.";
            }
        } else {
            $paymentTitle = "Payment " . humanize_label($status);
            $paymentMessage = "Your " . str_replace("_", " ", (string) $payment["payment_type"]) . " payment for booking " . $payment["reference"] . " was marked " . $status . ".";
        }
        notify_user(
            (int) $payment["user_id"],
            $paymentTitle,
            $paymentMessage,
            "payment",
            (int) $payment["booking_id"],
        );
        write_audit("payment_" . $status, "payment", $paymentId);
        $databaseConnection->commit();
    } catch (Throwable $error) {
        if ($databaseConnection->inTransaction()) {
            $databaseConnection->rollBack();
        }
        throw $error;
    }
}

/****************************************************************************
 * REFUNDS
 ****************************************************************************/

/** @return list<string> */
function refund_statuses(): array
{
    return ['pending', 'approved', 'processing', 'refunded', 'rejected', 'failed', 'cancelled'];
}

/** @return array<string,string> */
function refund_type_labels(): array
{
    return [
        'cancellation' => 'Cancellation Refund',
        'payment_correction' => 'Payment Refund',
        'security_deposit' => 'Security Deposit Refund',
        'booking_modification' => 'Modification Refund',
    ];
}

function refund_type_label(string $type): string
{
    return refund_type_labels()[$type] ?? 'Refund';
}

/** @return list<array<string,mixed>> */
function refunds_for_booking(int $bookingId): array
{
    $statement = database()->prepare(
        "SELECT r.*, p.payment_type, p.method, p.amount AS payment_amount,
                approver.name AS approver_name
         FROM payment_refunds r
         JOIN payments p ON p.id = r.payment_id
         LEFT JOIN users approver ON approver.id = r.approved_by
         WHERE r.booking_id = ? ORDER BY r.created_at DESC, r.id DESC"
    );
    $statement->execute([$bookingId]);
    return $statement->fetchAll();
}

/** @return list<array<string,mixed>> */
function refunds_for_user(int $userId): array
{
    $statement = database()->prepare(
        "SELECT r.*, p.payment_type, p.method, p.amount AS payment_amount,
                b.reference AS booking_reference, v.name AS vehicle_name
         FROM payment_refunds r
         JOIN payments p ON p.id = r.payment_id
         JOIN bookings b ON b.id = r.booking_id
         JOIN vehicles v ON v.id = b.vehicle_id
         WHERE b.user_id = ?
         ORDER BY r.created_at DESC, r.id DESC"
    );
    $statement->execute([$userId]);
    return $statement->fetchAll();
}

/** @return array{refunded:int,pending:int,total:int,status:string} */
function refund_summary_for_booking(int $bookingId): array
{
    $rows = refunds_for_booking($bookingId);
    $refunded = 0;
    $pending = 0;
    foreach ($rows as $row) {
        $amount = (int) $row['amount'];
        if ($row['status'] === 'refunded') {
            $refunded += $amount;
        } elseif (in_array($row['status'], ['pending', 'approved', 'processing'], true)) {
            $pending += $amount;
        }
    }
    $status = 'not_required';
    if ($pending > 0) {
        $status = 'pending';
    } elseif ($refunded > 0) {
        $status = 'refunded';
    } elseif ($rows) {
        $status = (string) $rows[0]['status'];
    }
    return ['refunded' => $refunded, 'pending' => $pending, 'total' => $refunded + $pending, 'status' => $status];
}

function refundable_balance_for_payment(int $paymentId): int
{
    $statement = database()->prepare(
        "SELECT p.amount,
                COALESCE(SUM(CASE WHEN r.status IN ('pending','approved','processing','refunded') THEN r.amount ELSE 0 END),0) AS committed_refund
         FROM payments p LEFT JOIN payment_refunds r ON r.payment_id = p.id
         WHERE p.id = ? AND p.status = 'paid'
         GROUP BY p.id, p.amount"
    );
    $statement->execute([$paymentId]);
    $row = $statement->fetch();
    return $row ? max(0, (int) $row['amount'] - (int) $row['committed_refund']) : 0;
}

/**
 * Allocate a trusted refund amount across verified payments for one booking.
 * Existing callers remain compatible; The unified refund system adds refund type/source metadata.
 *
 * @param array<string,int|null> $source
 * @param list<string>|null $allowedPaymentTypes
 * @return list<int>
 */
function create_refund_requests_for_booking(
    int $bookingId,
    int $amount,
    string $reason,
    ?int $requestedBy = null,
    string $refundType = 'payment_correction',
    array $source = [],
    ?array $allowedPaymentTypes = null,
): array {
    if ($amount <= 0) {
        return [];
    }
    if (!array_key_exists($refundType, refund_type_labels())) {
        throw new InvalidArgumentException('Choose a valid refund type.');
    }

    $paymentSql = "SELECT p.*,
                COALESCE(SUM(CASE WHEN r.status IN ('pending','approved','processing','refunded') THEN r.amount ELSE 0 END),0) AS committed_refund
         FROM payments p
         LEFT JOIN payment_refunds r ON r.payment_id = p.id
         WHERE p.booking_id = ? AND p.status = 'paid'";
    $params = [$bookingId];
    if ($allowedPaymentTypes) {
        $placeholders = implode(',', array_fill(0, count($allowedPaymentTypes), '?'));
        $paymentSql .= " AND p.payment_type IN ({$placeholders})";
        array_push($params, ...$allowedPaymentTypes);
    }
    $paymentSql .= " GROUP BY p.id
         ORDER BY CASE p.payment_type WHEN 'balance' THEN 0 WHEN 'modification' THEN 1 WHEN 'extension' THEN 2 WHEN 'deposit' THEN 3 ELSE 4 END,
                  p.paid_at DESC, p.id DESC";
    $payments = database()->prepare($paymentSql);
    $payments->execute($params);

    $remaining = $amount;
    $created = [];
    $normalizedReason = mb_substr(trim($reason), 0, 2000);
    $now = date('Y-m-d H:i:s');
    foreach ($payments->fetchAll() as $payment) {
        if ($remaining <= 0) {
            break;
        }
        $available = max(0, (int) $payment['amount'] - (int) $payment['committed_refund']);
        if ($available <= 0) {
            continue;
        }
        $allocation = min($remaining, $available);
        $insert = database()->prepare(
            "INSERT INTO payment_refunds
                (payment_id, booking_id, refund_type, cancellation_request_id, booking_modification_id, rental_settlement_id,
                 amount, status, reason, requested_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?)"
        );
        $insert->execute([
            (int) $payment['id'],
            $bookingId,
            $refundType,
            $source['cancellation_request_id'] ?? null,
            $source['booking_modification_id'] ?? null,
            $source['rental_settlement_id'] ?? null,
            $allocation,
            $normalizedReason,
            $now,
            $now,
            $now,
        ]);
        $refundId = (int) database()->lastInsertId();
        $created[] = $refundId;
        $remaining -= $allocation;
        write_audit('refund_created', 'payment_refund', $refundId, [
            'booking_id' => $bookingId,
            'payment_id' => (int) $payment['id'],
            'refund_type' => $refundType,
            'amount' => $allocation,
            'requested_by' => $requestedBy,
        ]);
        $typeAuditAction = match ($refundType) {
            'cancellation' => 'cancellation_refund_created',
            'payment_correction' => 'payment_refund_created',
            'security_deposit' => 'security_deposit_refund_created',
            'booking_modification' => 'modification_refund_created',
            default => 'refund_created',
        };
        write_audit($typeAuditAction, 'payment_refund', $refundId, [
            'booking_id' => $bookingId,
            'amount' => $allocation,
        ]);
    }
    if ($remaining > 0) {
        throw new RuntimeException('The requested refund exceeds the remaining verified paid amount.');
    }

    if ($created) {
        $bookingStatement = database()->prepare("SELECT user_id, reference FROM bookings WHERE id = ? LIMIT 1");
        $bookingStatement->execute([$bookingId]);
        $booking = $bookingStatement->fetch();
        if ($booking) {
            notify_user(
                (int) $booking['user_id'],
                refund_type_label($refundType) . ' Pending',
                refund_type_label($refundType) . ' of ' . money($amount) . ' for booking ' . $booking['reference'] . ' is awaiting administrator processing.',
                'refund',
                $bookingId,
            );
        }
    }
    return $created;
}

/** Create a manual payment-correction refund from one verified payment. */
function create_payment_refund(int $paymentId, int $amount, string $reason, string $adminNote, int $adminId): int
{
    if (mb_strlen(trim($reason)) < 4) {
        throw new InvalidArgumentException('Provide a clear reason for the payment refund.');
    }
    $databaseConnection = database();
    $databaseConnection->beginTransaction();
    try {
        $sql = "SELECT p.*, b.reference, b.user_id AS booking_user_id
                FROM payments p JOIN bookings b ON b.id = p.booking_id
                WHERE p.id = ? LIMIT 1";
        if ($databaseConnection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $statement = $databaseConnection->prepare($sql);
        $statement->execute([$paymentId]);
        $payment = $statement->fetch();
        if (!$payment || $payment['status'] !== 'paid') {
            throw new RuntimeException('Only a verified payment can be refunded.');
        }
        $available = refundable_balance_for_payment($paymentId);
        if ($amount < 1 || $amount > $available) {
            throw new InvalidArgumentException('Refund amount cannot exceed ' . money($available) . '.');
        }
        $duplicate = $databaseConnection->prepare(
            "SELECT id FROM payment_refunds WHERE payment_id=? AND refund_type='payment_correction' AND amount=? AND reason=? AND status IN ('pending','approved','processing') LIMIT 1"
        );
        $normalizedReason = mb_substr(trim($reason), 0, 2000);
        $duplicate->execute([$paymentId, $amount, $normalizedReason]);
        if ($duplicate->fetchColumn()) {
            throw new RuntimeException('An identical unresolved payment refund already exists.');
        }
        $now = date('Y-m-d H:i:s');
        $insert = $databaseConnection->prepare(
            "INSERT INTO payment_refunds
             (payment_id, booking_id, refund_type, amount, status, reason, requested_at, admin_note, created_at, updated_at)
             VALUES (?, ?, 'payment_correction', ?, 'pending', ?, ?, ?, ?, ?)"
        );
        $insert->execute([
            $paymentId,
            (int) $payment['booking_id'],
            $amount,
            mb_substr(trim($reason), 0, 2000),
            $now,
            mb_substr(trim($adminNote), 0, 2000),
            $now,
            $now,
        ]);
        $refundId = (int) $databaseConnection->lastInsertId();
        notify_user(
            (int) $payment['booking_user_id'],
            'Payment Refund Pending',
            'A payment correction refund of ' . money($amount) . ' for booking ' . $payment['reference'] . ' is awaiting processing.',
            'refund',
            (int) $payment['booking_id'],
        );
        write_audit('payment_refund_created', 'payment_refund', $refundId, [
            'booking_id' => (int) $payment['booking_id'],
            'payment_id' => $paymentId,
            'amount' => $amount,
            'admin_id' => $adminId,
        ]);
        $databaseConnection->commit();
        return $refundId;
    } catch (Throwable $error) {
        if ($databaseConnection->inTransaction()) {
            $databaseConnection->rollBack();
        }
        throw $error;
    }
}


/** @return list<array<string,mixed>> */
function refund_audit_history(int $refundId): array
{
    $statement = database()->prepare(
        "SELECT a.action, a.created_at, u.name AS actor_name
         FROM audit_logs a
         LEFT JOIN users u ON u.id = a.user_id
         WHERE a.entity_type='payment_refund' AND a.entity_id=?
         ORDER BY a.created_at DESC, a.id DESC"
    );
    $statement->execute([$refundId]);
    return $statement->fetchAll();
}

/** @return list<array<string,mixed>> */
function admin_refunds(string $status = 'all', string $query = '', string $type = 'all'): array
{
    $sql = "SELECT r.*, p.payment_type, p.method, p.amount AS payment_amount,
                   b.reference AS booking_reference, u.name AS customer_name,
                   u.email AS customer_email, v.name AS vehicle_name,
                   approver.name AS approver_name
            FROM payment_refunds r
            JOIN payments p ON p.id = r.payment_id
            JOIN bookings b ON b.id = r.booking_id
            JOIN users u ON u.id = b.user_id
            JOIN vehicles v ON v.id = b.vehicle_id
            LEFT JOIN users approver ON approver.id = r.approved_by
            WHERE 1=1";
    $params = [];
    if ($status !== 'all') {
        $sql .= ' AND r.status = ?';
        $params[] = $status;
    }
    if ($type !== 'all') {
        $sql .= ' AND r.refund_type = ?';
        $params[] = $type;
    }
    if ($query !== '') {
        $sql .= ' AND (b.reference LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR v.name LIKE ? OR r.reference_number LIKE ?)';
        $term = '%' . $query . '%';
        array_push($params, $term, $term, $term, $term, $term);
    }
    $sql .= " ORDER BY CASE r.status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 WHEN 'processing' THEN 2 ELSE 3 END, r.created_at DESC";
    $statement = database()->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}

function review_refund(int $refundId, string $newStatus, int $adminId, string $notes = '', string $referenceNumber = ''): void
{
    $databaseConnection = database();
    $databaseConnection->beginTransaction();
    try {
        $sql = "SELECT r.*, b.user_id, b.reference AS booking_reference, p.amount AS payment_amount, p.payment_type
                FROM payment_refunds r
                JOIN bookings b ON b.id = r.booking_id
                JOIN payments p ON p.id = r.payment_id
                WHERE r.id = ? LIMIT 1";
        if ($databaseConnection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $statement = $databaseConnection->prepare($sql);
        $statement->execute([$refundId]);
        $refund = $statement->fetch();
        if (!$refund) {
            throw new RuntimeException('Refund record not found.');
        }
        if ((string) ($refund['refund_type'] ?? '') === 'security_deposit' && in_array($newStatus, ['rejected','cancelled'], true)) {
            throw new RuntimeException('A finalized security-deposit settlement refund cannot be rejected. Correct the settlement before finalizing it.');
        }
        $allowed = [
            'pending' => ['approved', 'rejected', 'cancelled'],
            'approved' => ['processing', 'rejected'],
            'processing' => ['refunded', 'failed'],
            'failed' => ['processing', 'rejected'],
            'refunded' => [], 'rejected' => [], 'cancelled' => [],
        ];
        if (!in_array($newStatus, $allowed[$refund['status']] ?? [], true)) {
            throw new RuntimeException('That refund status transition is not allowed.');
        }
        $committed = $databaseConnection->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM payment_refunds
             WHERE payment_id = ? AND id <> ? AND status IN ('pending','approved','processing','refunded')"
        );
        $committed->execute([(int) $refund['payment_id'], $refundId]);
        if ((int) $committed->fetchColumn() + (int) $refund['amount'] > (int) $refund['payment_amount']) {
            throw new RuntimeException('Refund total cannot exceed the verified payment amount.');
        }
        $now = date('Y-m-d H:i:s');
        $approvedAt = in_array($newStatus, ['approved', 'processing', 'refunded'], true) ? ($refund['approved_at'] ?: $now) : $refund['approved_at'];
        $processedAt = $newStatus === 'refunded' ? $now : $refund['processed_at'];
        if ($newStatus === 'refunded' && trim($referenceNumber) === '') {
            $referenceNumber = 'RF-' . date('Ymd') . '-' . str_pad((string) $refundId, 6, '0', STR_PAD_LEFT);
        }
        $update = $databaseConnection->prepare(
            "UPDATE payment_refunds
             SET status = ?, approved_at = ?, approved_by = ?, processed_at = ?,
                 reference_number = ?, admin_note = ?, updated_at = ?
             WHERE id = ?"
        );
        $update->execute([
            $newStatus,
            $approvedAt,
            in_array($newStatus, ['approved', 'processing', 'refunded'], true) ? $adminId : $refund['approved_by'],
            $processedAt,
            mb_substr(trim($referenceNumber), 0, 120),
            mb_substr(trim($notes), 0, 2000),
            $now,
            $refundId,
        ]);
        if ((string) ($refund['refund_type'] ?? '') === 'security_deposit' && (int) ($refund['rental_settlement_id'] ?? 0) > 0) {
            $statusMap = ['pending' => 'refund_pending', 'approved' => 'refund_pending', 'processing' => 'refund_processing', 'refunded' => 'settled', 'failed' => 'refund_pending', 'rejected' => 'finalized'];
            if (isset($statusMap[$newStatus])) {
                $databaseConnection->prepare("UPDATE rental_settlements SET status=?, updated_at=? WHERE id=?")
                    ->execute([$statusMap[$newStatus], $now, (int) $refund['rental_settlement_id']]);
            }
        }
        if ($newStatus === 'refunded') {
            refresh_rental_settlement_status((int) $refund['booking_id']);
            if ((string) $refund['refund_type'] === 'payment_correction' && (string) $refund['payment_type'] === 'deposit') {
                $booking = booking_find_by_reference((string) $refund['booking_reference']);
                if ($booking && in_array($booking['status'], ['confirmed','ready'], true)) {
                    $summary = booking_payment_summary($booking);
                    if ((int) $summary['deposit_due'] > 0) {
                        $databaseConnection->prepare("UPDATE bookings SET status='pending', ready_at=NULL, updated_at=? WHERE id=?")
                            ->execute([$now, (int) $refund['booking_id']]);
                        sync_vehicle_status((int) $booking['vehicle_id']);
                    }
                }
            }
        }
        $refundLabel = refund_type_label((string) $refund['refund_type']);
        $titles = [
            'approved' => $refundLabel . ' Approved',
            'processing' => $refundLabel . ' Processing',
            'refunded' => (string) $refund['refund_type'] === 'security_deposit' ? 'Security Deposit Refunded' : $refundLabel . ' Processed',
            'rejected' => $refundLabel . ' Rejected',
            'failed' => $refundLabel . ' Failed',
            'cancelled' => $refundLabel . ' Cancelled',
        ];
        $messages = [
            'approved' => refund_type_label((string) $refund['refund_type']) . ' of ' . money((int) $refund['amount']) . ' for booking ' . $refund['booking_reference'] . ' was approved.',
            'processing' => 'Your ' . strtolower(refund_type_label((string) $refund['refund_type'])) . ' for booking ' . $refund['booking_reference'] . ' is being processed.',
            'refunded' => 'Your ' . strtolower(refund_type_label((string) $refund['refund_type'])) . ' of ' . money((int) $refund['amount']) . ' for booking ' . $refund['booking_reference'] . ' has been processed.',
            'rejected' => 'The ' . strtolower(refund_type_label((string) $refund['refund_type'])) . ' for booking ' . $refund['booking_reference'] . ' was rejected.',
            'failed' => 'The refund for booking ' . $refund['booking_reference'] . ' could not be completed and needs administrator follow-up.',
            'cancelled' => 'The refund for booking ' . $refund['booking_reference'] . ' was cancelled.',
        ];
        notify_user((int) $refund['user_id'], $titles[$newStatus] ?? 'Refund Updated', $messages[$newStatus] ?? 'Your refund status was updated.', 'refund', (int) $refund['booking_id']);
        write_audit('refund_' . ($newStatus === 'refunded' ? 'processed' : $newStatus), 'payment_refund', $refundId, [
            'booking_id' => (int) $refund['booking_id'], 'refund_type' => (string) $refund['refund_type'], 'amount' => (int) $refund['amount'],
        ]);
        $databaseConnection->commit();
    } catch (Throwable $error) {
        if ($databaseConnection->inTransaction()) {
            $databaseConnection->rollBack();
        }
        throw $error;
    }
}

/*****************************************************************************
 * PAYMENT ACCOUNT DETAILS
 *****************************************************************************/

/**
 * Return the centrally configured manual-payment account details.
 *
 * @return array<string, array<string, string>>
 */
function payment_account_details(): array
{
    return [
        "gcash" => [
            "label" => "GCash",
            "account_name" => PAYMENT_GCASH_NAME,
            "account_number" => PAYMENT_GCASH_NUMBER,
        ],
        "bank_transfer" => [
            "label" => "Bank Transfer",
            "bank_name" => PAYMENT_BANK_NAME,
            "account_name" => PAYMENT_BANK_ACCOUNT_NAME,
            "account_number" => PAYMENT_BANK_ACCOUNT_NUMBER,
        ],
    ];
}

/**
 * Return a consistent customer-facing label for a payment method.
 */
function payment_method_label(string $method): string
{
    return match ($method) {
        "gcash" => "GCash",
        "bank_transfer" => "Bank Transfer",
        "cash" => "Cash at Branch",
        default => humanize_label($method),
    };
}

/****************************************************************************
 * ADMIN PAYMENT LISTS AND SECURE PROOF LOOKUP
 ****************************************************************************/

/** Return payments for the administration page, optionally filtered by status. */
function admin_payments(string $status = "all"): array
{
    $sql =
        "SELECT p.*, b.reference AS booking_reference, u.name AS customer_name,
                u.email AS customer_email, v.name AS vehicle_name
         FROM payments p
         JOIN bookings b ON b.id = p.booking_id
         JOIN users u ON u.id = p.user_id
         JOIN vehicles v ON v.id = b.vehicle_id";
    $parameters = [];
    if ($status !== "all") {
        $sql .= " WHERE p.status = ?";
        $parameters[] = $status;
    }
    $sql .=
        " ORDER BY CASE p.status WHEN 'pending' THEN 0 ELSE 1 END, p.created_at DESC";
    $statement = database()->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchAll();
}

/** Return ownership and filename information for a stored payment proof. */
function payment_proof_record(int $paymentId): ?array
{
    $statement = database()->prepare(
        "SELECT user_id, proof_filename AS filename FROM payments WHERE id = ? LIMIT 1",
    );
    $statement->execute([$paymentId]);
    $record = $statement->fetch();
    return $record ?: null;
}
