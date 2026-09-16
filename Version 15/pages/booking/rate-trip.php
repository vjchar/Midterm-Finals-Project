<?php

declare(strict_types=1);


/**
 * FILE: pages/booking/rate-trip.php
 * FILE PURPOSE: Completed-trip rating and customer review submission page.
 * USED BY: Customers progressing through booking, payment, rental, or post-trip workflows.
 * RESPONSIBILITY: Loads the required application/services, handles only page-level request orchestration, and renders the user interface; reusable business/database logic belongs in services.
 *
 * Maintenance note: Keep this file focused on the responsibility described above.
 */
require dirname(__DIR__, 2) . "/includes/bootstrap.php";
$user = require_customer();
$eligible = reviewable_bookings_for_user((int) $user["id"]);
$reference = trim(
    (string) ($_GET["reference"] ??
        ($_POST["reference"] ?? ($eligible[0]["reference"] ?? ""))),
);
$booking = null;
foreach ($eligible as $candidate) {
    if ($candidate["reference"] === $reference) {
        $booking = $candidate;
        break;
    }
}
$errors = [];
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        require_csrf();
        if (!$booking) {
            throw new RuntimeException(
                "This completed booking is not eligible for another review.",
            );
        }

        $scores = [];
        foreach (
            [
                "overall",
                "cleanliness",
                "comfort",
                "vehicle_condition",
                "pickup_experience",
                "customer_support",
            ] as $field
        ) {
            $scores[$field] = filter_var(
                $_POST[$field] ?? null,
                FILTER_VALIDATE_INT,
                ["options" => ["min_range" => 1, "max_range" => 5]],
            );
            if ($scores[$field] === false) {
                throw new InvalidArgumentException(
                    "Choose a score from 1 to 5 for every rating category.",
                );
            }
        }

        $title = post_string("title");
        $body = post_string("body");
        if (mb_strlen($title) < 5 || mb_strlen($title) > 160) {
            throw new InvalidArgumentException(
                "Use a review title between 5 and 160 characters.",
            );
        }
        if (mb_strlen($body) < 20 || mb_strlen($body) > 3000) {
            throw new InvalidArgumentException(
                "Write a review between 20 and 3,000 characters.",
            );
        }
        if (!isset($_POST["consent"])) {
            throw new InvalidArgumentException(
                "Confirm that the review describes your own rental.",
            );
        }

        $files = [];
        if (
            isset($_FILES["photos"]["name"]) &&
            is_array($_FILES["photos"]["name"])
        ) {
            foreach ($_FILES["photos"]["name"] as $index => $name) {
                if ($_FILES["photos"]["error"][$index] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $files[] = [
                    "name" => $name,
                    "type" => $_FILES["photos"]["type"][$index],
                    "tmp_name" => $_FILES["photos"]["tmp_name"][$index],
                    "error" => $_FILES["photos"]["error"][$index],
                    "size" => $_FILES["photos"]["size"][$index],
                ];
            }
        }
        if (count($files) > 3) {
            throw new InvalidArgumentException(
                "Upload no more than three review photos.",
            );
        }

        submit_vehicle_review(
            $booking,
            (int) $user["id"],
            $scores,
            $title,
            $body,
            $files,
        );
        flash(
            "success",
            "Thank you. Your verified-trip review is awaiting moderation.",
        );
        redirect(
            "booking-view.php?reference=" . urlencode($booking["reference"]),
        );
    } catch (Throwable $error) {
        $errors[] = user_facing_error_message($error);
    }
}
$pageTitle = "Rate a Completed Trip | VJ Car Rental";
require dirname(__DIR__, 2) . "/includes/header.php";
?>
<section class="page-hero page-hero--compact pattern-layer">
    <div class="container">
        <span class="section-kicker">Verified customer feedback</span>
        <h1>Rate a completed trip</h1>
        <p>Only bookings completed by your account can be reviewed, and each booking accepts one moderated review.</p>
    </div>
</section>
<section class="content-section rating-page">
    <div class="container">
        <?php if (!$eligible): ?>
            <div class="empty-state">
                <i class="bi bi-star"></i>
                <h2>No trips are ready for review</h2>
                <p>A rating becomes available after an administrator marks your booking as completed.</p>
                <a class="btn btn-primary" href="my-bookings.php">View My Bookings</a>
            </div>
        <?php else: ?>
            <div class="row g-4 g-xl-5">
                <div class="col-lg-7">
                    <form class="form rating-form" method="post" enctype="multipart/form-data"><?= csrf_field() ?><div class="form-heading">
                            <span class="section-kicker">Completed rental review</span>
                            <h2>Rate the vehicle and service</h2>
                        </div>
                        <?php foreach ($errors as $error): ?>
                            <div class="alert alert-danger"><?= escape_html(
                                $error,
                            ) ?></div>
                        <?php endforeach; ?>
                        <label class="form-label" for="ratingReference">Completed booking</label>
                        <div class="booking-selector booking-selector--within-form">
                            <select class="form-select" id="ratingReference" name="reference" required>
                            <?php foreach ($eligible as $option): ?>
                                <option value="<?= escape_html(
                                    $option["reference"],
                                ) ?>" <?= $option["reference"] === $reference
    ? "selected"
    : "" ?>><?= escape_html(
    $option["reference"] . " - " . $option["vehicle_name"],
) ?></option>
                            <?php endforeach; ?>
                            </select>
                            <button
                                class="btn btn-outline"
                                type="submit"
                                formmethod="get"
                                formnovalidate
                            >
                                Open trip
                            </button>
                        </div>
                        <fieldset class="overall-rating">
                            <legend>Overall rating</legend>
                            <div class="star-input">
                                <?php for ($star = 5; $star >= 1; $star--): ?>
                                    <input id="star<?= $star ?>" name="overall" type="radio" value="<?= $star ?>" <?= (int) ($_POST[
    "overall"
] ?? 0) === $star
    ? "checked"
    : "" ?> required>
                                    <label for="star<?= $star ?>" title="<?= $star ?> stars">&#9733;</label>
                                <?php endfor; ?>
                            </div>
                        </fieldset>
                        <div class="rating-categories">
                            <?php foreach (
                                [
                                    "cleanliness" => "Cleanliness",
                                    "comfort" => "Comfort",
                                    "vehicle_condition" => "Vehicle condition",
                                    "pickup_experience" => "Pickup experience",
                                    "customer_support" => "Customer support",
                                ]
                                as $field => $label
                            ): ?>
                                <label>
                                    <span><?= escape_html($label) ?></span>
                                    <select class="form-select" name="<?= escape_html(
                                        $field,
                                    ) ?>" required>
                                        <option value="">Choose score</option>
                                        <?php for (
                                            $score = 5;
                                            $score >= 1;
                                            $score--
                                        ): ?>
                                            <option value="<?= $score ?>" <?= (int) ($_POST[
    $field
] ?? 0) === $score
    ? "selected"
    : "" ?>><?= $score ?> - <?= [
     "",
     "Needs improvement",
     "Fair",
     "Good",
     "Very good",
     "Excellent",
 ][$score] ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <label class="form-label" for="reviewTitle">Review title</label>
                        <input class="form-control" id="reviewTitle" name="title" value="<?= escape_html(
                            $_POST["title"] ?? "",
                        ) ?>" minlength="5" maxlength="160" required>
                        <label class="form-label mt-3" for="reviewBody">Tell us about your trip</label>
                        <textarea class="form-control" id="reviewBody" name="body" rows="6" minlength="20" maxlength="3000" required><?= escape_html(
                            $_POST["body"] ?? "",
                        ) ?></textarea>
                        <label class="photo-upload mt-3" for="reviewPhotos">
                            <i class="bi bi-camera"></i>
                            <span>
                                <strong>Add up to three trip photos</strong>
                                <small>JPG, PNG, or WebP; maximum 3 MB per file.</small>
                            </span>
                            <input id="reviewPhotos" name="photos[]" type="file" accept="image/jpeg,image/png,image/webp" multiple>
                        </label>
                        <div class="form-check mt-4">
                            <input class="form-check-input" id="reviewConsent" name="consent" type="checkbox" value="1" required>
                            <label class="form-check-label" for="reviewConsent">I confirm this feedback describes my own rental experience.</label>
                        </div>
                        <button class="btn btn-primary btn-lg mt-4" type="submit">Submit Verified Review</button>
                    </form>
                </div>
                <div class="col-lg-5">
                    <aside class="booking-summary-card">
                        <span class="section-kicker">Vehicle reviewed</span>
                        <img src="assets/images/cars/<?= escape_html(
                            $booking["vehicle_image"],
                        ) ?>" alt="<?= escape_html($booking["vehicle_name"]) ?>">
                        <h2><?= escape_html($booking["vehicle_name"]) ?></h2>
                        <p><?= escape_html($booking["vehicle_description"]) ?></p>
                        <div class="review-integrity">
                            <i class="bi bi-patch-check-fill"></i>
                            <div>
                                <strong>Verified rental</strong>
                                <p>Only completed bookings can be reviewed, and each booking can be reviewed once.</p>
                            </div>
                        </div>
                    </aside>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
