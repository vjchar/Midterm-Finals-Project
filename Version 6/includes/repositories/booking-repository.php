<?php

declare(strict_types=1);

/** @return array<string,mixed>|null */
function booking_find_by_reference(string $reference): ?array
{
    $bookingStatement = database()->prepare(
        "SELECT b.*, v.slug AS vehicle_slug, v.name AS vehicle_name,
                v.image AS vehicle_image, v.category AS vehicle_category,
                v.price AS current_daily_price,
                u.name AS customer_name, u.email AS customer_email,
                u.phone AS customer_phone
         FROM bookings b
         JOIN vehicles v ON v.id = b.vehicle_id
         JOIN users u ON u.id = b.user_id
         WHERE b.reference = ? LIMIT 1",
    );
    $bookingStatement->execute([$reference]);
    $booking = $bookingStatement->fetch();

    if (!$booking) {
        return null;
    }

    foreach (["id", "user_id", "vehicle_id", "subtotal", "total"] as $integerField) {
        $booking[$integerField] = (int) $booking[$integerField];
    }

    return $booking;
}

/** @return list<array<string,mixed>> */
function bookings_for_user(int $userId): array
{
    $bookingsStatement = database()->prepare(
        "SELECT b.*, v.slug AS vehicle_slug, v.name AS vehicle_name,
                v.image AS vehicle_image, v.category AS vehicle_category
         FROM bookings b
         JOIN vehicles v ON v.id = b.vehicle_id
         WHERE b.user_id = ?
         ORDER BY b.created_at DESC",
    );
    $bookingsStatement->execute([$userId]);

    return $bookingsStatement->fetchAll();
}

/** @return list<array<string,mixed>> */
function booking_all(string $status = "all"): array
{
    $sql =
        "SELECT b.*, v.name AS vehicle_name,
                u.name AS customer_name, u.email AS customer_email
         FROM bookings b
         JOIN vehicles v ON v.id = b.vehicle_id
         JOIN users u ON u.id = b.user_id";
    $parameters = [];

    if ($status !== "all") {
        $sql .= " WHERE b.status = ?";
        $parameters[] = $status;
    }

    $sql .= " ORDER BY b.created_at DESC";
    $statement = database()->prepare($sql);
    $statement->execute($parameters);

    return $statement->fetchAll();
}
