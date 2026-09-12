<?php

declare(strict_types=1);
http_response_code(404);
require dirname(__DIR__) . "/includes/bootstrap.php";
$pageTitle = "Page Not Found | VJ Car Rental";
require dirname(__DIR__) . "/includes/header.php";
?>

<section class="content-section error-page">
    <div class="container">
        <i class="bi bi-signpost-split"></i>
        <span class="section-kicker">Error 404</span>
        <h1>That road ends here.</h1>
        <p>The page may have moved or the address may be incorrect.</p>
        <div class="d-flex flex-wrap justify-content-center gap-2">
            <a class="btn btn-primary" href="index.php">
                Return Home
            </a>
            <a class="btn btn-outline" href="vehicles.php">
                Browse Vehicles
            </a>
        </div>
    </div>
</section>

<?php require dirname(__DIR__) . "/includes/footer.php";
?>
