<?php

declare(strict_types=1);

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
