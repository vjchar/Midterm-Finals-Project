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
    echo '<section class="content-section error-page"><div class="container"><h1>Booking not found</h1><p>The reservation is not available to this account.</p><a class="btn btn-primary" href="my-bookings.php">My Bookings</a></div></section>';
    require dirname(__DIR__, 2) . '/includes/footer.php';
    exit;
}

$errors = [];
$preview = null;
$currentInput = booking_modification_input_from_booking($booking);
$formInput = $currentInput;
$reasonValue = '';
$existing = unresolved_booking_modification((int) $booking['id']);
$history = booking_modifications_for_booking((int) $booking['id']);
$vehicles = vehicle_all();
$addons = addon_all();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_csrf();
        $action = post_string('action');
        if ($action === 'cancel_request') {
            $modificationId = filter_var($_POST['modification_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$modificationId) {
                throw new InvalidArgumentException('Choose a valid modification request.');
            }
            cancel_pending_booking_modification((int) $modificationId, (int) $user['id']);
            flash('success', 'Modification request cancelled.');
            redirect('booking-modification.php?reference=' . urlencode($reference));
        }
        $reasonValue = post_string('reason');
        $formInput = [
            'vehicle' => post_string('vehicle'),
            'pickup' => post_string('pickup'),
            'return' => post_string('return'),
            'pickup_time' => post_string('pickup_time', '09:00'),
            'return_time' => post_string('return_time', '09:00'),
            'pickup_method' => post_string('pickup_method', 'Branch pickup'),
            'location' => post_string('location'),
            'delivery_address' => post_string('delivery_address'),
            'addons' => is_array($_POST['addons'] ?? null) ? $_POST['addons'] : [],
            'promo' => post_string('promo'),
            'special_requests' => post_string('special_requests'),
        ];
        $preview = preview_booking_modification($booking, $formInput);
        if ($action === 'submit_request') {
            request_booking_modification($booking, (int) $user['id'], $formInput, $reasonValue);
            flash('success', 'Booking modification request submitted for administrator review.');
            redirect('booking-view.php?reference=' . urlencode($reference));
        }
    } catch (Throwable $error) {
        $errors[] = user_facing_error_message($error);
    }
}

$pageTitle = 'Modify Booking ' . $booking['reference'] . ' | VJ Car Rental';
require dirname(__DIR__, 2) . '/includes/header.php';
?>
<section class="page-hero page-hero--compact pattern-layer">
    <div class="container">
        <span class="section-kicker">Pre-pickup adjustment</span>
        <h1>Request booking modification</h1>
        <p>Your current booking remains authoritative until an approved change is fully activated.</p>
    </div>
</section>
<section class="content-section operations-page">
<div class="container">
    <?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= escape_html($error) ?></div><?php endforeach; ?>

    <?php if ($existing): ?>
        <article class="operation-card mb-4">
            <span class="section-kicker">Existing request</span>
            <h2><?= escape_html(ucwords(str_replace('_', ' ', $existing['status']))) ?></h2>
            <p>You already have a booking modification request in progress. Your current reservation remains unchanged until activation.</p>
            <div class="confirmation-details">
                <span><small>Requested</small><strong><?= date('M j, Y g:i A', strtotime($existing['requested_at'])) ?></strong></span>
                <span><small>Price difference</small><strong><?= ((int) $existing['price_difference'] >= 0 ? '+' : '−') . money(abs((int) $existing['price_difference'])) ?></strong></span>
            </div>
            <?php if ($existing['status'] === 'pending'): ?>
                <form method="post" class="mt-3" data-confirm="Cancel this pending modification request?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="reference" value="<?= escape_html($reference) ?>">
                    <input type="hidden" name="action" value="cancel_request">
                    <input type="hidden" name="modification_id" value="<?= (int) $existing['id'] ?>">
                    <button class="btn btn-outline-danger" type="submit">Cancel Pending Request</button>
                </form>
            <?php elseif ($existing['status'] === 'approved' && (int) $existing['price_difference'] > 0): ?>
                <a class="btn btn-primary mt-3" href="payments.php?reference=<?= urlencode($reference) ?>">Pay Modification Balance</a>
            <?php endif; ?>
        </article>
    <?php elseif (!in_array($booking['status'], ['pending','confirmed','ready'], true)): ?>
        <div class="alert alert-warning">This booking is no longer eligible for pre-pickup modification. Active rentals use the separate extension and early-return workflow.</div>
    <?php else: ?>
        <div class="row g-4 align-items-start">
            <div class="col-lg-7">
                <form class="operation-card" method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="reference" value="<?= escape_html($reference) ?>">
                    <span class="section-kicker">Requested booking</span>
                    <h2>Choose the changes you want</h2>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label" for="modifyVehicle">Vehicle</label>
                            <select class="form-select" id="modifyVehicle" name="vehicle" required>
                                <?php foreach ($vehicles as $vehicle): ?>
                                    <option value="<?= (int) $vehicle['id'] ?>" <?= (string) $formInput['vehicle'] === (string) $vehicle['id'] ? 'selected' : '' ?>><?= escape_html($vehicle['name']) ?> — <?= money((int) $vehicle['price']) ?>/day</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6"><label class="form-label" for="modifyPickup">Pickup date</label><input class="form-control" id="modifyPickup" name="pickup" type="date" value="<?= escape_html((string) $formInput['pickup']) ?>" required></div>
                        <div class="col-md-6"><label class="form-label" for="modifyPickupTime">Pickup time</label><input class="form-control" id="modifyPickupTime" name="pickup_time" type="time" value="<?= escape_html((string) $formInput['pickup_time']) ?>" required></div>
                        <div class="col-md-6"><label class="form-label" for="modifyReturn">Return date</label><input class="form-control" id="modifyReturn" name="return" type="date" value="<?= escape_html((string) $formInput['return']) ?>" required></div>
                        <div class="col-md-6"><label class="form-label" for="modifyReturnTime">Return time</label><input class="form-control" id="modifyReturnTime" name="return_time" type="time" value="<?= escape_html((string) $formInput['return_time']) ?>" required></div>
                        <div class="col-md-6">
                            <label class="form-label" for="modifyMethod">Fulfillment</label>
                            <select class="form-select" id="modifyMethod" name="pickup_method" required>
                                <?php foreach (['Branch pickup','Vehicle delivery'] as $method): ?><option value="<?= escape_html($method) ?>" <?= $formInput['pickup_method'] === $method ? 'selected' : '' ?>><?= escape_html($method) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="modifyLocation">Branch</label>
                            <select class="form-select" id="modifyLocation" name="location" required>
                                <?php foreach (booking_locations() as $location): ?><option value="<?= escape_html($location) ?>" <?= $formInput['location'] === $location ? 'selected' : '' ?>><?= escape_html($location) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12"><label class="form-label" for="modifyDelivery">Delivery address</label><input class="form-control" id="modifyDelivery" name="delivery_address" value="<?= escape_html((string) $formInput['delivery_address']) ?>" maxlength="255"></div>
                        <div class="col-12">
                            <label class="form-label">Add-ons</label>
                            <div class="modification-addon-grid">
                            <?php foreach ($addons as $addon): ?><label class="draft-addon-option"><input type="checkbox" name="addons[]" value="<?= escape_html($addon['key']) ?>" <?= in_array($addon['key'], $formInput['addons'] ?? [], true) ? 'checked' : '' ?>><span><strong><?= escape_html($addon['name']) ?></strong><small><?= money((int) $addon['price']) ?> / <?= escape_html($addon['billing']) ?></small></span></label><?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-md-6"><label class="form-label" for="modifyPromo">Promo code</label><input class="form-control" id="modifyPromo" name="promo" value="<?= escape_html((string) $formInput['promo']) ?>" maxlength="40"></div>
                        <div class="col-12"><label class="form-label" for="modifyRequests">Special requests</label><textarea class="form-control" id="modifyRequests" name="special_requests" rows="2" maxlength="2000"><?= escape_html((string) $formInput['special_requests']) ?></textarea></div>
                        <div class="col-12"><label class="form-label" for="modifyReason">Reason for change</label><textarea class="form-control" id="modifyReason" name="reason" rows="2" maxlength="2000" placeholder="Optional context for the rental team"><?= escape_html($reasonValue) ?></textarea></div>
                    </div>
                    <button class="btn btn-outline mt-3" type="submit" name="action" value="preview">Preview Changes</button>
                    <?php if ($preview && $preview['available']): ?><button class="btn btn-primary mt-3" type="submit" name="action" value="submit_request">Submit Modification Request</button><?php endif; ?>
                </form>
            </div>
            <div class="col-lg-5">
                <aside class="booking-summary-card sticky-lg-top">
                    <span class="section-kicker">Current vs requested</span>
                    <h2>Modification preview</h2>
                    <div class="summary-line"><span>Current total</span><strong><?= money((int) $booking['total']) ?></strong></div>
                    <?php if ($preview): ?>
                        <div class="summary-line"><span>Requested vehicle</span><strong><?= escape_html($preview['details']['vehicle']['name']) ?></strong></div>
                        <div class="summary-line"><span>Requested pickup</span><strong><?= date('M j, Y g:i A', strtotime($preview['details']['pickup_at'])) ?></strong></div>
                        <div class="summary-line"><span>Requested return</span><strong><?= date('M j, Y g:i A', strtotime($preview['details']['return_at'])) ?></strong></div>
                        <div class="summary-line"><span>Updated total</span><strong><?= money((int) $preview['details']['total']) ?></strong></div>
                        <div class="summary-total"><span>Difference</span><strong><?= ((int) $preview['difference'] >= 0 ? '+' : '−') . money(abs((int) $preview['difference'])) ?></strong></div>
                        <div class="alert <?= $preview['available'] ? 'alert-success' : 'alert-danger' ?> mt-3"><?= $preview['available'] ? 'Requested vehicle and schedule are currently available. Admin will recheck them at approval.' : 'The requested period conflicts with another reservation or maintenance.' ?></div>
                    <?php else: ?>
                        <p class="display-note">Preview the requested changes before submitting them for Admin review.</p>
                    <?php endif; ?>
                </aside>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($history): ?>
        <article class="operation-card mt-4">
            <span class="section-kicker">History</span><h2>Booking modification requests</h2>
            <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Requested</th><th>Status</th><th>Price impact</th><th>Admin note</th></tr></thead><tbody>
            <?php foreach ($history as $item): ?><tr><td><?= date('M j, Y g:i A', strtotime($item['requested_at'])) ?></td><td><span class="status-badge status-badge--<?= status_class($item['status']) ?>"><?= escape_html(humanize_label($item['status'])) ?></span></td><td><?= ((int) $item['price_difference'] >= 0 ? '+' : '−') . money(abs((int) $item['price_difference'])) ?></td><td><?= escape_html((string) ($item['admin_note'] ?: '—')) ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </article>
    <?php endif; ?>
</div>
</section>
<?php require dirname(__DIR__, 2) . '/includes/footer.php'; ?>
