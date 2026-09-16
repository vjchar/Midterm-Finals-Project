<?php

declare(strict_types=1);


/**
 * FILE: includes/notification-service.php
 * FILE PURPOSE: Notifications, contact messages, and administrative enquiry workflow service.
 * USED BY: Customer notifications, contact page, and admin messages page.
 * RESPONSIBILITY: Stores and retrieves notifications/contact enquiries and centralizes their workflow outside presentation pages.
 *
 * Maintenance note: Keep this file focused on the responsibility described above.
 */
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
 * Create the same operational notification for every active administrator.
 * This keeps Version 12 on one notification table/service while exposing the
 * operational notification center used by administrators.
 */
function notify_admins(
    string $title,
    string $message,
    string $type = "system",
    ?int $bookingId = null,
): void {
    $statement = database()->query(
        "SELECT id FROM users WHERE role = 'admin' AND status = 'active'",
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

/****************************************************************************
 * CONTACT ENQUIRIES
 ****************************************************************************/

/** Create one validated customer/contact enquiry. */
function create_contact_message(array $input): int
{
    if (trim((string) ($input["website"] ?? "")) !== "") {
        throw new RuntimeException("Message rejected.");
    }

    $name = trim((string) ($input["name"] ?? ""));
    $email = strtolower(trim((string) ($input["email"] ?? "")));
    $phone = trim((string) ($input["phone"] ?? ""));
    $subject = trim((string) ($input["subject"] ?? ""));
    $message = trim((string) ($input["message"] ?? ""));
    $allowedSubjects = [
        "New booking",
        "Vehicle information",
        "Corporate rental",
        "Existing rental support",
        "Other inquiry",
    ];

    if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
        throw new InvalidArgumentException("Enter your full name.");
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException("Enter a valid email address.");
    }
    if (!in_array($subject, $allowedSubjects, true)) {
        throw new InvalidArgumentException("Choose a valid support topic.");
    }
    if (mb_strlen($message) < 20 || mb_strlen($message) > 5000) {
        throw new InvalidArgumentException(
            "Enter a message between 20 and 5,000 characters.",
        );
    }

    $now = date("Y-m-d H:i:s");
    $statement = database()->prepare(
        "INSERT INTO contact_messages (name, email, phone, subject, message, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
    );
    $statement->execute([
        $name,
        $email,
        $phone,
        $subject,
        $message,
        "new",
        $now,
        $now,
    ]);
    $messageId = (int) database()->lastInsertId();
    write_audit("contact_message_created", "contact_message", $messageId);
    return $messageId;
}

/** Update the workflow status of a contact enquiry. */
function update_contact_message_status(int $messageId, string $status): void
{
    if (!in_array($status, ["new", "read", "closed"], true)) {
        throw new InvalidArgumentException("Choose a valid message status.");
    }
    $statement = database()->prepare(
        "UPDATE contact_messages SET status = ?, updated_at = ? WHERE id = ?",
    );
    $statement->execute([$status, date("Y-m-d H:i:s"), $messageId]);
    if (!$statement->rowCount()) {
        throw new RuntimeException("Contact message not found.");
    }
    write_audit("contact_message_updated", "contact_message", $messageId, [
        "status" => $status,
    ]);
}

/** Return contact enquiries for the administration inbox. */
function contact_messages(string $status = "new"): array
{
    $sql = "SELECT * FROM contact_messages";
    $parameters = [];
    if ($status !== "all") {
        $sql .= " WHERE status = ?";
        $parameters[] = $status;
    }
    $sql .= " ORDER BY created_at DESC";
    $statement = database()->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchAll();
}
