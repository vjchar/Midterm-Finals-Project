<?php

declare(strict_types=1);

/**
 * Development router for PHP's built-in server.
 *
 * XAMPP/Apache uses .htaccess for the same public-to-physical route mapping.
 * Run locally with: php -S 127.0.0.1:8765 router.php
 */

$projectRoot = __DIR__;
$requestPath = rawurldecode(
    (string) parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH),
);
$requestedRoute = trim(str_replace("\\", "/", $requestPath), "/");

if ($requestedRoute === "") {
    $requestedRoute = "index.php";
}

if (str_contains($requestedRoute, "..")) {
    http_response_code(400);
    exit("Invalid request path.");
}

$routeSegments = array_values(
    array_filter(
        explode("/", $requestedRoute),
        static fn(string $segment): bool => $segment !== "",
    ),
);
$normalizedRouteSegments = array_map("strtolower", $routeSegments);
$protectedDirectories = [
    "includes",
    "storage",
    "database",
    "docs",
    "tests",
    ".git",
];
$isProtectedDirectory = in_array(
    $normalizedRouteSegments[0] ?? "",
    $protectedDirectories,
    true,
);
$containsDotfile = false;
foreach ($routeSegments as $routeSegment) {
    if (str_starts_with($routeSegment, ".")) {
        $containsDotfile = true;
        break;
    }
}
$isProtectedProjectDocument =
    preg_match(
        '/^(?:readme|rubric)(?:$|[._-])/i',
        basename($requestedRoute),
    ) === 1;

// Reject private implementation and project files before considering whether
// they exist. This prevents the static-file fallback from exposing them.
if ($isProtectedDirectory || $containsDotfile || $isProtectedProjectDocument) {
    http_response_code(403);
    header("Content-Type: text/plain; charset=UTF-8");
    exit("Forbidden.");
}

$routeGroups = [
    "pages/company" => [
        "about.php",
        "contact.php",
        "services.php",
        "faq.php",
        "terms.php",
        "privacy.php",
    ],
    "pages/fleet" => [
        "vehicles.php",
        "vehicle-details.php",
        "compare.php",
        "favorites.php",
    ],
    "pages/booking" => [
        "booking.php",
        "booking-confirmation.php",
        "booking-view.php",
        "manage-booking.php",
        "rate-trip.php",
        "invoice.php",
        "rental-adjustment.php",
    ],
    "pages/account" => [
        "account.php",
        "profile.php",
        "my-bookings.php",
        "documents.php",
        "payments.php",
        "notifications.php",
        "saved-bookings.php",
    ],
    "pages/auth" => [
        "login.php",
        "register.php",
        "forgot-password.php",
        "reset-password.php",
        "logout.php",
        "admin-login.php",
    ],
    "pages/admin" => [
        "admin.php",
        "admin-addons.php",
        "admin-bookings.php",
        "admin-documents.php",
        "admin-maintenance.php",
        "admin-messages.php",
        "admin-payments.php",
        "admin-promos.php",
        "admin-rentals.php",
        "admin-reports.php",
        "admin-reviews.php",
        "admin-users.php",
        "admin-vehicles.php",
        "health.php",
    ],
    "pages/system" => ["setup.php"],
    "actions" => [
        "availability.php",
        "booking-estimate.php",
        "favorite-action.php",
        "fleet-availability.php",
        "promo-check.php",
        "review-photo.php",
        "secure-file.php",
    ],
    "errors" => ["403.php", "404.php", "500.php"],
];

$routeMap = ["index.php" => "index.php"];
foreach ($routeGroups as $routeDirectory => $routeFilenames) {
    foreach ($routeFilenames as $routeFilename) {
        $routeMap[$routeFilename] = $routeDirectory . "/" . $routeFilename;
    }
}


// Backward-compatible public aliases retained from Versions 3–8.
$routeMap["vehicle-management.php"] = "pages/admin/admin-vehicles.php";
$routeMap["booking-management.php"] = "pages/admin/admin-bookings.php";
$routeMap["payment-management.php"] = "pages/admin/admin-payments.php";
$routeMap["review-management.php"] = "pages/admin/admin-reviews.php";

$physicalRequest =
    $projectRoot .
    DIRECTORY_SEPARATOR .
    str_replace("/", DIRECTORY_SEPARATOR, $requestedRoute);
if (
    is_file($physicalRequest) &&
    strtolower(pathinfo($physicalRequest, PATHINFO_EXTENSION)) !== "php"
) {
    return false;
}

$requestScheme =
    !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off" ? "https" : "http";
$requestHost = $_SERVER["HTTP_HOST"] ?? "127.0.0.1:8765";
defined("APP_URL") ||
    define("APP_URL", $requestScheme . "://" . $requestHost);

if (isset($routeMap[$requestedRoute])) {
    $routeTargetPath =
        $projectRoot .
        DIRECTORY_SEPARATOR .
        str_replace(
            "/",
            DIRECTORY_SEPARATOR,
            $routeMap[$requestedRoute],
        );
    $_SERVER["PHP_SELF"] = "/" . $requestedRoute;
    $_SERVER["SCRIPT_NAME"] = "/" . $requestedRoute;
    $_SERVER["SCRIPT_FILENAME"] = $routeTargetPath;
    require $routeTargetPath;
    return true;
}

http_response_code(404);
$_SERVER["PHP_SELF"] = "/404.php";
$_SERVER["SCRIPT_NAME"] = "/404.php";
require $projectRoot .
    DIRECTORY_SEPARATOR .
    "errors" .
    DIRECTORY_SEPARATOR .
    "404.php";
return true;
