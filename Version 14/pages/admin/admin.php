<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";

$admin = require_admin();
$database = database();

$countQueries = [
    "vehicles" => "SELECT COUNT(*) FROM vehicles WHERE is_active = 1",
    "bookings" =>
        "SELECT COUNT(*) FROM bookings WHERE status IN ('pending', 'confirmed', 'ready', 'active', 'returned')",
    "customers" =>
        "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'customer' AND u.status = 'active'",
    "pending_documents" =>
        "SELECT COUNT(*) FROM customer_documents WHERE status = 'pending'",
    "pending_payments" =>
        "SELECT COUNT(*) FROM payments WHERE status = 'pending'",
    "active_rentals" => "SELECT COUNT(*) FROM bookings WHERE status = 'active'",
    "pending_adjustments" => "SELECT COUNT(*) FROM rental_adjustment_requests WHERE status = 'pending'",
    "pending_modifications" => "SELECT COUNT(*) FROM booking_modification_requests WHERE status = 'pending'",
    "pending_cancellations" => "SELECT COUNT(*) FROM booking_cancellation_requests WHERE status = 'pending'",
    "pending_refunds" => "SELECT COUNT(*) FROM payment_refunds WHERE status IN ('pending','approved','processing')",
    "pending_settlements" => "SELECT COUNT(*) FROM bookings b LEFT JOIN rental_settlements s ON s.booking_id=b.id WHERE b.status='returned' AND (s.id IS NULL OR s.status <> 'settled')",
    "revenue" =>
        "SELECT GREATEST(0, COALESCE((SELECT SUM(amount) FROM payments WHERE status = 'paid'),0) - COALESCE((SELECT SUM(amount) FROM payment_refunds WHERE status = 'refunded'),0))",
];

$counts = [];

foreach ($countQueries as $key => $query) {
    $counts[$key] = (int) $database->query($query)->fetchColumn();
}

$recentBookings = $database
    ->query(
        "SELECT
            b.reference,
            b.status,
            b.total,
            b.pickup_at,
            v.name AS vehicle_name,
            u.name AS customer_name
        FROM bookings b
        JOIN vehicles v ON v.id = b.vehicle_id
        JOIN users u ON u.id = b.user_id
        ORDER BY b.created_at DESC
        LIMIT 8",
    )
    ->fetchAll();

$statusRows = $database
    ->query(
        "SELECT status, COUNT(*) AS total
        FROM bookings
        GROUP BY status
        ORDER BY total DESC",
    )
    ->fetchAll();

$upcomingReturns = $database
    ->query(
        "SELECT
            b.reference,
            b.return_at,
            v.name AS vehicle_name,
            u.name AS customer_name
        FROM bookings b
        JOIN vehicles v ON v.id = b.vehicle_id
        JOIN users u ON u.id = b.user_id
        WHERE b.status = 'active'
        ORDER BY b.return_at
        LIMIT 6",
    )
    ->fetchAll();

$statusMaximum = max([
    1,
    ...array_map(
        static fn(array $row): int => (int) $row["total"],
        $statusRows,
    ),
]);

$currentHour = (int) date("G");
$greeting = match (true) {
    $currentHour < 12 => "Good morning",
    $currentHour < 18 => "Good afternoon",
    default => "Good evening",
};
$adminFirstName = explode(" ", trim($admin["name"]))[0];

$pageTitle = "Administration | VJ Car Rental";
$pageDescription = "VJ Car Rental administration and operations dashboard.";

require dirname(__DIR__, 2) . "/includes/header.php";
require dirname(__DIR__, 2) . "/includes/admin-nav.php";
?>

<section class="admin-hero admin-dashboard-hero">
    <div class="container">
        <div class="admin-dashboard-hero__copy">
            <span class="section-kicker">Operations dashboard</span>
            <h1><?= escape_html($greeting) ?>, <?= escape_html($adminFirstName) ?></h1>
            <p>
                Monitor reservations, verification, payments, active rentals,
                returns, and fleet readiness from one clear operations view.
            </p>

            <div class="admin-dashboard-hero__meta" aria-label="Dashboard date and purpose">
                <span>
                    <i class="bi bi-calendar3" aria-hidden="true"></i>
                    <?= escape_html(date("l, F j, Y")) ?>
                </span>
                <span>
                    <i class="bi bi-grid-1x2" aria-hidden="true"></i>
                    Live operations overview
                </span>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-primary admin-dashboard-hero__action" href="admin-bookings.php"><i class="bi bi-calendar2-check" aria-hidden="true"></i> Review bookings</a>
            <a class="btn btn-outline-light admin-dashboard-hero__action" href="admin-calendar.php"><i class="bi bi-calendar3" aria-hidden="true"></i> Rental calendar</a>
        </div>
    </div>
</section>

<section class="content-section admin-section admin-dashboard">
    <div class="container">
        <div class="admin-stats admin-dashboard-stats" aria-label="Operations summary">
            <article class="dashboard-stat dashboard-stat--fleet">
                <span class="dashboard-stat__icon" aria-hidden="true">
                    <i class="bi bi-car-front"></i>
                </span>
                <span class="dashboard-stat__content">
                    <small>Active vehicles</small>
                    <strong><?= $counts["vehicles"] ?></strong>
                    <span>Bookable fleet records</span>
                </span>
            </article>

            <article class="dashboard-stat dashboard-stat--booking">
                <span class="dashboard-stat__icon" aria-hidden="true">
                    <i class="bi bi-calendar2-check"></i>
                </span>
                <span class="dashboard-stat__content">
                    <small>Open bookings</small>
                    <strong><?= $counts["bookings"] ?></strong>
                    <span>Current reservation pipeline</span>
                </span>
            </article>

            <article class="dashboard-stat dashboard-stat--customer">
                <span class="dashboard-stat__icon" aria-hidden="true">
                    <i class="bi bi-people"></i>
                </span>
                <span class="dashboard-stat__content">
                    <small>Active customers</small>
                    <strong><?= $counts["customers"] ?></strong>
                    <span>Enabled customer accounts</span>
                </span>
            </article>

            <article class="dashboard-stat dashboard-stat--<?= $counts[
                "pending_documents"
            ] > 0
                ? "attention"
                : "clear" ?>">
                <span class="dashboard-stat__icon" aria-hidden="true">
                    <i class="bi bi-person-vcard"></i>
                </span>
                <span class="dashboard-stat__content">
                    <small>Documents to verify</small>
                    <strong><?= $counts["pending_documents"] ?></strong>
                    <span><?= $counts["pending_documents"] > 0
                        ? "Needs administrator review"
                        : "Verification queue is clear" ?></span>
                </span>
            </article>

            <article class="dashboard-stat dashboard-stat--<?= $counts[
                "pending_payments"
            ] > 0
                ? "attention"
                : "clear" ?>">
                <span class="dashboard-stat__icon" aria-hidden="true">
                    <i class="bi bi-credit-card"></i>
                </span>
                <span class="dashboard-stat__content">
                    <small>Payments to verify</small>
                    <strong><?= $counts["pending_payments"] ?></strong>
                    <span><?= $counts["pending_payments"] > 0
                        ? "Needs payment review"
                        : "Payment queue is clear" ?></span>
                </span>
            </article>

            <article class="dashboard-stat dashboard-stat--<?= $counts["pending_adjustments"] > 0 ? "attention" : "clear" ?>">
                <span class="dashboard-stat__icon" aria-hidden="true"><i class="bi bi-clock-history"></i></span>
                <span class="dashboard-stat__content">
                    <small>Rental adjustments</small>
                    <strong><?= $counts["pending_adjustments"] ?></strong>
                    <span><?= $counts["pending_adjustments"] > 0 ? "Extension / early-return requests" : "Adjustment queue is clear" ?></span>
                </span>
            </article>

            <article class="dashboard-stat dashboard-stat--revenue">
                <span class="dashboard-stat__icon" aria-hidden="true">
                    <i class="bi bi-cash-stack"></i>
                </span>
                <span class="dashboard-stat__content">
                    <small>Verified payments</small>
                    <strong><?= money($counts["revenue"]) ?></strong>
                    <span>Paid transaction total</span>
                </span>
            </article>
        </div>

        <div class="dashboard-workspace">
            <section class="dashboard-panel dashboard-recent-bookings">
                <div class="dashboard-panel__header">
                    <div>
                        <span class="section-kicker">Latest activity</span>
                        <h2>Recent bookings</h2>
                        <p>Newest reservations and their current status.</p>
                    </div>

                    <a class="dashboard-panel__link" href="admin-bookings.php">
                        View all
                        <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </a>
                </div>

                <?php if (!$recentBookings): ?>
                    <div class="dashboard-empty">
                        <span class="dashboard-empty__icon" aria-hidden="true">
                            <i class="bi bi-calendar2-plus"></i>
                        </span>
                        <strong>No bookings yet</strong>
                        <p>New customer reservations will appear here.</p>
                    </div>
                <?php else: ?>
                    <div class="admin-table-wrap dashboard-table-wrap">
                        <table class="admin-table dashboard-booking-table">
                            <thead>
                                <tr>
                                    <th scope="col">Reference</th>
                                    <th scope="col">Customer</th>
                                    <th scope="col">Vehicle</th>
                                    <th scope="col">Pickup</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentBookings as $booking): ?>
                                    <tr>
                                        <td data-label="Reference">
                                            <a
                                                class="dashboard-booking-reference"
                                                href="admin-bookings.php?reference=<?= urlencode(
                                                    $booking["reference"],
                                                ) ?>">
                                                <?= escape_html(
                                                    $booking["reference"],
                                                ) ?>
                                            </a>
                                        </td>
                                        <td data-label="Customer"><?= escape_html(
                                            $booking["customer_name"],
                                        ) ?></td>
                                        <td data-label="Vehicle"><?= escape_html(
                                            $booking["vehicle_name"],
                                        ) ?></td>
                                        <td data-label="Pickup">
                                            <time datetime="<?= escape_html(
                                                date(
                                                    "Y-m-d",
                                                    strtotime(
                                                        $booking["pickup_at"],
                                                    ),
                                                ),
                                            ) ?>">
                                                <?= escape_html(
                                                    date(
                                                        "M j, Y",
                                                        strtotime(
                                                            $booking[
                                                                "pickup_at"
                                                            ],
                                                        ),
                                                    ),
                                                ) ?>
                                            </time>
                                        </td>
                                        <td data-label="Status">
                                            <span class="status-badge status-badge--<?= status_class(
                                                $booking["status"],
                                            ) ?>">
                                                <?= escape_html(
                                                    ucfirst($booking["status"]),
                                                ) ?>
                                            </span>
                                        </td>
                                        <td class="dashboard-booking-total" data-label="Total">
                                            <?= money(
                                                (int) $booking["total"],
                                            ) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <aside class="dashboard-panel dashboard-operations-queue">
                <div class="dashboard-panel__header">
                    <div>
                        <span class="section-kicker">Immediate work</span>
                        <h2>Operations queue</h2>
                        <p>Open the areas that need daily attention.</p>
                    </div>
                </div>

                <nav class="dashboard-queue" aria-label="Operations shortcuts">
                    <a href="admin-documents.php?status=pending">
                        <span class="dashboard-queue__icon" aria-hidden="true">
                            <i class="bi bi-person-vcard"></i>
                        </span>
                        <span class="dashboard-queue__copy">
                            <strong>Documents</strong>
                            <small>Awaiting review</small>
                        </span>
                        <span class="dashboard-queue__count"><?= $counts[
                            "pending_documents"
                        ] ?></span>
                        <i class="bi bi-chevron-right dashboard-queue__arrow" aria-hidden="true"></i>
                    </a>

                    <a href="admin-payments.php?status=pending">
                        <span class="dashboard-queue__icon" aria-hidden="true">
                            <i class="bi bi-credit-card"></i>
                        </span>
                        <span class="dashboard-queue__copy">
                            <strong>Payments</strong>
                            <small>Awaiting verification</small>
                        </span>
                        <span class="dashboard-queue__count"><?= $counts[
                            "pending_payments"
                        ] ?></span>
                        <i class="bi bi-chevron-right dashboard-queue__arrow" aria-hidden="true"></i>
                    </a>

                    <a href="admin-bookings.php#modification-queue">
                        <span class="dashboard-queue__icon" aria-hidden="true"><i class="bi bi-pencil-square"></i></span>
                        <span class="dashboard-queue__copy"><strong>Booking changes</strong><small>Pre-pickup requests</small></span>
                        <span class="dashboard-queue__count"><?= $counts["pending_modifications"] ?></span>
                        <i class="bi bi-chevron-right dashboard-queue__arrow" aria-hidden="true"></i>
                    </a>
                    <a href="admin-bookings.php#cancellation-queue">
                        <span class="dashboard-queue__icon" aria-hidden="true"><i class="bi bi-x-octagon"></i></span>
                        <span class="dashboard-queue__copy"><strong>Cancellations</strong><small>Awaiting review</small></span>
                        <span class="dashboard-queue__count"><?= $counts["pending_cancellations"] ?></span>
                        <i class="bi bi-chevron-right dashboard-queue__arrow" aria-hidden="true"></i>
                    </a>
                    <a href="admin-payments.php#refund-history">
                        <span class="dashboard-queue__icon" aria-hidden="true"><i class="bi bi-arrow-counterclockwise"></i></span>
                        <span class="dashboard-queue__copy"><strong>Refunds</strong><small>Pending / processing</small></span>
                        <span class="dashboard-queue__count"><?= $counts["pending_refunds"] ?></span>
                        <i class="bi bi-chevron-right dashboard-queue__arrow" aria-hidden="true"></i>
                    </a>

                    <a href="admin-rentals.php">
                        <span class="dashboard-queue__icon" aria-hidden="true">
                            <i class="bi bi-key"></i>
                        </span>
                        <span class="dashboard-queue__copy">
                            <strong>Rental desk</strong>
                            <small>Checkout, returns & settlements</small>
                        </span>
                        <span class="dashboard-queue__count"><?= $counts["active_rentals"] + $counts["pending_settlements"] ?></span>
                        <i class="bi bi-chevron-right dashboard-queue__arrow" aria-hidden="true"></i>
                    </a>

                    <a href="admin-reports.php">
                        <span class="dashboard-queue__icon" aria-hidden="true">
                            <i class="bi bi-bar-chart-line"></i>
                        </span>
                        <span class="dashboard-queue__copy">
                            <strong>Reports</strong>
                            <small>Export and print summaries</small>
                        </span>
                        <i class="bi bi-chevron-right dashboard-queue__arrow" aria-hidden="true"></i>
                    </a>
                </nav>
            </aside>
        </div>

        <div class="dashboard-insights">
            <section class="dashboard-panel dashboard-status-panel">
                <div class="dashboard-panel__header">
                    <div>
                        <span class="section-kicker">Pipeline</span>
                        <h2>Booking status mix</h2>
                        <p>Distribution of reservations across every stage.</p>
                    </div>
                </div>

                <?php if (!$statusRows): ?>
                    <div class="dashboard-empty dashboard-empty--compact">
                        <strong>No booking status data</strong>
                        <p>Status totals will appear after the first reservation.</p>
                    </div>
                <?php else: ?>
                    <div class="dashboard-status-bars">
                        <?php foreach ($statusRows as $row): ?>
                            <?php
                            $statusLabel = ucwords(
                                str_replace("_", " ", $row["status"]),
                            );
                            $statusTotal = (int) $row["total"];
                            $statusPercentage = max(
                                4,
                                (int) round(
                                    ($statusTotal / $statusMaximum) * 100,
                                ),
                            );
                            ?>
                            <div class="dashboard-status-row">
                                <span><?= escape_html($statusLabel) ?></span>
                                <span class="dashboard-status-row__track" aria-hidden="true">
                                    <i style="--dashboard-bar-size: <?= $statusPercentage ?>%;"></i>
                                </span>
                                <strong><?= $statusTotal ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="dashboard-panel dashboard-returns-panel">
                <div class="dashboard-panel__header">
                    <div>
                        <span class="section-kicker">Due back</span>
                        <h2>Upcoming returns</h2>
                        <p>Active rentals ordered by return schedule.</p>
                    </div>

                    <a class="dashboard-panel__link" href="admin-rentals.php">
                        Rental desk
                        <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </a>
                </div>

                <?php if (!$upcomingReturns): ?>
                    <div class="dashboard-empty dashboard-empty--compact">
                        <span class="dashboard-empty__icon" aria-hidden="true">
                            <i class="bi bi-car-front-fill"></i>
                        </span>
                        <strong>No returns due</strong>
                        <p>Active rental returns will appear here.</p>
                    </div>
                <?php else: ?>
                    <div class="dashboard-return-list">
                        <?php foreach ($upcomingReturns as $return): ?>
                            <?php $returnTimestamp = strtotime(
                                $return["return_at"],
                            ); ?>
                            <a href="admin-rentals.php?reference=<?= urlencode(
                                $return["reference"],
                            ) ?>">
                                <span class="dashboard-return-list__date">
                                    <small><?= escape_html(
                                        date("M", $returnTimestamp),
                                    ) ?></small>
                                    <strong><?= escape_html(
                                        date("j", $returnTimestamp),
                                    ) ?></strong>
                                </span>
                                <span class="dashboard-return-list__copy">
                                    <small><?= escape_html(
                                        $return["reference"],
                                    ) ?></small>
                                    <strong><?= escape_html(
                                        $return["vehicle_name"],
                                    ) ?></strong>
                                    <span>
                                        <?= escape_html($return["customer_name"]) ?>
                                        <span aria-hidden="true">&middot;</span>
                                        <time datetime="<?= escape_html(
                                            date("c", $returnTimestamp),
                                        ) ?>">
                                            <?= escape_html(
                                                date("g:i A", $returnTimestamp),
                                            ) ?>
                                        </time>
                                    </span>
                                </span>
                                <i class="bi bi-chevron-right" aria-hidden="true"></i>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</section>

<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
