<?php

declare(strict_types=1);


/**
 * FILE: pages/booking/rental-adjustment.php
 * FILE PURPOSE: Customer rental extension/adjustment request page.
 * USED BY: Customers progressing through booking, payment, rental, or post-trip workflows.
 * RESPONSIBILITY: Loads the required application/services, handles only page-level request orchestration, and renders the user interface; reusable business/database logic belongs in services.
 *
 * Maintenance note: Keep this file focused on the responsibility described above.
 */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$user = require_customer();
$reference = trim((string)($_GET['reference'] ?? ($_POST['reference'] ?? '')));
$booking = $reference !== '' ? booking_find_by_reference($reference) : null;
if (!$booking || (int)$booking['user_id'] !== (int)$user['id']) {
    http_response_code(404);
    $pageTitle = 'Rental Adjustment Not Found | VJ Car Rental';
    require dirname(__DIR__, 2) . '/includes/header.php';
    echo '<section class="content-section error-page"><div class="container"><h1>Rental not found</h1><p>This rental is not available to your account.</p><a class="btn btn-primary" href="my-bookings.php">My Bookings</a></div></section>';
    require dirname(__DIR__, 2) . '/includes/footer.php';
    exit;
}
if ($booking['status'] !== 'active') {
    flash('warning', 'Rental adjustments are available only while the rental is active.');
    redirect('booking-view.php?reference=' . urlencode($reference));
}
$errors = [];
$preview = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_csrf();
        $action = post_string('action');
        if ($action === 'preview_extension') {
            $preview = preview_rental_extension($booking, (int)$user['id'], post_string('requested_date'), post_string('requested_time'));
        } elseif ($action === 'submit_extension') {
            create_rental_adjustment_request($booking, (int)$user['id'], 'extension', post_string('requested_date'), post_string('requested_time'), post_string('reason'));
            flash('success', 'Extension request submitted for administrator review.');
            redirect('booking-view.php?reference=' . urlencode($reference));
        } elseif ($action === 'submit_early_return') {
            create_rental_adjustment_request($booking, (int)$user['id'], 'early_return', post_string('requested_date'), post_string('requested_time'), post_string('reason'));
            flash('success', 'Early return request submitted. The actual return will still be recorded at check-in.');
            redirect('booking-view.php?reference=' . urlencode($reference));
        } elseif ($action === 'cancel_request') {
            $adjustmentId = filter_var($_POST['adjustment_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$adjustmentId) {
                throw new InvalidArgumentException('Choose a valid request.');
            }
            cancel_rental_adjustment_request((int)$adjustmentId, (int)$user['id']);
            flash('success', 'Pending rental adjustment cancelled.');
            redirect('rental-adjustment.php?reference=' . urlencode($reference));
        } else {
            throw new InvalidArgumentException('Choose a valid rental adjustment action.');
        }
    } catch (Throwable $error) {
        $errors[] = user_facing_error_message($error);
    }
}
$adjustments = rental_adjustments_for_booking((int)$booking['id']);
$unresolved = unresolved_rental_adjustment((int)$booking['id']);
$pageTitle = 'Rental Adjustment ' . $booking['reference'] . ' | VJ Car Rental';
require dirname(__DIR__, 2) . '/includes/header.php';
?>
<section class="page-hero page-hero--compact pattern-layer">
    <div class="container">
        <span class="section-kicker">Flexible rental</span>
        <h1>Adjust <?= escape_html($booking['reference']) ?></h1>
        <p>Request an earlier return or ask to keep <?= escape_html($booking['vehicle_name']) ?> longer. Every change is validated against bookings, maintenance, pricing, and payment rules.</p>
    </div>
</section>
<section class="content-section operations-page">
    <div class="container">
        <?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= escape_html($error) ?></div><?php endforeach; ?>
        <?php if ($unresolved): ?>
            <div class="alert alert-warning">You already have a <?= escape_html(humanize_label((string) $unresolved['request_type'])) ?> request with status <strong><?= escape_html(status_label((string) $unresolved['status'])) ?></strong>. Resolve it before creating another.</div>
        <?php endif; ?>
        <div class="rental-adjustment-overview">
            <article><span>Original scheduled return</span><strong><?= date('M j, Y g:i A', strtotime((string)($booking['original_return_at'] ?: $booking['return_at']))) ?></strong></article>
            <article><span>Current scheduled return</span><strong><?= date('M j, Y g:i A', strtotime($booking['return_at'])) ?></strong></article>
            <article><span>Rental status</span><strong><?= escape_html(humanize_label($booking['status'])) ?></strong></article>
        </div>
        <?php if (!$unresolved): ?>
        <div class="row g-4 mt-1">
            <div class="col-lg-6">
                <article class="operation-card rental-adjustment-card">
                    <span class="section-kicker">Return sooner</span>
                    <h2>Request Early Return</h2>
                    <p>Tell the rental desk when you plan to bring the vehicle back. This does not complete the rental automatically; mileage, fuel, inspection, damage, and final charges are still recorded at check-in.</p>
                    <form method="post" class="row g-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="reference" value="<?= escape_html($booking['reference']) ?>">
                        <input type="hidden" name="action" value="submit_early_return">
                        <div class="col-md-6"><label class="form-label" for="earlyDate">Requested date</label><input class="form-control" id="earlyDate" name="requested_date" type="date" min="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime($booking['return_at'])) ?>" required></div>
                        <div class="col-md-6"><label class="form-label" for="earlyTime">Requested time</label><input class="form-control" id="earlyTime" name="requested_time" type="time" value="<?= date('H:i', strtotime($booking['return_at'])) ?>" required></div>
                        <div class="col-12"><label class="form-label" for="earlyReason">Reason (optional)</label><textarea class="form-control" id="earlyReason" name="reason" rows="3" maxlength="1500"></textarea></div>
                        <div class="col-12"><button class="btn btn-outline" type="submit"><i class="bi bi-arrow-return-left"></i> Submit Early Return Request</button></div>
                    </form>
                </article>
            </div>
            <div class="col-lg-6">
                <article class="operation-card rental-adjustment-card">
                    <span class="section-kicker">Keep it longer</span>
                    <h2>Request Extension</h2>
                    <p>We check the vehicle against upcoming reservations and maintenance, then recalculate the rental using the current pricing rules.</p>
                    <form method="post" class="row g-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="reference" value="<?= escape_html($booking['reference']) ?>">
                        <div class="col-md-6"><label class="form-label" for="extendDate">New return date</label><input class="form-control" id="extendDate" name="requested_date" type="date" min="<?= date('Y-m-d', strtotime($booking['return_at'])) ?>" value="<?= date('Y-m-d', strtotime($booking['return_at'] . ' +1 day')) ?>" required></div>
                        <div class="col-md-6"><label class="form-label" for="extendTime">New return time</label><input class="form-control" id="extendTime" name="requested_time" type="time" value="<?= date('H:i', strtotime($booking['return_at'])) ?>" required></div>
                        <div class="col-12"><label class="form-label" for="extendReason">Reason (optional)</label><textarea class="form-control" id="extendReason" name="reason" rows="3" maxlength="1500"></textarea></div>
                        <div class="col-12 d-flex flex-wrap gap-2"><button class="btn btn-outline" type="submit" name="action" value="preview_extension"><i class="bi bi-calculator"></i> Preview Extension</button><button class="btn btn-primary" type="submit" name="action" value="submit_extension"><i class="bi bi-clock-history"></i> Submit Extension Request</button></div>
                    </form>
                    <?php if ($preview): ?>
                        <div class="extension-preview mt-4">
                            <h3>Extension preview</h3>
                            <dl>
                                <div><dt>Requested return</dt><dd><?= date('M j, Y g:i A', strtotime($preview['requested_return_at'])) ?></dd></div>
                                <div><dt>Updated rental duration</dt><dd><?= (int)$preview['pricing']['days'] ?> day<?= (int)$preview['pricing']['days'] === 1 ? '' : 's' ?></dd></div>
                                <div><dt>Current rental total</dt><dd><?= money((int)$booking['total']) ?></dd></div>
                                <div><dt>Additional extension cost</dt><dd><?= money((int)$preview['pricing']['price_difference']) ?></dd></div>
                                <div><dt>Updated rental total</dt><dd><?= money((int)$preview['pricing']['total']) ?></dd></div>
                                <div><dt>Availability</dt><dd><span class="status-badge status-badge--success">Available now</span></dd></div>
                            </dl>
                            <small>Availability and pricing are checked again when an administrator reviews the request and again before activation.</small>
                        </div>
                    <?php endif; ?>
                </article>
            </div>
        </div>
        <?php endif; ?>
        <article class="booking-management-panel mt-4">
            <span class="section-kicker">Rental change history</span>
            <h2>Adjustment requests</h2>
            <?php if (!$adjustments): ?><p>No rental adjustments have been requested for this booking.</p><?php else: ?>
                <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Type</th><th>Original return</th><th>Requested return</th><th>Price change</th><th>Status</th><th>Requested</th><th></th></tr></thead><tbody>
                <?php foreach ($adjustments as $item): ?><tr>
                    <td><?= escape_html(ucwords(str_replace('_',' ',$item['request_type']))) ?></td>
                    <td><?= date('M j, Y g:i A', strtotime($item['original_return_at'])) ?></td>
                    <td><?= date('M j, Y g:i A', strtotime($item['requested_return_at'])) ?></td>
                    <td><?= $item['request_type'] === 'extension' ? money((int)$item['price_difference']) : '—' ?></td>
                    <td><span class="status-badge status-badge--<?= status_class($item['status']) ?>"><?= escape_html(humanize_label($item['status'])) ?></span></td>
                    <td><?= date('M j, Y g:i A', strtotime($item['created_at'])) ?></td>
                    <td><?php if ($item['status'] === 'pending'): ?><form method="post" data-confirm="Cancel this pending rental adjustment request?"><?= csrf_field() ?><input type="hidden" name="reference" value="<?= escape_html($booking['reference']) ?>"><input type="hidden" name="adjustment_id" value="<?= (int)$item['id'] ?>"><button class="btn btn-outline btn-sm" name="action" value="cancel_request">Cancel</button></form><?php elseif ($item['request_type'] === 'extension' && $item['status'] === 'approved'): ?><a class="btn btn-primary btn-sm" href="payments.php?reference=<?= urlencode($booking['reference']) ?>">Pay Extension</a><?php endif; ?></td>
                </tr><?php endforeach; ?></tbody></table></div>
            <?php endif; ?>
        </article>
        <div class="mt-4"><a class="btn btn-outline" href="booking-view.php?reference=<?= urlencode($booking['reference']) ?>"><i class="bi bi-arrow-left"></i> Back to Rental Details</a></div>
    </div>
</section>
<?php require dirname(__DIR__, 2) . '/includes/footer.php'; ?>
