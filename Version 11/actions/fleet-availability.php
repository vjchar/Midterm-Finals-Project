<?php

declare(strict_types=1);
require dirname(__DIR__) . "/includes/bootstrap.php";
header("Content-Type: application/json; charset=UTF-8");
try {
    $pickupDate = trim((string) ($_GET["pickup"] ?? ""));
    $returnDate = trim((string) ($_GET["return"] ?? ""));

    if (!valid_date($pickupDate) || !valid_date($returnDate)) {
        throw new InvalidArgumentException(
            "Choose valid pickup and return dates.",
        );
    }

    $pickup = new DateTimeImmutable($pickupDate . " 09:00:00");
    $return = new DateTimeImmutable($returnDate . " 09:00:00");

    if ($return <= $pickup) {
        throw new InvalidArgumentException("Return must be later than pickup.");
    }

    $query = <<<'SQL'
    SELECT DISTINCT v.slug
    FROM vehicles AS v
    LEFT JOIN bookings AS b
        ON b.vehicle_id = v.id
        AND b.status IN ('pending', 'confirmed', 'ready', 'active')
        AND b.pickup_at < ?
        AND b.return_at > ?
    WHERE v.is_active = 0
        OR v.availability_status IN ('maintenance', 'unavailable')
        OR b.id IS NOT NULL
    SQL;

    $statement = database()->prepare($query);
    $statement->execute([
        $return->format("Y-m-d H:i:s"),
        $pickup->format("Y-m-d H:i:s"),
    ]);

    $response = [
        "ok" => true,
        "unavailable" => array_column($statement->fetchAll(), "slug"),
        "message" => sprintf(
            "Fleet filtered for %s to %s.",
            $pickup->format("M j"),
            $return->format("M j, Y"),
        ),
    ];

    echo json_encode($response, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(422);
    echo json_encode(["ok" => false, "message" => user_facing_error_message($error)]);
}
