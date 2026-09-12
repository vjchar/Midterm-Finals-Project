<?php

declare(strict_types=1);

/** @return list<array<string,mixed>> */
function payments_for_booking(int $bookingId): array
{
    $statement = database()->prepare(
        "SELECT p.*, u.name AS customer_name, recorder.name AS recorder_name
         FROM payments p
         JOIN users u ON u.id = p.user_id
         LEFT JOIN users recorder ON recorder.id = p.recorded_by
         WHERE p.booking_id = ?
         ORDER BY p.created_at DESC",
    );
    $statement->execute([$bookingId]);
    return $statement->fetchAll();
}

/**
 * @param array{id:int|string,total:int|string} $booking
 * @return array{payments:list<array<string,mixed>>,paid_total:int,pending_total:int,amount_due:int,status:string}
 */
function booking_payment_summary(array $booking): array
{
    $payments = payments_for_booking((int) $booking["id"]);
    $paidTotal = 0;
    $pendingTotal = 0;

    foreach ($payments as $payment) {
        $amount = (int) $payment["amount"];
        if ($payment["status"] === "paid") {
            $paidTotal += $amount;
        } elseif ($payment["status"] === "pending") {
            $pendingTotal += $amount;
        }
    }

    $amountDue = max(0, (int) $booking["total"] - $paidTotal);
    $status = $amountDue === 0 ? "paid" : ($pendingTotal > 0 ? "pending" : "unpaid");

    return [
        "payments" => $payments,
        "paid_total" => $paidTotal,
        "pending_total" => $pendingTotal,
        "amount_due" => $amountDue,
        "status" => $status,
    ];
}

/**
 * Create the customer-submitted full rental payment request using the payment
 * methods and verification pattern from Final(10).
 *
 * @param array{id:int|string,user_id:int|string,reference:string,total:int|string,status:string} $booking
 */
function create_payment_request(
    array $booking,
    int $userId,
    string $method,
    string $transactionReference,
    string $proofFilename = "",
): int {
    if ((int) $booking["user_id"] !== $userId) {
        throw new RuntimeException("This booking does not belong to your account.");
    }
    if (!in_array($booking["status"], ["pending", "confirmed"], true)) {
        throw new RuntimeException("This booking is not eligible for payment.");
    }
    if (!in_array($method, ["gcash", "bank_transfer", "cash"], true)) {
        throw new InvalidArgumentException("Choose a valid payment method.");
    }

    $summary = booking_payment_summary($booking);
    if ($summary["amount_due"] < 1) {
        throw new RuntimeException("This booking is already fully paid.");
    }
    if ($summary["pending_total"] > 0) {
        throw new RuntimeException("A payment for this booking is already awaiting verification.");
    }

    $transactionReference = trim($transactionReference);
    if (
        $method !== "cash" &&
        strlen($transactionReference) < 4 &&
        $proofFilename === ""
    ) {
        throw new InvalidArgumentException("Provide a transaction reference or payment proof.");
    }

    $currentTimestamp = date("Y-m-d H:i:s");
    $statement = database()->prepare(
        "INSERT INTO payments (
            booking_id, user_id, payment_type, method, amount,
            transaction_reference, proof_filename, status, created_at, updated_at
         ) VALUES (?, ?, 'balance', ?, ?, ?, ?, 'pending', ?, ?)",
    );
    $statement->execute([
        (int) $booking["id"],
        $userId,
        $method,
        (int) $summary["amount_due"],
        substr($transactionReference, 0, 120),
        $proofFilename,
        $currentTimestamp,
        $currentTimestamp,
    ]);

    return (int) database()->lastInsertId();
}

function review_payment(
    int $paymentId,
    string $status,
    string $notes,
    int $adminId,
): void {
    if (!in_array($status, ["paid", "failed"], true)) {
        throw new InvalidArgumentException("Choose a valid payment status.");
    }

    $select = database()->prepare(
        "SELECT p.id FROM payments p WHERE p.id = ? LIMIT 1",
    );
    $select->execute([$paymentId]);
    if (!$select->fetch()) {
        throw new RuntimeException("Payment not found.");
    }

    $currentTimestamp = date("Y-m-d H:i:s");
    $statement = database()->prepare(
        "UPDATE payments
         SET status = ?, notes = ?, recorded_by = ?, paid_at = ?, updated_at = ?
         WHERE id = ?",
    );
    $statement->execute([
        $status,
        substr(trim($notes), 0, 2000),
        $adminId,
        $status === "paid" ? $currentTimestamp : null,
        $currentTimestamp,
        $paymentId,
    ]);
}
