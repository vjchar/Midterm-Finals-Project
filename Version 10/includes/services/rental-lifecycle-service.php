<?php

declare(strict_types=1);

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
    $licenseApproved =
        ($documents["drivers_license"]["status"] ?? "") === "approved";
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
    notify_user(
        (int) $booking["user_id"],
        "Booking " . str_replace("_", " ", $newStatus),
        "Booking " .
            $booking["reference"] .
            " is now " .
            str_replace("_", " ", $newStatus) .
            ".",
        "booking",
        (int) $booking["id"],
    );
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
        $extraCharges = (int) ($input["extra_charges"] ?? 0);
        if ($extraCharges > 0) {
            $payment = $databaseConnection->prepare(
                "INSERT INTO payments (
                    booking_id, user_id, payment_type, method, amount,
                    transaction_reference, proof_filename, status, notes,
                    recorded_by, created_at, updated_at
                ) VALUES (?, ?, 'extra_charge', 'cash', ?, '', '', 'pending', ?, ?, ?, ?)",
            );
            $payment->execute([
                (int) $booking["id"],
                (int) $booking["user_id"],
                $extraCharges,
                "Created from return inspection",
                $adminId,
                $currentTimestamp,
                $currentTimestamp,
            ]);
        }
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
