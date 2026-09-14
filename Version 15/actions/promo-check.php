<?php

declare(strict_types=1);
require dirname(__DIR__) . "/includes/bootstrap.php";
header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store");
try {
    $code = trim((string) ($_GET["code"] ?? ""));
    $subtotal = filter_input(INPUT_GET, "subtotal", FILTER_VALIDATE_INT);

    if ($code === "" || $subtotal === false || $subtotal < 1) {
        throw new InvalidArgumentException(
            "Enter a promotion code after selecting your rental dates.",
        );
    }

    $promo = promo_find($code);
    if (!$promo) {
        throw new InvalidArgumentException(
            "That promotion code is invalid, inactive, expired, or fully redeemed.",
        );
    }

    echo json_encode(
        [
            "ok" => true,
            "code" => strtoupper((string) $promo["code"]),
            "discount_type" => $promo["discount_type"],
            "discount_value" => (int) $promo["discount_value"],
            "discount" => apply_promo($promo, (int) $subtotal),
            "message" => (string) $promo["description"],
        ],
        JSON_UNESCAPED_SLASHES,
    );
} catch (Throwable $error) {
    http_response_code(422);
    echo json_encode(
        ["ok" => false, "discount" => 0, "message" => user_facing_error_message($error)],
        JSON_UNESCAPED_SLASHES,
    );
}
