<?php

declare(strict_types=1);

/** @return list<array<string,mixed>> */
function rental_calendar_events(DateTimeImmutable $rangeStart, DateTimeImmutable $rangeEnd, string $type = 'all', ?int $vehicleId = null): array
{
    $events = [];
    $start = $rangeStart->format('Y-m-d H:i:s');
    $end = $rangeEnd->format('Y-m-d H:i:s');
    $bookingSql = "SELECT b.*, v.name AS vehicle_name, u.name AS customer_name
                   FROM bookings b JOIN vehicles v ON v.id=b.vehicle_id JOIN users u ON u.id=b.user_id
                   WHERE b.status NOT IN ('cancelled','rejected','no show')
                     AND ((b.pickup_at >= ? AND b.pickup_at < ?) OR (b.return_at >= ? AND b.return_at < ?)
                          OR (b.pickup_at < ? AND b.return_at > ?))";
    $params = [$start, $end, $start, $end, $end, $start];
    if ($vehicleId) {
        $bookingSql .= ' AND b.vehicle_id = ?';
        $params[] = $vehicleId;
    }
    $bookingSql .= ' ORDER BY b.pickup_at';
    $statement = database()->prepare($bookingSql);
    $statement->execute($params);
    foreach ($statement->fetchAll() as $booking) {
        $pickupType = $booking['pickup_method'] === 'Vehicle delivery' ? 'delivery' : 'pickup';
        if (($type === 'all' || $type === $pickupType) && (string) $booking['pickup_at'] >= $start && (string) $booking['pickup_at'] < $end) {
            $events[] = calendar_event_from_booking($booking, $pickupType, (string) $booking['pickup_at']);
        }
        if (($type === 'all' || $type === 'return') && (string) $booking['return_at'] >= $start && (string) $booking['return_at'] < $end) {
            $events[] = calendar_event_from_booking($booking, 'return', (string) $booking['return_at']);
        }
        if (($type === 'all' || $type === 'active') && in_array($booking['status'], ['active','returned','completed'], true)) {
            $actualActiveStart = (string) ($booking['started_at'] ?: $booking['pickup_at']);
            $actualActiveEnd = (string) ($booking['returned_at'] ?: $booking['return_at']);
            $visibleActiveStart = $actualActiveStart < $start ? $start : $actualActiveStart;
            $visibleActiveEnd = $actualActiveEnd > $end ? $end : $actualActiveEnd;
            $events[] = [
                'key' => 'active-' . $booking['id'],
                'type' => 'active',
                'title' => $booking['vehicle_name'] . ' — Active rental',
                'start_at' => $visibleActiveStart,
                'end_at' => $visibleActiveEnd,
                'vehicle_id' => (int) $booking['vehicle_id'],
                'vehicle_name' => (string) $booking['vehicle_name'],
                'booking_reference' => (string) $booking['reference'],
                'customer_name' => (string) $booking['customer_name'],
                'status' => (string) $booking['status'],
                'meta' => 'Active rental period · ' . date('M j, Y g:i A', strtotime($actualActiveStart)) . ' to ' . date('M j, Y g:i A', strtotime($actualActiveEnd)),
                'url' => 'admin-rentals.php?reference=' . urlencode((string) $booking['reference']),
            ];
        }
    }

    if ($type === 'all' || $type === 'maintenance') {
        $sql = "SELECT m.*, v.name AS vehicle_name FROM maintenance_records m JOIN vehicles v ON v.id=m.vehicle_id
                WHERE m.status IN ('scheduled','in_progress') AND m.starts_at < ? AND (m.ends_at IS NULL OR m.ends_at >= ?)";
        $mParams = [$end, $start];
        if ($vehicleId) {
            $sql .= ' AND m.vehicle_id = ?';
            $mParams[] = $vehicleId;
        }
        $statement = database()->prepare($sql);
        $statement->execute($mParams);
        foreach ($statement->fetchAll() as $record) {
            $actualMaintenanceStart = (string) $record['starts_at'];
            $actualMaintenanceEnd = (string) ($record['ends_at'] ?: $record['starts_at']);
            $visibleMaintenanceStart = $actualMaintenanceStart < $start ? $start : $actualMaintenanceStart;
            $visibleMaintenanceEnd = $actualMaintenanceEnd > $end ? $end : $actualMaintenanceEnd;
            $events[] = [
                'key' => 'maintenance-' . $record['id'],
                'type' => 'maintenance',
                'title' => $record['vehicle_name'] . ' — ' . $record['title'],
                'start_at' => $visibleMaintenanceStart,
                'end_at' => $visibleMaintenanceEnd,
                'vehicle_id' => (int) $record['vehicle_id'],
                'vehicle_name' => (string) $record['vehicle_name'],
                'booking_reference' => null,
                'customer_name' => null,
                'status' => (string) $record['status'],
                'meta' => trim((string) $record['description']) . (trim((string) $record['description']) !== '' ? ' · ' : '') . 'Scheduled ' . date('M j, Y g:i A', strtotime($actualMaintenanceStart)) . ($record['ends_at'] ? ' to ' . date('M j, Y g:i A', strtotime((string) $record['ends_at'])) : ''),
                'url' => 'admin-maintenance.php',
            ];
        }
    }

    if (in_array($type, ['all','extension','early_return'], true)) {
        $sql = "SELECT r.*, b.reference, b.vehicle_id, v.name AS vehicle_name, u.name AS customer_name
                FROM rental_adjustment_requests r
                JOIN bookings b ON b.id=r.booking_id JOIN vehicles v ON v.id=b.vehicle_id JOIN users u ON u.id=b.user_id
                WHERE r.requested_return_at >= ? AND r.requested_return_at < ?
                  AND ((r.request_type='extension' AND r.status IN ('activated','completed'))
                       OR (r.request_type='early_return' AND r.status IN ('approved','activated','completed')))";
        $aParams = [$start, $end];
        if ($vehicleId) {
            $sql .= ' AND b.vehicle_id = ?';
            $aParams[] = $vehicleId;
        }
        $statement = database()->prepare($sql);
        $statement->execute($aParams);
        foreach ($statement->fetchAll() as $adjustment) {
            $eventType = $adjustment['request_type'] === 'extension' ? 'extension' : 'early_return';
            if ($type !== 'all' && $type !== $eventType) {
                continue;
            }
            $events[] = [
                'key' => $eventType . '-' . $adjustment['id'],
                'type' => $eventType,
                'title' => $adjustment['vehicle_name'] . ' — ' . ($eventType === 'extension' ? 'Extended return' : 'Expected early return'),
                'start_at' => (string) $adjustment['requested_return_at'],
                'end_at' => (string) $adjustment['requested_return_at'],
                'vehicle_id' => (int) $adjustment['vehicle_id'],
                'vehicle_name' => (string) $adjustment['vehicle_name'],
                'booking_reference' => (string) $adjustment['reference'],
                'customer_name' => (string) $adjustment['customer_name'],
                'status' => (string) $adjustment['status'],
                'meta' => $eventType === 'extension' ? 'Approved/current extension return' : 'Expected return only; actual check-in remains separate.',
                'url' => 'admin-rentals.php?reference=' . urlencode((string) $adjustment['reference']),
            ];
        }
    }

    usort($events, static fn(array $a, array $b): int => strcmp((string) $a['start_at'], (string) $b['start_at']));
    return $events;
}

/** @return array<string,mixed> */
function calendar_event_from_booking(array $booking, string $type, string $at): array
{
    $labels = ['pickup' => 'Pickup', 'delivery' => 'Delivery', 'return' => 'Scheduled return'];
    return [
        'key' => $type . '-' . $booking['id'],
        'type' => $type,
        'title' => $booking['vehicle_name'] . ' — ' . ($labels[$type] ?? ucfirst($type)),
        'start_at' => $at,
        'end_at' => $at,
        'vehicle_id' => (int) $booking['vehicle_id'],
        'vehicle_name' => (string) $booking['vehicle_name'],
        'booking_reference' => (string) $booking['reference'],
        'customer_name' => (string) $booking['customer_name'],
        'status' => (string) $booking['status'],
        'meta' => $booking['pickup_method'] . ' · ' . ($booking['pickup_method'] === 'Vehicle delivery' ? ($booking['delivery_address'] ?: 'Delivery address on booking') : $booking['pickup_location']),
        'url' => 'admin-bookings.php?reference=' . urlencode((string) $booking['reference']),
    ];
}
