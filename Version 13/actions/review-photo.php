<?php

declare(strict_types=1);
require dirname(__DIR__) . "/includes/bootstrap.php";
$photoId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);
if (!$photoId) {
    http_response_code(404);
    exit();
}
$sql =
    "SELECT rp.filename FROM review_photos rp JOIN reviews r ON r.id = rp.review_id WHERE rp.id = ?";
if (!admin()) {
    $sql .= " AND r.status = 'approved'";
}

$statement = database()->prepare($sql . " LIMIT 1");
$statement->execute([$photoId]);
$filename = $statement->fetchColumn();
$path = $filename
    ? ROOT . "/storage/reviews/" . basename((string) $filename)
    : "";

if (!$filename || !is_file($path)) {
    http_response_code(404);
    exit();
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
if (!in_array($mime, ["image/jpeg", "image/png", "image/webp"], true)) {
    http_response_code(404);
    exit();
}
header("Content-Type: " . $mime);
header("Content-Length: " . filesize($path));
header(
    "Cache-Control: " .
        (admin() ? "private, no-store" : "public, max-age=86400"),
);
readfile($path);
exit();
