<?php

declare(strict_types=1);


/**
 * FILE: actions/api.php
 * FILE PURPOSE: Central AJAX/API endpoint for lightweight interactive actions.
 * USED BY: JavaScript and forms that request availability, estimates, favorites, promos, and related JSON responses.
 * RESPONSIBILITY: Validates request input, delegates business work to services, and returns a response. It should not contain direct SQL.
 *
 * Maintenance note: Keep this file focused on the responsibility described above.
 */
require dirname(__DIR__) . "/includes/bootstrap.php";

$action = strtolower(trim((string) ($_GET["action"] ?? "")));

$respondJson = static function (array $response, int $statusCode = 200): never {
    http_response_code($statusCode);
    header("Content-Type: application/json; charset=UTF-8");
    header("Cache-Control: no-store");
    echo json_encode(
        $response,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );
    exit();
};

switch ($action) {
    case "availability":
        try {
            $vehicle = vehicle_find((string) ($_GET["vehicle"] ?? ""));
            $pickup = trim((string) ($_GET["pickup"] ?? ""));
            $return = trim((string) ($_GET["return"] ?? ""));
            $pickupTime = trim((string) ($_GET["pickup_time"] ?? "09:00"));
            $returnTime = trim((string) ($_GET["return_time"] ?? "09:00"));

            if (!$vehicle || !valid_date($pickup) || !valid_date($return)) {
                throw new InvalidArgumentException("Select a vehicle and valid dates.");
            }

            $timePattern = '/^(?:[01]\\d|2[0-3]):[0-5]\\d$/';
            if (!preg_match($timePattern, $pickupTime) || !preg_match($timePattern, $returnTime)) {
                throw new InvalidArgumentException("Select valid pick-up and return times.");
            }

            $pickupAt = (new DateTimeImmutable($pickup . " " . $pickupTime))->format("Y-m-d H:i:s");
            $returnAt = (new DateTimeImmutable($return . " " . $returnTime))->format("Y-m-d H:i:s");

            if ($pickupAt < date("Y-m-d H:i:s")) {
                throw new InvalidArgumentException("Pick-up must be in the future.");
            }
            if ($returnAt <= $pickupAt) {
                throw new InvalidArgumentException("Return must be later than pick-up.");
            }

            $available = vehicle_available((int) $vehicle["id"], $pickupAt, $returnAt);
            $respondJson([
                "ok" => true,
                "available" => $available,
                "message" => $available
                    ? "Available for the selected period."
                    : "Already reserved during part of the selected period.",
            ]);
        } catch (Throwable $error) {
            $respondJson([
                "ok" => false,
                "available" => false,
                "message" => user_facing_error_message($error),
            ], 422);
        }

    case "booking-estimate":
        if ($_SERVER["REQUEST_METHOD"] !== "POST") {
            header("Allow: POST");
            $respondJson(["ok" => false, "message" => "This endpoint accepts POST requests only."], 405);
        }
        $authenticatedUser = current_user();
        if (!$authenticatedUser || $authenticatedUser["role"] !== "customer") {
            $respondJson(["ok" => false, "message" => "Sign in with a customer account to calculate a booking estimate."], 401);
        }
        if (!verify_csrf()) {
            $respondJson(["ok" => false, "message" => "Your session expired. Refresh the page and try again."], 419);
        }
        try {
            $bookingEstimate = parse_booking_input($_POST);
            $selectedVehicle = $bookingEstimate["vehicle"];
            if (!vehicle_available((int) $selectedVehicle["id"], (string) $bookingEstimate["pickup_at"], (string) $bookingEstimate["return_at"])) {
                throw new RuntimeException("This vehicle is already reserved during part of the selected schedule.");
            }
            $discount = (int) $bookingEstimate["discount"];
            $respondJson([
                "ok" => true,
                "message" => "Available for the selected schedule. PHP verified this estimate on the server.",
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
                        "addons_total" => money((int) $bookingEstimate["addons_total"]),
                        "delivery_fee" => money((int) $bookingEstimate["delivery_fee"]),
                        "discount" => $discount > 0 ? "−" . money($discount) : money(0),
                        "deposit" => money((int) $selectedVehicle["deposit"]),
                        "total" => money((int) $bookingEstimate["total"]),
                    ],
                ],
            ]);
        } catch (PDOException $error) {
            $respondJson(["ok" => false, "message" => user_facing_error_message($error)], 500);
        } catch (InvalidArgumentException | RuntimeException $error) {
            $respondJson(["ok" => false, "message" => user_facing_error_message($error)], 422);
        } catch (Throwable $error) {
            $respondJson(["ok" => false, "message" => user_facing_error_message($error)], 500);
        }

    case "favorite":
        if ($_SERVER["REQUEST_METHOD"] !== "POST") {
            http_response_code(405);
            header("Allow: POST");
            exit("Method not allowed.");
        }
        $authenticatedUser = require_customer();
        $returnDestination = safe_return_to($_POST["return_to"] ?? null, "vehicles.php");
        try {
            require_csrf();
            $selectedVehicle = vehicle_find((string) ($_POST["vehicle"] ?? ""));
            if (!$selectedVehicle) {
                throw new InvalidArgumentException("Vehicle not found.");
            }
            $isFavorite = toggle_vehicle_favorite(
                (int) $authenticatedUser["id"],
                (int) $selectedVehicle["id"],
            );
            flash(
                "success",
                $isFavorite
                    ? "Vehicle saved to your favorites."
                    : "Vehicle removed from your favorites.",
            );
        } catch (Throwable $error) {
            flash("danger", user_facing_error_message($error));
        }
        redirect($returnDestination);

    case "fleet-availability":
        try {
            $pickupDate = trim((string) ($_GET["pickup"] ?? ""));
            $returnDate = trim((string) ($_GET["return"] ?? ""));
            if (!valid_date($pickupDate) || !valid_date($returnDate)) {
                throw new InvalidArgumentException("Choose valid pickup and return dates.");
            }
            $pickup = new DateTimeImmutable($pickupDate . " 09:00:00");
            $return = new DateTimeImmutable($returnDate . " 09:00:00");
            if ($return <= $pickup) {
                throw new InvalidArgumentException("Return must be later than pickup.");
            }
            $respondJson([
                "ok" => true,
                "unavailable" => unavailable_vehicle_slugs(
                    $pickup->format("Y-m-d H:i:s"),
                    $return->format("Y-m-d H:i:s"),
                ),
                "message" => sprintf("Fleet filtered for %s to %s.", $pickup->format("M j"), $return->format("M j, Y")),
            ]);
        } catch (Throwable $error) {
            $respondJson(["ok" => false, "message" => user_facing_error_message($error)], 422);
        }

    case "promo-check":
        try {
            $code = trim((string) ($_GET["code"] ?? ""));
            $subtotal = filter_input(INPUT_GET, "subtotal", FILTER_VALIDATE_INT);
            if ($code === "" || $subtotal === false || $subtotal < 1) {
                throw new InvalidArgumentException("Enter a promotion code after selecting your rental dates.");
            }
            $promo = promo_find($code);
            if (!$promo) {
                throw new InvalidArgumentException("That promotion code is invalid, inactive, expired, or fully redeemed.");
            }
            $respondJson([
                "ok" => true,
                "code" => strtoupper((string) $promo["code"]),
                "discount_type" => $promo["discount_type"],
                "discount_value" => (int) $promo["discount_value"],
                "discount" => apply_promo($promo, (int) $subtotal),
                "message" => (string) $promo["description"],
            ]);
        } catch (Throwable $error) {
            $respondJson(["ok" => false, "discount" => 0, "message" => user_facing_error_message($error)], 422);
        }

    default:
        $respondJson(["ok" => false, "message" => "Unknown API action."], 404);
}
