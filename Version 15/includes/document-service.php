<?php

declare(strict_types=1);


/**
 * FILE: includes/document-service.php
 * FILE PURPOSE: Customer document and protected-document service.
 * USED BY: Account document pages, admin document review, and secure-file delivery.
 * RESPONSIBILITY: Handles document listing, upload metadata, verification workflow, and authorized lookup of protected customer documents.
 *
 * Maintenance note: Keep this file focused on the responsibility described above.
 */
/**
 * Return a customer's submitted rental documents keyed by document type.
 *
 * @return array<string, array{
 *     id: int|string,
 *     user_id: int|string,
 *     document_type: string,
 *     document_number: string,
 *     expiry_date: string|null,
 *     filename: string,
 *     status: string,
 *     admin_notes: string|null,
 *     verified_by: int|string|null,
 *     verified_at: string|null,
 *     created_at: string,
 *     updated_at: string,
 *     verifier_name: string|null
 * }>
 */
function customer_documents(int $userId): array
{
    $statement = database()->prepare(
        'SELECT d.*, verifier.name AS verifier_name FROM customer_documents d
         LEFT JOIN users verifier ON verifier.id = d.verified_by
         WHERE d.user_id = ? ORDER BY d.document_type',
    );
    $statement->execute([$userId]);
    $documents = [];
    foreach ($statement->fetchAll() as $document) {
        $documents[$document["document_type"]] = $document;
    }
    return $documents;
}

/**
 * Create or replace one required document submission for a customer.
 */
function upsert_customer_document(
    int $userId,
    string $type,
    string $number,
    ?string $expiryDate,
    string $filename,
): void {
    if (!in_array($type, ["drivers_license", "government_id"], true)) {
        throw new InvalidArgumentException("Choose a valid document type.");
    }
    $number = trim($number);
    if (mb_strlen($number) < 4 || mb_strlen($number) > 120) {
        throw new InvalidArgumentException("Enter a valid document number.");
    }
    if ($type === "drivers_license") {
        if (
            !$expiryDate ||
            !valid_date($expiryDate) ||
            new DateTimeImmutable($expiryDate) <= new DateTimeImmutable("today")
        ) {
            throw new InvalidArgumentException(
                "The driver’s license must have a future expiry date.",
            );
        }
    } else {
        $expiryDate = null;
    }
    $currentTimestamp = date("Y-m-d H:i:s");
    $statement = database()->prepare(
        "INSERT INTO customer_documents (user_id, document_type, document_number, expiry_date, filename, status, admin_notes, verified_by, verified_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, 'pending', NULL, NULL, NULL, ?, ?)
         ON DUPLICATE KEY UPDATE document_number = VALUES(document_number), expiry_date = VALUES(expiry_date),
         filename = VALUES(filename), status = 'pending', admin_notes = NULL, verified_by = NULL,
         verified_at = NULL, updated_at = VALUES(updated_at)",
    );
    $statement->execute([
        $userId,
        $type,
        $number,
        $expiryDate,
        $filename,
        $currentTimestamp,
        $currentTimestamp,
    ]);
    $updatedDocuments = customer_documents($userId);
    $allSubmitted = isset($updatedDocuments["drivers_license"], $updatedDocuments["government_id"]);
    $contextBooking = latest_actionable_booking_for_user($userId);
    notify_user(
        $userId,
        $allSubmitted ? "Documents Submitted — Verification Pending" : "Document Submitted — Continue Uploads",
        $allSubmitted
            ? "Your required documents have been submitted successfully and are awaiting verification. No action is required unless our team requests an update."
            : "Your " . ($type === "drivers_license" ? "driver’s license" : "government ID") . " was submitted. Upload the remaining required document to continue.",
        "document",
        $contextBooking ? (int) $contextBooking["id"] : null,
    );
    write_audit("customer_document_submitted", "customer_document", null, [
        "type" => $type,
    ]);
}

/**
 * Record an administrator's approval or rejection of a customer document.
 */
function review_customer_document(
    int $documentId,
    string $status,
    string $notes,
    int $adminId,
): void {
    if (!in_array($status, ["approved", "rejected"], true)) {
        throw new InvalidArgumentException("Choose approve or reject.");
    }
    $select = database()->prepare(
        "SELECT * FROM customer_documents WHERE id = ? LIMIT 1",
    );
    $select->execute([$documentId]);
    $document = $select->fetch();
    if (!$document) {
        throw new RuntimeException("Document not found.");
    }
    $currentTimestamp = date("Y-m-d H:i:s");
    $update = database()->prepare(
        "UPDATE customer_documents SET status = ?, admin_notes = ?, verified_by = ?, verified_at = ?, updated_at = ? WHERE id = ?",
    );
    $update->execute([
        $status,
        mb_substr(trim($notes), 0, 2000),
        $adminId,
        $currentTimestamp,
        $currentTimestamp,
        $documentId,
    ]);
    $contextBooking = latest_actionable_booking_for_user((int) $document["user_id"]);
    if ($status === "approved") {
        foreach (bookings_for_user((int) $document["user_id"]) as $candidateBooking) {
            if (($candidateBooking["status"] ?? "") !== "pending") {
                continue;
            }
            $fullCandidate = booking_find_by_reference((string) $candidateBooking["reference"]);
            if ($fullCandidate) {
                confirm_booking_when_requirements_complete($fullCandidate, $adminId);
            }
        }
        if ($contextBooking) {
            $contextBooking = booking_find_by_reference((string) $contextBooking["reference"]);
        }
    }
    if ($status === "rejected") {
        $notificationTitle = "Document Update Required";
        $notificationMessage = "Your " . str_replace("_", " ", $document["document_type"]) . " could not be verified. Replace the document to continue your booking.";
    } else {
        $notificationTitle = "Document Verified";
        $notificationMessage = "Your " . str_replace("_", " ", $document["document_type"]) . " was verified successfully.";
        if ($contextBooking) {
            $next = booking_next_step($contextBooking);
            if ($next["stage"] === "verification") {
                $notificationMessage .= " Your booking requirements are still being verified.";
            } elseif ($next["stage"] === "preparing") {
                $notificationMessage .= " Your requirements are verified and the vehicle is being prepared.";
            }
        }
    }
    notify_user(
        (int) $document["user_id"],
        $notificationTitle,
        $notificationMessage,
        "document",
        $contextBooking ? (int) $contextBooking["id"] : null,
    );
    write_audit(
        "customer_document_" . $status,
        "customer_document",
        $documentId,
    );
}

/****************************************************************************
 * ADMIN DOCUMENT LISTS AND SECURE LOOKUP
 ****************************************************************************/

/** Return customer documents for the administration verification queue. */
function admin_documents(string $status = "all"): array
{
    $sql =
        "SELECT d.*, u.name AS customer_name, u.email AS customer_email,
                verifier.name AS verifier_name
         FROM customer_documents d
         JOIN users u ON u.id = d.user_id
         LEFT JOIN users verifier ON verifier.id = d.verified_by";
    $parameters = [];
    if ($status !== "all") {
        $sql .= " WHERE d.status = ?";
        $parameters[] = $status;
    }
    $sql .=
        " ORDER BY CASE d.status WHEN 'pending' THEN 0 ELSE 1 END, d.updated_at DESC";
    $statement = database()->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchAll();
}

/** Return ownership and filename information for a stored customer document. */
function document_file_record(int $documentId): ?array
{
    $statement = database()->prepare(
        "SELECT user_id, filename FROM customer_documents WHERE id = ? LIMIT 1",
    );
    $statement->execute([$documentId]);
    $record = $statement->fetch();
    return $record ?: null;
}
