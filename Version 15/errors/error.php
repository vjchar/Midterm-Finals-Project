<?php

declare(strict_types=1);


/**
 * FILE: errors/error.php
 * FILE PURPOSE: Shared user-friendly HTTP error page.
 * USED BY: The application error handler and Apache routing for 403, 404, and 500 responses.
 * RESPONSIBILITY: Displays a safe error message without exposing stack traces, SQL errors, credentials, or server paths.
 *
 * Maintenance note: Keep this file focused on the responsibility described above.
 */
$errorCode = (int) ($errorCode ?? ($_GET["code"] ?? http_response_code()));
if (!in_array($errorCode, [403, 404, 500], true)) {
    $errorCode = 404;
}
http_response_code($errorCode);

if ($errorCode === 500) {
    $pageTitle = "Service Unavailable | VJ Car Rental";
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@700;800&display=swap" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="standalone-error-page">
    <main class="standalone-error-card">
        <span class="section-kicker">Error 500</span>
        <h1>We hit a temporary roadblock.</h1>
        <p>The request could not be completed. Please try again shortly. If the problem continues, contact VJ Car Rental support.</p>
        <a class="standalone-error-link" href="index.php">Return home</a>
    </main>
</body>
</html>
    <?php
    return;
}

if (!defined("BOOTSTRAPPED")) {
    require dirname(__DIR__) . "/includes/bootstrap.php";
}

if ($errorCode === 403) {
    $pageTitle = "Access Denied | VJ Car Rental";
    $errorKicker = "403 access denied";
    $errorIcon = "bi-shield-lock";
    $errorHeading = "You do not have permission to open this page.";
    $errorMessage = "Return to your account or contact an administrator if you believe this is incorrect.";
    $errorDestination = admin() ? "admin.php" : "account.php";
    $errorButton = admin() ? "Go to Admin Dashboard" : "Go to My Account";
} else {
    $pageTitle = "Page Not Found | VJ Car Rental";
    $errorKicker = "Error 404";
    $errorIcon = "bi-signpost-split";
    $errorHeading = "That road ends here.";
    $errorMessage = "The page may have moved or the address may be incorrect.";
    $errorDestination = "index.php";
    $errorButton = "Return Home";
}

require dirname(__DIR__) . "/includes/header.php";
?>
<section class="content-section error-page<?= $errorCode === 403 ? " pattern-layer" : "" ?>">
    <div class="container">
        <i class="bi <?= escape_html($errorIcon) ?>"></i>
        <span class="section-kicker"><?= escape_html($errorKicker) ?></span>
        <h1><?= escape_html($errorHeading) ?></h1>
        <p><?= escape_html($errorMessage) ?></p>
        <div class="d-flex flex-wrap justify-content-center gap-2">
            <a class="btn btn-primary" href="<?= escape_html($errorDestination) ?>"><?= escape_html($errorButton) ?></a>
            <?php if ($errorCode === 404): ?>
                <a class="btn btn-outline" href="vehicles.php">Browse Vehicles</a>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php require dirname(__DIR__) . "/includes/footer.php"; ?>
