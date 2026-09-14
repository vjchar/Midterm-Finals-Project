<?php

declare(strict_types=1);

require dirname(__DIR__) . "/includes/bootstrap.php";

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store");

$respondWithJson = static function (array $response, int $statusCode = 200): never {
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
        [
            "ok" => false,
            "message" => "This endpoint accepts POST requests only.",
        ],
        405,
    );
}

$authenticatedUser = current_user();
if (!$authenticatedUser || $authenticatedUser["role"] !== "customer") {
    $respondWithJson(
        [
            "ok" => false,
            "message" => "Sign in with a customer account to calculate a booking estimate.",
        ],
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

    $vehicleIsAvailable = vehicle_available(
        (int) $selectedVehicle["id"],
        (string) $bookingEstimate["pickup_at"],
        (string) $bookingEstimate["return_at"],
    );

    if (!$vehicleIsAvailable) {
        throw new RuntimeException(
            "This vehicle is already reserved during part of the selected schedule.",
        );
    }

    $discount = (int) $bookingEstimate["discount"];
    $respondWithJson([
        "ok" => true,
        "message" =>
            "Available for the selected schedule. PHP verified this estimate on the server.",
        "estimate" => [
            "days" => (int) $bookingEstimate["days"],
            "subtotal" => (int) $bookingEstimate["subtotal"],
            "addons_total" => (int) $bookingEstimate["addons_total"],
            "delivery_fee" => (int) $bookingEstimate["delivery_fee"],
            "discount" => $discount,
            "deposit" => (int) $selectedVehicle["deposit"],
            "total" => (int) $bookingEstimate["total"],
            "formatted" => [
                "subtotal" => money((int) $bookingEstimate["subtotal"]),
                "addons_total" => money(
                    (int) $bookingEstimate["addons_total"],
                ),
                "delivery_fee" => money(
                    (int) $bookingEstimate["delivery_fee"],
                ),
                "discount" => $discount > 0 ? "−" . money($discount) : money(0),
                "deposit" => money((int) $selectedVehicle["deposit"]),
                "total" => money((int) $bookingEstimate["total"]),
            ],
        ],
    ]);
} catch (PDOException $databaseError) {
    $respondWithJson(
        [
            "ok" => false,
            "message" => user_facing_error_message($databaseError),
        ],
        500,
    );
} catch (InvalidArgumentException | RuntimeException $validationError) {
    $respondWithJson(
        [
            "ok" => false,
            "message" => user_facing_error_message($validationError),
        ],
        422,
    );
} catch (Throwable $unexpectedError) {
    $respondWithJson(
        [
            "ok" => false,
            "message" => user_facing_error_message($unexpectedError),
        ],
        500,
    );
}
