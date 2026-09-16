<?php

declare(strict_types=1);


/**
 * FILE: includes/admin-service.php
 * FILE PURPOSE: Administrator dashboard, reporting, and system-diagnostic service.
 * USED BY: Admin dashboard, reports, and health pages.
 * RESPONSIBILITY: Contains administrator-only aggregation and diagnostic database work so admin pages do not contain direct SQL.
 *
 * Maintenance note: Keep this file focused on the responsibility described above.
 */
/****************************************************************************
 * ADMIN DASHBOARD AND REPORTING
 ****************************************************************************/

/** Return the complete data set required by the administration dashboard. */
function admin_dashboard_data(): array
{
    $database = database();
    $countQueries = [
        "vehicles" => "SELECT COUNT(*) FROM vehicles WHERE is_active = 1",
        "bookings" =>
            "SELECT COUNT(*) FROM bookings WHERE status IN ('pending', 'confirmed', 'ready', 'active', 'returned')",
        "customers" =>
            "SELECT COUNT(*) FROM users WHERE role = 'customer' AND status = 'active'",
        "pending_documents" =>
            "SELECT COUNT(*) FROM customer_documents WHERE status = 'pending'",
        "pending_payments" =>
            "SELECT COUNT(*) FROM payments WHERE status = 'pending'",
        "active_rentals" => "SELECT COUNT(*) FROM bookings WHERE status = 'active'",
        "pending_adjustments" =>
            "SELECT COUNT(*) FROM rental_adjustment_requests WHERE status = 'pending'",
        "pending_modifications" =>
            "SELECT COUNT(*) FROM booking_modification_requests WHERE status = 'pending'",
        "pending_cancellations" =>
            "SELECT COUNT(*) FROM booking_cancellation_requests WHERE status = 'pending'",
        "pending_refunds" =>
            "SELECT COUNT(*) FROM payment_refunds WHERE status IN ('pending','approved','processing')",
        "pending_settlements" =>
            "SELECT COUNT(*) FROM bookings b LEFT JOIN rental_settlements s ON s.booking_id=b.id WHERE b.status='returned' AND (s.id IS NULL OR s.status <> 'settled')",
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

    return [
        "counts" => $counts,
        "recent_bookings" => $recentBookings,
        "status_rows" => $statusRows,
        "upcoming_returns" => $upcomingReturns,
        "status_maximum" => $statusMaximum,
    ];
}

/**
 * Return all aggregated report data for one inclusive calendar range.
 * CSV rendering remains in the page because it is presentation/output logic.
 */
function admin_report_data(string $from, string $to): array
{
    if (!valid_date($from) || !valid_date($to) || $from > $to) {
        throw new InvalidArgumentException("Choose a valid report date range.");
    }

    $fromAt = $from . " 00:00:00";
    $toAt = $to . " 23:59:59";
    $bookings = admin_report_booking_summary($fromAt, $toAt);
    $operations = admin_report_operational_summary($fromAt, $toAt);
    $finance = admin_report_finance_summary($fromAt, $toAt);
    $fleet = admin_report_fleet_performance($fromAt, $toAt);

    return array_merge(
        ["from_at" => $fromAt, "to_at" => $toAt],
        $bookings,
        $operations,
        $finance,
        $fleet,
    );
}

/** Return booking rows and booking/revenue totals for a report period. */
function admin_report_booking_summary(string $fromAt, string $toAt): array
{
    $statement = database()->prepare(<<<'SQL'
SELECT
    b.reference,
    b.status,
    b.pickup_at,
    b.original_return_at,
    b.return_at,
    b.total,
    v.name AS vehicle_name,
    u.name AS customer_name,
    COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.booking_id=b.id AND p.status='paid'),0) AS paid_amount,
    COALESCE((SELECT SUM(r.amount) FROM payment_refunds r WHERE r.booking_id=b.id AND r.status='refunded'),0) AS refunded_amount
FROM bookings AS b
JOIN vehicles AS v ON v.id = b.vehicle_id
JOIN users AS u ON u.id = b.user_id
WHERE b.created_at BETWEEN ? AND ?
ORDER BY b.created_at DESC
SQL);
    $statement->execute([$fromAt, $toAt]);
    $rows = $statement->fetchAll();

    $statusCounts = [];
    $bookingValue = 0;
    $verifiedRevenue = 0;
    $refundTotal = 0;
    foreach ($rows as $row) {
        $status = (string) $row["status"];
        $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
        $bookingValue += (int) $row["total"];
        $verifiedRevenue += (int) $row["paid_amount"];
        $refundTotal += (int) $row["refunded_amount"];
    }

    return [
        "rows" => $rows,
        "status_counts" => $statusCounts,
        "booking_value" => $bookingValue,
        "verified_revenue" => $verifiedRevenue,
        "refund_total" => $refundTotal,
        "net_revenue" => max(0, $verifiedRevenue - $refundTotal),
    ];
}

/** Return adjustment/modification/cancellation operational counts. */
function admin_report_operational_summary(string $fromAt, string $toAt): array
{
    $adjustmentStatement = database()->prepare(
        "SELECT request_type,status,COUNT(*) AS total,COALESCE(SUM(price_difference),0) AS value
         FROM rental_adjustment_requests
         WHERE created_at BETWEEN ? AND ?
         GROUP BY request_type,status",
    );
    $adjustmentStatement->execute([$fromAt, $toAt]);
    $adjustmentRows = $adjustmentStatement->fetchAll();

    $pendingAdjustments = 0;
    $activatedExtensionValue = 0;
    foreach ($adjustmentRows as $row) {
        if ($row["status"] === "pending") {
            $pendingAdjustments += (int) $row["total"];
        }
        if (
            $row["request_type"] === "extension" &&
            in_array($row["status"], ["activated", "completed"], true)
        ) {
            $activatedExtensionValue += (int) $row["value"];
        }
    }

    $modificationStatement = database()->prepare(
        "SELECT status,COUNT(*) AS total,COALESCE(SUM(price_difference),0) AS value
         FROM booking_modification_requests
         WHERE created_at BETWEEN ? AND ?
         GROUP BY status",
    );
    $modificationStatement->execute([$fromAt, $toAt]);
    $pendingModifications = 0;
    foreach ($modificationStatement->fetchAll() as $row) {
        if ($row["status"] === "pending") {
            $pendingModifications += (int) $row["total"];
        }
    }

    $cancellationStatement = database()->prepare(
        "SELECT COUNT(*) FROM booking_cancellation_requests WHERE requested_at BETWEEN ? AND ?",
    );
    $cancellationStatement->execute([$fromAt, $toAt]);

    return [
        "adjustment_rows" => $adjustmentRows,
        "pending_adjustments" => $pendingAdjustments,
        "activated_extension_value" => $activatedExtensionValue,
        "pending_modifications" => $pendingModifications,
        "cancellation_count" => (int) $cancellationStatement->fetchColumn(),
    ];
}

/** Return payment/refund/deposit financial aggregates for a report period. */
function admin_report_finance_summary(string $fromAt, string $toAt): array
{
    $database = database();
    $financeStatement = $database->prepare(<<<'SQL'
SELECT
  COALESCE(SUM(CASE WHEN p.status='paid' THEN p.amount ELSE 0 END),0) AS gross_payments,
  COALESCE(SUM(CASE WHEN p.status='paid' AND p.payment_type='deposit' THEN p.amount ELSE 0 END),0) AS deposits_collected,
  COALESCE(SUM(CASE WHEN p.status='paid' AND p.payment_type<>'deposit' THEN p.amount ELSE 0 END),0) AS non_deposit_payments
FROM payments p
WHERE p.created_at BETWEEN ? AND ?
SQL);
    $financeStatement->execute([$fromAt, $toAt]);
    $finance = $financeStatement->fetch() ?: [
        "gross_payments" => 0,
        "deposits_collected" => 0,
        "non_deposit_payments" => 0,
    ];

    $refundTypeStatement = $database->prepare(
        "SELECT r.refund_type, r.status, COALESCE(SUM(r.amount),0) AS amount, COUNT(*) AS total
         FROM payment_refunds r
         WHERE r.created_at BETWEEN ? AND ?
         GROUP BY r.refund_type,r.status",
    );
    $refundTypeStatement->execute([$fromAt, $toAt]);
    $refundByType = [
        "cancellation" => 0,
        "payment_correction" => 0,
        "security_deposit" => 0,
        "booking_modification" => 0,
    ];
    $pendingRefundAmount = 0;
    foreach ($refundTypeStatement->fetchAll() as $row) {
        if ($row["status"] === "refunded") {
            $refundByType[$row["refund_type"]] =
                ($refundByType[$row["refund_type"]] ?? 0) + (int) $row["amount"];
        } elseif (in_array($row["status"], ["pending", "approved", "processing"], true)) {
            $pendingRefundAmount += (int) $row["amount"];
        }
    }

    $refundSourceStatement = $database->prepare(
        "SELECT
           COALESCE(SUM(CASE WHEN p.payment_type='deposit' AND r.status='refunded' THEN r.amount ELSE 0 END),0) AS refunded_from_deposits,
           COALESCE(SUM(CASE WHEN p.payment_type<>'deposit' AND r.status='refunded' THEN r.amount ELSE 0 END),0) AS refunded_from_non_deposits
         FROM payment_refunds r
         JOIN payments p ON p.id=r.payment_id
         WHERE r.created_at BETWEEN ? AND ?",
    );
    $refundSourceStatement->execute([$fromAt, $toAt]);
    $refundSource = $refundSourceStatement->fetch() ?: [
        "refunded_from_deposits" => 0,
        "refunded_from_non_deposits" => 0,
    ];

    $settlementStatement = $database->prepare(
        "SELECT COALESCE(SUM(LEAST(deposit_paid,total_deductions)),0) AS deductions_retained,
                COALESCE(SUM(outstanding_balance),0) AS outstanding_receivables
         FROM rental_settlements
         WHERE finalized_at BETWEEN ? AND ?",
    );
    $settlementStatement->execute([$fromAt, $toAt]);
    $settlementFinance = $settlementStatement->fetch() ?: [
        "deductions_retained" => 0,
        "outstanding_receivables" => 0,
    ];

    $securityDepositRefunds = (int) ($refundByType["security_deposit"] ?? 0);
    $depositsHeld = max(
        0,
        (int) $finance["deposits_collected"] -
            (int) $refundSource["refunded_from_deposits"] -
            (int) $settlementFinance["deductions_retained"],
    );
    $rentalRevenue = max(
        0,
        (int) $finance["non_deposit_payments"] -
            (int) $refundSource["refunded_from_non_deposits"] +
            (int) $settlementFinance["deductions_retained"],
    );

    return [
        "finance" => $finance,
        "refund_by_type" => $refundByType,
        "pending_refund_amount" => $pendingRefundAmount,
        "refund_source" => $refundSource,
        "settlement_finance" => $settlementFinance,
        "security_deposit_refunds" => $securityDepositRefunds,
        "deposits_held" => $depositsHeld,
        "rental_revenue" => $rentalRevenue,
    ];
}

/** Return the highest-performing vehicles and chart scale for a report period. */
function admin_report_fleet_performance(string $fromAt, string $toAt): array
{
    $statement = database()->prepare(<<<'SQL'
SELECT
    v.name,
    COUNT(b.id) AS rentals,
    COALESCE(SUM(b.total), 0) AS value
FROM vehicles AS v
LEFT JOIN bookings AS b
    ON b.vehicle_id = v.id
    AND b.created_at BETWEEN ? AND ?
GROUP BY v.id
ORDER BY rentals DESC, value DESC
LIMIT 8
SQL);
    $statement->execute([$fromAt, $toAt]);
    $topVehicles = $statement->fetchAll();

    return [
        "top_vehicles" => $topVehicles,
        "max_rentals" => max([
            1,
            ...array_map(
                static fn(array $row): int => (int) $row["rentals"],
                $topVehicles,
            ),
        ]),
    ];
}

/** Return the connected database name without exposing SQL in a page. */
function connected_database_name(): string
{
    return (string) database()->query("SELECT DATABASE()")->fetchColumn();
}
