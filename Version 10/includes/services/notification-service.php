<?php

declare(strict_types=1);

/**
 * Create an in-application notification for a customer.
 */
function notify_user(
    int $userId,
    string $title,
    string $message,
    string $type = "system",
    ?int $bookingId = null,
): void {
    $statement = database()->prepare(
        "INSERT INTO notifications (user_id, booking_id, type, title, message, is_read, created_at) VALUES (?, ?, ?, ?, ?, 0, ?)",
    );
    $statement->execute([
        $userId,
        $bookingId,
        mb_substr($type, 0, 40),
        mb_substr($title, 0, 160),
        $message,
        date("Y-m-d H:i:s"),
    ]);
}

/**
 * Count unread notifications belonging to one customer.
 */
/**
 * Create the same Final-derived notification for every active administrator.
 * This keeps Version 10 on one notification table/service while exposing the
 * operational notification center required by the final milestone.
 */
function notify_admins(
    string $title,
    string $message,
    string $type = "system",
    ?int $bookingId = null,
): void {
    $statement = database()->query(
        "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'admin' AND u.status = 'active'",
    );
    foreach ($statement->fetchAll() as $administrator) {
        notify_user(
            (int) $administrator["id"],
            $title,
            $message,
            $type,
            $bookingId,
        );
    }
}

function unread_notification_count(int $userId): int
{
    $statement = database()->prepare(
        "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0",
    );
    $statement->execute([$userId]);
    return (int) $statement->fetchColumn();
}

/**
 * @return list<array{
 *     id: int|string,
 *     user_id: int|string,
 *     booking_id: int|string|null,
 *     type: string,
 *     title: string,
 *     message: string,
 *     is_read: int|string,
 *     created_at: string,
 *     booking_reference: string|null
 * }>
 */
function notifications_for_user(int $userId, int $limit = 50): array
{
    $statement = database()->prepare(
        'SELECT n.*, b.reference AS booking_reference FROM notifications n
         LEFT JOIN bookings b ON b.id = n.booking_id WHERE n.user_id = ?
         ORDER BY n.created_at DESC LIMIT ?',
    );
    $statement->bindValue(1, $userId, PDO::PARAM_INT);
    $statement->bindValue(2, $limit, PDO::PARAM_INT);
    $statement->execute();
    return $statement->fetchAll();
}

/**
 * Mark every unread notification for one customer as read.
 */
function mark_notifications_read(int $userId): void
{
    $statement = database()->prepare(
        "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0",
    );
    $statement->execute([$userId]);
}
