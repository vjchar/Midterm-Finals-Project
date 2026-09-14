<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_admin();
$view = in_array($_GET['view'] ?? 'month', ['month','week','day'], true) ? (string) $_GET['view'] : 'month';
$dateValue = valid_date((string) ($_GET['date'] ?? '')) ? (string) $_GET['date'] : date('Y-m-d');
$anchor = new DateTimeImmutable($dateValue . ' 00:00:00');
$type = in_array($_GET['type'] ?? 'all', ['all','pickup','delivery','return','active','maintenance','extension','early_return'], true) ? (string) $_GET['type'] : 'all';
$vehicleId = filter_var($_GET['vehicle'] ?? null, FILTER_VALIDATE_INT) ?: null;
if ($view === 'day') {
    $rangeStart = $anchor;
    $rangeEnd = $anchor->modify('+1 day');
} elseif ($view === 'week') {
    $rangeStart = $anchor->modify('monday this week');
    $rangeEnd = $rangeStart->modify('+7 days');
} else {
    $monthStart = $anchor->modify('first day of this month');
    $rangeStart = $monthStart->modify('monday this week');
    $monthEnd = $anchor->modify('last day of this month');
    $rangeEnd = $monthEnd->modify('sunday this week')->modify('+1 day');
}
$events = rental_calendar_events($rangeStart, $rangeEnd, $type, $vehicleId ? (int) $vehicleId : null);
$eventsByDate = [];
foreach ($events as $event) {
    $key = date('Y-m-d', strtotime((string) $event['start_at']));
    $eventsByDate[$key][] = $event;
}
$vehicles = vehicle_all(false);
$previous = $view === 'month' ? $anchor->modify('-1 month') : ($view === 'week' ? $anchor->modify('-7 days') : $anchor->modify('-1 day'));
$next = $view === 'month' ? $anchor->modify('+1 month') : ($view === 'week' ? $anchor->modify('+7 days') : $anchor->modify('+1 day'));
$pageTitle = 'Rental Calendar | VJ Car Rental';
require dirname(__DIR__, 2) . '/includes/header.php';
require dirname(__DIR__, 2) . '/includes/admin-nav.php';
?>
<section class="admin-page-heading"><div class="container"><div><span class="section-kicker">Fleet operations</span><h1>Rental calendar</h1><p>See pickups, deliveries, returns, active rentals, maintenance, and approved rental adjustments from authoritative system records.</p></div></div></section>
<section class="content-section admin-section"><div class="container">
<form class="calendar-toolbar" method="get">
<div class="calendar-view-switch"><?php foreach (['month'=>'Month','week'=>'Week','day'=>'Day'] as $key=>$label): ?><a class="<?= $view === $key ? 'active' : '' ?>" href="admin-calendar.php?view=<?= $key ?>&amp;date=<?= urlencode($dateValue) ?>&amp;type=<?= urlencode($type) ?><?= $vehicleId ? '&amp;vehicle=' . (int) $vehicleId : '' ?>"><?= $label ?></a><?php endforeach; ?></div>
<select class="form-select" name="type"><option value="all">All events</option><?php foreach (['pickup'=>'Pickup','delivery'=>'Delivery','return'=>'Return','active'=>'Active rental','maintenance'=>'Maintenance','extension'=>'Extension','early_return'=>'Expected early return'] as $key=>$label): ?><option value="<?= $key ?>" <?= $type===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select>
<select class="form-select" name="vehicle"><option value="">All vehicles</option><?php foreach ($vehicles as $vehicle): ?><option value="<?= (int) $vehicle['id'] ?>" <?= (int) $vehicleId === (int) $vehicle['id'] ? 'selected' : '' ?>><?= escape_html($vehicle['name']) ?></option><?php endforeach; ?></select>
<input type="hidden" name="view" value="<?= escape_html($view) ?>"><input class="form-control" type="date" name="date" value="<?= escape_html($dateValue) ?>"><button class="btn btn-primary" type="submit">Apply</button>
</form>
<div class="calendar-navigation"><a class="btn btn-outline btn-sm" href="admin-calendar.php?view=<?= $view ?>&amp;date=<?= $previous->format('Y-m-d') ?>&amp;type=<?= urlencode($type) ?><?= $vehicleId ? '&amp;vehicle=' . (int) $vehicleId : '' ?>">← Previous</a><h2><?= $view === 'month' ? $anchor->format('F Y') : ($view === 'week' ? $rangeStart->format('M j') . '–' . $rangeEnd->modify('-1 day')->format('M j, Y') : $anchor->format('F j, Y')) ?></h2><a class="btn btn-outline btn-sm" href="admin-calendar.php?view=<?= $view ?>&amp;date=<?= $next->format('Y-m-d') ?>&amp;type=<?= urlencode($type) ?><?= $vehicleId ? '&amp;vehicle=' . (int) $vehicleId : '' ?>">Next →</a></div>
<?php if ($view === 'month'): ?>
<div class="rental-calendar-grid"><div class="calendar-weekdays"><?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $day): ?><span><?= $day ?></span><?php endforeach; ?></div><div class="calendar-days">
<?php for ($cursor=$rangeStart; $cursor<$rangeEnd; $cursor=$cursor->modify('+1 day')): $dayKey=$cursor->format('Y-m-d'); $inMonth=$cursor->format('m')===$anchor->format('m'); ?><article class="calendar-day <?= $inMonth ? '' : 'is-outside' ?> <?= $dayKey===date('Y-m-d')?'is-today':'' ?>"><header><strong><?= $cursor->format('j') ?></strong></header><div class="calendar-day-events"><?php foreach ($eventsByDate[$dayKey] ?? [] as $event): ?><a class="calendar-event calendar-event--<?= escape_html($event['type']) ?>" href="#event-<?= escape_html($event['key']) ?>"><span><?= escape_html(ucwords(str_replace('_',' ', $event['type']))) ?></span><strong><?= escape_html($event['vehicle_name']) ?></strong><small><?= date('g:i A', strtotime($event['start_at'])) ?></small></a><?php endforeach; ?></div></article><?php endfor; ?>
</div></div>
<?php else: ?>
<div class="calendar-agenda"><?php for ($cursor=$rangeStart; $cursor<$rangeEnd; $cursor=$cursor->modify('+1 day')): $dayKey=$cursor->format('Y-m-d'); ?><section class="calendar-agenda-day"><h3><?= $cursor->format('l, F j') ?></h3><?php foreach ($eventsByDate[$dayKey] ?? [] as $event): ?><a class="calendar-event calendar-event--<?= escape_html($event['type']) ?>" href="#event-<?= escape_html($event['key']) ?>"><span><?= date('g:i A', strtotime($event['start_at'])) ?> · <?= escape_html(ucwords(str_replace('_',' ', $event['type']))) ?></span><strong><?= escape_html($event['title']) ?></strong></a><?php endforeach; ?><?php if (!($eventsByDate[$dayKey] ?? [])): ?><p class="display-note">No matching events.</p><?php endif; ?></section><?php endfor; ?></div>
<?php endif; ?>
<article class="operation-card mt-4"><span class="section-kicker">Event details</span><h2>Operational schedule</h2><?php if (!$events): ?><p class="display-note">No events match the selected period and filters.</p><?php else: ?><div class="calendar-event-details"><?php foreach ($events as $event): ?><section id="event-<?= escape_html($event['key']) ?>" class="calendar-detail-card"><div><span class="status-badge status-badge--<?= status_class($event['status']) ?>"><?= escape_html(ucwords(str_replace('_',' ', $event['type']))) ?></span><h3><?= escape_html($event['title']) ?></h3><p><?= escape_html($event['meta']) ?></p></div><dl><div><dt>Schedule</dt><dd><?= date('M j, Y g:i A', strtotime($event['start_at'])) ?></dd></div><?php if ($event['booking_reference']): ?><div><dt>Booking</dt><dd><?= escape_html($event['booking_reference']) ?></dd></div><?php endif; ?><?php if ($event['customer_name']): ?><div><dt>Customer</dt><dd><?= escape_html($event['customer_name']) ?></dd></div><?php endif; ?><div><dt>Status</dt><dd><?= escape_html(ucwords(str_replace('_',' ', $event['status']))) ?></dd></div></dl><a class="btn btn-outline btn-sm" href="<?= escape_html($event['url']) ?>">Open Record</a></section><?php endforeach; ?></div><?php endif; ?></article>
</div></section>
<?php require dirname(__DIR__, 2) . '/includes/footer.php'; ?>
