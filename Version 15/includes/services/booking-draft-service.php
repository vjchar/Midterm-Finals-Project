<?php

declare(strict_types=1);

/**
 * Generate a human-readable reference for a saved booking draft.
 */
function booking_draft_reference(): string
{
    return "DRAFT-" . date("ymd") . "-" . strtoupper(bin2hex(random_bytes(3)));
}

/**
 * Mark stale active drafts as expired for one customer.
 */
function expire_booking_drafts_for_user(int $userId): void
{
    $statement = database()->prepare(
        "SELECT id FROM booking_drafts WHERE user_id = ? AND status = 'active' AND expires_at <= ?",
    );
    $now = date("Y-m-d H:i:s");
    $statement->execute([$userId, $now]);
    $expiredIds = array_map('intval', array_column($statement->fetchAll(), 'id'));
    if (!$expiredIds) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($expiredIds), '?'));
    $update = database()->prepare(
        "UPDATE booking_drafts SET status = 'expired', updated_at = ? WHERE user_id = ? AND status = 'active' AND id IN ({$placeholders})",
    );
    $update->execute(array_merge([$now, $userId], $expiredIds));

    foreach ($expiredIds as $draftId) {
        write_audit('booking_draft_expired', 'booking_draft', $draftId);
    }
}

/**
 * Fetch one draft owned by the authenticated customer.
 */
function booking_draft_find_owned(int $draftId, int $userId, bool $includeInactive = true): ?array
{
    expire_booking_drafts_for_user($userId);
    $sql = "SELECT d.*, v.slug AS vehicle_slug, v.name AS vehicle_name, v.image AS vehicle_image,
                   v.category AS vehicle_category, v.price AS current_daily_price, v.deposit AS current_deposit
            FROM booking_drafts d
            JOIN vehicles v ON v.id = d.vehicle_id
            WHERE d.id = ? AND d.user_id = ?";
    if (!$includeInactive) {
        $sql .= " AND d.status = 'active'";
    }
    $sql .= " LIMIT 1";
    $statement = database()->prepare($sql);
    $statement->execute([$draftId, $userId]);
    $draft = $statement->fetch();
    if (!$draft) {
        return null;
    }

    foreach (
        [
            'id', 'user_id', 'vehicle_id', 'estimated_subtotal',
            'estimated_addons_total', 'estimated_delivery_fee',
            'estimated_discount', 'estimated_total', 'estimated_deposit',
        ] as $integerField
    ) {
        $draft[$integerField] = (int) ($draft[$integerField] ?? 0);
    }

    $addonStatement = database()->prepare(
        "SELECT da.*, a.addon_key FROM booking_draft_addons da
         LEFT JOIN addons a ON a.id = da.addon_id
         WHERE da.draft_id = ? ORDER BY da.id",
    );
    $addonStatement->execute([$draftId]);
    $draft['addons'] = $addonStatement->fetchAll();
    return $draft;
}

/**
 * Fetch active drafts for the customer, newest first.
 *
 * @return list<array<string,mixed>>
 */
function booking_drafts_for_user(int $userId): array
{
    expire_booking_drafts_for_user($userId);
    $statement = database()->prepare(
        "SELECT d.*, v.slug AS vehicle_slug, v.name AS vehicle_name, v.image AS vehicle_image,
                v.category AS vehicle_category, v.price AS current_daily_price
         FROM booking_drafts d
         JOIN vehicles v ON v.id = d.vehicle_id
         WHERE d.user_id = ? AND d.status = 'active'
         ORDER BY d.updated_at DESC",
    );
    $statement->execute([$userId]);
    return $statement->fetchAll();
}

/**
 * Convert a saved draft into the raw booking input accepted by the trusted
 * Version 12 pricing/validation service.
 */
function booking_draft_raw_input(array $draft, ?string $promoOverride = null): array
{
    $pickup = new DateTimeImmutable((string) $draft['pickup_at']);
    $return = new DateTimeImmutable((string) $draft['return_at']);
    $addonKeys = [];
    foreach ($draft['addons'] ?? [] as $addon) {
        if (!empty($addon['addon_key'])) {
            $addonKeys[] = (string) $addon['addon_key'];
        }
    }

    return [
        'vehicle' => (string) $draft['vehicle_slug'],
        'pickup' => $pickup->format('Y-m-d'),
        'return' => $return->format('Y-m-d'),
        'pickup_time' => $pickup->format('H:i'),
        'return_time' => $return->format('H:i'),
        'pickup_method' => (string) $draft['pickup_method'],
        'location' => (string) $draft['pickup_location'],
        'delivery_address' => (string) ($draft['delivery_address'] ?? ''),
        'addons' => $addonKeys,
        'promo' => $promoOverride ?? (string) ($draft['promo_code'] ?? ''),
        'special_requests' => (string) ($draft['special_requests'] ?? ''),
    ];
}

/**
 * Return form values for resuming/editing a draft.
 */
function booking_draft_form_values(array $draft): array
{
    return booking_draft_raw_input($draft);
}

/**
 * Recalculate a draft with the current trusted pricing and availability rules.
 * An expired promo is removed for the preview and reported to the customer.
 *
 * @return array{details:?array,available:bool,error:string,promo_warning:string,current_total:int,saved_total:int,difference:int,effective_promo_code:string}
 */
function booking_draft_preview(array $draft): array
{
    $promoWarning = '';
    $effectivePromo = (string) ($draft['promo_code'] ?? '');
    try {
        $details = parse_booking_input(booking_draft_raw_input($draft));
    } catch (InvalidArgumentException $error) {
        if ($effectivePromo !== '' && str_contains(strtolower($error->getMessage()), 'promotion code')) {
            $promoWarning = 'The promotion saved with this booking is no longer available. The current price has been recalculated without it.';
            $effectivePromo = '';
            try {
                $details = parse_booking_input(booking_draft_raw_input($draft, ''));
            } catch (Throwable $retryError) {
                return [
                    'details' => null,
                    'available' => false,
                    'error' => user_facing_error_message($retryError),
                    'promo_warning' => $promoWarning,
                    'current_total' => 0,
                    'saved_total' => (int) $draft['estimated_total'],
                    'difference' => -(int) $draft['estimated_total'],
                    'effective_promo_code' => '',
                ];
            }
        } else {
            return [
                'details' => null,
                'available' => false,
                'error' => user_facing_error_message($error),
                'promo_warning' => '',
                'current_total' => 0,
                'saved_total' => (int) $draft['estimated_total'],
                'difference' => -(int) $draft['estimated_total'],
                'effective_promo_code' => $effectivePromo,
            ];
        }
    }

    $available = vehicle_available(
        (int) $details['vehicle']['id'],
        (string) $details['pickup_at'],
        (string) $details['return_at'],
    );
    $currentTotal = (int) $details['total'];
    $savedTotal = (int) $draft['estimated_total'];
    return [
        'details' => $details,
        'available' => $available,
        'error' => $available ? '' : 'This vehicle is no longer available for your saved dates.',
        'promo_warning' => $promoWarning,
        'current_total' => $currentTotal,
        'saved_total' => $savedTotal,
        'difference' => $currentTotal - $savedTotal,
        'effective_promo_code' => $effectivePromo,
    ];
}

/**
 * Create or update a draft from the same server-validated booking input used by
 * a real booking. Drafts never block fleet availability and never consume promos.
 */
function save_booking_draft(int $userId, array $bookingInput, ?int $draftId = null): array
{
    expire_booking_drafts_for_user($userId);
    $details = parse_booking_input($bookingInput);
    if (!vehicle_available((int) $details['vehicle']['id'], (string) $details['pickup_at'], (string) $details['return_at'])) {
        throw new RuntimeException('This vehicle is already reserved during part of the selected schedule. Choose different dates or another vehicle before saving.');
    }

    $databaseConnection = database();
    $databaseConnection->beginTransaction();
    try {
        $now = date('Y-m-d H:i:s');
        $expiresAt = (new DateTimeImmutable($now))
            ->modify('+' . BOOKING_DRAFT_TTL_DAYS . ' days')
            ->format('Y-m-d H:i:s');
        $isNew = $draftId === null || $draftId < 1;

        if ($isNew) {
            do {
                $reference = booking_draft_reference();
                $check = $databaseConnection->prepare('SELECT COUNT(*) FROM booking_drafts WHERE reference = ?');
                $check->execute([$reference]);
            } while ((int) $check->fetchColumn() > 0);

            $statement = $databaseConnection->prepare(
                "INSERT INTO booking_drafts (
                    reference, user_id, vehicle_id, pickup_at, return_at, pickup_method,
                    pickup_location, delivery_address, promo_code, estimated_subtotal,
                    estimated_addons_total, estimated_delivery_fee, estimated_discount,
                    estimated_total, estimated_deposit, special_requests, status,
                    expires_at, last_saved_at, created_at, updated_at
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, ?)",
            );
            $statement->execute([
                $reference,
                $userId,
                $details['vehicle']['id'],
                $details['pickup_at'],
                $details['return_at'],
                $details['pickup_method'],
                $details['pickup_location'],
                $details['delivery_address'],
                $details['promo_code'],
                $details['subtotal'],
                $details['addons_total'],
                $details['delivery_fee'],
                $details['discount'],
                $details['total'],
                $details['deposit'],
                $details['special_requests'],
                $expiresAt,
                $now,
                $now,
                $now,
            ]);
            $draftId = (int) $databaseConnection->lastInsertId();
        } else {
            $lockSql = "SELECT id, status, expires_at FROM booking_drafts WHERE id = ? AND user_id = ? LIMIT 1";
            if ($databaseConnection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $lockSql .= ' FOR UPDATE';
            }
            $lock = $databaseConnection->prepare($lockSql);
            $lock->execute([$draftId, $userId]);
            $existing = $lock->fetch();
            if (!$existing) {
                throw new RuntimeException('Saved booking not found.');
            }
            if ($existing['status'] !== 'active' || strtotime((string) $existing['expires_at']) <= time()) {
                throw new RuntimeException('This saved booking has expired or is no longer editable.');
            }
            $statement = $databaseConnection->prepare(
                "UPDATE booking_drafts SET vehicle_id = ?, pickup_at = ?, return_at = ?, pickup_method = ?,
                    pickup_location = ?, delivery_address = ?, promo_code = ?, estimated_subtotal = ?,
                    estimated_addons_total = ?, estimated_delivery_fee = ?, estimated_discount = ?,
                    estimated_total = ?, estimated_deposit = ?, special_requests = ?, expires_at = ?,
                    last_saved_at = ?, updated_at = ? WHERE id = ? AND user_id = ? AND status = 'active'",
            );
            $statement->execute([
                $details['vehicle']['id'], $details['pickup_at'], $details['return_at'],
                $details['pickup_method'], $details['pickup_location'], $details['delivery_address'],
                $details['promo_code'], $details['subtotal'], $details['addons_total'],
                $details['delivery_fee'], $details['discount'], $details['total'],
                $details['deposit'], $details['special_requests'], $expiresAt, $now, $now,
                $draftId, $userId,
            ]);
            $databaseConnection->prepare('DELETE FROM booking_draft_addons WHERE draft_id = ?')->execute([$draftId]);
        }

        $addonInsert = $databaseConnection->prepare(
            "INSERT INTO booking_draft_addons (draft_id, addon_id, addon_name, unit_price, billing, quantity, line_total)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
        );
        foreach ($details['addons'] as $addon) {
            $addonInsert->execute([
                $draftId, $addon['id'], $addon['name'], $addon['price'],
                $addon['billing'], $addon['quantity'], $addon['line_total'],
            ]);
        }

        $databaseConnection->commit();
        $draft = booking_draft_find_owned((int) $draftId, $userId, true);
        if (!$draft) {
            throw new RuntimeException('The saved booking could not be reloaded.');
        }
        write_audit($isNew ? 'booking_draft_created' : 'booking_draft_updated', 'booking_draft', (int) $draftId, [
            'reference' => (string) $draft['reference'],
        ]);
        if ($isNew) {
            notify_user(
                $userId,
                'Booking Saved for Later',
                'Your booking draft ' . $draft['reference'] . ' has been saved. The vehicle is not reserved until you complete the booking.',
                'draft',
                null,
            );
        }
        return $draft;
    } catch (Throwable $error) {
        if ($databaseConnection->inTransaction()) {
            $databaseConnection->rollBack();
        }
        throw $error;
    }
}

/**
 * Discard an owned active draft without deleting its history.
 */
function discard_booking_draft(int $draftId, int $userId): void
{
    $statement = database()->prepare(
        "UPDATE booking_drafts SET status = 'discarded', updated_at = ? WHERE id = ? AND user_id = ? AND status = 'active'",
    );
    $statement->execute([date('Y-m-d H:i:s'), $draftId, $userId]);
    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('This saved booking is no longer available to discard.');
    }
    write_audit('booking_draft_discarded', 'booking_draft', $draftId);
}

/**
 * Convert one owned active draft into a real booking using the same trusted
 * booking persistence, availability lock, pricing, add-ons, and promo logic.
 */
function convert_booking_draft(int $draftId, int $userId): array
{
    expire_booking_drafts_for_user($userId);
    $databaseConnection = database();
    $databaseConnection->beginTransaction();
    try {
        $draftSql = "SELECT d.*, v.slug AS vehicle_slug FROM booking_drafts d
                     JOIN vehicles v ON v.id = d.vehicle_id
                     WHERE d.id = ? AND d.user_id = ? LIMIT 1";
        if ($databaseConnection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $draftSql .= ' FOR UPDATE';
        }
        $statement = $databaseConnection->prepare($draftSql);
        $statement->execute([$draftId, $userId]);
        $draft = $statement->fetch();
        if (!$draft) {
            throw new RuntimeException('Saved booking not found.');
        }
        if ($draft['status'] !== 'active') {
            throw new RuntimeException('Only an active saved booking can be converted.');
        }
        if (strtotime((string) $draft['expires_at']) <= time()) {
            $databaseConnection->prepare("UPDATE booking_drafts SET status='expired', updated_at=? WHERE id=?")
                ->execute([date('Y-m-d H:i:s'), $draftId]);
            throw new RuntimeException('This saved booking has expired. Start a new booking using the latest availability and pricing.');
        }

        $addonStatement = $databaseConnection->prepare(
            "SELECT da.*, a.addon_key FROM booking_draft_addons da
             LEFT JOIN addons a ON a.id = da.addon_id WHERE da.draft_id = ? ORDER BY da.id",
        );
        $addonStatement->execute([$draftId]);
        $draft['addons'] = $addonStatement->fetchAll();

        $details = parse_booking_input(booking_draft_raw_input($draft));
        $booking = persist_booking_details($databaseConnection, $userId, $details);
        $now = date('Y-m-d H:i:s');
        $update = $databaseConnection->prepare(
            "UPDATE booking_drafts SET status='converted', converted_booking_id=?, updated_at=? WHERE id=? AND user_id=? AND status='active'",
        );
        $update->execute([(int) $booking['id'], $now, $draftId, $userId]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('The saved booking changed while it was being processed. Please try again.');
        }
        $databaseConnection->commit();

        notify_user(
            $userId,
            'Booking Created — Payment Required',
            'Your saved booking was confirmed as booking ' . $booking['reference'] . '. Continue with the next unfinished requirement.',
            'booking',
            (int) $booking['id'],
        );
        notify_admins(
            'New booking received',
            'Booking ' . $booking['reference'] . ' was created from a saved customer draft.',
            'booking',
            (int) $booking['id'],
        );
        write_audit('booking_draft_converted', 'booking_draft', $draftId, [
            'booking_id' => (int) $booking['id'],
            'booking_reference' => (string) $booking['reference'],
        ]);
        write_audit('booking_created', 'booking', (int) $booking['id'], [
            'reference' => (string) $booking['reference'],
            'source' => 'booking_draft',
            'draft_id' => $draftId,
        ]);
        return $booking;
    } catch (Throwable $error) {
        if ($databaseConnection->inTransaction()) {
            $databaseConnection->rollBack();
        }
        throw $error;
    }
}
