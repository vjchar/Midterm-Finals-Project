<?php

declare(strict_types=1);

/** @return list<string> */
function rental_adjustment_statuses(): array
{
    return ['pending', 'approved', 'rejected', 'cancelled', 'activated', 'completed'];
}

/** @return list<array<string,mixed>> */
function rental_adjustments_for_booking(int $bookingId): array
{
    $statement = database()->prepare(
        "SELECT r.*, reviewer.name AS reviewer_name
         FROM rental_adjustment_requests r
         LEFT JOIN users reviewer ON reviewer.id = r.reviewed_by
         WHERE r.booking_id = ? ORDER BY r.created_at DESC, r.id DESC"
    );
    $statement->execute([$bookingId]);
    return $statement->fetchAll();
}

/** @return array<string,mixed>|null */
function rental_adjustment_find(int $adjustmentId): ?array
{
    $statement = database()->prepare(
        "SELECT r.*, b.reference, b.vehicle_id, b.status AS booking_status,
                b.return_at, b.pickup_at, b.started_at,
                b.total AS booking_total, b.promo_code, b.delivery_fee,
                v.name AS vehicle_name, v.price AS current_daily_price,
                u.name AS customer_name, u.email AS customer_email
         FROM rental_adjustment_requests r
         JOIN bookings b ON b.id = r.booking_id
         JOIN vehicles v ON v.id = b.vehicle_id
         JOIN users u ON u.id = r.user_id
         WHERE r.id = ? LIMIT 1"
    );
    $statement->execute([$adjustmentId]);
    $row = $statement->fetch();
    return $row ?: null;
}

/** @return array<string,mixed>|null */
function unresolved_rental_adjustment(int $bookingId): ?array
{
    $statement = database()->prepare(
        "SELECT * FROM rental_adjustment_requests
         WHERE booking_id = ? AND status IN ('pending','approved')
         ORDER BY created_at DESC LIMIT 1"
    );
    $statement->execute([$bookingId]);
    $row = $statement->fetch();
    return $row ?: null;
}

/** @return array<string,mixed>|null */
function approved_extension_for_booking(int $bookingId): ?array
{
    $statement = database()->prepare(
        "SELECT * FROM rental_adjustment_requests
         WHERE booking_id = ? AND request_type = 'extension' AND status = 'approved'
         ORDER BY created_at DESC LIMIT 1"
    );
    $statement->execute([$bookingId]);
    $row = $statement->fetch();
    return $row ?: null;
}

function rental_adjustment_parse_datetime(string $dateValue, string $timeValue): string
{
    if (!valid_date($dateValue) || !preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $timeValue)) {
        throw new InvalidArgumentException('Choose a valid return date and time.');
    }
    return (new DateTimeImmutable($dateValue . ' ' . $timeValue))->format('Y-m-d H:i:s');
}

/** @return array{days:int,subtotal:int,addons_total:int,discount:int,total:int,price_difference:int} */
function extension_pricing(array $booking, string $requestedReturnAt): array
{
    $pickup = new DateTimeImmutable((string) $booking['pickup_at']);
    $requestedReturn = new DateTimeImmutable($requestedReturnAt);
    if ($requestedReturn <= $pickup) {
        throw new InvalidArgumentException('The requested return must be after pickup.');
    }
    $days = max(1, (int) ceil(($requestedReturn->getTimestamp() - $pickup->getTimestamp()) / 86400));
    if ($days > 30) {
        throw new InvalidArgumentException('Online rentals can cover up to 30 days. Contact support for a longer arrangement.');
    }
    $vehicle = vehicle_find((int) $booking['vehicle_id'], false);
    if (!$vehicle || !(bool) $vehicle['is_active']) {
        throw new RuntimeException('This vehicle is no longer eligible for an extension.');
    }
    $subtotal = (int) $vehicle['price'] * $days;
    $addonStatement = database()->prepare('SELECT * FROM booking_addons WHERE booking_id = ?');
    $addonStatement->execute([(int) $booking['id']]);
    $addonsTotal = 0;
    foreach ($addonStatement->fetchAll() as $addon) {
        $quantity = $addon['billing'] === 'day' ? $days : 1;
        $addonsTotal += (int) $addon['unit_price'] * $quantity;
    }
    $promotion = null;
    if (!empty($booking['promo_code'])) {
        $promoStatement = database()->prepare('SELECT * FROM promos WHERE UPPER(code) = UPPER(?) LIMIT 1');
        $promoStatement->execute([(string) $booking['promo_code']]);
        $promotion = $promoStatement->fetch() ?: null;
    }
    $discount = $promotion ? apply_promo($promotion, $subtotal) : 0;
    $total = $subtotal + $addonsTotal + (int) $booking['delivery_fee'] - $discount;
    return [
        'days' => $days,
        'subtotal' => $subtotal,
        'addons_total' => $addonsTotal,
        'discount' => $discount,
        'total' => $total,
        'price_difference' => max(0, $total - (int) $booking['total']),
    ];
}

function extension_has_maintenance_conflict(int $vehicleId, string $fromAt, string $toAt): bool
{
    $statement = database()->prepare(
        "SELECT COUNT(*) FROM maintenance_records
         WHERE vehicle_id = ? AND status IN ('scheduled','in_progress')
         AND starts_at < ? AND COALESCE(ends_at, starts_at) > ?"
    );
    $statement->execute([$vehicleId, $toAt, $fromAt]);
    return (int) $statement->fetchColumn() > 0;
}

function assert_extension_available(array $booking, string $requestedReturnAt): void
{
    $currentReturn = new DateTimeImmutable((string) $booking['return_at']);
    $requestedReturn = new DateTimeImmutable($requestedReturnAt);
    if ($requestedReturn <= $currentReturn) {
        throw new InvalidArgumentException('An extension must be later than the current scheduled return.');
    }
    if ($currentReturn <= new DateTimeImmutable()) {
        throw new RuntimeException('This rental is already due or overdue. Contact the rental desk for assistance.');
    }
    if (!vehicle_available((int) $booking['vehicle_id'], $currentReturn->format('Y-m-d H:i:s'), $requestedReturnAt, (int) $booking['id'])) {
        throw new RuntimeException('This vehicle is unavailable for the requested extension period.');
    }
    if (extension_has_maintenance_conflict((int) $booking['vehicle_id'], $currentReturn->format('Y-m-d H:i:s'), $requestedReturnAt)) {
        throw new RuntimeException('This vehicle is unavailable for the requested extension period.');
    }
}

/** @return array{requested_return_at:string,pricing:array<string,int>} */
function preview_rental_extension(array $booking, int $userId, string $dateValue, string $timeValue): array
{
    if ((int) $booking['user_id'] !== $userId || $booking['status'] !== 'active') {
        throw new RuntimeException('Only your active rental can be extended.');
    }
    $requestedReturnAt = rental_adjustment_parse_datetime($dateValue, $timeValue);
    assert_extension_available($booking, $requestedReturnAt);
    return ['requested_return_at' => $requestedReturnAt, 'pricing' => extension_pricing($booking, $requestedReturnAt)];
}

function create_rental_adjustment_request(array $booking, int $userId, string $type, string $dateValue, string $timeValue, string $reason): int
{
    if ((int) $booking['user_id'] !== $userId || $booking['status'] !== 'active') {
        throw new RuntimeException('Only your active rental can be adjusted.');
    }
    if (unresolved_rental_adjustment((int) $booking['id'])) {
        throw new RuntimeException('Resolve the current rental adjustment request before creating another.');
    }
    if (!in_array($type, ['early_return', 'extension'], true)) {
        throw new InvalidArgumentException('Choose a valid rental adjustment.');
    }
    $requestedReturnAt = rental_adjustment_parse_datetime($dateValue, $timeValue);
    $startedAt = new DateTimeImmutable((string) ($booking['started_at'] ?: $booking['pickup_at']));
    $currentReturn = new DateTimeImmutable((string) $booking['return_at']);
    $priceDifference = 0;
    if ($type === 'early_return') {
        $requested = new DateTimeImmutable($requestedReturnAt);
        if ($requested <= $startedAt || $requested >= $currentReturn) {
            throw new InvalidArgumentException('Choose a return time after vehicle release and before the current scheduled return.');
        }
        if ($requested <= new DateTimeImmutable()) {
            throw new InvalidArgumentException('Choose a future early-return time.');
        }
    } else {
        assert_extension_available($booking, $requestedReturnAt);
        $priceDifference = extension_pricing($booking, $requestedReturnAt)['price_difference'];
    }
    $now = date('Y-m-d H:i:s');
    $databaseConnection = database();
    $databaseConnection->beginTransaction();
    try {
        if ($databaseConnection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $lock = $databaseConnection->prepare('SELECT id FROM bookings WHERE id = ? FOR UPDATE');
            $lock->execute([(int) $booking['id']]);
        }
        if (unresolved_rental_adjustment((int) $booking['id'])) {
            throw new RuntimeException('Resolve the current rental adjustment request before creating another.');
        }
        $statement = $databaseConnection->prepare(
            "INSERT INTO rental_adjustment_requests
            (booking_id,user_id,request_type,original_return_at,requested_return_at,status,price_difference,customer_reason,created_at,updated_at)
            VALUES (?,?,?,?,?,'pending',?,?,?,?)"
        );
        $statement->execute([
            (int) $booking['id'], $userId, $type, (string) $booking['return_at'], $requestedReturnAt,
            $priceDifference, mb_substr(trim($reason), 0, 1500), $now, $now,
        ]);
        $id = (int) $databaseConnection->lastInsertId();
        $databaseConnection->prepare('UPDATE bookings SET original_return_at = COALESCE(original_return_at, return_at), updated_at = ? WHERE id = ?')
            ->execute([$now, (int) $booking['id']]);
        notify_user($userId, $type === 'extension' ? 'Extension request submitted' : 'Early return request submitted',
            'Your request for booking ' . $booking['reference'] . ' is awaiting administrator review.', 'rental', (int) $booking['id']);
        notify_admins($type === 'extension' ? 'New rental extension request' : 'New early return request',
            'Booking ' . $booking['reference'] . ' has a rental adjustment request awaiting review.', 'rental', (int) $booking['id']);
        write_audit($type === 'extension' ? 'rental_extension_requested' : 'early_return_requested', 'rental_adjustment', $id, ['booking_id' => (int) $booking['id']]);
        $databaseConnection->commit();
        return $id;
    } catch (Throwable $error) {
        if ($databaseConnection->inTransaction()) { $databaseConnection->rollBack(); }
        throw $error;
    }
}

function cancel_rental_adjustment_request(int $adjustmentId, int $userId): void
{
    $adjustment = rental_adjustment_find($adjustmentId);
    if (!$adjustment || (int) $adjustment['user_id'] !== $userId || $adjustment['status'] !== 'pending') {
        throw new RuntimeException('That rental adjustment cannot be cancelled.');
    }
    database()->prepare("UPDATE rental_adjustment_requests SET status='cancelled', updated_at=? WHERE id=?")
        ->execute([date('Y-m-d H:i:s'), $adjustmentId]);
    write_audit('rental_adjustment_cancelled', 'rental_adjustment', $adjustmentId);
}

function review_rental_adjustment_request(int $adjustmentId, string $decision, string $adminNote, int $adminId): void
{
    if (!in_array($decision, ['approve','reject'], true)) {
        throw new InvalidArgumentException('Choose approve or reject.');
    }
    $databaseConnection = database();
    $databaseConnection->beginTransaction();
    try {
        $selectSql = "SELECT r.*, b.reference,b.user_id,b.vehicle_id,b.status AS booking_status,b.pickup_at,b.return_at,b.started_at,b.total,b.promo_code,b.delivery_fee
                      FROM rental_adjustment_requests r JOIN bookings b ON b.id=r.booking_id WHERE r.id=? LIMIT 1";
        if ($databaseConnection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') { $selectSql .= ' FOR UPDATE'; }
        $select = $databaseConnection->prepare($selectSql);
        $select->execute([$adjustmentId]);
        $adjustment = $select->fetch();
        if (!$adjustment || $adjustment['status'] !== 'pending' || $adjustment['booking_status'] !== 'active') {
            throw new RuntimeException('This request is no longer eligible for review.');
        }
        $now = date('Y-m-d H:i:s');
        if ($decision === 'reject') {
            $databaseConnection->prepare("UPDATE rental_adjustment_requests SET status='rejected',admin_note=?,reviewed_at=?,reviewed_by=?,updated_at=? WHERE id=?")
                ->execute([mb_substr(trim($adminNote),0,1500),$now,$adminId,$now,$adjustmentId]);
            notify_user((int)$adjustment['user_id'],'Rental adjustment rejected','Your request for booking '.$adjustment['reference'].' was not approved.','rental',(int)$adjustment['booking_id']);
            write_audit('rental_adjustment_rejected','rental_adjustment',$adjustmentId,['admin_id'=>$adminId]);
            $databaseConnection->commit();
            return;
        }
        if ($adjustment['request_type'] === 'extension') {
            if ($databaseConnection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $vehicleLock = $databaseConnection->prepare('SELECT id FROM vehicles WHERE id = ? FOR UPDATE');
                $vehicleLock->execute([(int) $adjustment['vehicle_id']]);
            }
            assert_extension_available($adjustment, (string)$adjustment['requested_return_at']);
            $pricing = extension_pricing($adjustment, (string)$adjustment['requested_return_at']);
            $databaseConnection->prepare("UPDATE rental_adjustment_requests SET status='approved',price_difference=?,admin_note=?,reviewed_at=?,reviewed_by=?,updated_at=? WHERE id=?")
                ->execute([$pricing['price_difference'],mb_substr(trim($adminNote),0,1500),$now,$adminId,$now,$adjustmentId]);
            if ($pricing['price_difference'] === 0) {
                activate_extension_request($adjustmentId, $adminId);
            } else {
                notify_user((int)$adjustment['user_id'],'Extension approved — payment required','Your extension for booking '.$adjustment['reference'].' was approved. Submit the additional payment to activate the new return schedule.','rental',(int)$adjustment['booking_id']);
            }
            write_audit('rental_extension_approved','rental_adjustment',$adjustmentId,['admin_id'=>$adminId,'price_difference'=>$pricing['price_difference']]);
        } else {
            $databaseConnection->prepare("UPDATE rental_adjustment_requests SET status='approved',admin_note=?,reviewed_at=?,reviewed_by=?,updated_at=? WHERE id=?")
                ->execute([mb_substr(trim($adminNote),0,1500),$now,$adminId,$now,$adjustmentId]);
            notify_user((int)$adjustment['user_id'],'Early return request approved','The rental desk has acknowledged your planned early return for booking '.$adjustment['reference'].'. Actual return is recorded at check-in.','rental',(int)$adjustment['booking_id']);
            write_audit('early_return_approved','rental_adjustment',$adjustmentId,['admin_id'=>$adminId]);
        }
        $databaseConnection->commit();
    } catch (Throwable $error) {
        if ($databaseConnection->inTransaction()) { $databaseConnection->rollBack(); }
        throw $error;
    }
}

function activate_extension_request(int $adjustmentId, int $adminId): void
{
    $databaseConnection = database();
    $ownsTransaction = !$databaseConnection->inTransaction();
    if ($ownsTransaction) { $databaseConnection->beginTransaction(); }
    try {
        $sql = "SELECT r.*, b.reference,b.user_id,b.vehicle_id,b.status AS booking_status,b.pickup_at,b.return_at,b.started_at,b.total,b.promo_code,b.delivery_fee
                FROM rental_adjustment_requests r JOIN bookings b ON b.id=r.booking_id WHERE r.id=? LIMIT 1";
        if ($databaseConnection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') { $sql .= ' FOR UPDATE'; }
        $statement = $databaseConnection->prepare($sql);
        $statement->execute([$adjustmentId]);
        $adjustment = $statement->fetch();
        if (!$adjustment || $adjustment['request_type'] !== 'extension' || $adjustment['status'] !== 'approved' || $adjustment['booking_status'] !== 'active') {
            throw new RuntimeException('This extension is not ready to activate.');
        }
        if ($databaseConnection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $vehicleLock = $databaseConnection->prepare('SELECT id FROM vehicles WHERE id = ? FOR UPDATE');
            $vehicleLock->execute([(int) $adjustment['vehicle_id']]);
        }
        assert_extension_available($adjustment, (string)$adjustment['requested_return_at']);
        $pricing = extension_pricing($adjustment, (string)$adjustment['requested_return_at']);
        $paidStatement = $databaseConnection->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE rental_adjustment_id=? AND payment_type='extension' AND status='paid'");
        $paidStatement->execute([$adjustmentId]);
        if ((int)$paidStatement->fetchColumn() < $pricing['price_difference']) {
            throw new RuntimeException('Verify the full extension payment before activating the new return schedule.');
        }
        $days = $pricing['days'];
        $addonStatement = $databaseConnection->prepare('SELECT * FROM booking_addons WHERE booking_id=?');
        $addonStatement->execute([(int)$adjustment['booking_id']]);
        foreach ($addonStatement->fetchAll() as $addon) {
            $quantity = $addon['billing'] === 'day' ? $days : 1;
            $databaseConnection->prepare('UPDATE booking_addons SET quantity=?, line_total=? WHERE id=?')
                ->execute([$quantity,(int)$addon['unit_price']*$quantity,(int)$addon['id']]);
        }
        $now = date('Y-m-d H:i:s');
        $databaseConnection->prepare('UPDATE bookings SET original_return_at=COALESCE(original_return_at,return_at), return_at=?, subtotal=?, addons_total=?, discount=?, total=?, updated_at=? WHERE id=?')
            ->execute([(string)$adjustment['requested_return_at'],$pricing['subtotal'],$pricing['addons_total'],$pricing['discount'],$pricing['total'],$now,(int)$adjustment['booking_id']]);
        $databaseConnection->prepare("UPDATE rental_adjustment_requests SET status='activated',price_difference=?,activated_at=?,updated_at=? WHERE id=?")
            ->execute([$pricing['price_difference'],$now,$now,$adjustmentId]);
        notify_user((int)$adjustment['user_id'],'Rental extension activated','Booking '.$adjustment['reference'].' now has a return time of '.date('M j, Y g:i A',strtotime((string)$adjustment['requested_return_at'])).'.','rental',(int)$adjustment['booking_id']);
        write_audit('extension_activated','rental_adjustment',$adjustmentId,['admin_id'=>$adminId,'new_return_at'=>$adjustment['requested_return_at']]);
        if ($ownsTransaction) { $databaseConnection->commit(); }
    } catch (Throwable $error) {
        if ($ownsTransaction && $databaseConnection->inTransaction()) { $databaseConnection->rollBack(); }
        throw $error;
    }
}

function finalize_rental_adjustments_on_return(int $bookingId): void
{
    $now = date('Y-m-d H:i:s');
    database()->prepare("UPDATE rental_adjustment_requests SET status='completed',updated_at=? WHERE booking_id=? AND request_type='early_return' AND status IN ('pending','approved')")
        ->execute([$now,$bookingId]);
    database()->prepare("UPDATE rental_adjustment_requests SET status='cancelled',updated_at=? WHERE booking_id=? AND request_type='extension' AND status IN ('pending','approved')")
        ->execute([$now,$bookingId]);
}
