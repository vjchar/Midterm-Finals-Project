<?php

declare(strict_types=1);


/**
 * FILE: pages/account/notifications.php
 * FILE PURPOSE: Customer notification inbox and notification-status page.
 * USED BY: Authenticated customers using their account area.
 * RESPONSIBILITY: Loads the required application/services, handles only page-level request orchestration, and renders the user interface; reusable business/database logic belongs in services.
 *
 * Maintenance note: Keep this file focused on the responsibility described above.
 */
require dirname(__DIR__, 2) . "/includes/bootstrap.php";
$user = require_auth();
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    require_csrf();
    mark_notifications_read($user["id"]);
    flash("success", "All notifications marked as read.");
    redirect("notifications.php");
}
$notifications = notifications_for_user($user["id"]);
$pageTitle = "Notifications | VJ Car Rental";
require dirname(__DIR__, 2) . "/includes/header.php";
?>
<section class="page-hero page-hero--compact pattern-layer">
    <div class="container">
        <span class="section-kicker">Account updates</span>
        <h1>Notifications</h1>
        <p>Follow booking, payment, review, document, pickup, return, and operational updates in one place.</p>
    </div>
</section>
<section class="content-section operations-page">
    <div class="container">
        <div class="section-toolbar">
            <div>
                <span class="section-kicker">Latest activity</span>
                <h2>Your updates</h2>
            </div>
            <?php if ($notifications): ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <button class="btn btn-vj-outline" type="submit">
                        <i class="bi bi-check2-all"></i>
                        Mark All Read
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <?php if (!$notifications): ?>
            <div class="empty-state">
                <i class="bi bi-bell"></i>
                <h2>No notifications yet</h2>
                <p>Booking and account updates will appear here.</p>
            </div>
        <?php else: ?>
            <div class="notification-list">
                <?php foreach ($notifications as $notification): ?>
                    <article class="notification-item<?= $notification["is_read"]
                        ? ""
                        : " is-unread" ?>">
                        <i class="bi <?= match ($notification["type"]) {
                            "payment" => "bi-credit-card",
                            "document" => "bi-person-vcard",
                            "rental" => "bi-key",
                            "booking" => "bi-calendar2-check",
                            default => "bi-bell",
                        } ?>"></i>
                        <div>
                            <div>
                                <strong><?= escape_html($notification["title"]) ?></strong>
                                <time><?= date(
                                    "M j, Y g:i A",
                                    strtotime($notification["created_at"]),
                                ) ?></time>
                            </div>
                            <p><?= escape_html($notification["message"]) ?></p>
                            <?php $notificationAction = notification_journey_action($notification); ?>
                            <a href="<?= escape_html($notificationAction["url"]) ?>"><?= escape_html($notificationAction["label"]) ?></a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
