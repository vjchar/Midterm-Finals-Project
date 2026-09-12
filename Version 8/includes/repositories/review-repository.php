<?php

declare(strict_types=1);

/**
 * Fetch approved review rows and their customer display name.
 *
 * @return list<array<string, mixed>>
 */
function approved_reviews_for_vehicle(int $vehicleId, int $limit = 8): array
{
    $reviewStatement = database()->prepare(
        "SELECT r.*, u.name AS reviewer_name FROM reviews r JOIN users u ON u.id = r.user_id WHERE r.vehicle_id = ? AND r.status = 'approved' ORDER BY r.created_at DESC LIMIT ?",
    );
    $reviewStatement->bindValue(1, $vehicleId, PDO::PARAM_INT);
    $reviewStatement->bindValue(2, $limit, PDO::PARAM_INT);
    $reviewStatement->execute();

    return $reviewStatement->fetchAll();
}

/** @return array<string,mixed>|null */
function review_for_booking(int $bookingId): ?array
{
    $statement = database()->prepare(
        "SELECT * FROM reviews WHERE booking_id = ? LIMIT 1",
    );
    $statement->execute([$bookingId]);
    $row = $statement->fetch();
    return $row ?: null;
}
