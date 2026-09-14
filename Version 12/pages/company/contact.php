<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";
$errors = [];
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        require_csrf();
        if (post_string("website") !== "") {
            throw new RuntimeException("Message rejected.");
        }
        $name = post_string("name");
        $email = strtolower(post_string("email"));
        $phone = post_string("phone");
        $subject = post_string("subject");
        $message = post_string("message");
        $allowedSubjects = [
            "New booking",
            "Vehicle information",
            "Corporate rental",
            "Existing rental support",
            "Other inquiry",
        ];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            throw new InvalidArgumentException("Enter your full name.");
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Enter a valid email address.");
        }
        if (!in_array($subject, $allowedSubjects, true)) {
            throw new InvalidArgumentException("Choose a valid support topic.");
        }
        if (mb_strlen($message) < 20 || mb_strlen($message) > 5000) {
            throw new InvalidArgumentException(
                "Enter a message between 20 and 5,000 characters.",
            );
        }
        $statement = database()->prepare(
            "INSERT INTO contact_messages (name, email, phone, subject, message, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
        );
        $currentTimestamp = date("Y-m-d H:i:s");
        $statement->execute([
            $name,
            $email,
            $phone,
            $subject,
            $message,
            "new",
            $currentTimestamp,
            $currentTimestamp,
        ]);
        write_audit(
            "contact_message_created",
            "contact_message",
            (int) database()->lastInsertId(),
        );
        flash(
            "success",
            "Your message was received. Our team will respond through your email address.",
        );
        redirect("contact.php");
    } catch (Throwable $error) {
        $errors[] = user_facing_error_message($error);
    }
}
$pageTitle = "Contact Us | VJ Car Rental";
$pageDescription =
    "Contact VJ Car Rental for booking questions, fleet information, and customer support.";
require dirname(__DIR__, 2) . "/includes/header.php";
?>

<section class="page-hero page-hero--compact pattern-layer">
    <div class="container">
        <span class="section-kicker">We are ready to help</span>
        <h1>Let us plan the road ahead</h1>
        <p>Ask about a vehicle, trip schedule, service option, or rental requirement. Your message is securely stored for the support team.</p>
    </div>
</section>
<section class="content-section contact-section">
    <div class="container">
        <div class="row g-4 g-xl-5 align-items-start">
            <div class="col-lg-5">
                <span class="section-kicker">Contact VJ</span>
                <h2>Friendly support, whenever you need it</h2>
                <p>Reach our team using any of the channels below. We will help you choose a vehicle and clarify the details of your trip.</p>
                <div class="contact-list">
                    <article>
                        <i class="bi bi-geo-alt"></i>
                        <div>
                            <h3>Visit our office</h3>
                            <p>Manjuyod, Negros Oriental, Philippines</p>
                        </div>
                    </article>
                    <article>
                        <i class="bi bi-telephone"></i>
                        <div>
                            <h3>Call our team</h3>
                            <p>+63 994 894 5174</p>
                        </div>
                    </article>
                    <article>
                        <i class="bi bi-envelope"></i>
                        <div>
                            <h3>Email reservations</h3>
                            <p>bernardinovjcharles@gmail.com</p>
                        </div>
                    </article>
                    <article>
                        <i class="bi bi-clock"></i>
                        <div>
                            <h3>Opening hours</h3>
                            <p>Monday-Sunday, 7:00 AM-9:00 PM</p>
                        </div>
                    </article>
                </div>
                <div class="support-callout">
                    <i class="bi bi-headset"></i>
                    <div>
                        <strong>Need roadside assistance?</strong>
                        <span>Our rental support line is available 24/7.</span>
                    </div>
                </div>
            </div>
            <div class="col-lg-7">
                <form class="form contact-form" method="post">
                    <?= csrf_field() ?>
                    <input class="visually-hidden" name="website" type="text" tabindex="-1" autocomplete="off" aria-hidden="true">
                    <div class="form-heading">
                        <span class="section-kicker">Send a message</span>
                        <h2>How can we help?</h2>
                    </div>
                    <?php foreach ($errors as $error): ?>
                        <div class="alert alert-danger"><?= escape_html(
                            $error,
                        ) ?></div>
                    <?php endforeach; ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="contactName">Full name *</label>
                            <input class="form-control" id="contactName" name="name" type="text" value="<?= escape_html(
                                $_POST["name"] ??
                                    (current_user()["name"] ?? ""),
                            ) ?>" maxlength="120" autocomplete="name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="contactEmail">Email address *</label>
                            <input class="form-control" id="contactEmail" name="email" type="email" value="<?= escape_html(
                                $_POST["email"] ??
                                    (current_user()["email"] ?? ""),
                            ) ?>" maxlength="190" autocomplete="email" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="contactPhone">Mobile number</label>
                            <input class="form-control" id="contactPhone" name="phone" type="tel" value="<?= escape_html(
                                $_POST["phone"] ??
                                    (current_user()["phone"] ?? ""),
                            ) ?>" maxlength="40" autocomplete="tel">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="contactTopic">What can we help with? *</label>
                            <select class="form-select" id="contactTopic" name="subject" required>
                                <option value="">Select a topic</option>
                                <?php foreach (
                                    [
                                        "New booking",
                                        "Vehicle information",
                                        "Corporate rental",
                                        "Existing rental support",
                                        "Other inquiry",
                                    ]
                                    as $topic
                                ): ?>
                                    <option <?= ($_POST["subject"] ?? "") ===
                                    $topic
                                        ? "selected"
                                        : "" ?>><?= escape_html($topic) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="contactMessage">Message *</label>
                            <textarea class="form-control" id="contactMessage" name="message" rows="6" minlength="20" maxlength="5000" placeholder="Tell us about your trip or question" required><?= escape_html(
                                $_POST["message"] ?? "",
                            ) ?></textarea>
                        </div>
                    </div>
                    <button class="btn btn-primary btn-lg mt-4" type="submit">Send Message <i class="bi bi-send"></i>
                    </button>
                    <small class="display-note">
                        <i class="bi bi-shield-check"></i> Messages are stored in the protected administrator inbox.</small>
                </form>
            </div>
        </div>
    </div>
</section>
<section class="contact-map pattern-layer">
    <div class="container">
        <div class="contact-map__heading">
            <div><span class="section-kicker">Main service area</span>
                <h2>VJ Car Rental — Manjuyod</h2>
                <p>The exact pickup point is included in a confirmed booking.</p>
            </div>
            <a class="btn btn-vj-outline" href="https://www.openstreetmap.org/export/embed.html?bbox=121.068%2C14.623%2C121.137%2C14.679&amp;layer=mapnik&amp;marker=14.6507%2C121.1029" target="_blank" rel="noopener"><i class="bi bi-map"></i> Open Map</a>
        </div>
        <iframe title="Map of Manjuyod, Negros Oriental" src="https://www.openstreetmap.org/export/embed.html?bbox=121.068%2C14.623%2C121.137%2C14.679&amp;layer=mapnik&amp;marker=14.6507%2C121.1029" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
