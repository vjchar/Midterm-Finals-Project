<?php

declare(strict_types=1);

require dirname(__DIR__) . "/includes/bootstrap.php";
$user = require_auth();
$type = trim((string) ($_GET["type"] ?? ""));
$id = filter_var($_GET["id"] ?? null, FILTER_VALIDATE_INT);

if (!$id || $type !== "payment") {
    http_response_code(404);
    exit("File not found.");
}

$statement = database()->prepare(
    "SELECT user_id, proof_filename AS filename FROM payments WHERE id = ? LIMIT 1",
);
$statement->execute([$id]);
$record = $statement->fetch();
$isOwner = $record && (int) $record["user_id"] === (int) $user["id"];
$isAdmin = ($user["role"] ?? "") === "admin";
$hasSafeFilename = $record && basename((string) $record["filename"]) === (string) $record["filename"];

if (!$record || (!$isAdmin && !$isOwner) || !$hasSafeFilename || $record["filename"] === "") {
    http_response_code(404);
    exit("File not found.");
}

$path = ROOT . DIRECTORY_SEPARATOR . "storage" . DIRECTORY_SEPARATOR . "payment-proofs" . DIRECTORY_SEPARATOR . $record["filename"];
if (!is_file($path)) {
    http_response_code(404);
    exit("File not found.");
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: "application/octet-stream";
if (!in_array($mime, ["image/jpeg", "image/png", "application/pdf"], true)) {
    http_response_code(404);
    exit("File not found.");
}
header("Content-Type: " . $mime);
header("Content-Length: " . filesize($path));
header('Content-Disposition: inline; filename="' . basename((string) $record["filename"]) . '"');
header("Cache-Control: private, no-store, max-age=0");
readfile($path);
exit();
