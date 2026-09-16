<?php

declare(strict_types=1);


/**
 * FILE: includes/vehicle-service.php
 * FILE PURPOSE: Vehicle catalog and vehicle-related business/data service.
 * USED BY: Fleet pages, favorites, reviews, maintenance, add-ons, ratings, and API actions.
 * RESPONSIBILITY: Owns vehicle queries and related operations including favorites, review persistence/moderation, review photos, add-ons, maintenance, and rating aggregates.
 *
 * Maintenance note: Keep this file focused on the responsibility described above.
 */
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
        $addonRow["billing"] = "rental";
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

/****************************************************************************
 * FAVORITES, REVIEWS, ADD-ONS, AND FLEET MAINTENANCE
 ****************************************************************************/

/** Toggle a customer favorite and return true when the vehicle is now saved. */
function toggle_vehicle_favorite(int $userId, int $vehicleId): bool
{
    $existing = database()->prepare(
        "SELECT id FROM favorites WHERE user_id = ? AND vehicle_id = ?",
    );
    $existing->execute([$userId, $vehicleId]);
    $favoriteId = $existing->fetchColumn();

    if ($favoriteId) {
        $delete = database()->prepare("DELETE FROM favorites WHERE id = ?");
        $delete->execute([(int) $favoriteId]);
        return false;
    }

    $create = database()->prepare(
        "INSERT INTO favorites (user_id, vehicle_id, created_at) VALUES (?, ?, ?)",
    );
    $create->execute([$userId, $vehicleId, date("Y-m-d H:i:s")]);
    return true;
}

/** Return vehicle slugs unavailable within the supplied date/time range. */
function unavailable_vehicle_slugs(string $pickupAt, string $returnAt): array
{
    $query = <<<'SQL'
SELECT DISTINCT v.slug
FROM vehicles AS v
LEFT JOIN bookings AS b
    ON b.vehicle_id = v.id
    AND b.status IN ('pending', 'confirmed', 'ready', 'active')
    AND b.pickup_at < ?
    AND b.return_at > ?
WHERE v.is_active = 0
    OR v.availability_status IN ('maintenance', 'unavailable')
    OR b.id IS NOT NULL
SQL;
    $statement = database()->prepare($query);
    $statement->execute([$returnAt, $pickupAt]);
    return array_column($statement->fetchAll(), "slug");
}

/** Return average approved rating components for one vehicle. */
function vehicle_rating_averages(int $vehicleId): array
{
    $statement = database()->prepare(
        "SELECT AVG(cleanliness) AS cleanliness, AVG(comfort) AS comfort, AVG(vehicle_condition) AS vehicle_condition, AVG(pickup_experience) AS pickup_experience, AVG(customer_support) AS customer_support FROM reviews WHERE vehicle_id = ? AND status = 'approved'",
    );
    $statement->execute([$vehicleId]);
    return $statement->fetch() ?: [];
}

/** Return the review photo filename when it is viewable by the requester. */
function review_photo_filename(int $photoId, bool $includeUnapproved = false): ?string
{
    $sql =
        "SELECT rp.filename FROM review_photos rp JOIN reviews r ON r.id = rp.review_id WHERE rp.id = ?";
    if (!$includeUnapproved) {
        $sql .= " AND r.status = 'approved'";
    }
    $statement = database()->prepare($sql . " LIMIT 1");
    $statement->execute([$photoId]);
    $filename = $statement->fetchColumn();
    return $filename ? (string) $filename : null;
}

/** Find a completed booking owned by a customer for verified review submission. */
function reviewable_booking(string $reference, int $userId): ?array
{
    $statement = database()->prepare(
        "SELECT b.reference, b.id, b.vehicle_id, v.name AS vehicle_name, v.slug AS vehicle_slug, v.image AS vehicle_image, v.description AS vehicle_description
         FROM bookings b
         JOIN vehicles v ON v.id = b.vehicle_id
         WHERE b.reference = ? AND b.user_id = ? AND b.status = 'completed'
         LIMIT 1",
    );
    $statement->execute([$reference, $userId]);
    $booking = $statement->fetch();
    return $booking ?: null;
}

/** Return one review already submitted for a booking, if present. */
function review_for_booking(int $bookingId): ?array
{
    $statement = database()->prepare(
        "SELECT id, status FROM reviews WHERE booking_id = ? LIMIT 1",
    );
    $statement->execute([$bookingId]);
    $review = $statement->fetch();
    return $review ?: null;
}

/** Submit a verified-trip review and optional photos in one transaction. */
function submit_vehicle_review(
    array $booking,
    int $userId,
    array $scores,
    string $title,
    string $body,
    array $files,
): int {
    if (review_for_booking((int) $booking["id"])) {
        throw new RuntimeException("A review has already been submitted for this booking.");
    }

    $database = database();
    $savedFiles = [];
    $database->beginTransaction();
    try {
        $now = date("Y-m-d H:i:s");
        $insert = $database->prepare(
            "INSERT INTO reviews (booking_id, user_id, vehicle_id, overall, cleanliness, comfort, vehicle_condition, pickup_experience, customer_support, title, body, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        );
        $insert->execute([
            (int) $booking["id"],
            $userId,
            (int) $booking["vehicle_id"],
            (int) $scores["overall"],
            (int) $scores["cleanliness"],
            (int) $scores["comfort"],
            (int) $scores["vehicle_condition"],
            (int) $scores["pickup_experience"],
            (int) $scores["customer_support"],
            $title,
            $body,
            "pending",
            $now,
            $now,
        ]);
        $reviewId = (int) $database->lastInsertId();

        foreach ($files as $file) {
            $filename = upload_file(
                $file,
                ROOT . "/storage/reviews",
                [
                    "image/jpeg" => "jpg",
                    "image/png" => "png",
                    "image/webp" => "webp",
                ],
                3 * 1024 * 1024,
                "review-" . $reviewId,
            );
            $savedFiles[] = $filename;
            $photo = $database->prepare(
                "INSERT INTO review_photos (review_id, filename, created_at) VALUES (?, ?, ?)",
            );
            $photo->execute([$reviewId, $filename, $now]);
        }

        $database->commit();
        notify_admins(
            "Review awaiting moderation",
            "A verified-trip review for booking " . $booking["reference"] . " is ready for moderation.",
            "review",
            (int) $booking["id"],
        );
        write_audit("review_submitted", "review", $reviewId, [
            "booking_id" => (int) $booking["id"],
        ]);
        return $reviewId;
    } catch (Throwable $error) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        foreach ($savedFiles as $filename) {
            $path = ROOT . "/storage/reviews/" . basename($filename);
            if (is_file($path)) {
                unlink($path);
            }
        }
        throw $error;
    }
}

/** Save or update a rental add-on and return its id. */
function save_addon(array $input): int
{
    $id = (int) ($input["addon_id"] ?? 0);
    $key = strtolower(
        preg_replace("/[^a-z0-9-]/i", "-", trim((string) ($input["addon_key"] ?? ""))),
    );
    $name = mb_substr(trim((string) ($input["name"] ?? "")), 0, 140);
    $price = filter_var($input["price"] ?? null, FILTER_VALIDATE_INT);
    $billing = "rental";
    $icon = preg_replace("/[^a-z0-9-]/i", "", trim((string) ($input["icon"] ?? "")));
    $active = !empty($input["is_active"]) ? 1 : 0;

    if ($key === "" || $name === "" || strlen($key) > 80) {
        throw new InvalidArgumentException("Enter a key and display name.");
    }
    if ($price === false || $price < 0) {
        throw new InvalidArgumentException("Enter a valid add-on price.");
    }
    if ($icon === "" || strlen($icon) > 80) {
        $icon = "bi-plus-circle";
    }

    $now = date("Y-m-d H:i:s");
    if ($id > 0) {
        $statement = database()->prepare(
            "UPDATE addons SET addon_key=?, name=?, price=?, billing=?, icon=?, is_active=?, updated_at=? WHERE id=?",
        );
        $statement->execute([$key, $name, $price, $billing, $icon, $active, $now, $id]);
        if ($statement->rowCount() === 0 && !addon_find($id)) {
            throw new RuntimeException("Rental add-on not found.");
        }
    } else {
        $statement = database()->prepare(
            "INSERT INTO addons (addon_key,name,price,billing,icon,is_active,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)",
        );
        $statement->execute([$key, $name, $price, $billing, $icon, $active, $now, $now]);
        $id = (int) database()->lastInsertId();
    }

    write_audit("addon_saved", "addon", $id, [
        "key" => $key,
        "active" => (bool) $active,
    ]);
    return $id;
}

/** Return one add-on by id. */
function addon_find(int $id): ?array
{
    $statement = database()->prepare("SELECT * FROM addons WHERE id=? LIMIT 1");
    $statement->execute([$id]);
    $addon = $statement->fetch();
    if ($addon) {
        $addon["billing"] = "rental";
    }
    return $addon ?: null;
}

/** Create a fleet maintenance record. */
function create_maintenance_record(int $adminId, array $input): int
{
    $vehicleId = filter_var($input["vehicle_id"] ?? null, FILTER_VALIDATE_INT);
    $cost = filter_var($input["cost"] ?? 0, FILTER_VALIDATE_INT, [
        "options" => ["min_range" => 0, "max_range" => 10000000],
    ]);
    $startsAt = DateTimeImmutable::createFromFormat(
        "Y-m-d\\TH:i",
        trim((string) ($input["starts_at"] ?? "")),
    );
    $title = trim((string) ($input["title"] ?? ""));
    $description = trim((string) ($input["description"] ?? ""));

    if (
        !$vehicleId ||
        $cost === false ||
        !$startsAt ||
        mb_strlen($title) < 3 ||
        mb_strlen($description) < 5
    ) {
        throw new InvalidArgumentException(
            "Complete all maintenance fields with valid values.",
        );
    }

    $now = date("Y-m-d H:i:s");
    $statement = database()->prepare(
        "INSERT INTO maintenance_records
            (vehicle_id, title, description, status, starts_at, cost, created_by, created_at, updated_at)
         VALUES (?, ?, ?, 'scheduled', ?, ?, ?, ?, ?)",
    );
    $statement->execute([
        (int) $vehicleId,
        mb_substr($title, 0, 160),
        mb_substr($description, 0, 3000),
        $startsAt->format("Y-m-d H:i:s"),
        (int) $cost,
        $adminId,
        $now,
        $now,
    ]);
    $recordId = (int) database()->lastInsertId();
    write_audit("maintenance_created", "maintenance_record", $recordId);
    return $recordId;
}

/** Update maintenance status and synchronize vehicle availability. */
function update_maintenance_status(int $recordId, string $status): void
{
    if (
        $recordId < 1 ||
        !in_array($status, ["scheduled", "in_progress", "completed", "cancelled"], true)
    ) {
        throw new InvalidArgumentException("Choose a valid maintenance update.");
    }

    $select = database()->prepare(
        "SELECT * FROM maintenance_records WHERE id = ? LIMIT 1",
    );
    $select->execute([$recordId]);
    $record = $select->fetch();
    if (!$record) {
        throw new RuntimeException("Maintenance record not found.");
    }

    $now = date("Y-m-d H:i:s");
    $update = database()->prepare(
        "UPDATE maintenance_records SET status = ?, ends_at = ?, updated_at = ? WHERE id = ?",
    );
    $update->execute([
        $status,
        in_array($status, ["completed", "cancelled"], true) ? $now : null,
        $now,
        $recordId,
    ]);

    if ($status === "in_progress") {
        database()
            ->prepare(
                "UPDATE vehicles SET availability_status = 'maintenance', updated_at = ? WHERE id = ?",
            )
            ->execute([$now, (int) $record["vehicle_id"]]);
    } elseif (in_array($status, ["completed", "cancelled"], true)) {
        database()
            ->prepare(
                "UPDATE vehicles SET availability_status = 'available', updated_at = ? WHERE id = ?",
            )
            ->execute([$now, (int) $record["vehicle_id"]]);
        sync_vehicle_status((int) $record["vehicle_id"]);
    }

    write_audit("maintenance_status_updated", "maintenance_record", $recordId, [
        "status" => $status,
    ]);
}

/** Return maintenance records for the admin maintenance page. */
function maintenance_records(): array
{
    return database()
        ->query(
            'SELECT m.*, v.name AS vehicle_name, u.name AS creator_name
             FROM maintenance_records m
             JOIN vehicles v ON v.id = m.vehicle_id
             JOIN users u ON u.id = m.created_by
             ORDER BY m.created_at DESC',
        )
        ->fetchAll();
}

/** Moderate a verified-trip review. */
function moderate_review(int $reviewId, string $status): void
{
    if (!in_array($status, ["pending", "approved", "rejected"], true)) {
        throw new InvalidArgumentException("Choose a valid review decision.");
    }
    $statement = database()->prepare(
        "SELECT r.id, r.status, r.user_id, r.booking_id, b.reference FROM reviews r JOIN bookings b ON b.id = r.booking_id WHERE r.id = ? LIMIT 1",
    );
    $statement->execute([$reviewId]);
    $review = $statement->fetch();
    if (!$review) {
        throw new RuntimeException("Review not found.");
    }
    $update = database()->prepare(
        "UPDATE reviews SET status = ?, updated_at = ? WHERE id = ?",
    );
    $update->execute([$status, date("Y-m-d H:i:s"), $reviewId]);
    notify_user(
        (int) $review["user_id"],
        "Review " . $status,
        "Your review for booking " . $review["reference"] . " was marked " . $status . ".",
        "review",
        (int) $review["booking_id"],
    );
    write_audit("review_moderated", "review", $reviewId, [
        "from" => $review["status"],
        "to" => $status,
    ]);
}

/** Return reviews for admin moderation. */
function admin_reviews(string $status = "pending"): array
{
    $sql =
        "SELECT r.*, u.name AS customer_name, u.email AS customer_email, v.name AS vehicle_name, b.reference FROM reviews r JOIN users u ON u.id=r.user_id JOIN vehicles v ON v.id=r.vehicle_id JOIN bookings b ON b.id=r.booking_id";
    $parameters = [];
    if ($status !== "all") {
        $sql .= " WHERE r.status = ?";
        $parameters[] = $status;
    }
    $sql .= " ORDER BY r.created_at DESC";
    $statement = database()->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchAll();
}

/** Return photos belonging to one review. */
function review_photos_for_review(int $reviewId): array
{
    $statement = database()->prepare(
        "SELECT id, filename FROM review_photos WHERE review_id = ? ORDER BY id",
    );
    $statement->execute([$reviewId]);
    return $statement->fetchAll();
}

/** Return completed, settled bookings that have not yet been reviewed. */
function reviewable_bookings_for_user(int $userId): array
{
    $statement = database()->prepare(
        "SELECT b.reference, b.id, b.vehicle_id, v.name AS vehicle_name, v.slug AS vehicle_slug,
                v.image AS vehicle_image, v.description AS vehicle_description
         FROM bookings b
         JOIN vehicles v ON v.id = b.vehicle_id
         JOIN rental_settlements s ON s.booking_id = b.id AND s.status = 'settled'
         LEFT JOIN reviews r ON r.booking_id = b.id
         WHERE b.user_id = ? AND b.status = 'completed' AND r.id IS NULL
         ORDER BY b.completed_at DESC, b.updated_at DESC",
    );
    $statement->execute([$userId]);
    return $statement->fetchAll();
}
