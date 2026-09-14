<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$user = require_customer();
$reference = trim((string) ($_GET['reference'] ?? ($_POST['reference'] ?? '')));
$booking = $reference !== '' ? booking_find_by_reference($reference) : null;
if (!$booking || (int) $booking['user_id'] !== (int) $user['id']) {
    http_response_code(404);
    $pageTitle = 'Booking Not Found | VJ Car Rental';
    require dirname(__DIR__, 2) . '/includes/header.php';
    echo '<section class="content-section error-page"><div class="container"><h1>Booking not found</h1><a class="btn btn-primary" href="my-bookings.php">My Bookings</a></div></section>';
    require dirname(__DIR__, 2) . '/includes/footer.php';
    exit;
}
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_csrf();
        request_booking_cancellation($booking, (int) $user['id'], post_string('reason'));
        flash('success', 'Cancellation request submitted for administrator review.');
        redirect('booking-cancellation.php?reference=' . urlencode($reference));
    } catch (Throwable $error) {
        $errors[] = user_facing_error_message($error);
    }
}
$request = cancellation_request_for_booking((int) $booking['id']);
$preview = cancellation_refund_preview($booking);
$refunds = refunds_for_booking((int) $booking['id']);
$pageTitle = 'Cancel Booking ' . $booking['reference'] . ' | VJ Car Rental';
require dirname(__DIR__, 2) . '/includes/header.php';
?>
<section class="page-hero page-hero--compact pattern-layer"><div class="container"><span class="section-kicker">Cancellation & refund</span><h1>Booking <?= escape_html($booking['reference']) ?></h1><p>Review the recorded payments and current refund estimate before requesting cancellation.</p></div></section>
<section class="content-section"><div class="container">
<?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= escape_html($error) ?></div><?php endforeach; ?>
<div class="row g-4 align-items-start">
<div class="col-lg-7">
<article class="operation-card">
<span class="section-kicker">Cancellation status</span><h2><?= $request ? escape_html(ucwords(str_replace('_',' ', $request['status']))) : 'Request cancellation' ?></h2>
<div class="confirmation-details">
<span><small>Vehicle</small><strong><?= escape_html($booking['vehicle_name']) ?></strong></span>
<span><small>Pickup</small><strong><?= date('M j, Y g:i A', strtotime($booking['pickup_at'])) ?></strong></span>
<span><small>Paid amount available</small><strong><?= money($preview['paid']) ?></strong></span>
<span><small>Estimated refundable</small><strong><?= money($request ? (int) $request['refundable_amount'] : $preview['refundable']) ?></strong></span>
<span><small>Estimated non-refundable</small><strong><?= money($request ? (int) $request['non_refundable_amount'] : $preview['non_refundable']) ?></strong></span>
</div>
<?php if ($request): ?>
<p class="display-note mt-3">Reason: <?= escape_html((string) $request['reason']) ?></p>
<?php if ($request['admin_note']): ?><p class="display-note">Admin note: <?= escape_html((string) $request['admin_note']) ?></p><?php endif; ?>
<?php elseif (in_array($booking['status'], ['pending','confirmed','ready'], true) && $preview['hours_to_pickup'] > 0): ?>
<?php if ($preview['hours_to_pickup'] > CANCELLATION_REFUND_CUTOFF_HOURS): ?>
<div class="alert alert-info mt-3">Under the current configurable policy, verified payments are fully refundable when an eligible cancellation is approved more than <?= CANCELLATION_REFUND_CUTOFF_HOURS ?> hours before pickup.</div>
<?php else: ?>
<div class="alert alert-warning mt-3">Pickup is within <?= CANCELLATION_REFUND_CUTOFF_HOURS ?> hours. You may still submit a cancellation request for Admin review, but the standard refundable window has closed and no automatic refund is due under the current policy.</div>
<?php endif; ?>
<form method="post" class="mt-3">
<?= csrf_field() ?><input type="hidden" name="reference" value="<?= escape_html($reference) ?>">
<label class="form-label" for="cancelReason">Cancellation reason</label>
<textarea class="form-control" id="cancelReason" name="reason" rows="4" maxlength="2000" required></textarea>
<button class="btn btn-outline-danger mt-3" type="submit">Request Cancellation</button>
</form>
<?php elseif (in_array($booking['status'], ['pending','confirmed','ready'], true)): ?>
<div class="alert alert-warning mt-3">The scheduled pickup time has already passed. Contact VJ Car Rental support for assistance.</div>
<?php else: ?><div class="alert alert-warning mt-3">This booking is no longer eligible for normal pre-pickup cancellation.</div><?php endif; ?>
</article>
<?php if ($refunds): ?><article class="operation-card mt-4" id="refunds"><span class="section-kicker">Refund history</span><h2>Recorded refund transactions</h2><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Amount</th><th>Payment</th><th>Status</th><th>Processed</th><th>Reference</th></tr></thead><tbody><?php foreach ($refunds as $refund): ?><tr><td><?= money((int) $refund['amount']) ?></td><td><?= escape_html(ucwords(str_replace('_',' ', $refund['payment_type']))) ?></td><td><span class="status-badge status-badge--<?= status_class($refund['status']) ?>"><?= escape_html(ucfirst($refund['status'])) ?></span></td><td><?= $refund['processed_at'] ? date('M j, Y g:i A', strtotime($refund['processed_at'])) : '—' ?></td><td><?= escape_html((string) ($refund['reference_number'] ?: '—')) ?></td></tr><?php endforeach; ?></tbody></table></div></article><?php endif; ?>
</div>
<div class="col-lg-5"><aside class="booking-summary-card sticky-lg-top"><span class="section-kicker">Refund estimate</span><div class="summary-line"><span>Current booking total</span><strong><?= money((int) $booking['total']) ?></strong></div><div class="summary-line"><span>Verified paid amount</span><strong><?= money($preview['paid']) ?></strong></div><div class="summary-line"><span>Refundable</span><strong><?= money($preview['refundable']) ?></strong></div><div class="summary-line"><span>Non-refundable</span><strong><?= money($preview['non_refundable']) ?></strong></div><p class="display-note mt-3">Final refund records are created only after Admin approves an eligible cancellation. Refunds remain linked to the original verified payment.</p></aside></div>
</div>
</div></section>
<?php require dirname(__DIR__, 2) . '/includes/footer.php'; ?>
