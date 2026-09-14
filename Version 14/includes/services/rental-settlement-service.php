<?php

declare(strict_types=1);

/** @return array<string,mixed>|null */
function rental_settlement_for_booking(int $bookingId): ?array
{
    $statement = database()->prepare(
        "SELECT s.*, u.name AS finalized_by_name
         FROM rental_settlements s
         LEFT JOIN users u ON u.id = s.finalized_by
         WHERE s.booking_id = ? LIMIT 1"
    );
    $statement->execute([$bookingId]);
    $row = $statement->fetch();
    return $row ?: null;
}

function verified_deposit_held_for_booking(int $bookingId): int
{
    $statement = database()->prepare("SELECT id FROM payments WHERE booking_id=? AND payment_type='deposit' AND status='paid'");
    $statement->execute([$bookingId]);
    $total = 0;
    foreach ($statement->fetchAll() as $row) {
        $total += refundable_balance_for_payment((int) $row['id']);
    }
    return $total;
}

/** @return array{deposit_held:int,inspection_charge:int,damage:int,fuel:int,late:int,other:int,total_deductions:int,deposit_refund:int,outstanding:int} */
function rental_settlement_preview(array $booking, array $input = []): array
{
    $inspections = inspections_for_booking((int) $booking['id']);
    $checkin = $inspections['checkin'] ?? null;
    $inspectionCharge = $checkin ? (int) $checkin['extra_charges'] : 0;
    $damage = max(0, (int) ($input['damage_charges'] ?? 0));
    $fuel = max(0, (int) ($input['fuel_charges'] ?? 0));
    $late = max(0, (int) ($input['late_charges'] ?? 0));
    $other = max(0, (int) ($input['other_charges'] ?? $inspectionCharge));
    $deductions = $damage + $fuel + $late + $other;
    $depositHeld = verified_deposit_held_for_booking((int) $booking['id']);
    return [
        'deposit_held' => $depositHeld,
        'inspection_charge' => $inspectionCharge,
        'damage' => $damage,
        'fuel' => $fuel,
        'late' => $late,
        'other' => $other,
        'total_deductions' => $deductions,
        'deposit_refund' => max(0, $depositHeld - $deductions),
        'outstanding' => max(0, $deductions - $depositHeld),
    ];
}

/** Finalize one authoritative post-return settlement and create any deposit refund/outstanding charge. */
function finalize_return_settlement(array $booking, array $input, int $adminId): int
{
    if ($booking['status'] !== 'returned') {
        throw new RuntimeException('Only a returned rental can be settled.');
    }
    if (rental_settlement_for_booking((int) $booking['id'])) {
        throw new RuntimeException('This rental already has a finalized settlement.');
    }
    $inspections = inspections_for_booking((int) $booking['id']);
    if (!isset($inspections['checkin'])) {
        throw new RuntimeException('Complete the return inspection before final settlement.');
    }
    $preview = rental_settlement_preview($booking, $input);
    if ($preview['total_deductions'] !== $preview['inspection_charge']) {
        throw new InvalidArgumentException(
            'Damage, fuel, late, and other deductions must add up to the return-inspection extra charge of ' . money($preview['inspection_charge']) . '.'
        );
    }

    $databaseConnection = database();
    $databaseConnection->beginTransaction();
    try {
        $now = date('Y-m-d H:i:s');
        $status = $preview['outstanding'] > 0 ? 'balance_due' : ($preview['deposit_refund'] > 0 ? 'refund_pending' : 'settled');
        $insert = $databaseConnection->prepare(
            "INSERT INTO rental_settlements
             (booking_id, deposit_paid, damage_charges, fuel_charges, late_charges, other_charges,
              total_deductions, deposit_refund_amount, outstanding_balance, status, finalized_at, finalized_by, notes, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $insert->execute([
            (int) $booking['id'], $preview['deposit_held'], $preview['damage'], $preview['fuel'], $preview['late'], $preview['other'],
            $preview['total_deductions'], $preview['deposit_refund'], $preview['outstanding'], $status, $now, $adminId,
            mb_substr(trim((string) ($input['settlement_notes'] ?? '')), 0, 3000), $now, $now,
        ]);
        $settlementId = (int) $databaseConnection->lastInsertId();

        if ($preview['deposit_refund'] > 0) {
            create_refund_requests_for_booking(
                (int) $booking['id'],
                $preview['deposit_refund'],
                'Unused security deposit after final return settlement',
                $adminId,
                'security_deposit',
                ['rental_settlement_id' => $settlementId],
                ['deposit'],
            );
        }
        notify_user(
            (int) $booking['user_id'],
            'Return Settlement Complete',
            'Return settlement for booking ' . $booking['reference'] . ' is complete. ' .
            ($preview['deposit_refund'] > 0
                ? 'Security deposit refund: ' . money($preview['deposit_refund']) . ' (pending processing).'
                : ($preview['outstanding'] > 0
                    ? 'Outstanding balance: ' . money($preview['outstanding']) . '.'
                    : 'No refund or additional balance is due.')),
            'settlement',
            (int) $booking['id'],
        );
        write_audit('return_settlement_finalized', 'rental_settlement', $settlementId, [
            'booking_id' => (int) $booking['id'],
            'deposit_paid' => $preview['deposit_held'],
            'deductions' => $preview['total_deductions'],
            'deposit_refund' => $preview['deposit_refund'],
            'outstanding' => $preview['outstanding'],
        ]);
        $databaseConnection->commit();
        return $settlementId;
    } catch (Throwable $error) {
        if ($databaseConnection->inTransaction()) {
            $databaseConnection->rollBack();
        }
        throw $error;
    }
}

function refresh_rental_settlement_status(int $bookingId): void
{
    $settlement = rental_settlement_for_booking($bookingId);
    if (!$settlement) {
        return;
    }
    $refundState = database()->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN status IN ('pending','approved','processing') THEN amount ELSE 0 END),0) AS pending_amount,
            COALESCE(SUM(CASE WHEN status='refunded' THEN amount ELSE 0 END),0) AS refunded_amount
         FROM payment_refunds
         WHERE rental_settlement_id=? AND refund_type='security_deposit'"
    );
    $refundState->execute([(int) $settlement['id']]);
    $refundSummary = $refundState->fetch() ?: ['pending_amount'=>0,'refunded_amount'=>0];
    $bookingStatement = database()->prepare('SELECT reference FROM bookings WHERE id=? LIMIT 1');
    $bookingStatement->execute([$bookingId]);
    $reference = (string) $bookingStatement->fetchColumn();
    $booking = $reference !== '' ? booking_find_by_reference($reference) : null;
    $paymentSummary = $booking ? booking_payment_summary($booking) : null;
    $status = (string) $settlement['status'];
    if ((int) $settlement['outstanding_balance'] > 0 && $paymentSummary && (int) $paymentSummary['extra_due'] > 0) {
        $status = 'balance_due';
    } elseif ((int) $settlement['deposit_refund_amount'] > 0) {
        if ((int) $refundSummary['pending_amount'] > 0) {
            $status = 'refund_pending';
        } elseif ((int) $refundSummary['refunded_amount'] >= (int) $settlement['deposit_refund_amount']) {
            $status = 'settled';
        }
    } else {
        $status = 'settled';
    }
    database()->prepare('UPDATE rental_settlements SET status=?, updated_at=? WHERE id=?')->execute([$status, date('Y-m-d H:i:s'), (int) $settlement['id']]);
}
