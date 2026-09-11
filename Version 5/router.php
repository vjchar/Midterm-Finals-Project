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
    "database",
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
    ],
    "pages/fleet" => [
        "vehicles.php",
        "vehicle-details.php",
    ],
    "pages/management" => [
        "vehicle-management.php",
        "booking-management.php",
    ],
    "pages/booking" => [
        "booking.php",
        "booking-confirmation.php",
        "booking-view.php",
    ],
    "pages/account" => [
        "my-bookings.php",
    ],
    "actions" => [
        "availability.php",
        "booking-estimate.php",
    ],
    "pages/auth" => [
        "login.php",
        "register.php",
        "logout.php",
    ],
    "errors" => ["403.php", "404.php", "500.php"],
];

$routeMap = ["index.php" => "index.php"];
foreach ($routeGroups as $routeDirectory => $routeFilenames) {
    foreach ($routeFilenames as $routeFilename) {
        $routeMap[$routeFilename] = $routeDirectory . "/" . $routeFilename;
    }
}

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
