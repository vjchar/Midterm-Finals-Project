<?php

declare(strict_types=1);

/** Normalize scalar, JSON, and verified-review values returned for a vehicle. */
function decode_vehicle(array $databaseRow): array
{
    $databaseRow["id"] = (int) $databaseRow["id"];
    foreach (["price", "seats", "doors", "daily_km", "review_count"] as $integerField) {
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
    return $databaseRow;
}

/** @return list<array<string, mixed>> */
function vehicle_all(): array
{
    $vehicleQuery = "SELECT v.*, COALESCE(r.rating, 0) AS rating, COALESCE(r.review_count, 0) AS review_count
        FROM vehicles v
        LEFT JOIN (
            SELECT vehicle_id, AVG(overall) AS rating, COUNT(*) AS review_count
            FROM reviews
            WHERE status = 'approved'
            GROUP BY vehicle_id
        ) r ON r.vehicle_id = v.id
        ORDER BY v.id";

    return array_map(
        "decode_vehicle",
        database()->query($vehicleQuery)->fetchAll(),
    );
}

/** @return array<string, mixed>|null */
function vehicle_find(string|int $value): ?array
{
    $lookupColumn =
        is_int($value) || ctype_digit((string) $value) ? "v.id" : "v.slug";
    $vehicleQuery = "SELECT v.*, COALESCE(r.rating, 0) AS rating, COALESCE(r.review_count, 0) AS review_count
        FROM vehicles v
        LEFT JOIN (
            SELECT vehicle_id, AVG(overall) AS rating, COUNT(*) AS review_count
            FROM reviews
            WHERE status = 'approved'
            GROUP BY vehicle_id
        ) r ON r.vehicle_id = v.id
        WHERE {$lookupColumn} = ?
        LIMIT 1";
    $vehicleStatement = database()->prepare($vehicleQuery);
    $vehicleStatement->execute([$value]);
    $vehicleRow = $vehicleStatement->fetch();
    return $vehicleRow ? decode_vehicle($vehicleRow) : null;
}

/** Determine whether a vehicle has no overlapping live booking. */
function vehicle_available(
    int $vehicleId,
    string $pickupAt,
    string $returnAt,
    ?int $excludeBookingId = null,
): bool {
    $vehicleStatusStatement = database()->prepare(
        "SELECT id FROM vehicles WHERE id = ? LIMIT 1",
    );
    $vehicleStatusStatement->execute([$vehicleId]);
    if (!$vehicleStatusStatement->fetch()) {
        return false;
    }

    $availabilityQuery = "SELECT COUNT(*) FROM bookings WHERE vehicle_id = ?
        AND status IN ('pending', 'confirmed')
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
