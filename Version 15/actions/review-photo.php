<?php

declare(strict_types=1);

/**
 * FILE: actions/review-photo.php
 * FILE PURPOSE: Protected endpoint for serving customer review photos.
 * USED BY: Review displays that need an uploaded review image.
 * RESPONSIBILITY: Checks access/request data and delegates review-photo lookup before streaming the image safely.
 *
 * Maintenance note: Keep this file focused on the responsibility described above.
 */
require dirname(__DIR__) . "/includes/bootstrap.php";
$photoId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);
if (!$photoId) {
    http_response_code(404);
    exit();
}
$filename = review_photo_filename((int) $photoId, admin());
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
