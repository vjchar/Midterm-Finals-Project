<?php

declare(strict_types=1);

/** @return list<string> */
function refund_statuses(): array
{
    return ['pending', 'approved', 'processing', 'refunded', 'rejected', 'failed'];
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
        $latest = (string) $rows[0]['status'];
        $status = $latest;
    }
    return [
        'refunded' => $refunded,
        'pending' => $pending,
        'total' => $refunded + $pending,
        'status' => $status,
    ];
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
    if (!$row) {
        return 0;
    }
    return max(0, (int) $row['amount'] - (int) $row['committed_refund']);
}

/**
 * Allocate a trusted refund amount across verified payments for one booking.
 * Returns the created refund record ids.
 *
 * @return list<int>
 */
function create_refund_requests_for_booking(
    int $bookingId,
    int $amount,
    string $reason,
    ?int $requestedBy = null,
): array {
    if ($amount <= 0) {
        return [];
    }
    $payments = database()->prepare(
        "SELECT p.*,
                COALESCE(SUM(CASE WHEN r.status IN ('pending','approved','processing','refunded') THEN r.amount ELSE 0 END),0) AS committed_refund
         FROM payments p
         LEFT JOIN payment_refunds r ON r.payment_id = p.id
         WHERE p.booking_id = ? AND p.status = 'paid'
         GROUP BY p.id
         ORDER BY CASE p.payment_type WHEN 'balance' THEN 0 WHEN 'modification' THEN 1 WHEN 'extension' THEN 2 WHEN 'deposit' THEN 3 ELSE 4 END,
                  p.paid_at DESC, p.id DESC"
    );
    $payments->execute([$bookingId]);
    $remaining = $amount;
    $created = [];
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
                (payment_id, booking_id, amount, status, reason, requested_at, created_at, updated_at)
             VALUES (?, ?, ?, 'pending', ?, ?, ?, ?)"
        );
        $insert->execute([
            (int) $payment['id'],
            $bookingId,
            $allocation,
            mb_substr(trim($reason), 0, 2000),
            $now,
            $now,
            $now,
        ]);
        $refundId = (int) database()->lastInsertId();
        $created[] = $refundId;
        $remaining -= $allocation;
        write_audit('refund_requested', 'payment_refund', $refundId, [
            'booking_id' => $bookingId,
            'payment_id' => (int) $payment['id'],
            'amount' => $allocation,
            'requested_by' => $requestedBy,
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
                'Refund Pending',
                'A refund of ' . money($amount) . ' for booking ' . $booking['reference'] . ' is awaiting administrator processing.',
                'refund',
                $bookingId,
            );
        }
    }
    return $created;
}

/** @return list<array<string,mixed>> */
function admin_refunds(string $status = 'all', string $query = ''): array
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
    if ($query !== '') {
        $sql .= ' AND (b.reference LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR v.name LIKE ?)';
        $term = '%' . $query . '%';
        array_push($params, $term, $term, $term, $term);
    }
    $sql .= " ORDER BY CASE r.status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 WHEN 'processing' THEN 2 ELSE 3 END, r.created_at DESC";
    $statement = database()->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}

function review_refund(
    int $refundId,
    string $newStatus,
    int $adminId,
    string $notes = '',
    string $referenceNumber = '',
): void {
    $databaseConnection = database();
    $databaseConnection->beginTransaction();
    try {
        $sql = "SELECT r.*, b.user_id, b.reference AS booking_reference, p.amount AS payment_amount
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
        $allowed = [
            'pending' => ['approved', 'rejected'],
            'approved' => ['processing', 'refunded', 'rejected'],
            'processing' => ['refunded', 'failed'],
            'failed' => ['processing', 'rejected'],
            'refunded' => [],
            'rejected' => [],
        ];
        if (!in_array($newStatus, $allowed[$refund['status']] ?? [], true)) {
            throw new RuntimeException('That refund status transition is not allowed.');
        }
        $committed = database()->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM payment_refunds
             WHERE payment_id = ? AND id <> ? AND status IN ('pending','approved','processing','refunded')"
        );
        $committed->execute([(int) $refund['payment_id'], $refundId]);
        if ((int) $committed->fetchColumn() + (int) $refund['amount'] > (int) $refund['payment_amount']) {
            throw new RuntimeException('Refund total cannot exceed the verified payment amount.');
        }
        $now = date('Y-m-d H:i:s');
        $approvedAt = in_array($newStatus, ['approved', 'processing', 'refunded'], true)
            ? ($refund['approved_at'] ?: $now)
            : $refund['approved_at'];
        $processedAt = $newStatus === 'refunded' ? $now : $refund['processed_at'];
        $update = $databaseConnection->prepare(
            "UPDATE payment_refunds
             SET status = ?, approved_at = ?, approved_by = ?, processed_at = ?,
                 reference_number = ?, notes = ?, updated_at = ?
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
        $titles = [
            'approved' => 'Refund Approved',
            'processing' => 'Refund Processing',
            'refunded' => 'Refund Processed',
            'rejected' => 'Refund Rejected',
            'failed' => 'Refund Failed',
        ];
        $messages = [
            'approved' => 'A refund of ' . money((int) $refund['amount']) . ' for booking ' . $refund['booking_reference'] . ' was approved.',
            'processing' => 'Your refund for booking ' . $refund['booking_reference'] . ' is being processed.',
            'refunded' => 'Your refund of ' . money((int) $refund['amount']) . ' for booking ' . $refund['booking_reference'] . ' was marked processed.',
            'rejected' => 'The refund request for booking ' . $refund['booking_reference'] . ' was rejected.',
            'failed' => 'The refund for booking ' . $refund['booking_reference'] . ' could not be completed and needs administrator follow-up.',
        ];
        notify_user(
            (int) $refund['user_id'],
            $titles[$newStatus] ?? 'Refund Updated',
            $messages[$newStatus] ?? 'Your refund status was updated.',
            'refund',
            (int) $refund['booking_id'],
        );
        write_audit('refund_' . $newStatus, 'payment_refund', $refundId, [
            'booking_id' => (int) $refund['booking_id'],
            'amount' => (int) $refund['amount'],
        ]);
        $databaseConnection->commit();
    } catch (Throwable $error) {
        if ($databaseConnection->inTransaction()) {
            $databaseConnection->rollBack();
        }
        throw $error;
    }
}
