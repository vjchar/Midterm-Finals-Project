<?php

declare(strict_types=1);

require dirname(__DIR__) . "/includes/bootstrap.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    header("Allow: POST");
    exit("Method not allowed.");
}

$authenticatedUser = require_customer();
$returnDestination = safe_return_to(
    $_POST["return_to"] ?? null,
    "vehicles.php",
);

try {
    require_csrf();

    $selectedVehicle = vehicle_find((string) ($_POST["vehicle"] ?? ""));

    if (!$selectedVehicle) {
        throw new InvalidArgumentException("Vehicle not found.");
    }

    $existingFavoriteStatement = database()->prepare(
        "SELECT id FROM favorites WHERE user_id = ? AND vehicle_id = ?",
    );
    $existingFavoriteStatement->execute([
        $authenticatedUser["id"],
        $selectedVehicle["id"],
    ]);
    $existingFavoriteId = $existingFavoriteStatement->fetchColumn();

    if ($existingFavoriteId) {
        $deleteFavoriteStatement = database()->prepare(
            "DELETE FROM favorites WHERE id = ?",
        );
        $deleteFavoriteStatement->execute([$existingFavoriteId]);
        flash("success", "Vehicle removed from your favorites.");
    } else {
        $createFavoriteStatement = database()->prepare(
            "INSERT INTO favorites (user_id, vehicle_id, created_at) VALUES (?, ?, ?)",
        );
        $createFavoriteStatement->execute([
            $authenticatedUser["id"],
            $selectedVehicle["id"],
            date("Y-m-d H:i:s"),
        ]);
        flash("success", "Vehicle saved to your favorites.");
    }
} catch (Throwable $exception) {
    flash("danger", user_facing_error_message($exception));
}

redirect($returnDestination);
