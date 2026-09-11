<?php

declare(strict_types=1);

/**
 * Normalize scalar and JSON values returned for a vehicle.
 *
 * @param array<string, mixed> $databaseRow
 * @return array<string, mixed>
 */
function decode_vehicle(array $databaseRow): array
{
    $databaseRow["id"] = (int) $databaseRow["id"];
    foreach (["price", "seats", "doors", "daily_km"] as $integerField) {
        if (isset($databaseRow[$integerField])) {
            $databaseRow[$integerField] = (int) $databaseRow[$integerField];
        }
    }
    $databaseRow["inclusions"] =
        json_decode((string) ($databaseRow["inclusions"] ?? "[]"), true) ?: [];
    $databaseRow["features"] =
        json_decode((string) ($databaseRow["features"] ?? "[]"), true) ?: [];
    return $databaseRow;
}

/** @return list<array<string, mixed>> */
function vehicle_all(): array
{
    $vehicleQuery = "SELECT * FROM vehicles ORDER BY id";
    return array_map(
        "decode_vehicle",
        database()->query($vehicleQuery)->fetchAll(),
    );
}

/** @return array<string, mixed>|null */
function vehicle_find(string|int $value): ?array
{
    $lookupColumn =
        is_int($value) || ctype_digit((string) $value) ? "id" : "slug";
    $vehicleStatement = database()->prepare(
        "SELECT * FROM vehicles WHERE {$lookupColumn} = ? LIMIT 1",
    );
    $vehicleStatement->execute([$value]);
    $vehicleRow = $vehicleStatement->fetch();
    return $vehicleRow ? decode_vehicle($vehicleRow) : null;
}


/**
 * Determine whether a vehicle has no overlapping live booking.
 */
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
