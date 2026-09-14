<?php

declare(strict_types=1);

/**
 * @return list<array<string, mixed>>
 */
function payments_for_booking(int $bookingId): array
{
    $statement = database()->prepare(
        'SELECT p.*, u.name AS customer_name, recorder.name AS recorder_name FROM payments p
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
 *     paid_total: int
 * }
 */
function booking_payment_summary(array $booking): array
{
    $payments = payments_for_booking((int) $booking["id"]);
    $paidDeposit = 0;
    $paidRental = 0;
    $paidExtra = 0;
    $extraDue = 0;
    foreach ($payments as $payment) {
        $amount = (int) $payment["amount"];
        if ($payment["payment_type"] === "extra_charge") {
            $extraDue += $amount;
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
        }
    }
    return [
        "payments" => $payments,
        "deposit_due" => max(0, (int) $booking["deposit"] - $paidDeposit),
        "rental_due" => max(0, (int) $booking["total"] - $paidRental),
        "extra_due" => max(0, $extraDue - $paidExtra),
        "paid_total" => $paidDeposit + $paidRental + $paidExtra,
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
): int {
    if ((int) $booking["user_id"] !== $userId) {
        throw new RuntimeException(
            "This booking does not belong to your account.",
        );
    }
    $validType = in_array($type, ["deposit", "balance"], true);
    $validMethod = in_array($method, ["gcash", "bank_transfer", "cash"], true);
    if (!$validType || !$validMethod) {
        throw new InvalidArgumentException(
            "Choose a valid payment type and method.",
        );
    }
    $summary = booking_payment_summary($booking);
    $maximum =
        $type === "deposit" ? $summary["deposit_due"] : $summary["rental_due"];
    if ($amount < 1 || $amount > $maximum) {
        throw new InvalidArgumentException(
            "Enter an amount no greater than the current amount due.",
        );
    }
    $hasReference = mb_strlen(trim($reference)) >= 4;
    if ($method !== "cash" && !$hasReference && $proofFilename === "") {
        throw new InvalidArgumentException(
            "Provide a transaction reference or payment proof.",
        );
    }
    $currentTimestamp = date("Y-m-d H:i:s");
    $statement = database()->prepare(
        "INSERT INTO payments (booking_id, user_id, payment_type, method, amount, transaction_reference, proof_filename, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)",
    );
    $statement->execute([
        (int) $booking["id"],
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
    notify_user(
        $userId,
        "Payment submitted",
        "Your " .
            str_replace("_", " ", $type) .
            " payment for booking " .
            $booking["reference"] .
            " is awaiting verification.",
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
    if (!in_array($status, ["paid", "failed", "refunded"], true)) {
        throw new InvalidArgumentException("Choose a valid payment status.");
    }
    $select = database()->prepare(
        "SELECT p.*, b.reference FROM payments p JOIN bookings b ON b.id = p.booking_id WHERE p.id = ? LIMIT 1",
    );
    $select->execute([$paymentId]);
    $payment = $select->fetch();
    if (!$payment) {
        throw new RuntimeException("Payment not found.");
    }
    $currentTimestamp = date("Y-m-d H:i:s");
    $statement = database()->prepare(
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
    notify_user(
        (int) $payment["user_id"],
        "Payment " . $status,
        "Your payment for booking " .
            $payment["reference"] .
            " was marked " .
            $status .
            ".",
        "payment",
        (int) $payment["booking_id"],
    );
    write_audit("payment_" . $status, "payment", $paymentId);
}
