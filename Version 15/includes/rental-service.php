<?php

declare(strict_types=1);


/**
 * FILE: includes/rental-service.php
 * FILE PURPOSE: Rental lifecycle and operations service.
 * USED BY: Admin rental pages, rental adjustment pages, checkout/check-in flows, and reports.
 * RESPONSIBILITY: Handles rental states, checkout/check-in, adjustments, extensions, calendar data, queues, and settlement operations.
 *
 * Maintenance note: Keep this file focused on the responsibility described above.
 */
/**
 * Rental lifecycle, checkout/check-in, adjustments, calendar, and settlement workflows.
 */

/****************************************************************************
 * RENTAL STATUS, REQUIREMENTS, CHECKOUT, AND CHECK-IN
 ****************************************************************************/

/**
 * @return list<string>
 */
function booking_statuses(): array
{
    return [
        "pending",
        "confirmed",
        "ready",
        "active",
        "returned",
        "completed",
        "cancelled",
        "rejected",
        "no_show",
    ];
}

/**
 * @return array<string, list<string>>
 */
function booking_transitions(): array
{
    return [
        "pending" => ["confirmed", "rejected", "cancelled"],
        "confirmed" => ["ready", "cancelled", "no_show"],
        "ready" => ["active", "cancelled", "no_show"],
        "active" => ["returned"],
        "returned" => ["completed"],
        "completed" => [],
        "cancelled" => [],
        "rejected" => [],
        "no_show" => [],
    ];
}

/**
 * Combine document and payment readiness checks for a booking.
 *
 * @param array{id: int|string, user_id: int|string, deposit: int|string, total: int|string} $booking
 * @return array{
 *     documents: array<string, array<string, mixed>>,
 *     payments: array{
 *         payments: list<array<string, mixed>>,
 *         deposit_due: int,
 *         rental_due: int,
 *         extra_due: int,
 *         paid_total: int
 *     },
 *     license_approved: bool,
 *     id_approved: bool,
 *     deposit_paid: bool,
 *     ready_for_confirmation: bool
 * }
 */
function booking_requirements(array $booking): array
{
    $documents = customer_documents((int) $booking["user_id"]);
    $payments = booking_payment_summary($booking);
    $licenseDocument = $documents["drivers_license"] ?? null;
    $licenseApproved =
        ($licenseDocument["status"] ?? "") === "approved" &&
        !empty($licenseDocument["expiry_date"]) &&
        new DateTimeImmutable((string) $licenseDocument["expiry_date"]) > new DateTimeImmutable("today");
    $idApproved = ($documents["government_id"]["status"] ?? "") === "approved";
    return [
        "documents" => $documents,
        "payments" => $payments,
        "license_approved" => $licenseApproved,
        "id_approved" => $idApproved,
        "deposit_paid" => $payments["deposit_due"] === 0,
        "ready_for_confirmation" =>
            $licenseApproved && $idApproved && $payments["deposit_due"] === 0,
    ];
}


/**
 * Move a pending booking directly into the existing confirmed/preparation state
 * once an administrator has completed the final required verification.
 */
function confirm_booking_when_requirements_complete(array $booking, int $adminId): bool
{
    if (($booking["status"] ?? "") !== "pending") {
        return false;
    }
    $requirements = booking_requirements($booking);
    if (!$requirements["ready_for_confirmation"]) {
        return false;
    }
    update_booking_status(
        $booking,
        "confirmed",
        $adminId,
        (string) ($booking["admin_notes"] ?? ""),
    );
    return true;
}

/**
 * Resolve the lifecycle timestamp column associated with a booking status.
 */
function booking_timestamp_column(string $status): ?string
{
    return match ($status) {
        "confirmed" => "approved_at",
        "ready" => "ready_at",
        "active" => "started_at",
        "returned" => "returned_at",
        "completed" => "completed_at",
        "cancelled" => "cancelled_at",
        "rejected" => "rejected_at",
        "no_show" => "no_show_at",
        default => null,
    };
}

/**
 * Recalculate one vehicle's operational state from its active bookings.
 */
function sync_vehicle_status(int $vehicleId): void
{
    $vehicle = database()->prepare(
        "SELECT is_active, availability_status FROM vehicles WHERE id = ? LIMIT 1",
    );
    $vehicle->execute([$vehicleId]);
    $row = $vehicle->fetch();
    if (!$row) {
        return;
    }
    if (!(bool) $row["is_active"]) {
        $next = "unavailable";
    } elseif (
        in_array(
            $row["availability_status"],
            ["maintenance", "unavailable"],
            true,
        )
    ) {
        return;
    } else {
        $active = database()->prepare(
            "SELECT COUNT(*) FROM bookings WHERE vehicle_id = ? AND status = 'active'",
        );
        $active->execute([$vehicleId]);
        if ((int) $active->fetchColumn() > 0) {
            $next = "rented";
        } else {
            $reserved = database()->prepare(
                "SELECT COUNT(*) FROM bookings WHERE vehicle_id = ? AND status IN ('confirmed','ready') AND return_at >= ?",
            );
            $reserved->execute([$vehicleId, date("Y-m-d H:i:s")]);
            $next =
                (int) $reserved->fetchColumn() > 0 ? "reserved" : "available";
        }
    }
    $update = database()->prepare(
        "UPDATE vehicles SET availability_status = ?, updated_at = ? WHERE id = ?",
    );
    $update->execute([$next, date("Y-m-d H:i:s"), $vehicleId]);
}

/**
 * Apply one permitted administrator-driven booking status transition.
 *
 * @param array{
 *     id: int|string,
 *     user_id: int|string,
 *     vehicle_id: int|string,
 *     reference: string,
 *     status: string,
 *     deposit: int|string,
 *     total: int|string
 * } $booking
 */
function update_booking_status(
    array $booking,
    string $newStatus,
    int $adminId,
    string $notes = "",
): void {
    $transitions = booking_transitions();
    if (!in_array($newStatus, $transitions[$booking["status"]] ?? [], true)) {
        throw new RuntimeException(
            "That booking status transition is not allowed.",
        );
    }
    if ($newStatus === "confirmed") {
        $requirements = booking_requirements($booking);
        if (!$requirements["ready_for_confirmation"]) {
            throw new RuntimeException(
                "Approve both customer documents and verify the security deposit before confirming this booking.",
            );
        }
    }
    if ($newStatus === "ready") {
        if (function_exists("unresolved_booking_modification") && unresolved_booking_modification((int) $booking["id"])) {
            throw new RuntimeException(
                "Resolve the pending booking modification before marking this vehicle ready.",
            );
        }
        if (function_exists("cancellation_request_for_booking")) {
            $pendingCancellation = cancellation_request_for_booking((int) $booking["id"]);
            if ($pendingCancellation && ($pendingCancellation["status"] ?? "") === "pending") {
                throw new RuntimeException(
                    "Resolve the pending cancellation request before marking this vehicle ready.",
                );
            }
        }
        $requirements = booking_requirements($booking);
        if (!$requirements["ready_for_confirmation"]) {
            throw new RuntimeException(
                "Payment and required documents must still be verified before the vehicle can be marked ready.",
            );
        }
        $vehicleStatement = database()->prepare(
            "SELECT is_active, availability_status FROM vehicles WHERE id = ? LIMIT 1",
        );
        $vehicleStatement->execute([(int) $booking["vehicle_id"]]);
        $vehicle = $vehicleStatement->fetch();
        if (!$vehicle || !(bool) $vehicle["is_active"] || in_array($vehicle["availability_status"], ["maintenance", "unavailable", "rented"], true)) {
            throw new RuntimeException(
                "The vehicle is not operationally available to mark ready.",
            );
        }
        $otherActive = database()->prepare(
            "SELECT COUNT(*) FROM bookings WHERE vehicle_id = ? AND id <> ? AND status = 'active'",
        );
        $otherActive->execute([(int) $booking["vehicle_id"], (int) $booking["id"]]);
        if ((int) $otherActive->fetchColumn() > 0) {
            throw new RuntimeException(
                "This vehicle is still assigned to another active rental.",
            );
        }
    }
    if ($newStatus === "active") {
        throw new RuntimeException(
            "Use the Rental Desk checkout form to release the vehicle.",
        );
    }
    if ($newStatus === "returned") {
        throw new RuntimeException(
            "Use the Rental Desk check-in form to record the return inspection.",
        );
    }
    $currentTimestamp = date("Y-m-d H:i:s");
    $sql = "UPDATE bookings SET status = ?, admin_notes = ?, updated_at = ?";
    $parameters = [
        $newStatus,
        mb_substr(trim($notes), 0, 3000),
        $currentTimestamp,
    ];
    $timestampColumn = booking_timestamp_column($newStatus);
    if ($timestampColumn !== null) {
        $sql .= ", {$timestampColumn} = ?";
        $parameters[] = $currentTimestamp;
    }
    $sql .= " WHERE id = ?";
    $parameters[] = (int) $booking["id"];
    $statement = database()->prepare($sql);
    $statement->execute($parameters);
    sync_vehicle_status((int) $booking["vehicle_id"]);
    if ($newStatus === "confirmed") {
        notify_user(
            (int) $booking["user_id"],
            "Requirements Verified — Vehicle Preparation",
            "Your payment and required documents for booking " . $booking["reference"] . " have been verified. Our team is preparing your vehicle. No action is required right now.",
            "booking",
            (int) $booking["id"],
        );
    } elseif ($newStatus === "ready") {
        $isDelivery = ($booking["pickup_method"] ?? "") === "Vehicle delivery";
        $readyTitle = $isDelivery
            ? "Your Vehicle Is Ready for Delivery"
            : "Your Vehicle Is Ready for Pickup";
        $scheduledHandover = !empty($booking["pickup_at"])
            ? date("M j, Y g:i A", strtotime((string) $booking["pickup_at"]))
            : "your scheduled handover time";
        $readyMessage = $isDelivery
            ? "Your booked vehicle for " . $booking["reference"] . " has been prepared and is ready for delivery. Scheduled delivery: " . $scheduledHandover . ". Delivery location: " . ($booking["delivery_address"] ?? "your booking address") . "."
            : "Your booked vehicle for " . $booking["reference"] . " is now ready for pickup. Scheduled pickup: " . $scheduledHandover . ". Pickup location: " . ($booking["pickup_location"] ?? "the selected branch") . ".";
        notify_user(
            (int) $booking["user_id"],
            $readyTitle,
            $readyMessage,
            "booking",
            (int) $booking["id"],
        );
        write_audit(
            $isDelivery ? "vehicle_ready_for_delivery" : "vehicle_ready_for_pickup",
            "booking",
            (int) $booking["id"],
            ["admin_id" => $adminId, "pickup_method" => $booking["pickup_method"] ?? ""],
        );
    } else {
        notify_user(
            (int) $booking["user_id"],
            "Booking " . str_replace("_", " ", $newStatus),
            "Booking " . $booking["reference"] . " is now " . str_replace("_", " ", $newStatus) . ".",
            "booking",
            (int) $booking["id"],
        );
    }
    write_audit("booking_status_updated", "booking", (int) $booking["id"], [
        "from" => $booking["status"],
        "to" => $newStatus,
        "admin_id" => $adminId,
    ]);
}

/**
 * Create or replace a checkout/check-in inspection for one booking.
 *
 * @param array{id: int|string} $booking
 * @param array{
 *     odometer?: mixed,
 *     fuel_percent?: mixed,
 *     body_condition?: mixed,
 *     extra_charges?: mixed,
 *     notes?: mixed,
 *     damage_notes?: mixed
 * } $input
 */
function save_inspection(
    array $booking,
    string $type,
    array $input,
    int $adminId,
): void {
    if (!in_array($type, ["checkout", "checkin"], true)) {
        throw new InvalidArgumentException("Choose a valid inspection type.");
    }
    $odometer = filter_var($input["odometer"] ?? null, FILTER_VALIDATE_INT, [
        "options" => ["min_range" => 0, "max_range" => 2000000],
    ]);
    $fuel = filter_var($input["fuel_percent"] ?? null, FILTER_VALIDATE_INT, [
        "options" => ["min_range" => 0, "max_range" => 100],
    ]);
    $condition = trim((string) ($input["body_condition"] ?? ""));
    $extraCharges =
        $type === "checkin"
            ? filter_var($input["extra_charges"] ?? 0, FILTER_VALIDATE_INT, [
                "options" => ["min_range" => 0, "max_range" => 1000000],
            ])
            : 0;
    if (
        $odometer === false ||
        $fuel === false ||
        $extraCharges === false ||
        !in_array($condition, ["excellent", "good", "fair", "damaged"], true)
    ) {
        throw new InvalidArgumentException("Enter valid inspection values.");
    }
    if ($type === "checkin") {
        $checkout = database()->prepare(
            "SELECT odometer FROM rental_inspections WHERE booking_id = ? AND inspection_type = 'checkout'",
        );
        $checkout->execute([(int) $booking["id"]]);
        $outOdometer = $checkout->fetchColumn();
        if ($outOdometer === false || $odometer < (int) $outOdometer) {
            throw new InvalidArgumentException(
                "Return odometer cannot be below the checkout odometer.",
            );
        }
    }
    $currentTimestamp = date("Y-m-d H:i:s");
    $statement = database()->prepare(
        'INSERT INTO rental_inspections (booking_id, inspection_type, odometer, fuel_percent, body_condition, notes, damage_notes, extra_charges, recorded_by, inspected_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE odometer = VALUES(odometer), fuel_percent = VALUES(fuel_percent), body_condition = VALUES(body_condition),
         notes = VALUES(notes), damage_notes = VALUES(damage_notes), extra_charges = VALUES(extra_charges), recorded_by = VALUES(recorded_by),
         inspected_at = VALUES(inspected_at), updated_at = VALUES(updated_at)',
    );
    $statement->execute([
        (int) $booking["id"],
        $type,
        $odometer,
        $fuel,
        $condition,
        mb_substr(trim((string) ($input["notes"] ?? "")), 0, 3000),
        mb_substr(trim((string) ($input["damage_notes"] ?? "")), 0, 3000),
        $extraCharges,
        $adminId,
        $currentTimestamp,
        $currentTimestamp,
        $currentTimestamp,
    ]);
}

/**
 * Record checkout inspection data and activate a ready booking.
 *
 * @param array{id: int|string, user_id: int|string, vehicle_id: int|string, reference: string, status: string} $booking
 * @param array<string, mixed> $input
 */
function checkout_booking(array $booking, array $input, int $adminId): void
{
    if ($booking["status"] !== "ready") {
        throw new RuntimeException("Only a ready booking can be checked out.");
    }
    save_inspection($booking, "checkout", $input, $adminId);
    $currentTimestamp = date("Y-m-d H:i:s");
    $statement = database()->prepare(
        "UPDATE bookings SET status = 'active', started_at = ?, updated_at = ? WHERE id = ?",
    );
    $statement->execute([
        $currentTimestamp,
        $currentTimestamp,
        (int) $booking["id"],
    ]);
    sync_vehicle_status((int) $booking["vehicle_id"]);
    notify_user(
        (int) $booking["user_id"],
        "Vehicle checked out",
        "Your rental for booking " .
            $booking["reference"] .
            " is now active. Drive safely!",
        "rental",
        (int) $booking["id"],
    );
    write_audit("rental_checked_out", "booking", (int) $booking["id"]);
}

/**
 * Record return inspection data and move an active booking to returned.
 *
 * @param array{id: int|string, user_id: int|string, vehicle_id: int|string, reference: string, status: string} $booking
 * @param array<string, mixed> $input
 */
function checkin_booking(array $booking, array $input, int $adminId): void
{
    if ($booking["status"] !== "active") {
        throw new RuntimeException("Only an active rental can be checked in.");
    }
    $databaseConnection = database();
    $databaseConnection->beginTransaction();
    try {
        save_inspection($booking, "checkin", $input, $adminId);
        $currentTimestamp = date("Y-m-d H:i:s");
        $statement = $databaseConnection->prepare(
            "UPDATE bookings SET status = 'returned', returned_at = ?, updated_at = ? WHERE id = ?",
        );
        $statement->execute([
            $currentTimestamp,
            $currentTimestamp,
            (int) $booking["id"],
        ]);
        // The settlement workflow records return charges on the inspection first. The final
        // settlement applies the verified security deposit before creating any
        // remaining extra-charge payment, preventing double collection.
        finalize_rental_adjustments_on_return((int) $booking["id"]);
        $databaseConnection->commit();
    } catch (Throwable $error) {
        if ($databaseConnection->inTransaction()) {
            $databaseConnection->rollBack();
        }
        throw $error;
    }
    sync_vehicle_status((int) $booking["vehicle_id"]);
    notify_user(
        (int) $booking["user_id"],
        "Vehicle returned",
        "The return inspection for booking " .
            $booking["reference"] .
            " has been recorded.",
        "rental",
        (int) $booking["id"],
    );
    write_audit("rental_checked_in", "booking", (int) $booking["id"]);
}

/**
 * Return checkout/check-in inspections keyed by inspection type.
 *
 * @return array<string, array<string, mixed>>
 */
function inspections_for_booking(int $bookingId): array
{
    $statement = database()->prepare(
        "SELECT i.*, u.name AS inspector_name FROM rental_inspections i JOIN users u ON u.id = i.recorded_by WHERE i.booking_id = ? ORDER BY i.inspected_at",
    );
    $statement->execute([$bookingId]);
    $inspections = [];
    foreach ($statement->fetchAll() as $inspection) {
        $inspections[$inspection["inspection_type"]] = $inspection;
    }
    return $inspections;
}

/****************************************************************************
 * RENTAL ADJUSTMENTS AND EXTENSIONS
 ****************************************************************************/

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
    $addonStatement = database()->prepare(
        "SELECT ba.*, a.addon_key
         FROM booking_addons ba
         LEFT JOIN addons a ON a.id = ba.addon_id
         WHERE ba.booking_id = ?"
    );
    $addonStatement->execute([(int) $booking['id']]);
    $addonsTotal = 0;
    foreach ($addonStatement->fetchAll() as $addon) {
        $quantity =
            ($addon['addon_key'] ?? '') === 'child-seat' &&
            $addon['billing'] === 'rental'
                ? max(1, min(4, (int) $addon['quantity']))
                : 1;
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
        $addonStatement = $databaseConnection->prepare(
            "SELECT ba.*, a.addon_key
             FROM booking_addons ba
             LEFT JOIN addons a ON a.id = ba.addon_id
             WHERE ba.booking_id=?"
        );
        $addonStatement->execute([(int)$adjustment['booking_id']]);
        foreach ($addonStatement->fetchAll() as $addon) {
            $quantity =
                ($addon['addon_key'] ?? '') === 'child-seat' &&
                $addon['billing'] === 'rental'
                    ? max(1, min(4, (int)$addon['quantity']))
                    : 1;
            $databaseConnection->prepare(
                "UPDATE booking_addons
                 SET billing='rental', quantity=?, line_total=?
                 WHERE id=?"
            )->execute([$quantity,(int)$addon['unit_price']*$quantity,(int)$addon['id']]);
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

/****************************************************************************
 * RENTAL CALENDAR
 ****************************************************************************/

/** @return list<array<string,mixed>> */
function rental_calendar_events(DateTimeImmutable $rangeStart, DateTimeImmutable $rangeEnd, string $type = 'all', ?int $vehicleId = null): array
{
    $events = [];
    $start = $rangeStart->format('Y-m-d H:i:s');
    $end = $rangeEnd->format('Y-m-d H:i:s');
    $bookingSql = "SELECT b.*, v.name AS vehicle_name, u.name AS customer_name
                   FROM bookings b JOIN vehicles v ON v.id=b.vehicle_id JOIN users u ON u.id=b.user_id
                   WHERE b.status NOT IN ('cancelled','rejected','no_show')
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
        'title' => $booking['vehicle_name'] . ' — ' . ($labels[$type] ?? humanize_label($type)),
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

/****************************************************************************
 * RENTAL SETTLEMENT
 ****************************************************************************/

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

/****************************************************************************
 * ADMIN RENTAL QUEUES
 ****************************************************************************/

/** Return the active rental desk queue. */
function admin_rentals(): array
{
    $query = <<<'SQL'
SELECT
    b.reference,
    b.status,
    b.pickup_at,
    b.return_at,
    v.name AS vehicle_name,
    v.availability_status,
    u.name AS customer_name
FROM bookings AS b
JOIN vehicles AS v ON v.id = b.vehicle_id
JOIN users AS u ON u.id = b.user_id
WHERE b.status IN ('ready', 'active', 'returned')
ORDER BY
    CASE b.status
        WHEN 'active' THEN 0
        WHEN 'ready' THEN 1
        ELSE 2
    END,
    b.pickup_at
SQL;
    return database()->query($query)->fetchAll();
}

/** Return rental adjustment requests that still require operational attention. */
function admin_rental_adjustments(): array
{
    return database()
        ->query(
            "SELECT r.*, b.reference, b.return_at AS current_return_at, b.status AS booking_status,
                    v.name AS vehicle_name, u.name AS customer_name
             FROM rental_adjustment_requests r
             JOIN bookings b ON b.id = r.booking_id
             JOIN vehicles v ON v.id = b.vehicle_id
             JOIN users u ON u.id = r.user_id
             WHERE r.status IN ('pending','approved')
             ORDER BY CASE r.status WHEN 'pending' THEN 0 ELSE 1 END, r.created_at",
        )
        ->fetchAll();
}
