<?php

declare(strict_types=1);


/**
 * FILE: pages/auth/forgot-password.php
 * FILE PURPOSE: Password-reset request page.
 * USED BY: Visitors or users entering/leaving the authentication flow.
 * RESPONSIBILITY: Loads the required application/services, handles only page-level request orchestration, and renders the user interface; reusable business/database logic belongs in services.
 *
 * Maintenance note: Keep this file focused on the responsibility described above.
 */
require dirname(__DIR__, 2) . "/includes/bootstrap.php";
$message = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        require_csrf();
        request_password_reset(post_string("email"));
        $message = "If that account exists, a password-reset link has been prepared.";
    } catch (Throwable $error) {
        $message = user_facing_error_message($error);
    }
}
$localResetLink = $_SESSION["local_reset_link"] ?? null;
unset($_SESSION["local_reset_link"]);
$pageTitle = "Reset Password | VJ Car Rental";
require dirname(__DIR__, 2) . "/includes/header.php";
?>
<section class="auth-section pattern-layer">
    <div class="container">
        <div class="auth-shell auth-shell--single">
            <form class="form auth-form" method="post"><?= csrf_field() ?><span class="section-kicker">Account recovery</span>
                <h1>Reset your password</h1>
                <p>Enter your account email. For privacy, the response is the same whether the address exists or not.</p>
                <?php if ($message): ?>
                    <div class="alert alert-info"><?= escape_html($message) ?></div>
                <?php endif; ?>
                <label class="form-label" for="forgotEmail">Email address</label>
                <input class="form-control" id="forgotEmail" name="email" type="email" autocomplete="email" required>
                <button class="btn btn-vj-primary w-100 mt-4" type="submit">Send Reset Link</button>
                <?php if ($localResetLink): ?>
                    <div class="local-reset-link">
                        <strong>Local development link</strong>
                        <a href="<?= escape_html(
                            $localResetLink,
                        ) ?>">Open password reset</a>
                    </div>
                <?php endif; ?>
                <p class="auth-switch">
                    <a href="login.php">Back to sign in</a>
                </p>
            </form>
        </div>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
