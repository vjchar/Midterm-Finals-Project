<?php

declare(strict_types=1);

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
