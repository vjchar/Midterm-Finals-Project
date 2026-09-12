<?php

declare(strict_types=1);

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
