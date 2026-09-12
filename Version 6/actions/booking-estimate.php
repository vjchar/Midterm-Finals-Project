<?php

declare(strict_types=1);

require dirname(__DIR__) . "/includes/bootstrap.php";

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store");

$respondWithJson = static function (
    array $response,
    int $statusCode = 200,
): never {
    http_response_code($statusCode);
    echo json_encode(
        $response,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );
    exit();
};

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Allow: POST");
    $respondWithJson(
        ["ok" => false, "message" => "This endpoint accepts POST requests only."],
        405,
    );
}

if (!current_user()) {
    $respondWithJson(
        ["ok" => false, "message" => "Sign in to calculate a booking estimate."],
        401,
    );
}

if (!verify_csrf()) {
    $respondWithJson(
        [
            "ok" => false,
            "message" => "Your session expired. Refresh the page and try again.",
        ],
        419,
    );
}

try {
    $bookingEstimate = parse_booking_input($_POST);
    $selectedVehicle = $bookingEstimate["vehicle"];

    if (
        !vehicle_available(
            (int) $selectedVehicle["id"],
            (string) $bookingEstimate["pickup_at"],
            (string) $bookingEstimate["return_at"],
        )
    ) {
        throw new RuntimeException(
            "This vehicle is already reserved during part of the selected schedule.",
        );
    }

    $respondWithJson([
        "ok" => true,
        "message" =>
            "Available for the selected schedule. PHP verified this estimate on the server.",
        "estimate" => [
            "days" => (int) $bookingEstimate["days"],
            "subtotal" => (int) $bookingEstimate["subtotal"],
            "addons_total" => 0,
            "delivery_fee" => 0,
            "discount" => 0,
            "deposit" => 0,
            "total" => (int) $bookingEstimate["total"],
            "formatted" => [
                "subtotal" => money((int) $bookingEstimate["subtotal"]),
                "addons_total" => money(0),
                "delivery_fee" => money(0),
                "discount" => money(0),
                "deposit" => money(0),
                "total" => money((int) $bookingEstimate["total"]),
            ],
        ],
    ]);
} catch (InvalidArgumentException | RuntimeException $validationError) {
    $respondWithJson(
        ["ok" => false, "message" => user_facing_error_message($validationError)],
        422,
    );
} catch (Throwable $unexpectedError) {
    $respondWithJson(
        ["ok" => false, "message" => user_facing_error_message($unexpectedError)],
        500,
    );
}
