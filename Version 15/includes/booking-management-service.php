<?php

declare(strict_types=1);


/**
 * FILE: includes/booking-management-service.php
 * FILE PURPOSE: Booking modification, rescheduling, and cancellation service.
 * USED BY: Manage-booking, modification, and cancellation pages.
 * RESPONSIBILITY: Encapsulates eligibility rules, requests, calculations, and persistence for changes to existing bookings.
 *
 * Maintenance note: Keep this file focused on the responsibility described above.
 */
/**
 * Customer and administrator booking changes, rescheduling, and cancellation workflows.
 */

/****************************************************************************
 * BOOKING MODIFICATIONS AND RESCHEDULING
 ****************************************************************************/

/** @return list<string> */
function booking_modification_statuses(): array
{
    return ['pending', 'approved', 'rejected', 'cancelled', 'activated'];
}

/** @return array<string,mixed>|null */
function booking_modification_find(int $modificationId): ?array
{
    $statement = database()->prepare(
        "SELECT m.*, b.reference AS booking_reference, b.status AS booking_status,
                u.name AS customer_name, u.email AS customer_email,
                v.name AS current_vehicle_name, requested_vehicle.name AS requested_vehicle_name,
                reviewer.name AS reviewer_name
         FROM booking_modification_requests m
         JOIN bookings b ON b.id = m.booking_id
         JOIN users u ON u.id = m.user_id
         JOIN vehicles v ON v.id = b.vehicle_id
         LEFT JOIN vehicles requested_vehicle ON requested_vehicle.id = m.requested_vehicle_id
         LEFT JOIN users reviewer ON reviewer.id = m.reviewed_by
         WHERE m.id = ? LIMIT 1"
    );
    $statement->execute([$modificationId]);
    $row = $statement->fetch();
    return $row ?: null;
}

/** @return array<string,mixed>|null */
function unresolved_booking_modification(int $bookingId): ?array
{
    $statement = database()->prepare(
        "SELECT * FROM booking_modification_requests
         WHERE booking_id = ? AND status IN ('pending','approved')
         ORDER BY created_at DESC LIMIT 1"
    );
    $statement->execute([$bookingId]);
    $row = $statement->fetch();
    return $row ?: null;
}

/** @return array<string,mixed>|null */
function approved_modification_for_booking(int $bookingId): ?array
{
    $statement = database()->prepare(
        "SELECT * FROM booking_modification_requests
         WHERE booking_id = ? AND status = 'approved' AND price_difference > 0
         ORDER BY reviewed_at DESC, id DESC LIMIT 1"
    );
    $statement->execute([$bookingId]);
    $row = $statement->fetch();
    return $row ?: null;
}

/** @return list<array<string,mixed>> */
function booking_modifications_for_booking(int $bookingId): array
{
    $statement = database()->prepare(
        "SELECT m.*, reviewer.name AS reviewer_name,
                rv.name AS requested_vehicle_name
         FROM booking_modification_requests m
         LEFT JOIN users reviewer ON reviewer.id = m.reviewed_by
         LEFT JOIN vehicles rv ON rv.id = m.requested_vehicle_id
         WHERE m.booking_id = ? ORDER BY m.created_at DESC"
    );
    $statement->execute([$bookingId]);
    return $statement->fetchAll();
}

/** @return list<array<string,mixed>> */
function admin_booking_modifications(string $status = 'pending'): array
{
    $sql = "SELECT m.*, b.reference AS booking_reference, b.pickup_at AS current_pickup_at,
                   b.return_at AS current_return_at, u.name AS customer_name, u.email AS customer_email,
                   v.name AS current_vehicle_name, rv.name AS requested_vehicle_name
            FROM booking_modification_requests m
            JOIN bookings b ON b.id = m.booking_id
            JOIN users u ON u.id = m.user_id
            JOIN vehicles v ON v.id = b.vehicle_id
            LEFT JOIN vehicles rv ON rv.id = m.requested_vehicle_id";
    $params = [];
    if ($status !== 'all') {
        $sql .= ' WHERE m.status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY m.requested_at DESC';
    $statement = database()->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}

function booking_modification_maintenance_conflict(int $vehicleId, string $pickupAt, string $returnAt): bool
{
    $statement = database()->prepare(
        "SELECT COUNT(*) FROM maintenance_records
         WHERE vehicle_id = ? AND status IN ('scheduled','in_progress')
           AND starts_at < ? AND (ends_at IS NULL OR ends_at > ?)"
    );
    $statement->execute([$vehicleId, $returnAt, $pickupAt]);
    return (int) $statement->fetchColumn() > 0;
}

/** @return array<string,mixed> */
function booking_modification_input_from_booking(array $booking): array
{
    return [
        'vehicle' => (string) $booking['vehicle_id'],
        'pickup' => date('Y-m-d', strtotime((string) $booking['pickup_at'])),
        'return' => date('Y-m-d', strtotime((string) $booking['return_at'])),
        'pickup_time' => date('H:i', strtotime((string) $booking['pickup_at'])),
        'return_time' => date('H:i', strtotime((string) $booking['return_at'])),
        'pickup_method' => (string) $booking['pickup_method'],
        'location' => (string) $booking['pickup_location'],
        'delivery_address' => (string) $booking['delivery_address'],
        'addons' => array_values(array_map(static fn(array $addon): string => (string) ($addon['addon_key'] ?? ''), booking_modification_addon_rows((int) $booking['id']))),
        'promo' => (string) ($booking['promo_code'] ?? ''),
        'special_requests' => (string) ($booking['special_requests'] ?? ''),
    ];
}

/** @return list<array<string,mixed>> */
function booking_modification_addon_rows(int $bookingId): array
{
    $statement = database()->prepare(
        "SELECT ba.*, a.addon_key FROM booking_addons ba JOIN addons a ON a.id = ba.addon_id WHERE ba.booking_id = ? ORDER BY ba.id"
    );
    $statement->execute([$bookingId]);
    return $statement->fetchAll();
}


/** @return array<string,mixed> */
function booking_modification_original_payload(array $booking): array
{
    return [
        'vehicle' => (string) $booking['vehicle_id'],
        'vehicle_id' => (int) $booking['vehicle_id'],
        'vehicle_name' => (string) $booking['vehicle_name'],
        'pickup' => date('Y-m-d', strtotime((string) $booking['pickup_at'])),
        'return' => date('Y-m-d', strtotime((string) $booking['return_at'])),
        'pickup_time' => date('H:i', strtotime((string) $booking['pickup_at'])),
        'return_time' => date('H:i', strtotime((string) $booking['return_at'])),
        'pickup_at' => (string) $booking['pickup_at'],
        'return_at' => (string) $booking['return_at'],
        'pickup_method' => (string) $booking['pickup_method'],
        'location' => (string) $booking['pickup_location'],
        'delivery_address' => (string) $booking['delivery_address'],
        'addons' => array_values(array_filter(array_map(static fn(array $addon): string => (string) ($addon['addon_key'] ?? ''), booking_modification_addon_rows((int) $booking['id'])))),
        'promo' => (string) ($booking['promo_code'] ?? ''),
        'special_requests' => (string) ($booking['special_requests'] ?? ''),
        'subtotal' => (int) $booking['subtotal'],
        'addons_total' => (int) $booking['addons_total'],
        'delivery_fee' => (int) $booking['delivery_fee'],
        'discount' => (int) $booking['discount'],
        'total' => (int) $booking['total'],
    ];
}

/** @return array{details:array<string,mixed>,difference:int,maintenance_conflict:bool,available:bool} */
function preview_booking_modification(array $booking, array $input): array
{
    if (!in_array($booking['status'], ['pending', 'confirmed', 'ready'], true)) {
        throw new RuntimeException('This booking can no longer be modified before pickup.');
    }
    $details = parse_booking_input($input);
    $available = vehicle_available(
        (int) $details['vehicle']['id'],
        (string) $details['pickup_at'],
        (string) $details['return_at'],
        (int) $booking['id'],
    );
    $maintenanceConflict = booking_modification_maintenance_conflict(
        (int) $details['vehicle']['id'],
        (string) $details['pickup_at'],
        (string) $details['return_at'],
    );
    return [
        'details' => $details,
        'difference' => (int) $details['total'] - (int) $booking['total'],
        'maintenance_conflict' => $maintenanceConflict,
        'available' => $available && !$maintenanceConflict,
    ];
}

/** @return array<string,mixed> */
function normalized_modification_payload(array $details): array
{
    return [
        'vehicle' => (string) $details['vehicle']['id'],
        'vehicle_id' => (int) $details['vehicle']['id'],
        'vehicle_name' => (string) $details['vehicle']['name'],
        'pickup' => date('Y-m-d', strtotime((string) $details['pickup_at'])),
        'return' => date('Y-m-d', strtotime((string) $details['return_at'])),
        'pickup_time' => date('H:i', strtotime((string) $details['pickup_at'])),
        'return_time' => date('H:i', strtotime((string) $details['return_at'])),
        'pickup_at' => (string) $details['pickup_at'],
        'return_at' => (string) $details['return_at'],
        'pickup_method' => (string) $details['pickup_method'],
        'location' => (string) $details['pickup_location'],
        'delivery_address' => (string) $details['delivery_address'],
        'addons' => array_values(array_map(static fn(array $addon): string => (string) $addon['key'], $details['addons'])),
        'promo' => (string) $details['promo_code'],
        'special_requests' => (string) $details['special_requests'],
        'subtotal' => (int) $details['subtotal'],
        'addons_total' => (int) $details['addons_total'],
        'delivery_fee' => (int) $details['delivery_fee'],
        'discount' => (int) $details['discount'],
        'total' => (int) $details['total'],
    ];
}

function request_booking_modification(array $booking, int $userId, array $input, string $reason): int
{
    if ((int) $booking['user_id'] !== $userId) {
        throw new RuntimeException('This booking does not belong to your account.');
    }
    if (unresolved_booking_modification((int) $booking['id'])) {
        throw new RuntimeException('This booking already has a modification request awaiting resolution.');
    }
    $cancellationRequest = cancellation_request_for_booking((int) $booking['id']);
    if (($cancellationRequest['status'] ?? null) === 'pending') {
        throw new RuntimeException('Resolve the pending cancellation request before changing this booking.');
    }
    $preview = preview_booking_modification($booking, $input);
    if (!$preview['available']) {
        throw new RuntimeException('The requested vehicle or schedule is unavailable because of another reservation or maintenance period.');
    }
    $requested = normalized_modification_payload($preview['details']);
    $original = booking_modification_original_payload($booking);
    if ($original === $requested) {
        throw new InvalidArgumentException('Change at least one booking detail before submitting a modification request.');
    }
    $now = date('Y-m-d H:i:s');
    $statement = database()->prepare(
        "INSERT INTO booking_modification_requests
            (booking_id, user_id, request_type, original_data, requested_data, requested_vehicle_id,
             price_difference, status, customer_reason, requested_at, created_at, updated_at)
         VALUES (?, ?, 'pre_pickup', ?, ?, ?, ?, 'pending', ?, ?, ?, ?)"
    );
    $statement->execute([
        (int) $booking['id'],
        $userId,
        json_encode($original, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        json_encode($requested, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        (int) $requested['vehicle_id'],
        (int) $preview['difference'],
        mb_substr(trim($reason), 0, 2000),
        $now,
        $now,
        $now,
    ]);
    $id = (int) database()->lastInsertId();
    notify_user($userId, 'Booking Modification Requested', 'Your requested changes for booking ' . $booking['reference'] . ' are awaiting administrator review.', 'booking_modification', (int) $booking['id']);
    notify_admins('Booking Modification Requested', 'Booking ' . $booking['reference'] . ' has a pre-pickup modification request awaiting review.', 'booking_modification', (int) $booking['id']);
    write_audit('booking_modification_requested', 'booking_modification', $id, ['booking_id' => (int) $booking['id']]);
    return $id;
}

function cancel_pending_booking_modification(int $modificationId, int $userId): void
{
    $statement = database()->prepare(
        "UPDATE booking_modification_requests SET status='cancelled', updated_at=? WHERE id=? AND user_id=? AND status='pending'"
    );
    $statement->execute([date('Y-m-d H:i:s'), $modificationId, $userId]);
    if ($statement->rowCount() < 1) {
        throw new RuntimeException('That modification request can no longer be cancelled.');
    }
    write_audit('booking_modification_cancelled', 'booking_modification', $modificationId);
}

/** @param array<string,mixed> $requested */
function apply_booking_modification(array $modification, array $booking, array $requested, int $adminId): void
{
    $details = parse_booking_input($requested);
    if (!vehicle_available((int) $details['vehicle']['id'], (string) $details['pickup_at'], (string) $details['return_at'], (int) $booking['id'])) {
        throw new RuntimeException('The requested vehicle is no longer available for the modified schedule.');
    }
    if (booking_modification_maintenance_conflict((int) $details['vehicle']['id'], (string) $details['pickup_at'], (string) $details['return_at'])) {
        throw new RuntimeException('The requested vehicle conflicts with a scheduled maintenance period.');
    }
    $databaseConnection = database();
    $oldVehicleId = (int) $booking['vehicle_id'];
    $nextStatus = $booking['status'] === 'ready' ? 'confirmed' : $booking['status'];
    $now = date('Y-m-d H:i:s');
    $databaseConnection->prepare(
        "UPDATE bookings SET vehicle_id=?, pickup_at=?, return_at=?, pickup_method=?, pickup_location=?, delivery_address=?,
                status=?, ready_at=?, promo_code=?, subtotal=?, addons_total=?, delivery_fee=?, discount=?, total=?,
                special_requests=?, updated_at=? WHERE id=?"
    )->execute([
        (int) $details['vehicle']['id'],
        $details['pickup_at'],
        $details['return_at'],
        $details['pickup_method'],
        $details['pickup_location'],
        $details['delivery_address'],
        $nextStatus,
        $nextStatus === 'ready' ? $booking['ready_at'] : null,
        $details['promo_code'],
        $details['subtotal'],
        $details['addons_total'],
        $details['delivery_fee'],
        $details['discount'],
        $details['total'],
        $details['special_requests'],
        $now,
        (int) $booking['id'],
    ]);
    $databaseConnection->prepare('DELETE FROM booking_addons WHERE booking_id = ?')->execute([(int) $booking['id']]);
    $insertAddon = $databaseConnection->prepare(
        "INSERT INTO booking_addons (booking_id, addon_id, addon_name, unit_price, billing, quantity, line_total)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    foreach ($details['addons'] as $addon) {
        $insertAddon->execute([
            (int) $booking['id'],
            (int) $addon['id'],
            $addon['name'],
            (int) $addon['price'],
            $addon['billing'],
            (int) $addon['quantity'],
            (int) $addon['line_total'],
        ]);
    }
    $databaseConnection->prepare(
        "UPDATE booking_modification_requests SET status='activated', activated_at=?, updated_at=? WHERE id=?"
    )->execute([$now, $now, (int) $modification['id']]);
    sync_vehicle_status($oldVehicleId);
    sync_vehicle_status((int) $details['vehicle']['id']);
    $difference = (int) $modification['price_difference'];
    if ($difference < 0) {
        $credit = min(abs($difference), modification_refundable_paid_amount((int) $booking['id']));
        if ($credit > 0) {
            create_refund_requests_for_booking((int) $booking['id'], $credit, 'Credit from approved booking modification', $adminId, 'booking_modification', ['booking_modification_id' => (int) $modification['id']], ['balance','modification']);
        }
    }
    notify_user((int) $booking['user_id'], 'Booking Modification Activated', 'The approved changes for booking ' . $booking['reference'] . ' are now active. Your verified documents remain valid unless their real status changes.', 'booking_modification', (int) $booking['id']);
    write_audit('booking_modification_activated', 'booking_modification', (int) $modification['id'], ['booking_id' => (int) $booking['id']]);
}

function modification_refundable_paid_amount(int $bookingId): int
{
    $statement = database()->prepare(
        "SELECT COALESCE(SUM(amount),0) FROM payments
         WHERE booking_id=? AND status='paid' AND payment_type IN ('balance','modification')"
    );
    $statement->execute([$bookingId]);
    $paid = (int) $statement->fetchColumn();
    $refunds = refund_summary_for_booking($bookingId);
    return max(0, $paid - $refunds['total']);
}

function review_booking_modification(int $modificationId, string $decision, int $adminId, string $adminNote = ''): void
{
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        throw new InvalidArgumentException('Choose approve or reject.');
    }
    $databaseConnection = database();
    $databaseConnection->beginTransaction();
    try {
        $sql = "SELECT * FROM booking_modification_requests WHERE id = ? LIMIT 1";
        if ($databaseConnection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $statement = $databaseConnection->prepare($sql);
        $statement->execute([$modificationId]);
        $modification = $statement->fetch();
        if (!$modification || $modification['status'] !== 'pending') {
            throw new RuntimeException('That modification request is no longer pending.');
        }
        $bookingSql = "SELECT reference FROM bookings WHERE id=? LIMIT 1";
        if ($databaseConnection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $bookingSql .= ' FOR UPDATE';
        }
        $bookingStatement = $databaseConnection->prepare($bookingSql);
        $bookingStatement->execute([(int) $modification['booking_id']]);
        $reference = (string) $bookingStatement->fetchColumn();
        $booking = $reference !== '' ? booking_find_by_reference($reference) : null;
        if (!$booking || !in_array($booking['status'], ['pending', 'confirmed', 'ready'], true)) {
            throw new RuntimeException('The booking is no longer eligible for pre-pickup modification.');
        }
        $requested = json_decode((string) $modification['requested_data'], true, 512, JSON_THROW_ON_ERROR);
        if ($databaseConnection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $vehicleLock = $databaseConnection->prepare("SELECT id FROM vehicles WHERE id=? FOR UPDATE");
            $vehicleLock->execute([(int) ($requested['vehicle_id'] ?? $requested['vehicle'] ?? 0)]);
        }
        $preview = preview_booking_modification($booking, $requested);
        if (!$preview['available']) {
            throw new RuntimeException('The requested vehicle or schedule is no longer available.');
        }
        $difference = (int) $preview['difference'];
        $now = date('Y-m-d H:i:s');
        if ($decision === 'rejected') {
            $databaseConnection->prepare(
                "UPDATE booking_modification_requests SET status='rejected', price_difference=?, admin_note=?, reviewed_at=?, reviewed_by=?, updated_at=? WHERE id=?"
            )->execute([$difference, mb_substr(trim($adminNote), 0, 2000), $now, $adminId, $now, $modificationId]);
            notify_user((int) $booking['user_id'], 'Booking Modification Rejected', 'The requested changes for booking ' . $booking['reference'] . ' were not approved. Your current booking remains unchanged.', 'booking_modification', (int) $booking['id']);
            write_audit('booking_modification_rejected', 'booking_modification', $modificationId, ['booking_id' => (int) $booking['id']]);
            $databaseConnection->commit();
            return;
        }
        $databaseConnection->prepare(
            "UPDATE booking_modification_requests SET status='approved', price_difference=?, requested_data=?, requested_vehicle_id=?, admin_note=?, reviewed_at=?, reviewed_by=?, updated_at=? WHERE id=?"
        )->execute([
            $difference,
            json_encode(normalized_modification_payload($preview['details']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            (int) $preview['details']['vehicle']['id'],
            mb_substr(trim($adminNote), 0, 2000),
            $now,
            $adminId,
            $now,
            $modificationId,
        ]);
        $modification['status'] = 'approved';
        $modification['price_difference'] = $difference;
        $modification['requested_data'] = json_encode(normalized_modification_payload($preview['details']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($difference > 0) {
            notify_user((int) $booking['user_id'], 'Modification Approved — Additional Payment Required', 'Your booking changes were approved. Pay the additional ' . money($difference) . ' before the new schedule becomes active.', 'booking_modification', (int) $booking['id']);
        } else {
            apply_booking_modification($modification, $booking, json_decode((string) $modification['requested_data'], true, 512, JSON_THROW_ON_ERROR), $adminId);
        }
        write_audit('booking_modification_approved', 'booking_modification', $modificationId, ['booking_id' => (int) $booking['id'], 'price_difference' => $difference]);
        $databaseConnection->commit();
    } catch (Throwable $error) {
        if ($databaseConnection->inTransaction()) {
            $databaseConnection->rollBack();
        }
        throw $error;
    }
}

function activate_booking_modification(int $modificationId, int $adminId): void
{
    $databaseConnection = database();
    $sql = "SELECT * FROM booking_modification_requests WHERE id=? LIMIT 1";
    if ($databaseConnection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $sql .= ' FOR UPDATE';
    }
    $statement = $databaseConnection->prepare($sql);
    $statement->execute([$modificationId]);
    $modification = $statement->fetch();
    if (!$modification || $modification['status'] !== 'approved') {
        throw new RuntimeException('That booking modification is not awaiting activation.');
    }
    $bookingSql = "SELECT reference FROM bookings WHERE id=? LIMIT 1";
    if ($databaseConnection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $bookingSql .= ' FOR UPDATE';
    }
    $bookingStatement = $databaseConnection->prepare($bookingSql);
    $bookingStatement->execute([(int) $modification['booking_id']]);
    $reference = (string) $bookingStatement->fetchColumn();
    $booking = $reference !== '' ? booking_find_by_reference($reference) : null;
    if (!$booking) {
        throw new RuntimeException('Related booking not found.');
    }
    $requested = json_decode((string) $modification['requested_data'], true, 512, JSON_THROW_ON_ERROR);
    if ($databaseConnection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $vehicleLock = $databaseConnection->prepare("SELECT id FROM vehicles WHERE id=? FOR UPDATE");
        $vehicleLock->execute([(int) ($requested['vehicle_id'] ?? $requested['vehicle'] ?? 0)]);
    }
    apply_booking_modification($modification, $booking, $requested, $adminId);
}

/****************************************************************************
 * BOOKING CANCELLATION
 ****************************************************************************/

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
