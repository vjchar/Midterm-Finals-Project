<?php

declare(strict_types=1);


/**
 * FILE: pages/system/setup.php
 * FILE PURPOSE: Controlled system setup/bootstrap page for first-time/local configuration tasks.
 * USED BY: Authorized local setup/maintenance workflow.
 * RESPONSIBILITY: Loads the required application/services, handles only page-level request orchestration, and renders the user interface; reusable business/database logic belongs in services.
 *
 * Maintenance note: Keep this file focused on the responsibility described above.
 */
require dirname(__DIR__, 2) . "/includes/bootstrap.php";
if (admin_exists()) {
    redirect(admin() ? "admin.php" : "login.php");
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
        register_user($_POST, "admin");
        attempt_login(post_string("email"), post_string("password"));
        flash(
            "success",
            "Administrator account created.",
        );
        redirect("admin.php");
    } catch (Throwable $error) {
        $errors[] = user_facing_error_message($error);
    }
}
$pageTitle = "Create Administrator | VJ Car Rental";
$pageDescription = "Create the administrator account for VJ Car Rental.";
require dirname(__DIR__, 2) . "/includes/header.php";
?>
<section class="auth-section pattern-layer">
    <div class="container">
        <div class="auth-shell auth-shell--wide">
            <div class="auth-intro">
                <span class="section-kicker">Administrator setup</span>
                <h1>Create Administrator Account</h1>
                <p>Create the administrator account used to manage VJ Car Rental.</p>
            </div>
            <form class="form auth-form" method="post">
                <?= csrf_field() ?>
                <h2>Account details</h2>
                <?php foreach ($errors as $error): ?>
                    <div class="alert alert-danger"><?= escape_html($error) ?></div>
                <?php endforeach; ?>
                <label class="form-label" for="setupName">Full name</label>
                <input class="form-control" id="setupName" name="name" value="<?= escape_html(
                    post_string("name"),
                ) ?>" maxlength="120" autocomplete="name" required>
                <label class="form-label mt-3" for="setupEmail">Email address</label>
                <input class="form-control" id="setupEmail" name="email" type="email" value="<?= escape_html(
                    post_string("email"),
                ) ?>" maxlength="190" autocomplete="email" required>
                <label class="form-label mt-3" for="setupPhone">Phone number</label>
                <input class="form-control" id="setupPhone" name="phone" type="tel" value="<?= escape_html(
                    post_string("phone"),
                ) ?>" maxlength="40" autocomplete="tel">
                <label class="form-label mt-3" for="setupPassword">Password</label>
                <input
                    class="form-control"
                    id="setupPassword"
                    name="password"
                    type="password"
                    minlength="<?= PASSWORD_MIN_LENGTH ?>"
                    autocomplete="new-password"
                    required>
                <small class="form-help">At least <?= PASSWORD_MIN_LENGTH ?> characters with uppercase, lowercase, and a number.</small>
                <label class="form-label mt-3" for="setupPasswordConfirmation">Confirm password</label>
                <input
                    class="form-control"
                    id="setupPasswordConfirmation"
                    name="password_confirmation"
                    type="password"
                    minlength="<?= PASSWORD_MIN_LENGTH ?>"
                    autocomplete="new-password"
                    required>
                <button class="btn btn-primary w-100 mt-4" type="submit">Create Administrator</button>
            </form>
        </div>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
