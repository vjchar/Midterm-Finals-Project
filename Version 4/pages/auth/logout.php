<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    header("Allow: POST");
    exit("Method Not Allowed");
}
require_csrf();
logout();
header("Location: index.php", true, 303);
exit();
