<?php

declare(strict_types=1);

/**
 * FILE: actions/secure-file.php
 * FILE PURPOSE: Protected endpoint for serving private uploaded files.
 * USED BY: Customer/admin document and payment-proof views.
 * RESPONSIBILITY: Authorizes the requester, resolves the requested private file through services, and streams it without exposing storage directly.
 *
 * Maintenance note: Keep this file focused on the responsibility described above.
 */
require dirname(__DIR__) . "/includes/bootstrap.php";
$user = require_auth();
$type = trim((string) ($_GET["type"] ?? ""));
$id = filter_var($_GET["id"] ?? null, FILTER_VALIDATE_INT);

if (!$id || !in_array($type, ["document", "payment"], true)) {
    http_response_code(404);
    exit("File not found.");
}
if ($type === "document") {
    $record = document_file_record((int) $id);
    $directory =
        ROOT .
        DIRECTORY_SEPARATOR .
        "storage" .
        DIRECTORY_SEPARATOR .
        "documents";
} else {
    $record = payment_proof_record((int) $id);
    $directory =
        ROOT .
        DIRECTORY_SEPARATOR .
        "storage" .
        DIRECTORY_SEPARATOR .
        "payment-proofs";
}

$isOwner = $record && (int) $record["user_id"] === (int) $user["id"];
$isAdmin = $user["role"] === "admin";
$hasSafeFilename =
    $record &&
    basename((string) $record["filename"]) === (string) $record["filename"];

if (!$record || (!$isAdmin && !$isOwner) || !$hasSafeFilename) {
    http_response_code(404);
    exit("File not found.");
}

$filename = (string) $record["filename"];
$path = $directory . DIRECTORY_SEPARATOR . $filename;
if (!is_file($path)) {
    http_response_code(404);
    exit("File not found.");
}

$mime =
    (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: "application/octet-stream";
header("Content-Type: " . $mime);
header("Content-Length: " . filesize($path));
header('Content-Disposition: inline; filename="' . basename($filename) . '"');
header("Cache-Control: private, no-store, max-age=0");
readfile($path);
exit();
