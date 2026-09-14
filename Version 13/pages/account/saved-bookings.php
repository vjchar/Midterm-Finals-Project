<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";
$user = require_customer();
$errors = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        require_csrf();
        $action = post_string("action");
        $draftId = filter_var($_POST["draft_id"] ?? null, FILTER_VALIDATE_INT);
        if (!$draftId) {
            throw new InvalidArgumentException("Choose a valid saved booking.");
        }
        if ($action !== "discard") {
            throw new InvalidArgumentException("Choose a valid saved-booking action.");
        }
        discard_booking_draft((int) $draftId, (int) $user["id"]);
        flash("success", "Saved booking discarded. No vehicle reservation was affected.");
        redirect("saved-bookings.php");
    } catch (Throwable $error) {
        $errors[] = user_facing_error_message($error);
    }
}

$drafts = booking_drafts_for_user((int) $user["id"]);
$selectedDraft = null;
$selectedPreview = null;
$selectedDraftId = max(0, (int) ($_GET["draft"] ?? 0));
if ($selectedDraftId > 0) {
    $selectedDraft = booking_draft_find_owned($selectedDraftId, (int) $user["id"], true);
    if ($selectedDraft) {
        $selectedPreview = booking_draft_preview($selectedDraft);
    }
}

$pageTitle = "Saved Bookings | VJ Car Rental";
$pageDescription = "Resume, review, or discard booking drafts without reserving a vehicle until final confirmation.";
require dirname(__DIR__, 2) . "/includes/header.php";
?>
<section class="page-hero page-hero--compact pattern-layer">
    <div class="container">
        <span class="section-kicker">Version 13 · Save for later</span>
        <h1>Saved bookings</h1>
        <p>Plan a rental now and decide later. A saved booking is a draft only—the vehicle and price are rechecked when you continue.</p>
    </div>
</section>

<section class="content-section operations-page">
    <div class="container">
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-danger"><?= escape_html($error) ?></div>
        <?php endforeach; ?>
        <?php if (isset($_GET["saved"])): ?>
            <div class="alert alert-success"><strong>Booking saved.</strong> The vehicle is not reserved yet. Availability and pricing will be checked again before you proceed.</div>
        <?php endif; ?>

        <div class="draft-rule-callout">
            <i class="bi bi-info-circle"></i>
            <div>
                <strong>A saved booking is not a reservation.</strong>
                <p>Other customers may still book the vehicle. Current availability, maintenance, pricing, add-ons, and promotions are revalidated before conversion.</p>
            </div>
        </div>

        <?php if ($selectedDraft): ?>
            <?php
            $selectedPickup = new DateTimeImmutable((string) $selectedDraft["pickup_at"]);
            $selectedReturn = new DateTimeImmutable((string) $selectedDraft["return_at"]);
            $selectedImage = "assets/images/cars/" . basename((string) $selectedDraft["vehicle_image"]);
            ?>
            <article class="draft-detail-card">
                <div class="draft-detail-card__visual">
                    <img src="<?= escape_html($selectedImage) ?>" alt="<?= escape_html((string) $selectedDraft["vehicle_name"]) ?>">
                </div>
                <div class="draft-detail-card__body">
                    <div class="draft-detail-card__heading">
                        <div>
                            <span class="section-kicker">Saved booking <?= escape_html((string) $selectedDraft["reference"]) ?></span>
                            <h2><?= escape_html((string) $selectedDraft["vehicle_name"]) ?></h2>
                        </div>
                        <span class="status-badge status-badge--<?= status_class((string) $selectedDraft["status"]) ?>"><?= escape_html(ucfirst((string) $selectedDraft["status"])) ?></span>
                    </div>
                    <div class="draft-detail-grid">
                        <div><span>Pickup</span><strong><?= $selectedPickup->format("M j, Y · g:i A") ?></strong></div>
                        <div><span>Return</span><strong><?= $selectedReturn->format("M j, Y · g:i A") ?></strong></div>
                        <div><span>Fulfillment</span><strong><?= escape_html((string) $selectedDraft["pickup_method"]) ?></strong></div>
                        <div><span>Saved estimate</span><strong><?= money((int) $selectedDraft["estimated_total"]) ?></strong></div>
                        <div><span>Last saved</span><strong><?= date("M j, Y · g:i A", strtotime((string) $selectedDraft["last_saved_at"])) ?></strong></div>
                        <div><span>Draft expires</span><strong><?= date("M j, Y · g:i A", strtotime((string) $selectedDraft["expires_at"])) ?></strong></div>
                    </div>

                    <?php if ($selectedPreview && ($selectedPreview["promo_warning"] ?? "") !== ""): ?>
                        <div class="alert alert-warning mt-3"><?= escape_html((string) $selectedPreview["promo_warning"]) ?></div>
                    <?php endif; ?>

                    <?php if ($selectedPreview && $selectedPreview["details"]): ?>
                        <div class="draft-current-check <?= $selectedPreview["available"] ? "is-available" : "is-unavailable" ?>">
                            <div>
                                <span>Current availability</span>
                                <strong><?= $selectedPreview["available"] ? "Vehicle Available" : "Vehicle Unavailable" ?></strong>
                            </div>
                            <div>
                                <span>Current total</span>
                                <strong><?= money((int) $selectedPreview["current_total"]) ?></strong>
                            </div>
                            <div>
                                <span>Price difference</span>
                                <strong><?= (int) $selectedPreview["difference"] === 0
                                    ? "No change"
                                    : (((int) $selectedPreview["difference"] > 0 ? "+" : "−") . money(abs((int) $selectedPreview["difference"]))) ?></strong>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning mt-3"><?= escape_html((string) ($selectedPreview["error"] ?? "This draft needs updated booking details before it can continue.")) ?></div>
                    <?php endif; ?>

                    <?php if (($selectedDraft["status"] ?? "") === "active"): ?>
                        <div class="draft-actions">
                            <a class="btn btn-primary" href="booking.php?draft=<?= (int) $selectedDraft["id"] ?>"><i class="bi bi-arrow-right-circle"></i> Continue Booking</a>
                            <a class="btn btn-outline" href="booking.php?draft=<?= (int) $selectedDraft["id"] ?>"><i class="bi bi-pencil"></i> Edit Draft</a>
                            <form method="post" data-confirm="Discard this saved booking? The vehicle was never reserved by this draft.">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="discard">
                                <input type="hidden" name="draft_id" value="<?= (int) $selectedDraft["id"] ?>">
                                <button class="btn btn-outline-danger" type="submit"><i class="bi bi-trash"></i> Discard Draft</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="draft-actions">
                            <a class="btn btn-primary" href="booking.php?source_draft=<?= (int) $selectedDraft["id"] ?>"><i class="bi bi-arrow-repeat"></i> Use These Details Again</a>
                            <a class="btn btn-outline" href="booking.php?vehicle=<?= urlencode((string) $selectedDraft["vehicle_slug"]) ?>"><i class="bi bi-plus-circle"></i> Start a New Booking</a>
                        </div>
                    <?php endif; ?>
                </div>
            </article>
        <?php endif; ?>

        <div class="section-heading section-heading--split mt-5">
            <div><span class="section-kicker">Your plans</span><h2>Active saved bookings</h2></div>
            <a class="btn btn-primary" href="booking.php"><i class="bi bi-plus-circle"></i> Plan Another Rental</a>
        </div>

        <?php if (!$drafts): ?>
            <div class="empty-state">
                <i class="bi bi-bookmark"></i>
                <h2>No saved bookings yet</h2>
                <p>Build a rental and choose <strong>Save for Later</strong> when you want time to decide before creating a reservation.</p>
                <a class="btn btn-primary" href="booking.php">Build a Rental</a>
            </div>
        <?php else: ?>
            <div class="saved-drafts-grid">
                <?php foreach ($drafts as $draft): ?>
                    <?php
                    $pickup = new DateTimeImmutable((string) $draft["pickup_at"]);
                    $return = new DateTimeImmutable((string) $draft["return_at"]);
                    $image = "assets/images/cars/" . basename((string) $draft["vehicle_image"]);
                    ?>
                    <article class="saved-draft-card">
                        <img src="<?= escape_html($image) ?>" alt="<?= escape_html((string) $draft["vehicle_name"]) ?>" loading="lazy">
                        <div class="saved-draft-card__body">
                            <span class="section-kicker"><?= escape_html((string) $draft["reference"]) ?></span>
                            <h3><?= escape_html((string) $draft["vehicle_name"]) ?></h3>
                            <dl>
                                <div><dt>Dates</dt><dd><?= $pickup->format("M j") ?> – <?= $return->format("M j, Y") ?></dd></div>
                                <div><dt>Fulfillment</dt><dd><?= escape_html((string) $draft["pickup_method"]) ?></dd></div>
                                <div><dt>Estimated total</dt><dd><?= money((int) $draft["estimated_total"]) ?></dd></div>
                                <div><dt>Last saved</dt><dd><?= date("M j · g:i A", strtotime((string) $draft["last_saved_at"])) ?></dd></div>
                            </dl>
                            <div class="saved-draft-card__actions">
                                <a class="btn btn-primary btn-sm" href="booking.php?draft=<?= (int) $draft["id"] ?>">Continue Booking</a>
                                <a class="btn btn-outline btn-sm" href="saved-bookings.php?draft=<?= (int) $draft["id"] ?>">View Details</a>
                                <form method="post" data-confirm="Discard this saved booking?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="discard">
                                    <input type="hidden" name="draft_id" value="<?= (int) $draft["id"] ?>">
                                    <button class="btn btn-outline btn-sm" type="submit">Discard</button>
                                </form>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
