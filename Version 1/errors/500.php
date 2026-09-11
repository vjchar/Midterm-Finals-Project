<?php

declare(strict_types=1);

http_response_code(500);
$pageTitle = "Service Unavailable | VJ Car Rental";
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= htmlspecialchars(
        $pageTitle,
        ENT_QUOTES | ENT_SUBSTITUTE,
        "UTF-8",
    ) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@700;800&display=swap"
        rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>

<body class="standalone-error-page">
    <main class="standalone-error-card">
        <span class="section-kicker">Error 500</span>
        <h1>We hit a temporary roadblock.</h1>
        <p>
            The request could not be completed. Please try again shortly. If the
            problem continues, contact VJ Car Rental support.
        </p>
        <a class="standalone-error-link" href="index.php">Return home</a>
    </main>
</body>

</html>