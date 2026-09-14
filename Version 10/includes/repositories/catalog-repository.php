<?php

declare(strict_types=1);

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
