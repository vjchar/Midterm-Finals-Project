<?php

declare(strict_types=1);
require dirname(__DIR__) . "/includes/bootstrap.php";
$user = require_auth();
$type = trim((string) ($_GET["type"] ?? ""));
$id = filter_var($_GET["id"] ?? null, FILTER_VALIDATE_INT);

if (!$id || !in_array($type, ["document", "payment"], true)) {
    http_response_code(404);
    exit("File not found.");
}
if ($type === "document") {
    $statement = database()->prepare(
        "SELECT user_id, filename FROM customer_documents WHERE id = ? LIMIT 1",
    );
    $directory =
        ROOT .
        DIRECTORY_SEPARATOR .
        "storage" .
        DIRECTORY_SEPARATOR .
        "documents";
} else {
    $statement = database()->prepare(
        "SELECT user_id, proof_filename AS filename FROM payments WHERE id = ? LIMIT 1",
    );
    $directory =
        ROOT .
        DIRECTORY_SEPARATOR .
        "storage" .
        DIRECTORY_SEPARATOR .
        "payment-proofs";
}

$statement->execute([$id]);
$record = $statement->fetch();

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
