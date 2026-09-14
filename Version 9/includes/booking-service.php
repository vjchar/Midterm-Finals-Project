<?php

declare(strict_types=1);

/**
 * Return the branch names accepted by the booking form.
 *
 * @return list<string>
 */
function booking_locations(): array
{
    return ["Manjuyod Branch", "Bais City Branch", "Dumaguete City Branch"];
}

/**
 * Validate raw booking fields and calculate a server-authoritative estimate.
 *
 * @param array{
 *     vehicle?: mixed,
 *     pickup?: mixed,
 *     return?: mixed,
 *     pickup_time?: mixed,
 *     return_time?: mixed,
 *     pickup_method?: mixed,
 *     location?: mixed,
 *     delivery_address?: mixed,
 *     addons?: mixed,
 *     promo?: mixed,
 *     special_requests?: mixed
 * } $bookingInput
 * @return array{
 *     vehicle: array<string, mixed>,
 *     pickup_at: string,
 *     return_at: string,
 *     days: int,
 *     pickup_method: string,
 *     pickup_location: string,
 *     delivery_address: string,
 *     addons: list<array<string, mixed>>,
 *     promo: array<string, mixed>|null,
 *     promo_code: string,
 *     subtotal: int,
 *     addons_total: int,
 *     delivery_fee: int,
 *     discount: int,
 *     total: int,
 *     deposit: int,
 *     special_requests: string
 * }
 * @throws InvalidArgumentException When any field or selected option is invalid.
 */
function parse_booking_input(array $bookingInput): array
{
    $selectedVehicle = vehicle_find(
        (string) ($bookingInput["vehicle"] ?? ""),
    );
    if (!$selectedVehicle) {
        throw new InvalidArgumentException("Choose an active vehicle.");
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
        throw new InvalidArgumentException(
            "Return must be later than pick-up.",
        );
    }

    $seconds =
        $returnDateTime->getTimestamp() - $pickupDateTime->getTimestamp();
    $rentalDays = max(1, (int) ceil($seconds / 86400));
    if ($rentalDays > 30) {
        throw new InvalidArgumentException(
            "Online bookings can cover up to 30 rental days. Contact support for longer rentals.",
        );
    }

    $selectedPickupMethod = trim(
        (string) ($bookingInput["pickup_method"] ?? "Branch pickup"),
    );
    if (
        !in_array(
            $selectedPickupMethod,
            ["Branch pickup", "Vehicle delivery"],
            true,
        )
    ) {
        throw new InvalidArgumentException("Choose a valid pickup method.");
    }
    $pickupLocation = trim((string) ($bookingInput["location"] ?? ""));
    if (!in_array($pickupLocation, booking_locations(), true)) {
        throw new InvalidArgumentException("Choose a valid branch.");
    }
    $deliveryAddress = trim((string) ($bookingInput["delivery_address"] ?? ""));
    if (
        $selectedPickupMethod === "Vehicle delivery" &&
        (mb_strlen($deliveryAddress) < 8 || mb_strlen($deliveryAddress) > 255)
    ) {
        throw new InvalidArgumentException(
            "Enter a complete delivery address.",
        );
    }

    $selectedAddOnKeys = array_values(
        array_unique(
            array_map(
                "strval",
                is_array($bookingInput["addons"] ?? null)
                    ? $bookingInput["addons"]
                    : [],
            ),
        ),
    );

    $activeRentalAddOns = addon_all();
    $selectedRentalAddOns = array_values(
        array_filter(
            $activeRentalAddOns,
            static fn(array $rentalAddOn): bool => in_array(
                $rentalAddOn["key"],
                $selectedAddOnKeys,
                true,
            ),
        ),
    );
    if (count($selectedRentalAddOns) !== count($selectedAddOnKeys)) {
        throw new InvalidArgumentException(
            "One or more selected add-ons are unavailable.",
        );
    }
    $rentalSubtotal = (int) $selectedVehicle["price"] * $rentalDays;
    $addOnTotal = 0;
    foreach ($selectedRentalAddOns as &$rentalAddOn) {
        $addOnQuantity = $rentalAddOn["billing"] === "day" ? $rentalDays : 1;
        $rentalAddOn["quantity"] = $addOnQuantity;
        $rentalAddOn["line_total"] =
            (int) $rentalAddOn["price"] * $addOnQuantity;
        $addOnTotal += $rentalAddOn["line_total"];
    }
    unset($rentalAddOn);

    $vehicleDeliveryFee =
        $selectedPickupMethod === "Vehicle delivery" ? 800 : 0;
    $promotionCode = strtoupper(trim((string) ($bookingInput["promo"] ?? "")));
    $promotion = $promotionCode !== "" ? promo_find($promotionCode) : null;
    if ($promotionCode !== "" && !$promotion) {
        throw new InvalidArgumentException(
            "The promotion code is invalid, inactive, expired, or fully used.",
        );
    }
    $promotionDiscount = apply_promo($promotion, $rentalSubtotal);

    return [
        "vehicle" => $selectedVehicle,
        "pickup_at" => $pickupDateTime->format("Y-m-d H:i:s"),
        "return_at" => $returnDateTime->format("Y-m-d H:i:s"),
        "days" => $rentalDays,
        "pickup_method" => $selectedPickupMethod,
        "pickup_location" => $pickupLocation,
        "delivery_address" =>
            $selectedPickupMethod === "Vehicle delivery"
                ? $deliveryAddress
                : "",
        "addons" => $selectedRentalAddOns,
        "promo" => $promotion,
        "promo_code" => $promotion["code"] ?? "",
        "subtotal" => $rentalSubtotal,
        "addons_total" => $addOnTotal,
        "delivery_fee" => $vehicleDeliveryFee,
        "discount" => $promotionDiscount,
        "total" =>
            $rentalSubtotal +
            $addOnTotal +
            $vehicleDeliveryFee -
            $promotionDiscount,
        "deposit" => (int) $selectedVehicle["deposit"],
        "special_requests" => mb_substr(
            trim((string) ($bookingInput["special_requests"] ?? "")),
            0,
            2000,
        ),
    ];
}

/**
 * Persist a validated booking, its add-ons, notification, and audit record.
 *
 * @param array<string, mixed> $bookingInput Raw fields accepted by
 *     parse_booking_input().
 * @return array<string, mixed> The newly persisted booking record.
 * @throws Throwable When validation or transactional persistence fails.
 */
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
                $bookingDetails["pickup_at"],
                $bookingDetails["return_at"],
            )
        ) {
            throw new RuntimeException(
                "That vehicle is already reserved for part of the selected period. " .
                    "Choose another vehicle or different dates.",
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
                pickup_method, pickup_location, delivery_address, status,
                promo_code, subtotal, addons_total, delivery_fee, discount,
                total, deposit, special_requests, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        );
        $statement->execute([
            $bookingReference,
            $userId,
            $bookingDetails["vehicle"]["id"],
            $bookingDetails["pickup_at"],
            $bookingDetails["return_at"],
            $bookingDetails["pickup_method"],
            $bookingDetails["pickup_location"],
            $bookingDetails["delivery_address"],
            "pending",
            $bookingDetails["promo_code"],
            $bookingDetails["subtotal"],
            $bookingDetails["addons_total"],
            $bookingDetails["delivery_fee"],
            $bookingDetails["discount"],
            $bookingDetails["total"],
            $bookingDetails["deposit"],
            $bookingDetails["special_requests"],
            $currentTimestamp,
            $currentTimestamp,
        ]);

        $bookingId = (int) $databaseConnection->lastInsertId();
        $rentalAddOnStatement = $databaseConnection->prepare(
            "INSERT INTO booking_addons (
                booking_id, addon_id, addon_name, unit_price,
                billing, quantity, line_total
            ) VALUES (?, ?, ?, ?, ?, ?, ?)",
        );
        foreach ($bookingDetails["addons"] as $rentalAddOn) {
            $rentalAddOnStatement->execute([
                $bookingId,
                $rentalAddOn["id"],
                $rentalAddOn["name"],
                $rentalAddOn["price"],
                $rentalAddOn["billing"],
                $rentalAddOn["quantity"],
                $rentalAddOn["line_total"],
            ]);
        }
        if ($bookingDetails["promo"]) {
            $promotionUpdate = $databaseConnection->prepare(
                "UPDATE promos SET used_count = used_count + 1, updated_at = ? WHERE id = ?",
            );
            $promotionUpdate->execute([
                $currentTimestamp,
                $bookingDetails["promo"]["id"],
            ]);
        }

        $databaseConnection->commit();
        notify_user(
            $userId,
            "Booking received",
            "Your reservation " .
                $bookingReference .
                " was saved. Upload your documents and submit the security deposit for verification.",
            "booking",
            $bookingId,
        );
        notify_admins(
            "New booking received",
            "Booking " . $bookingReference . " is ready for operations review.",
            "booking",
            $bookingId,
        );
        write_audit("booking_created", "booking", $bookingId, [
            "reference" => $bookingReference,
        ]);
        return booking_find_by_reference($bookingReference);
    } catch (Throwable $exception) {
        if ($databaseConnection->inTransaction()) {
            $databaseConnection->rollBack();
        }
        throw $exception;
    }
}

/**
 * Cancel an eligible booking and notify its customer.
 *
 * @param array{
 *     id: int|string,
 *     user_id: int|string,
 *     vehicle_id: int|string,
 *     reference: string,
 *     status: string,
 *     pickup_at: string
 * } $booking
 * @param int $actorId Account ID recorded in the audit trail.
 * @param bool $adminOverride Whether to bypass the customer cancellation cutoff.
 * @throws RuntimeException When the booking cannot be cancelled.
 */
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
    $statement = database()->prepare(
        "UPDATE bookings SET status = 'cancelled', cancelled_at = ?, updated_at = ? WHERE id = ?",
    );
    $currentTimestamp = date("Y-m-d H:i:s");
    $statement->execute([$currentTimestamp, $currentTimestamp, $booking["id"]]);
    sync_vehicle_status((int) $booking["vehicle_id"]);
    notify_user(
        (int) $booking["user_id"],
        "Booking cancelled",
        "Booking " . $booking["reference"] . " has been cancelled.",
        "booking",
        (int) $booking["id"],
    );
    write_audit("booking_cancelled", "booking", (int) $booking["id"], [
        "actor_id" => $actorId,
    ]);
}

/**
 * Move an eligible booking to a new available schedule and recalculate totals.
 *
 * @param array{
 *     id: int|string,
 *     user_id: int|string,
 *     vehicle_id: int|string,
 *     reference: string,
 *     status: string,
 *     promo_code: string,
 *     delivery_fee: int|string
 * } $booking
 * @param string $requestedPickupDateTime Local date and time accepted by
 *     DateTimeImmutable.
 * @param string $requestedReturnDateTime Local date and time accepted by
 *     DateTimeImmutable.
 * @param int $actorId Account ID recorded in the audit trail.
 * @throws InvalidArgumentException When the requested schedule is invalid.
 * @throws RuntimeException When the booking or vehicle cannot be rescheduled.
 */
function reschedule_booking(
    array $booking,
    string $requestedPickupDateTime,
    string $requestedReturnDateTime,
    int $actorId,
): void {
    if (!in_array($booking["status"], ["pending", "confirmed"], true)) {
        throw new RuntimeException(
            "This booking can no longer be rescheduled.",
        );
    }
    $pickupDateTime = new DateTimeImmutable($requestedPickupDateTime);
    $returnDateTime = new DateTimeImmutable($requestedReturnDateTime);
    if (
        $pickupDateTime <= new DateTimeImmutable("+1 hour") ||
        $returnDateTime <= $pickupDateTime
    ) {
        throw new InvalidArgumentException("Choose valid future dates.");
    }

    $rentalDays = (int) ceil(
        ($returnDateTime->getTimestamp() - $pickupDateTime->getTimestamp()) /
            86400,
    );
    if ($rentalDays > 30) {
        throw new InvalidArgumentException(
            "Online bookings can cover up to 30 days.",
        );
    }
    $databaseConnection = database();
    $databaseConnection->beginTransaction();
    try {
        if (
            $databaseConnection->getAttribute(PDO::ATTR_DRIVER_NAME) === "mysql"
        ) {
            $vehicleLockStatement = $databaseConnection->prepare(
                "SELECT id FROM vehicles WHERE id = ? FOR UPDATE",
            );
            $vehicleLockStatement->execute([$booking["vehicle_id"]]);
        }
        if (
            !vehicle_available(
                (int) $booking["vehicle_id"],
                $pickupDateTime->format("Y-m-d H:i:s"),
                $returnDateTime->format("Y-m-d H:i:s"),
                (int) $booking["id"],
            )
        ) {
            throw new RuntimeException(
                "The vehicle is not available for the new dates.",
            );
        }
        $rentalDays = max(
            1,
            (int) ceil(
                ($returnDateTime->getTimestamp() -
                    $pickupDateTime->getTimestamp()) /
                    86400,
            ),
        );

        $selectedVehicle = vehicle_find((int) $booking["vehicle_id"], false);
        if (!$selectedVehicle || !$selectedVehicle["is_active"]) {
            throw new RuntimeException(
                "This vehicle is no longer available for online rescheduling.",
            );
        }
        $rentalSubtotal = (int) $selectedVehicle["price"] * $rentalDays;
        $bookingAddOnsStatement = $databaseConnection->prepare(
            "SELECT * FROM booking_addons WHERE booking_id = ?",
        );
        $bookingAddOnsStatement->execute([$booking["id"]]);
        $addOnTotal = 0;
        foreach ($bookingAddOnsStatement->fetchAll() as $rentalAddOn) {
            $addOnQuantity =
                $rentalAddOn["billing"] === "day" ? $rentalDays : 1;
            $addOnLineTotal = (int) $rentalAddOn["unit_price"] * $addOnQuantity;
            $addOnUpdateStatement = $databaseConnection->prepare(
                "UPDATE booking_addons SET quantity = ?, line_total = ? WHERE id = ?",
            );
            $addOnUpdateStatement->execute([
                $addOnQuantity,
                $addOnLineTotal,
                $rentalAddOn["id"],
            ]);
            $addOnTotal += $addOnLineTotal;
        }

        $promotion = null;
        if ($booking["promo_code"]) {
            $promotionStatement = $databaseConnection->prepare(
                "SELECT * FROM promos WHERE UPPER(code) = UPPER(?) LIMIT 1",
            );
            $promotionStatement->execute([$booking["promo_code"]]);
            $promotion = $promotionStatement->fetch() ?: null;
        }
        $promotionDiscount = $promotion
            ? apply_promo($promotion, $rentalSubtotal)
            : 0;
        $recalculatedTotal =
            $rentalSubtotal +
            $addOnTotal +
            (int) $booking["delivery_fee"] -
            $promotionDiscount;
        $statement = $databaseConnection->prepare(
            "UPDATE bookings
             SET pickup_at = ?, return_at = ?, subtotal = ?, addons_total = ?,
                 discount = ?, total = ?, updated_at = ?
             WHERE id = ?",
        );
        $statement->execute([
            $pickupDateTime->format("Y-m-d H:i:s"),
            $returnDateTime->format("Y-m-d H:i:s"),
            $rentalSubtotal,
            $addOnTotal,
            $promotionDiscount,
            $recalculatedTotal,
            date("Y-m-d H:i:s"),
            $booking["id"],
        ]);
        $databaseConnection->commit();
        notify_user(
            (int) $booking["user_id"],
            "Booking schedule updated",
            "The schedule for booking " .
                $booking["reference"] .
                " was changed successfully.",
            "booking",
            (int) $booking["id"],
        );
        write_audit("booking_rescheduled", "booking", (int) $booking["id"], [
            "actor_id" => $actorId,
        ]);
    } catch (Throwable $exception) {
        if ($databaseConnection->inTransaction()) {
            $databaseConnection->rollBack();
        }
        throw $exception;
    }
}
