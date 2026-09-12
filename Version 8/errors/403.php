<?php

declare(strict_types=1);

http_response_code(403);
$pageTitle = "Access Denied | VJ Car Rental";
if (!defined("BOOTSTRAPPED")) {
    require dirname(__DIR__) . "/includes/bootstrap.php";
}
require dirname(__DIR__) . "/includes/header.php";
?>

<section class="content-section error-page pattern-layer">
    <div class="container">
        <i class="bi bi-shield-lock"></i>
        <span class="section-kicker">403 access denied</span>
        <h1>You do not have permission to open this page.</h1>
        <p>Return to the public site or contact VJ Car Rental if you need assistance.</p>
        <a class="btn btn-primary" href="index.php">Return Home</a>
    </div>
</section>

<?php require dirname(__DIR__) . "/includes/footer.php";
?>
