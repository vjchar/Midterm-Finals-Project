<?php

declare(strict_types=1);

/** @return array<string,mixed>|null */
function cancellation_request_for_booking(int $bookingId): ?array
{
    $statement = database()->prepare(
        "SELECT c.*, reviewer.name AS reviewer_name
         FROM booking_cancellation_requests c
         LEFT JOIN users reviewer ON reviewer.id = c.reviewed_by
         WHERE c.booking_id = ? ORDER BY c.created_at DESC LIMIT 1"
    );
    $statement->execute([$bookingId]);
    $row = $statement->fetch();
    return $row ?: null;
}

/** @return list<array<string,mixed>> */
function admin_cancellation_requests(string $status = 'pending'): array
{
    $sql = "SELECT c.*, b.reference AS booking_reference, b.status AS booking_status,
                   b.pickup_at, b.total, u.name AS customer_name, u.email AS customer_email,
                   v.name AS vehicle_name
            FROM booking_cancellation_requests c
            JOIN bookings b ON b.id = c.booking_id
            JOIN users u ON u.id = c.user_id
            JOIN vehicles v ON v.id = b.vehicle_id";
    $params = [];
    if ($status !== 'all') {
        $sql .= ' WHERE c.status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY c.requested_at DESC';
    $statement = database()->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}

/** @return array{paid:int,refundable:int,non_refundable:int,hours_to_pickup:int} */
function cancellation_refund_preview(array $booking): array
{
    $paidStatement = database()->prepare(
        "SELECT COALESCE(SUM(p.amount),0)
         FROM payments p
         WHERE p.booking_id = ? AND p.status = 'paid'"
    );
    $paidStatement->execute([(int) $booking['id']]);
    $grossPaid = (int) $paidStatement->fetchColumn();
    $refunds = refund_summary_for_booking((int) $booking['id']);
    $availablePaid = max(0, $grossPaid - $refunds['total']);
    $hours = (int) floor((strtotime((string) $booking['pickup_at']) - time()) / 3600);
    $refundable = $hours > CANCELLATION_REFUND_CUTOFF_HOURS ? $availablePaid : 0;
    return [
        'paid' => $availablePaid,
        'refundable' => $refundable,
        'non_refundable' => max(0, $availablePaid - $refundable),
        'hours_to_pickup' => $hours,
    ];
}

function request_booking_cancellation(array $booking, int $userId, string $reason): int
{
    if ((int) $booking['user_id'] !== $userId) {
        throw new RuntimeException('This booking does not belong to your account.');
    }
    if (!in_array($booking['status'], ['pending', 'confirmed', 'ready'], true)) {
        throw new RuntimeException('This booking can no longer use pre-pickup cancellation.');
    }
    if (unresolved_booking_modification((int) $booking['id'])) {
        throw new RuntimeException('Resolve the pending booking modification before requesting cancellation.');
    }
    if (new DateTimeImmutable((string) $booking['pickup_at']) <= new DateTimeImmutable('now')) {
        throw new RuntimeException('Pre-pickup cancellation requests are no longer available after the scheduled pickup time. Contact support for assistance.');
    }
    if (mb_strlen(trim($reason)) < 3) {
        throw new InvalidArgumentException('Tell us briefly why you need to cancel this booking.');
    }
    $existing = cancellation_request_for_booking((int) $booking['id']);
    if ($existing && $existing['status'] === 'pending') {
        throw new RuntimeException('A cancellation request is already awaiting review.');
    }
    $preview = cancellation_refund_preview($booking);
    $now = date('Y-m-d H:i:s');
    $statement = database()->prepare(
        "INSERT INTO booking_cancellation_requests
            (booking_id, user_id, reason, status, refundable_amount, non_refundable_amount, requested_at, created_at, updated_at)
         VALUES (?, ?, ?, 'pending', ?, ?, ?, ?, ?)"
    );
    $statement->execute([
        (int) $booking['id'],
        $userId,
        mb_substr(trim($reason), 0, 2000),
        $preview['refundable'],
        $preview['non_refundable'],
        $now,
        $now,
        $now,
    ]);
    $requestId = (int) database()->lastInsertId();
    notify_user($userId, 'Cancellation Submitted', 'Your cancellation request for booking ' . $booking['reference'] . ' is awaiting administrator review.', 'cancellation', (int) $booking['id']);
    notify_admins('Cancellation Request', 'Booking ' . $booking['reference'] . ' has a cancellation request awaiting review.', 'cancellation', (int) $booking['id']);
    write_audit('booking_cancellation_requested', 'booking_cancellation', $requestId, ['booking_id' => (int) $booking['id']]);
    return $requestId;
}

function review_booking_cancellation(int $requestId, string $decision, int $adminId, string $adminNote = ''): void
{
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        throw new InvalidArgumentException('Choose approve or reject.');
    }
    $databaseConnection = database();
    $databaseConnection->beginTransaction();
    try {
        $sql = "SELECT c.*, b.reference, b.status AS booking_status, b.pickup_at, b.vehicle_id, b.user_id AS booking_user_id
                FROM booking_cancellation_requests c
                JOIN bookings b ON b.id = c.booking_id
                WHERE c.id = ? LIMIT 1";
        if ($databaseConnection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $statement = $databaseConnection->prepare($sql);
        $statement->execute([$requestId]);
        $request = $statement->fetch();
        if (!$request || $request['status'] !== 'pending') {
            throw new RuntimeException('That cancellation request is no longer pending.');
        }
        $booking = booking_find_by_reference((string) $request['reference']);
        if (!$booking || !in_array($booking['status'], ['pending', 'confirmed', 'ready'], true)) {
            throw new RuntimeException('The booking is no longer eligible for pre-pickup cancellation.');
        }
        $preview = cancellation_refund_preview($booking);
        $now = date('Y-m-d H:i:s');
        $databaseConnection->prepare(
            "UPDATE booking_cancellation_requests
             SET status = ?, refundable_amount = ?, non_refundable_amount = ?, admin_note = ?, reviewed_at = ?, reviewed_by = ?, updated_at = ?
             WHERE id = ?"
        )->execute([
            $decision,
            $preview['refundable'],
            $preview['non_refundable'],
            mb_substr(trim($adminNote), 0, 2000),
            $now,
            $adminId,
            $now,
            $requestId,
        ]);
        if ($decision === 'approved') {
            $databaseConnection->prepare(
                "UPDATE bookings SET status='cancelled', cancelled_at=?, updated_at=? WHERE id=?"
            )->execute([$now, $now, (int) $booking['id']]);
            sync_vehicle_status((int) $booking['vehicle_id']);
            if ($preview['refundable'] > 0) {
                create_refund_requests_for_booking(
                    (int) $booking['id'],
                    $preview['refundable'],
                    'Approved cancellation for booking ' . $booking['reference'],
                    $adminId,
                    'cancellation',
                    ['cancellation_request_id' => $requestId],
                );
            }
            notify_user(
                (int) $booking['user_id'],
                'Cancellation Approved',
                'Booking ' . $booking['reference'] . ' was cancelled. ' . ($preview['refundable'] > 0
                    ? 'A refund of ' . money($preview['refundable']) . ' is awaiting processing.'
                    : 'No refund is due under the current cancellation cutoff policy.'),
                'cancellation',
                (int) $booking['id'],
            );
            write_audit('booking_cancelled', 'booking', (int) $booking['id'], ['cancellation_request_id' => $requestId]);
        } else {
            notify_user((int) $booking['user_id'], 'Cancellation Rejected', 'The cancellation request for booking ' . $booking['reference'] . ' was not approved. Your existing booking remains unchanged.', 'cancellation', (int) $booking['id']);
        }
        write_audit('booking_cancellation_' . $decision, 'booking_cancellation', $requestId, ['booking_id' => (int) $booking['id']]);
        $databaseConnection->commit();
    } catch (Throwable $error) {
        if ($databaseConnection->inTransaction()) {
            $databaseConnection->rollBack();
        }
        throw $error;
    }
}
