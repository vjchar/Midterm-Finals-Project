<?php

declare(strict_types=1);

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
