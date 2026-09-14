<?php

declare(strict_types=1);

http_response_code(403);
$pageTitle = "Access Denied | VJ Car Rental";
if (!defined("BOOTSTRAPPED")) {
    require dirname(__DIR__) . "/includes/bootstrap.php";
}
$accessDeniedDestination = admin() ? "admin.php" : "account.php";
$accessDeniedLabel = admin()
    ? "Go to Admin Dashboard"
    : "Go to My Account";
require dirname(__DIR__) . "/includes/header.php";
?>

<section class="content-section error-page pattern-layer">
    <div class="container">
        <i class="bi bi-shield-lock"></i>
        <span class="section-kicker">403 access denied</span>
        <h1>You do not have permission to open this page.</h1>
        <p>Return to your account or contact an administrator if you believe this is incorrect.</p>
        <a class="btn btn-primary" href="<?= $accessDeniedDestination ?>"><?= $accessDeniedLabel ?></a>
    </div>
</section>

<?php require dirname(__DIR__) . "/includes/footer.php";
?>
