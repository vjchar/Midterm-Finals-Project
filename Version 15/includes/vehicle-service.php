<?php

declare(strict_types=1);

/**
 * Vehicle, catalog, promotion, review, and favorite data access for VJ Car Rental.
 */

/****************************************************************************
 * VEHICLE CATALOG AND AVAILABILITY
 ****************************************************************************/

/**
 * Normalize the scalar and JSON values returned for a vehicle.
 *
 * @param array<string, mixed> $databaseRow
 * @return array{
 *     id: int,
 *     slug: string,
 *     name: string,
 *     brand: string,
 *     category: string,
 *     image: string,
 *     price: int,
 *     deposit: int,
 *     seats: int,
 *     doors: int,
 *     luggage: string,
 *     transmission: string,
 *     fuel: string,
 *     daily_km: int,
 *     overview_title: string,
 *     overview: string,
 *     description: string,
 *     inclusions: array<mixed>,
 *     features: array<mixed>,
 *     is_active: bool,
 *     availability_status: string,
 *     created_at: string,
 *     updated_at: string,
 *     rating: float,
 *     review_count: int,
 *     rental_count: int
 * }
 */
function decode_vehicle(array $databaseRow): array
{
    $databaseRow["id"] = (int) $databaseRow["id"];
    foreach (
        [
            "price",
            "deposit",
            "seats",
            "doors",
            "daily_km",
            "review_count",
            "rental_count",
        ]
        as $integerField
    ) {
        if (isset($databaseRow[$integerField])) {
            $databaseRow[$integerField] = (int) $databaseRow[$integerField];
        }
    }

    $databaseRow["rating"] = isset($databaseRow["rating"])
        ? round((float) $databaseRow["rating"], 1)
        : 0.0;
    $databaseRow["inclusions"] =
        json_decode((string) ($databaseRow["inclusions"] ?? "[]"), true) ?: [];
    $databaseRow["features"] =
        json_decode((string) ($databaseRow["features"] ?? "[]"), true) ?: [];
    $databaseRow["is_active"] = (bool) ($databaseRow["is_active"] ?? true);
    $databaseRow["availability_status"] = (string) (
        $databaseRow["effective_availability_status"] ??
        $databaseRow["availability_status"] ??
        "available"
    );
    unset($databaseRow["effective_availability_status"]);

    return $databaseRow;
}

/**
 * Fetch vehicles with their approved-review and completed-rental totals.
 *
 * @return list<array<string, mixed>>
 */
function vehicle_all(bool $activeOnly = true): array
{
    $vehicleQuery = "SELECT v.*, COALESCE(r.rating, 0) AS rating, COALESCE(r.review_count, 0) AS review_count,
        COALESCE(b.rental_count, 0) AS rental_count,
        CASE
            WHEN EXISTS (
                SELECT 1
                FROM bookings active_booking
                WHERE active_booking.vehicle_id = v.id
                    AND active_booking.status = 'active'
            ) THEN 'rented'
            WHEN v.availability_status IN ('maintenance', 'unavailable') THEN v.availability_status
            WHEN EXISTS (
                SELECT 1
                FROM bookings reserved_booking
                WHERE reserved_booking.vehicle_id = v.id
                    AND reserved_booking.status IN ('confirmed', 'ready')
                    AND reserved_booking.return_at >= NOW()
            ) THEN 'reserved'
            ELSE 'available'
        END AS effective_availability_status
        FROM vehicles v LEFT JOIN (
            SELECT vehicle_id, AVG(overall) AS rating, COUNT(*) AS review_count
            FROM reviews WHERE status = 'approved' GROUP BY vehicle_id
        ) r ON r.vehicle_id = v.id LEFT JOIN (
            SELECT vehicle_id, COUNT(*) AS rental_count FROM bookings WHERE status IN ('returned','completed') GROUP BY vehicle_id
        ) b ON b.vehicle_id = v.id";
    if ($activeOnly) {
        $vehicleQuery .= " WHERE v.is_active = 1";
    }
    $vehicleQuery .= " ORDER BY v.id";

    return array_map(
        "decode_vehicle",
        database()->query($vehicleQuery)->fetchAll(),
    );
}

/**
 * Find a vehicle by numeric identifier or slug.
 *
 * @return array<string, mixed>|null
 */
function vehicle_find(string|int $value, bool $activeOnly = true): ?array
{
    $lookupColumn =
        is_int($value) || ctype_digit((string) $value) ? "v.id" : "v.slug";
    $vehicleQuery = "SELECT v.*, COALESCE(r.rating, 0) AS rating, COALESCE(r.review_count, 0) AS review_count,
        COALESCE(b.rental_count, 0) AS rental_count,
        CASE
            WHEN EXISTS (
                SELECT 1
                FROM bookings active_booking
                WHERE active_booking.vehicle_id = v.id
                    AND active_booking.status = 'active'
            ) THEN 'rented'
            WHEN v.availability_status IN ('maintenance', 'unavailable') THEN v.availability_status
            WHEN EXISTS (
                SELECT 1
                FROM bookings reserved_booking
                WHERE reserved_booking.vehicle_id = v.id
                    AND reserved_booking.status IN ('confirmed', 'ready')
                    AND reserved_booking.return_at >= NOW()
            ) THEN 'reserved'
            ELSE 'available'
        END AS effective_availability_status
        FROM vehicles v LEFT JOIN (
            SELECT vehicle_id, AVG(overall) AS rating, COUNT(*) AS review_count
            FROM reviews WHERE status = 'approved' GROUP BY vehicle_id
        ) r ON r.vehicle_id = v.id LEFT JOIN (
            SELECT vehicle_id, COUNT(*) AS rental_count FROM bookings WHERE status IN ('returned','completed') GROUP BY vehicle_id
        ) b ON b.vehicle_id = v.id WHERE {$lookupColumn} = ?";
    if ($activeOnly) {
        $vehicleQuery .= " AND v.is_active = 1";
    }
    $vehicleQuery .= " LIMIT 1";

    $vehicleStatement = database()->prepare($vehicleQuery);
    $vehicleStatement->execute([$value]);
    $vehicleRow = $vehicleStatement->fetch();

    return $vehicleRow ? decode_vehicle($vehicleRow) : null;
}

/**
 * Determine whether a usable vehicle has no overlapping live booking.
 */
function vehicle_available(
    int $vehicleId,
    string $pickupAt,
    string $returnAt,
    ?int $excludeBookingId = null,
): bool {
    $vehicleStatusStatement = database()->prepare(
        "SELECT is_active, availability_status FROM vehicles WHERE id = ? LIMIT 1",
    );
    $vehicleStatusStatement->execute([$vehicleId]);
    $vehicleStatus = $vehicleStatusStatement->fetch();
    if (
        !$vehicleStatus ||
        !(bool) $vehicleStatus["is_active"] ||
        in_array(
            $vehicleStatus["availability_status"],
            ["maintenance", "unavailable"],
            true,
        )
    ) {
        return false;
    }

    $availabilityQuery = "SELECT COUNT(*) FROM bookings WHERE vehicle_id = ?
        AND status IN ('pending', 'confirmed', 'ready', 'active')
        AND pickup_at < ? AND return_at > ?";
    $availabilityParameters = [$vehicleId, $returnAt, $pickupAt];
    if ($excludeBookingId !== null) {
        $availabilityQuery .= " AND id <> ?";
        $availabilityParameters[] = $excludeBookingId;
    }

    $availabilityStatement = database()->prepare($availabilityQuery);
    $availabilityStatement->execute($availabilityParameters);

    return (int) $availabilityStatement->fetchColumn() === 0;
}

/****************************************************************************
 * ADD-ONS, PROMOTIONS, REVIEWS, AND FAVORITES
 ****************************************************************************/

/**
 * Fetch rental add-ons for catalog and booking displays.
 *
 * @return list<array{
 *     id: int,
 *     key: string,
 *     name: string,
 *     price: int,
 *     billing: string,
 *     icon: string,
 *     is_active: bool
 * }>
 */
function addon_all(bool $activeOnly = true): array
{
    $addonQuery =
        "SELECT id, addon_key AS `key`, name, price, billing, icon, is_active FROM addons";
    if ($activeOnly) {
        $addonQuery .= " WHERE is_active = 1";
    }
    $addonQuery .= " ORDER BY id";

    return array_map(static function (array $addonRow): array {
        $addonRow["id"] = (int) $addonRow["id"];
        $addonRow["price"] = (int) $addonRow["price"];
        $addonRow["is_active"] = (bool) $addonRow["is_active"];

        return $addonRow;
    }, database()->query($addonQuery)->fetchAll());
}

/**
 * Find a promotion that is active and valid at the current time.
 *
 * @return array{
 *     id: int|string,
 *     code: string,
 *     description: string,
 *     discount_type: string,
 *     discount_value: int|string,
 *     starts_at: string|null,
 *     ends_at: string|null,
 *     max_uses: int|string|null,
 *     used_count: int|string,
 *     is_active: int|string,
 *     created_at: string,
 *     updated_at: string
 * }|null
 */
function promo_find(string $code): ?array
{
    $promotionStatement = database()
        ->prepare("SELECT * FROM promos WHERE UPPER(code) = UPPER(?) AND is_active = 1
        AND (starts_at IS NULL OR starts_at <= ?) AND (ends_at IS NULL OR ends_at >= ?)
        AND (max_uses IS NULL OR used_count < max_uses) LIMIT 1");
    $currentTimestamp = date("Y-m-d H:i:s");
    $promotionStatement->execute([
        trim($code),
        $currentTimestamp,
        $currentTimestamp,
    ]);
    $promotion = $promotionStatement->fetch();

    return $promotion ?: null;
}

/**
 * Calculate the discount supplied by a promotion without exceeding subtotal.
 *
 * @param array{discount_type: string, discount_value: int|string}|null $promo
 */
function apply_promo(?array $promo, int $subtotal): int
{
    if (!$promo) {
        return 0;
    }

    return $promo["discount_type"] === "fixed"
        ? min($subtotal, (int) $promo["discount_value"])
        : min(
            $subtotal,
            (int) round($subtotal * ((int) $promo["discount_value"] / 100)),
        );
}

/**
 * Fetch approved review rows and their customer display name.
 *
 * @return list<array<string, mixed>>
 */
function approved_reviews_for_vehicle(
    int $vehicleId,
    int $limit = 8,
): array {
    $reviewStatement = database()->prepare(
        "SELECT r.*, u.name AS reviewer_name FROM reviews r JOIN users u ON u.id = r.user_id WHERE r.vehicle_id = ? AND r.status = 'approved' ORDER BY r.created_at DESC LIMIT ?",
    );
    $reviewStatement->bindValue(1, $vehicleId, PDO::PARAM_INT);
    $reviewStatement->bindValue(2, $limit, PDO::PARAM_INT);
    $reviewStatement->execute();

    return $reviewStatement->fetchAll();
}

/**
 * Return the vehicle slugs saved by a customer.
 *
 * @return list<string>
 */
function favorite_slugs(int $userId): array
{
    $favoriteStatement = database()->prepare(
        "SELECT v.slug FROM favorites f JOIN vehicles v ON v.id = f.vehicle_id WHERE f.user_id = ?",
    );
    $favoriteStatement->execute([$userId]);

    return array_column($favoriteStatement->fetchAll(), "slug");
}
