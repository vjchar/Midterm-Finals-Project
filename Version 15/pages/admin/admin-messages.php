<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";
$admin = require_admin();
$errors = [];
$validStatuses = ["new", "read", "closed"];
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        require_csrf();
        $messageId = (int) post_string("message_id");
        $status = post_string("status");
        if (!in_array($status, $validStatuses, true)) {
            throw new InvalidArgumentException(
                "Choose a valid message status.",
            );
        }
        $statement = database()->prepare(
            "UPDATE contact_messages SET status = ?, updated_at = ? WHERE id = ?",
        );
        $statement->execute([$status, date("Y-m-d H:i:s"), $messageId]);
        if (!$statement->rowCount()) {
            throw new RuntimeException("Contact message not found.");
        }
        write_audit(
            "contact_message_updated",
            "contact_message",
            $messageId,
            ["status" => $status],
        );
        flash("success", "Enquiry status updated.");
        redirect(
            "admin-messages.php?status=" .
                urlencode((string) ($_GET["status"] ?? "all")),
        );
    } catch (Throwable $error) {
        $errors[] = user_facing_error_message($error);
    }
}
$filter = (string) ($_GET["status"] ?? "new");
if (!in_array($filter, array_merge(["all"], $validStatuses), true)) {
    $filter = "new";
}
$sql = "SELECT * FROM contact_messages";
$parameters = [];
if ($filter !== "all") {
    $sql .= " WHERE status = ?";
    $parameters[] = $filter;
}
$sql .= " ORDER BY created_at DESC";
$statement = database()->prepare($sql);
$statement->execute($parameters);
$messages = $statement->fetchAll();
$pageTitle = "Contact Enquiries | VJ Car Rental";
require dirname(__DIR__, 2) . "/includes/header.php";
require dirname(__DIR__, 2) . "/includes/admin-nav.php";
?>
<section class="admin-page-heading">
    <div class="container">
        <div>
            <span class="section-kicker">Customer care inbox</span>
            <h1>Contact enquiries</h1>
            <p>Track new messages, active follow-ups, and resolved requests.</p>
        </div>
    </div>
</section>
<section class="content-section admin-section">
    <div class="container">
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-danger"><?= escape_html($error) ?></div>
        <?php endforeach; ?>
        <div class="admin-filter-tabs">
            <?php foreach (array_merge(["all"], $validStatuses) as $status): ?>
                <a class="<?= $filter === $status
                    ? "active"
                    : "" ?>" href="admin-messages.php?status=<?= $status ?>"><?= humanize_label(
    $status,
) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="admin-message-list">
            <?php foreach ($messages as $message): ?>
                <article class="admin-message-card">
                    <header>
                        <div>
                            <span class="status-badge status-badge--<?= status_class(
                                $message["status"],
                            ) ?>"><?= escape_html(
    humanize_label($message["status"]),
) ?></span>
                            <h2><?= escape_html($message["subject"]) ?></h2>
                            <small><?= date(
                                "M j, Y g:i A",
                                strtotime($message["created_at"]),
                            ) ?></small>
                        </div>
                        <a href="mailto:<?= escape_html(
                            $message["email"],
                        ) ?>?subject=Re:%20<?= rawurlencode(
    $message["subject"],
) ?>" class="btn btn-outline btn-sm">
                            <i class="bi bi-reply"></i> Reply by email</a>
                    </header>
                    <p><?= nl2br(escape_html($message["message"])) ?></p>
                    <footer>
                        <span>
                            <strong><?= escape_html($message["name"]) ?></strong>
                            <small>
                                <a href="mailto:<?= escape_html(
                                    $message["email"],
                                ) ?>"><?= escape_html(
    $message["email"],
) ?></a><?= $message["phone"] ? " • " . escape_html($message["phone"]) : "" ?></small>
                        </span>
                        <form method="post" class="admin-inline-form"><?= csrf_field() ?><input type="hidden" name="message_id" value="<?= (int) $message[
    "id"
] ?>">
                            <select class="form-select" name="status" aria-label="Message status">
                                <?php foreach ($validStatuses as $status): ?>
                                    <option value="<?= $status ?>" <?= $message[
    "status"
] === $status
    ? "selected"
    : "" ?>><?= humanize_label($status) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-primary" type="submit">Save</button>
                        </form>
                    </footer>
                </article>
            <?php endforeach; ?>
            <?php if (!$messages): ?>
                <div class="empty-state">
                    <i class="bi bi-inbox"></i>
                    <h3>No messages here</h3>
                    <p>The selected enquiry queue is currently clear.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
