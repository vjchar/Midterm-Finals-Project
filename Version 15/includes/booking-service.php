<?php

declare(strict_types=1);

/**
 * Core booking service: queries, validation, pricing, creation, and saved drafts.
 */

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
function persist_booking_details(
    PDO $databaseConnection,
    int $userId,
    array $bookingDetails,
): array {
    if ($databaseConnection->getAttribute(PDO::ATTR_DRIVER_NAME) === "mysql") {
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

    $booking = booking_find_by_reference($bookingReference);
    if (!$booking) {
        throw new RuntimeException("The booking could not be reloaded after creation.");
    }
    return $booking;
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
        $booking = persist_booking_details(
            $databaseConnection,
            $userId,
            $bookingDetails,
        );
        $databaseConnection->commit();

        notify_user(
            $userId,
            "Booking Created — Payment Required",
            "Your booking " . $booking["reference"] . " was created successfully. Next step: complete the required payment.",
            "booking",
            (int) $booking["id"],
        );
        notify_admins(
            "New booking received",
            "Booking " . $booking["reference"] . " is ready for operations review.",
            "booking",
            (int) $booking["id"],
        );
        write_audit("booking_created", "booking", (int) $booking["id"], [
            "reference" => (string) $booking["reference"],
        ]);
        return $booking;
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

/****************************************************************************
 * BOOKING QUERIES
 ****************************************************************************/

/**
 * Fetch one booking with its customer, vehicle, and selected add-ons.
 *
 * @return array{
 *     id: int,
 *     reference: string,
 *     user_id: int,
 *     vehicle_id: int,
 *     pickup_at: string,
 *     return_at: string,
 *     pickup_method: string,
 *     pickup_location: string,
 *     delivery_address: string,
 *     status: string,
 *     promo_code: string,
 *     subtotal: int,
 *     addons_total: int,
 *     delivery_fee: int,
 *     discount: int,
 *     total: int,
 *     deposit: int,
 *     special_requests: string|null,
 *     admin_notes: string|null,
 *     cancelled_at: string|null,
 *     completed_at: string|null,
 *     approved_at: string|null,
 *     ready_at: string|null,
 *     started_at: string|null,
 *     returned_at: string|null,
 *     rejected_at: string|null,
 *     no_show_at: string|null,
 *     created_at: string,
 *     updated_at: string,
 *     vehicle_slug: string,
 *     vehicle_name: string,
 *     vehicle_image: string,
 *     vehicle_category: string,
 *     current_daily_price: mixed,
 *     customer_name: string,
 *     customer_email: string,
 *     customer_phone: string,
 *     addons: list<array<string, mixed>>
 * }|null
 */
function booking_find_by_reference(string $reference): ?array
{
    $bookingStatement = database()
        ->prepare("SELECT b.*, v.slug AS vehicle_slug, v.name AS vehicle_name, v.image AS vehicle_image,
            v.category AS vehicle_category, v.price AS current_daily_price, u.name AS customer_name, u.email AS customer_email,
            u.phone AS customer_phone
        FROM bookings b JOIN vehicles v ON v.id = b.vehicle_id JOIN users u ON u.id = b.user_id
        WHERE b.reference = ? LIMIT 1");
    $bookingStatement->execute([$reference]);
    $booking = $bookingStatement->fetch();
    if (!$booking) {
        return null;
    }

    foreach (
        [
            "id",
            "user_id",
            "vehicle_id",
            "subtotal",
            "addons_total",
            "delivery_fee",
            "discount",
            "total",
            "deposit",
        ]
        as $integerField
    ) {
        $booking[$integerField] = (int) $booking[$integerField];
    }

    $bookingAddonsStatement = database()->prepare(
        "SELECT * FROM booking_addons WHERE booking_id = ? ORDER BY id",
    );
    $bookingAddonsStatement->execute([$booking["id"]]);
    $booking["addons"] = $bookingAddonsStatement->fetchAll();

    return $booking;
}

/**
 * Fetch a customer's bookings in reverse creation order.
 *
 * @return list<array<string, mixed>>
 */
function bookings_for_user(int $userId): array
{
    $bookingsStatement = database()->prepare(
        "SELECT b.*, v.slug AS vehicle_slug, v.name AS vehicle_name, v.image AS vehicle_image, v.category AS vehicle_category FROM bookings b JOIN vehicles v ON v.id = b.vehicle_id WHERE b.user_id = ? ORDER BY b.created_at DESC",
    );
    $bookingsStatement->execute([$userId]);

    return $bookingsStatement->fetchAll();
}

/****************************************************************************
 * SAVED BOOKING DRAFTS
 ****************************************************************************/

/**
 * Generate a human-readable reference for a saved booking draft.
 */
function booking_draft_reference(): string
{
    return "DRAFT-" . date("ymd") . "-" . strtoupper(bin2hex(random_bytes(3)));
}

/**
 * Mark stale active drafts as expired for one customer.
 */
function expire_booking_drafts_for_user(int $userId): void
{
    $statement = database()->prepare(
        "SELECT id FROM booking_drafts WHERE user_id = ? AND status = 'active' AND expires_at <= ?",
    );
    $now = date("Y-m-d H:i:s");
    $statement->execute([$userId, $now]);
    $expiredIds = array_map('intval', array_column($statement->fetchAll(), 'id'));
    if (!$expiredIds) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($expiredIds), '?'));
    $update = database()->prepare(
        "UPDATE booking_drafts SET status = 'expired', updated_at = ? WHERE user_id = ? AND status = 'active' AND id IN ({$placeholders})",
    );
    $update->execute(array_merge([$now, $userId], $expiredIds));

    foreach ($expiredIds as $draftId) {
        write_audit('booking_draft_expired', 'booking_draft', $draftId);
    }
}

/**
 * Fetch one draft owned by the authenticated customer.
 */
function booking_draft_find_owned(int $draftId, int $userId, bool $includeInactive = true): ?array
{
    expire_booking_drafts_for_user($userId);
    $sql = "SELECT d.*, v.slug AS vehicle_slug, v.name AS vehicle_name, v.image AS vehicle_image,
                   v.category AS vehicle_category, v.price AS current_daily_price, v.deposit AS current_deposit
            FROM booking_drafts d
            JOIN vehicles v ON v.id = d.vehicle_id
            WHERE d.id = ? AND d.user_id = ?";
    if (!$includeInactive) {
        $sql .= " AND d.status = 'active'";
    }
    $sql .= " LIMIT 1";
    $statement = database()->prepare($sql);
    $statement->execute([$draftId, $userId]);
    $draft = $statement->fetch();
    if (!$draft) {
        return null;
    }

    foreach (
        [
            'id', 'user_id', 'vehicle_id', 'estimated_subtotal',
            'estimated_addons_total', 'estimated_delivery_fee',
            'estimated_discount', 'estimated_total', 'estimated_deposit',
        ] as $integerField
    ) {
        $draft[$integerField] = (int) ($draft[$integerField] ?? 0);
    }

    $addonStatement = database()->prepare(
        "SELECT da.*, a.addon_key FROM booking_draft_addons da
         LEFT JOIN addons a ON a.id = da.addon_id
         WHERE da.draft_id = ? ORDER BY da.id",
    );
    $addonStatement->execute([$draftId]);
    $draft['addons'] = $addonStatement->fetchAll();
    return $draft;
}

/**
 * Fetch active drafts for the customer, newest first.
 *
 * @return list<array<string,mixed>>
 */
function booking_drafts_for_user(int $userId): array
{
    expire_booking_drafts_for_user($userId);
    $statement = database()->prepare(
        "SELECT d.*, v.slug AS vehicle_slug, v.name AS vehicle_name, v.image AS vehicle_image,
                v.category AS vehicle_category, v.price AS current_daily_price
         FROM booking_drafts d
         JOIN vehicles v ON v.id = d.vehicle_id
         WHERE d.user_id = ? AND d.status = 'active'
         ORDER BY d.updated_at DESC",
    );
    $statement->execute([$userId]);
    return $statement->fetchAll();
}

/**
 * Convert a saved draft into the raw booking input accepted by the trusted
 * Version 12 pricing/validation service.
 */
function booking_draft_raw_input(array $draft, ?string $promoOverride = null): array
{
    $pickup = new DateTimeImmutable((string) $draft['pickup_at']);
    $return = new DateTimeImmutable((string) $draft['return_at']);
    $addonKeys = [];
    foreach ($draft['addons'] ?? [] as $addon) {
        if (!empty($addon['addon_key'])) {
            $addonKeys[] = (string) $addon['addon_key'];
        }
    }

    return [
        'vehicle' => (string) $draft['vehicle_slug'],
        'pickup' => $pickup->format('Y-m-d'),
        'return' => $return->format('Y-m-d'),
        'pickup_time' => $pickup->format('H:i'),
        'return_time' => $return->format('H:i'),
        'pickup_method' => (string) $draft['pickup_method'],
        'location' => (string) $draft['pickup_location'],
        'delivery_address' => (string) ($draft['delivery_address'] ?? ''),
        'addons' => $addonKeys,
        'promo' => $promoOverride ?? (string) ($draft['promo_code'] ?? ''),
        'special_requests' => (string) ($draft['special_requests'] ?? ''),
    ];
}

/**
 * Return form values for resuming/editing a draft.
 */
function booking_draft_form_values(array $draft): array
{
    return booking_draft_raw_input($draft);
}

/**
 * Recalculate a draft with the current trusted pricing and availability rules.
 * An expired promo is removed for the preview and reported to the customer.
 *
 * @return array{details:?array,available:bool,error:string,promo_warning:string,current_total:int,saved_total:int,difference:int,effective_promo_code:string}
 */
function booking_draft_preview(array $draft): array
{
    $promoWarning = '';
    $effectivePromo = (string) ($draft['promo_code'] ?? '');
    try {
        $details = parse_booking_input(booking_draft_raw_input($draft));
    } catch (InvalidArgumentException $error) {
        if ($effectivePromo !== '' && str_contains(strtolower($error->getMessage()), 'promotion code')) {
            $promoWarning = 'The promotion saved with this booking is no longer available. The current price has been recalculated without it.';
            $effectivePromo = '';
            try {
                $details = parse_booking_input(booking_draft_raw_input($draft, ''));
            } catch (Throwable $retryError) {
                return [
                    'details' => null,
                    'available' => false,
                    'error' => user_facing_error_message($retryError),
                    'promo_warning' => $promoWarning,
                    'current_total' => 0,
                    'saved_total' => (int) $draft['estimated_total'],
                    'difference' => -(int) $draft['estimated_total'],
                    'effective_promo_code' => '',
                ];
            }
        } else {
            return [
                'details' => null,
                'available' => false,
                'error' => user_facing_error_message($error),
                'promo_warning' => '',
                'current_total' => 0,
                'saved_total' => (int) $draft['estimated_total'],
                'difference' => -(int) $draft['estimated_total'],
                'effective_promo_code' => $effectivePromo,
            ];
        }
    }

    $available = vehicle_available(
        (int) $details['vehicle']['id'],
        (string) $details['pickup_at'],
        (string) $details['return_at'],
    );
    $currentTotal = (int) $details['total'];
    $savedTotal = (int) $draft['estimated_total'];
    return [
        'details' => $details,
        'available' => $available,
        'error' => $available ? '' : 'This vehicle is no longer available for your saved dates.',
        'promo_warning' => $promoWarning,
        'current_total' => $currentTotal,
        'saved_total' => $savedTotal,
        'difference' => $currentTotal - $savedTotal,
        'effective_promo_code' => $effectivePromo,
    ];
}

/**
 * Create or update a draft from the same server-validated booking input used by
 * a real booking. Drafts never block fleet availability and never consume promos.
 */
function save_booking_draft(int $userId, array $bookingInput, ?int $draftId = null): array
{
    expire_booking_drafts_for_user($userId);
    $details = parse_booking_input($bookingInput);
    if (!vehicle_available((int) $details['vehicle']['id'], (string) $details['pickup_at'], (string) $details['return_at'])) {
        throw new RuntimeException('This vehicle is already reserved during part of the selected schedule. Choose different dates or another vehicle before saving.');
    }

    $databaseConnection = database();
    $databaseConnection->beginTransaction();
    try {
        $now = date('Y-m-d H:i:s');
        $expiresAt = (new DateTimeImmutable($now))
            ->modify('+' . BOOKING_DRAFT_TTL_DAYS . ' days')
            ->format('Y-m-d H:i:s');
        $isNew = $draftId === null || $draftId < 1;

        if ($isNew) {
            do {
                $reference = booking_draft_reference();
                $check = $databaseConnection->prepare('SELECT COUNT(*) FROM booking_drafts WHERE reference = ?');
                $check->execute([$reference]);
            } while ((int) $check->fetchColumn() > 0);

            $statement = $databaseConnection->prepare(
                "INSERT INTO booking_drafts (
                    reference, user_id, vehicle_id, pickup_at, return_at, pickup_method,
                    pickup_location, delivery_address, promo_code, estimated_subtotal,
                    estimated_addons_total, estimated_delivery_fee, estimated_discount,
                    estimated_total, estimated_deposit, special_requests, status,
                    expires_at, last_saved_at, created_at, updated_at
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, ?)",
            );
            $statement->execute([
                $reference,
                $userId,
                $details['vehicle']['id'],
                $details['pickup_at'],
                $details['return_at'],
                $details['pickup_method'],
                $details['pickup_location'],
                $details['delivery_address'],
                $details['promo_code'],
                $details['subtotal'],
                $details['addons_total'],
                $details['delivery_fee'],
                $details['discount'],
                $details['total'],
                $details['deposit'],
                $details['special_requests'],
                $expiresAt,
                $now,
                $now,
                $now,
            ]);
            $draftId = (int) $databaseConnection->lastInsertId();
        } else {
            $lockSql = "SELECT id, status, expires_at FROM booking_drafts WHERE id = ? AND user_id = ? LIMIT 1";
            if ($databaseConnection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $lockSql .= ' FOR UPDATE';
            }
            $lock = $databaseConnection->prepare($lockSql);
            $lock->execute([$draftId, $userId]);
            $existing = $lock->fetch();
            if (!$existing) {
                throw new RuntimeException('Saved booking not found.');
            }
            if ($existing['status'] !== 'active' || strtotime((string) $existing['expires_at']) <= time()) {
                throw new RuntimeException('This saved booking has expired or is no longer editable.');
            }
            $statement = $databaseConnection->prepare(
                "UPDATE booking_drafts SET vehicle_id = ?, pickup_at = ?, return_at = ?, pickup_method = ?,
                    pickup_location = ?, delivery_address = ?, promo_code = ?, estimated_subtotal = ?,
                    estimated_addons_total = ?, estimated_delivery_fee = ?, estimated_discount = ?,
                    estimated_total = ?, estimated_deposit = ?, special_requests = ?, expires_at = ?,
                    last_saved_at = ?, updated_at = ? WHERE id = ? AND user_id = ? AND status = 'active'",
            );
            $statement->execute([
                $details['vehicle']['id'], $details['pickup_at'], $details['return_at'],
                $details['pickup_method'], $details['pickup_location'], $details['delivery_address'],
                $details['promo_code'], $details['subtotal'], $details['addons_total'],
                $details['delivery_fee'], $details['discount'], $details['total'],
                $details['deposit'], $details['special_requests'], $expiresAt, $now, $now,
                $draftId, $userId,
            ]);
            $databaseConnection->prepare('DELETE FROM booking_draft_addons WHERE draft_id = ?')->execute([$draftId]);
        }

        $addonInsert = $databaseConnection->prepare(
            "INSERT INTO booking_draft_addons (draft_id, addon_id, addon_name, unit_price, billing, quantity, line_total)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
        );
        foreach ($details['addons'] as $addon) {
            $addonInsert->execute([
                $draftId, $addon['id'], $addon['name'], $addon['price'],
                $addon['billing'], $addon['quantity'], $addon['line_total'],
            ]);
        }

        $databaseConnection->commit();
        $draft = booking_draft_find_owned((int) $draftId, $userId, true);
        if (!$draft) {
            throw new RuntimeException('The saved booking could not be reloaded.');
        }
        write_audit($isNew ? 'booking_draft_created' : 'booking_draft_updated', 'booking_draft', (int) $draftId, [
            'reference' => (string) $draft['reference'],
        ]);
        if ($isNew) {
            notify_user(
                $userId,
                'Booking Saved for Later',
                'Your booking draft ' . $draft['reference'] . ' has been saved. The vehicle is not reserved until you complete the booking.',
                'draft',
                null,
            );
        }
        return $draft;
    } catch (Throwable $error) {
        if ($databaseConnection->inTransaction()) {
            $databaseConnection->rollBack();
        }
        throw $error;
    }
}

/**
 * Discard an owned active draft without deleting its history.
 */
function discard_booking_draft(int $draftId, int $userId): void
{
    $statement = database()->prepare(
        "UPDATE booking_drafts SET status = 'discarded', updated_at = ? WHERE id = ? AND user_id = ? AND status = 'active'",
    );
    $statement->execute([date('Y-m-d H:i:s'), $draftId, $userId]);
    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('This saved booking is no longer available to discard.');
    }
    write_audit('booking_draft_discarded', 'booking_draft', $draftId);
}

/**
 * Convert one owned active draft into a real booking using the same trusted
 * booking persistence, availability lock, pricing, add-ons, and promo logic.
 */
function convert_booking_draft(int $draftId, int $userId): array
{
    expire_booking_drafts_for_user($userId);
    $databaseConnection = database();
    $databaseConnection->beginTransaction();
    try {
        $draftSql = "SELECT d.*, v.slug AS vehicle_slug FROM booking_drafts d
                     JOIN vehicles v ON v.id = d.vehicle_id
                     WHERE d.id = ? AND d.user_id = ? LIMIT 1";
        if ($databaseConnection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $draftSql .= ' FOR UPDATE';
        }
        $statement = $databaseConnection->prepare($draftSql);
        $statement->execute([$draftId, $userId]);
        $draft = $statement->fetch();
        if (!$draft) {
            throw new RuntimeException('Saved booking not found.');
        }
        if ($draft['status'] !== 'active') {
            throw new RuntimeException('Only an active saved booking can be converted.');
        }
        if (strtotime((string) $draft['expires_at']) <= time()) {
            $databaseConnection->prepare("UPDATE booking_drafts SET status='expired', updated_at=? WHERE id=?")
                ->execute([date('Y-m-d H:i:s'), $draftId]);
            throw new RuntimeException('This saved booking has expired. Start a new booking using the latest availability and pricing.');
        }

        $addonStatement = $databaseConnection->prepare(
            "SELECT da.*, a.addon_key FROM booking_draft_addons da
             LEFT JOIN addons a ON a.id = da.addon_id WHERE da.draft_id = ? ORDER BY da.id",
        );
        $addonStatement->execute([$draftId]);
        $draft['addons'] = $addonStatement->fetchAll();

        $details = parse_booking_input(booking_draft_raw_input($draft));
        $booking = persist_booking_details($databaseConnection, $userId, $details);
        $now = date('Y-m-d H:i:s');
        $update = $databaseConnection->prepare(
            "UPDATE booking_drafts SET status='converted', converted_booking_id=?, updated_at=? WHERE id=? AND user_id=? AND status='active'",
        );
        $update->execute([(int) $booking['id'], $now, $draftId, $userId]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('The saved booking changed while it was being processed. Please try again.');
        }
        $databaseConnection->commit();

        notify_user(
            $userId,
            'Booking Created — Payment Required',
            'Your saved booking was confirmed as booking ' . $booking['reference'] . '. Continue with the next unfinished requirement.',
            'booking',
            (int) $booking['id'],
        );
        notify_admins(
            'New booking received',
            'Booking ' . $booking['reference'] . ' was created from a saved customer draft.',
            'booking',
            (int) $booking['id'],
        );
        write_audit('booking_draft_converted', 'booking_draft', $draftId, [
            'booking_id' => (int) $booking['id'],
            'booking_reference' => (string) $booking['reference'],
        ]);
        write_audit('booking_created', 'booking', (int) $booking['id'], [
            'reference' => (string) $booking['reference'],
            'source' => 'booking_draft',
            'draft_id' => $draftId,
        ]);
        return $booking;
    } catch (Throwable $error) {
        if ($databaseConnection->inTransaction()) {
            $databaseConnection->rollBack();
        }
        throw $error;
    }
}
