<?php

declare(strict_types=1);


/**
 * FILE: pages/auth/register.php
 * FILE PURPOSE: Customer registration page.
 * USED BY: Visitors or users entering/leaving the authentication flow.
 * RESPONSIBILITY: Loads the required application/services, handles only page-level request orchestration, and renders the user interface; reusable business/database logic belongs in services.
 *
 * Maintenance note: Keep this file focused on the responsibility described above.
 */
require dirname(__DIR__, 2) . "/includes/bootstrap.php";
$returnTo = safe_return_to(
    $_GET["return_to"] ?? ($_POST["return_to"] ?? null),
);
if (authenticated()) {
    redirect($returnTo);
}
$errors = [];
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        require_csrf();
        if (post_string("password") !== post_string("password_confirmation")) {
            throw new InvalidArgumentException(
                "The password confirmation does not match.",
            );
        }
        if (!isset($_POST["terms"])) {
            throw new InvalidArgumentException(
                "You must accept the terms and privacy policy.",
            );
        }
        register_user($_POST);
        attempt_login(post_string("email"), post_string("password"));
        flash("success", "Your account is ready.");
        redirect($returnTo);
    } catch (Throwable $error) {
        $errors[] = user_facing_error_message($error);
    }
}
$pageTitle = "Create Account | VJ Car Rental";
$pageDescription = "Create a secure VJ Car Rental customer account.";
require dirname(__DIR__, 2) . "/includes/header.php";
?>
<section class="auth-section pattern-layer">
    <div class="container">
        <div class="auth-shell auth-shell--wide">
            <div class="auth-intro">
                <span class="section-kicker">Join VJ Car Rental</span>
                <h1>Your trips in one place</h1>
                <p>An account gives you protected access to booking history, rescheduling, cancellation, favorites, and completed-trip reviews.</p>
                <ul>
                    <li>Transparent booking totals</li>
                    <li>Date-based vehicle availability</li>
                    <li>Private account and reservation records</li>
                </ul>
            </div>
            <form class="form auth-form" method="post">
                <?= csrf_field() ?>
                <input
                    type="hidden"
                    name="return_to"
                    value="<?= escape_html($returnTo) ?>">
                <h2>Create account</h2>
                <?php foreach ($errors as $error): ?>
                    <div class="alert alert-danger"><?= escape_html($error) ?></div>
                <?php endforeach; ?>
                <label class="form-label" for="registerName">Full name</label>
                <input class="form-control" id="registerName" name="name" value="<?= escape_html(
                    post_string("name"),
                ) ?>" maxlength="120" autocomplete="name" required>
                <div class="row g-3 mt-0">
                    <div class="col-md-6">
                        <label class="form-label" for="registerEmail">Email address</label>
                        <input class="form-control" id="registerEmail" name="email" type="email" value="<?= escape_html(
                            post_string("email"),
                        ) ?>" maxlength="190" autocomplete="email" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="registerPhone">Phone number</label>
                        <input class="form-control" id="registerPhone" name="phone" type="tel" value="<?= escape_html(
                            post_string("phone"),
                        ) ?>" maxlength="40" autocomplete="tel">
                    </div>
                </div>
                <div class="row g-3 mt-0">
                    <div class="col-md-6">
                        <label class="form-label" for="registerPassword">Password</label>
                        <input
                            class="form-control"
                            id="registerPassword"
                            name="password"
                            type="password"
                            minlength="<?= PASSWORD_MIN_LENGTH ?>"
                            autocomplete="new-password"
                            required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="registerConfirm">Confirm password</label>
                        <input
                            class="form-control"
                            id="registerConfirm"
                            name="password_confirmation"
                            type="password"
                            minlength="<?= PASSWORD_MIN_LENGTH ?>"
                            autocomplete="new-password"
                            required>
                    </div>
                </div>
                <small class="form-help">Use <?= PASSWORD_MIN_LENGTH ?>+ characters with uppercase, lowercase, and a number.</small>
                <div class="form-check mt-4">
                    <input class="form-check-input" id="registerTerms" name="terms" type="checkbox" value="1" required>
                    <label class="form-check-label" for="registerTerms">
                        I accept the <a href="terms.php">terms</a> and
                        <a href="privacy.php">privacy policy</a>.
                    </label>
                </div>
                <button class="btn btn-primary w-100 mt-4" type="submit">Create Account</button>
                <p class="auth-switch">Already registered? <a href="login.php?return_to=<?= urlencode(
                    $returnTo,
                ) ?>">Sign in</a>
                </p>
            </form>
        </div>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
