<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";
$admin = require_admin();
$driver = database()->getAttribute(PDO::ATTR_DRIVER_NAME);
$databaseName = (string) database()->query("SELECT DATABASE()")->fetchColumn();
$checks = [
    [
        "PHP version",
        version_compare(PHP_VERSION, "8.1.0", ">="),
        PHP_VERSION . " (8.1+ required)",
    ],
    [
        "Direct MySQL connection",
        $driver === "mysql",
        strtoupper($driver) . " connected",
    ],
    ["Database name", $databaseName === DB_NAME, $databaseName],
    [
        "PDO MySQL driver",
        in_array("mysql", PDO::getAvailableDrivers(), true),
        implode(", ", PDO::getAvailableDrivers()),
    ],
    [
        "Storage writable",
        is_writable(ROOT . "/storage"),
        ROOT . "/storage",
    ],
    [
        "Review uploads writable",
        is_writable(ROOT . "/storage/reviews"),
        ROOT . "/storage/reviews",
    ],
    [
        "Vehicle images writable",
        is_writable(ROOT . "/assets/images/cars"),
        ROOT . "/assets/images/cars",
    ],
    [
        "Direct configuration",
        is_file(ROOT . "/includes/config.php"),
        "includes/config.php",
    ],
    [
        "Application key",
        strlen(APP_KEY) >= 32,
        "Configured in includes/config.php",
    ],
    ["Debug disabled", !APP_DEBUG, APP_DEBUG ? "Enabled" : "Disabled"],
    [
        "HTTPS",
        APP_MODE !== "production" ||
        (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off"),
        APP_MODE === "production"
            ? "Required on the public site"
            : "Optional for local development",
    ],
];
$healthy =
    count(
        array_filter($checks, static fn(array $check): bool => !$check[1]),
    ) === 0;
$pageTitle = "System Health | VJ Car Rental";
require dirname(__DIR__, 2) . "/includes/header.php";
require dirname(__DIR__, 2) . "/includes/admin-nav.php";
?>
<section class="admin-page-heading">
    <div class="container">
        <div>
            <span class="section-kicker">Deployment readiness</span>
            <h1>System health</h1>
            <p>Checks for the direct XAMPP database connection, application files, uploads, and security settings.</p>
        </div>
        <span class="status-badge status-badge--<?= $healthy
            ? "success"
            : "warning" ?>"><?= $healthy
    ? "All checks passed"
    : "Action required" ?></span>
    </div>
</section>
<section class="content-section admin-section">
    <div class="container">
        <div class="health-grid">
            <?php foreach ($checks as [$label, $passed, $detail]): ?>
                <article class="health-check <?= $passed
                    ? "is-passed"
                    : "is-warning" ?>">
                    <i class="bi <?= $passed
                        ? "bi-check-circle-fill"
                        : "bi-exclamation-triangle-fill" ?>">
                    </i>
                    <span>
                        <strong><?= escape_html($label) ?></strong>
                        <small><?= escape_html($detail) ?></small>
                    </span>
                </article>
            <?php endforeach; ?>
        </div>
        <div class="booking-note mt-4">
            <strong>Before public launch:</strong> run these checks under the real domain, configure HTTPS and mail delivery, use a dedicated least-privilege database account, and complete the legal business information in Terms and Privacy.
        </div>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
