<?php

declare(strict_types=1);


/**
 * FILE: pages/auth/reset-password.php
 * FILE PURPOSE: Password-reset completion page for a valid reset token.
 * USED BY: Visitors or users entering/leaving the authentication flow.
 * RESPONSIBILITY: Loads the required application/services, handles only page-level request orchestration, and renders the user interface; reusable business/database logic belongs in services.
 *
 * Maintenance note: Keep this file focused on the responsibility described above.
 */
require dirname(__DIR__, 2) . "/includes/bootstrap.php";
$token = trim((string) ($_GET["token"] ?? ($_POST["token"] ?? "")));
$reset = password_reset_record($token);
$errorMessage = "";
if ($_SERVER["REQUEST_METHOD"] === "POST" && $reset) {
    try {
        require_csrf();
        complete_password_reset(
            $reset,
            (string) ($_POST["password"] ?? ""),
            (string) ($_POST["password_confirmation"] ?? ""),
        );
        flash("success", "Password updated. You can now sign in.");
        redirect("login.php");
    } catch (Throwable $error) {
        $errorMessage = user_facing_error_message($error);
    }
}
$pageTitle = "Choose New Password | VJ Car Rental";
require dirname(__DIR__, 2) . "/includes/header.php";
?>
<section class="auth-section pattern-layer">
    <div class="container">
        <div class="auth-shell auth-shell--single">
            <form class="form auth-form" method="post"><?= csrf_field() ?><input type="hidden" name="token" value="<?= escape_html(
    $token,
) ?>">
                <span class="section-kicker">Account recovery</span>
                <h1>Choose a new password</h1>
                <?php if (!$reset): ?>
                    <div class="alert alert-danger">This reset link is invalid or expired.</div>
                    <a class="btn btn-primary w-100" href="forgot-password.php">Request Another Link</a>
                <?php else: ?>
                    <?php if ($errorMessage): ?>
                        <div class="alert alert-danger"><?= escape_html(
                            $errorMessage,
                        ) ?></div>
                    <?php endif; ?>
                    <label class="form-label" for="resetPassword">New password</label>
                    <input class="form-control" id="resetPassword" name="password" type="password" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required>
                    <label class="form-label mt-3" for="resetConfirmation">Confirm password</label>
                    <input class="form-control" id="resetConfirmation" name="password_confirmation" type="password" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required>
                    <button class="btn btn-primary w-100 mt-4" type="submit">Update Password</button>
                <?php endif; ?>
            </form>
        </div>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
