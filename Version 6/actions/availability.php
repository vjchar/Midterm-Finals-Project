<?php

declare(strict_types=1);
require dirname(__DIR__) . "/includes/bootstrap.php";
header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store");

try {
    $vehicle = vehicle_find((string) ($_GET["vehicle"] ?? ""));
    $pickup = trim((string) ($_GET["pickup"] ?? ""));
    $return = trim((string) ($_GET["return"] ?? ""));
    $pickupTime = trim((string) ($_GET["pickup_time"] ?? "09:00"));
    $returnTime = trim((string) ($_GET["return_time"] ?? "09:00"));

    if (!$vehicle || !valid_date($pickup) || !valid_date($return)) {
        throw new InvalidArgumentException("Select a vehicle and valid dates.");
    }

    $timePattern = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';
    if (
        !preg_match($timePattern, $pickupTime) ||
        !preg_match($timePattern, $returnTime)
    ) {
        throw new InvalidArgumentException(
            "Select valid pick-up and return times.",
        );
    }

    $pickupAt = (new DateTimeImmutable($pickup . " " . $pickupTime))->format(
        "Y-m-d H:i:s",
    );
    $returnAt = (new DateTimeImmutable($return . " " . $returnTime))->format(
        "Y-m-d H:i:s",
    );

    if ($pickupAt < date("Y-m-d H:i:s")) {
        throw new InvalidArgumentException("Pick-up must be in the future.");
    }
    if ($returnAt <= $pickupAt) {
        throw new InvalidArgumentException("Return must be later than pick-up.");
    }

    $available = vehicle_available(
        (int) $vehicle["id"],
        $pickupAt,
        $returnAt,
    );

    echo json_encode(
        [
            "ok" => true,
            "available" => $available,
            "message" => $available
                ? "Available for the selected period."
                : "Already reserved during part of the selected period.",
        ],
        JSON_UNESCAPED_SLASHES,
    );
} catch (Throwable $error) {
    http_response_code(422);
    echo json_encode(
        [
            "ok" => false,
            "available" => false,
            "message" => user_facing_error_message($error),
        ],
        JSON_UNESCAPED_SLASHES,
    );
}
