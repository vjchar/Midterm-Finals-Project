<?php

declare(strict_types=1);

/** @return list<string> */
function booking_locations(): array
{
    return ["Manjuyod Branch", "Bais City Branch", "Dumaguete City Branch"];
}

/** @return list<string> */
function booking_statuses(): array
{
    return ["pending", "confirmed", "cancelled", "completed"];
}

/** @return array<string,list<string>> */
function booking_transitions(): array
{
    return [
        "pending" => ["confirmed", "cancelled"],
        "confirmed" => ["completed", "cancelled"],
        "cancelled" => [],
        "completed" => [],
    ];
}

/** @return array<string,mixed> */
function parse_booking_input(array $bookingInput): array
{
    $selectedVehicle = vehicle_find(
        (string) ($bookingInput["vehicle"] ?? ""),
    );
    if (!$selectedVehicle) {
        throw new InvalidArgumentException("Choose a valid vehicle.");
    }

    $pickupDateValue = trim((string) ($bookingInput["pickup"] ?? ""));
    $returnDateValue = trim((string) ($bookingInput["return"] ?? ""));
    $pickupTimeValue = trim((string) ($bookingInput["pickup_time"] ?? "09:00"));
    $returnTimeValue = trim((string) ($bookingInput["return_time"] ?? "09:00"));
    $timePattern = '/^([01]\d|2[0-3]):[0-5]\d$/';

    if (
        !valid_date($pickupDateValue) ||
        !valid_date($returnDateValue) ||
        !preg_match($timePattern, $pickupTimeValue) ||
        !preg_match($timePattern, $returnTimeValue)
    ) {
        throw new InvalidArgumentException(
            "Choose valid pick-up and return dates and times.",
        );
    }

    $pickupDateTime = new DateTimeImmutable(
        $pickupDateValue . " " . $pickupTimeValue,
    );
    $returnDateTime = new DateTimeImmutable(
        $returnDateValue . " " . $returnTimeValue,
    );

    if ($pickupDateTime <= new DateTimeImmutable("+1 hour")) {
        throw new InvalidArgumentException(
            "Pick-up must be at least one hour from now.",
        );
    }
    if ($returnDateTime <= $pickupDateTime) {
        throw new InvalidArgumentException("Return must be later than pick-up.");
    }

    $seconds =
        $returnDateTime->getTimestamp() - $pickupDateTime->getTimestamp();
    $rentalDays = max(1, (int) ceil($seconds / 86400));

    if ($rentalDays > 30) {
        throw new InvalidArgumentException(
            "Online bookings can cover up to 30 rental days. Contact support for longer rentals.",
        );
    }

    $pickupLocation = trim((string) ($bookingInput["location"] ?? ""));
    if (!in_array($pickupLocation, booking_locations(), true)) {
        throw new InvalidArgumentException("Choose a valid branch.");
    }

    $rentalSubtotal = (int) $selectedVehicle["price"] * $rentalDays;

    return [
        "vehicle" => $selectedVehicle,
        "pickup_at" => $pickupDateTime->format("Y-m-d H:i:s"),
        "return_at" => $returnDateTime->format("Y-m-d H:i:s"),
        "days" => $rentalDays,
        "pickup_method" => "Branch pickup",
        "pickup_location" => $pickupLocation,
        "subtotal" => $rentalSubtotal,
        "total" => $rentalSubtotal,
        "special_requests" => substr(
            trim((string) ($bookingInput["special_requests"] ?? "")),
            0,
            2000,
        ),
    ];
}

/** @return array<string,mixed> */
function create_booking(int $userId, array $bookingInput): array
{
    $bookingDetails = parse_booking_input($bookingInput);
    $databaseConnection = database();
    $databaseConnection->beginTransaction();

    try {
        if (
            $databaseConnection->getAttribute(PDO::ATTR_DRIVER_NAME) === "mysql"
        ) {
            $vehicleLockStatement = $databaseConnection->prepare(
                "SELECT id FROM vehicles WHERE id = ? FOR UPDATE",
            );
            $vehicleLockStatement->execute([$bookingDetails["vehicle"]["id"]]);
        }

        if (
            !vehicle_available(
                (int) $bookingDetails["vehicle"]["id"],
                (string) $bookingDetails["pickup_at"],
                (string) $bookingDetails["return_at"],
            )
        ) {
            throw new RuntimeException(
                "That vehicle is already reserved for part of the selected period. Choose another vehicle or different dates.",
            );
        }

        do {
            $bookingReference = booking_reference();
            $referenceCheckStatement = $databaseConnection->prepare(
                "SELECT COUNT(*) FROM bookings WHERE reference = ?",
            );
            $referenceCheckStatement->execute([$bookingReference]);
        } while ((int) $referenceCheckStatement->fetchColumn() > 0);

        $currentTimestamp = date("Y-m-d H:i:s");
        $statement = $databaseConnection->prepare(
            "INSERT INTO bookings (
                reference, user_id, vehicle_id, pickup_at, return_at,
                pickup_method, pickup_location, status, subtotal, total,
                special_requests, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        );
        $statement->execute([
            $bookingReference,
            $userId,
            $bookingDetails["vehicle"]["id"],
            $bookingDetails["pickup_at"],
            $bookingDetails["return_at"],
            $bookingDetails["pickup_method"],
            $bookingDetails["pickup_location"],
            "pending",
            $bookingDetails["subtotal"],
            $bookingDetails["total"],
            $bookingDetails["special_requests"],
            $currentTimestamp,
            $currentTimestamp,
        ]);

        $databaseConnection->commit();
        return booking_find_by_reference($bookingReference);
    } catch (Throwable $exception) {
        if ($databaseConnection->inTransaction()) {
            $databaseConnection->rollBack();
        }
        throw $exception;
    }
}

function cancel_booking(
    array $booking,
    int $actorId,
    bool $adminOverride = false,
): void {
    if (!in_array($booking["status"], ["pending", "confirmed"], true)) {
        throw new RuntimeException("This booking can no longer be cancelled.");
    }

    if (
        !$adminOverride &&
        new DateTimeImmutable($booking["pickup_at"]) <=
            new DateTimeImmutable("+24 hours")
    ) {
        throw new RuntimeException(
            "Online cancellation closes 24 hours before pick-up. Contact support for assistance.",
        );
    }

    $currentTimestamp = date("Y-m-d H:i:s");
    $statement = database()->prepare(
        "UPDATE bookings
         SET status = 'cancelled', cancelled_at = ?, updated_at = ?
         WHERE id = ?",
    );
    $statement->execute([
        $currentTimestamp,
        $currentTimestamp,
        $booking["id"],
    ]);
}

function update_booking_status(
    array $booking,
    string $newStatus,
    int $actorId,
    string $adminNotes = "",
): void {
    $allowedTransitions = booking_transitions();
    if (
        !in_array(
            $newStatus,
            $allowedTransitions[$booking["status"]] ?? [],
            true,
        )
    ) {
        throw new RuntimeException("That status transition is not allowed.");
    }

    $currentTimestamp = date("Y-m-d H:i:s");
    $completedAt = $newStatus === "completed" ? $currentTimestamp : null;
    $cancelledAt = $newStatus === "cancelled" ? $currentTimestamp : null;

    $statement = database()->prepare(
        "UPDATE bookings
         SET status = ?, admin_notes = ?, completed_at = ?,
             cancelled_at = ?, updated_at = ?
         WHERE id = ?",
    );
    $statement->execute([
        $newStatus,
        substr(trim($adminNotes), 0, 3000),
        $completedAt,
        $cancelledAt,
        $currentTimestamp,
        $booking["id"],
    ]);
}
