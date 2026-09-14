<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";
$admin = require_admin();
$errors = [];
$validStatuses = ["pending", "approved", "rejected"];
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        require_csrf();
        $reviewId = (int) post_string("review_id");
        $status = post_string("status");
        if (!in_array($status, $validStatuses, true)) {
            throw new InvalidArgumentException(
                "Choose a valid review decision.",
            );
        }
        $statement = database()->prepare(
            "SELECT r.id, r.status, r.user_id, r.booking_id, b.reference FROM reviews r JOIN bookings b ON b.id = r.booking_id WHERE r.id = ? LIMIT 1",
        );
        $statement->execute([$reviewId]);
        $review = $statement->fetch();
        if (!$review) {
            throw new RuntimeException("Review not found.");
        }
        $update = database()->prepare(
            "UPDATE reviews SET status = ?, updated_at = ? WHERE id = ?",
        );
        $update->execute([$status, date("Y-m-d H:i:s"), $reviewId]);
        notify_user(
            (int) $review["user_id"],
            "Review " . $status,
            "Your review for booking " . $review["reference"] . " was marked " . $status . ".",
            "review",
            (int) $review["booking_id"],
        );
        write_audit("review_moderated", "review", $reviewId, [
            "from" => $review["status"],
            "to" => $status,
        ]);
        flash("success", "Review status updated to " . $status . ".");
        redirect(
            "admin-reviews.php?status=" .
                urlencode((string) ($_GET["status"] ?? "all")),
        );
    } catch (Throwable $error) {
        $errors[] = user_facing_error_message($error);
    }
}
$filter = (string) ($_GET["status"] ?? "pending");
if (!in_array($filter, array_merge(["all"], $validStatuses), true)) {
    $filter = "pending";
}
$sql =
    "SELECT r.*, u.name AS customer_name, u.email AS customer_email, v.name AS vehicle_name, b.reference FROM reviews r JOIN users u ON u.id=r.user_id JOIN vehicles v ON v.id=r.vehicle_id JOIN bookings b ON b.id=r.booking_id";
$parameters = [];
if ($filter !== "all") {
    $sql .= " WHERE r.status = ?";
    $parameters[] = $filter;
}
$sql .= " ORDER BY r.created_at DESC";
$statement = database()->prepare($sql);
$statement->execute($parameters);
$reviews = $statement->fetchAll();
$photoStatement = database()->prepare(
    "SELECT id, filename FROM review_photos WHERE review_id = ? ORDER BY id",
);
$pageTitle = "Review Moderation | VJ Car Rental";
require dirname(__DIR__, 2) . "/includes/header.php";
require dirname(__DIR__, 2) . "/includes/admin-nav.php";
?>
<section class="admin-page-heading">
    <div class="container">
        <div>
            <span class="section-kicker">Verified trip feedback</span>
            <h1>Moderate reviews</h1>
            <p>Approve useful customer feedback and reject content that is unsafe, irrelevant, or abusive.</p>
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
                    : "" ?>" href="admin-reviews.php?status=<?= $status ?>"><?= ucfirst(
    $status,
) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="admin-review-list">
            <?php foreach ($reviews as $review):

                $photoStatement->execute([(int) $review["id"]]);
                $photos = $photoStatement->fetchAll();
                ?>
                <article class="admin-review-card">
                    <header>
                        <div>
                            <span class="status-badge status-badge--<?= status_class(
                                $review["status"],
                            ) ?>"><?= escape_html(ucfirst($review["status"])) ?></span>
                            <h2><?= escape_html($review["title"]) ?></h2>
                            <small><?= escape_html(
                                $review["vehicle_name"],
                            ) ?> • <?= escape_html($review["reference"]) ?> • <?= date(
     "M j, Y",
     strtotime($review["created_at"]),
 ) ?></small>
                        </div>
                        <strong class="admin-review-score">
                            <i class="bi bi-star-fill"></i> <?= (int) $review[
                                "overall"
                            ] ?>/5</strong>
                    </header>
                    <p><?= nl2br(escape_html($review["body"])) ?></p>
                    <div class="review-score-grid">
                        <?php foreach (
                            [
                                "cleanliness" => "Cleanliness",
                                "comfort" => "Comfort",
                                "vehicle_condition" => "Condition",
                                "pickup_experience" => "Pickup",
                                "customer_support" => "Support",
                            ]
                            as $key => $label
                        ): ?>
                            <span>
                                <small><?= $label ?></small>
                                <strong><?= (int) $review[$key] ?>/5</strong>
                            </span>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($photos): ?>
                        <div class="admin-review-photos">
                            <?php foreach ($photos as $photo): ?>
                                <img src="review-photo.php?id=<?= (int) $photo[
                                    "id"
                                ] ?>" alt="Customer review upload">
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <footer>
                        <span>
                            <strong><?= escape_html(
                                $review["customer_name"],
                            ) ?></strong>
                            <small><?= escape_html(
                                $review["customer_email"],
                            ) ?></small>
                        </span>
                        <form method="post" class="admin-inline-form"><?= csrf_field() ?><input type="hidden" name="review_id" value="<?= (int) $review[
    "id"
] ?>">
                            <select class="form-select" name="status" aria-label="Review decision">
                                <?php foreach ($validStatuses as $status): ?>
                                    <option value="<?= $status ?>" <?= $review[
    "status"
] === $status
    ? "selected"
    : "" ?>><?= ucfirst($status) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-primary" type="submit">Save Decision</button>
                        </form>
                    </footer>
                </article>
            <?php
            endforeach; ?>
            <?php if (!$reviews): ?>
                <div class="empty-state">
                    <i class="bi bi-chat-square-heart"></i>
                    <h3>No <?= escape_html(
                        $filter === "all" ? "" : $filter,
                    ) ?> reviews</h3>
                    <p>Reviews in this queue will appear here.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
