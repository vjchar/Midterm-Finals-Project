<?php

declare(strict_types=1);

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
            "Administrator created. Review the production settings before launch.",
        );
        redirect("admin.php");
    } catch (Throwable $error) {
        $errors[] = user_facing_error_message($error);
    }
}
$pageTitle = "First-time Setup | VJ Car Rental";
$pageDescription = "Securely create the first VJ Car Rental administrator.";
require dirname(__DIR__, 2) . "/includes/header.php";
?>
<section class="auth-section pattern-layer">
    <div class="container">
        <div class="auth-shell auth-shell--wide">
            <div class="auth-intro">
                <span class="section-kicker">Secure first-time setup</span>
                <h1>Create the administrator</h1>
                <p>This screen locks automatically after the first active administrator is created.</p>
                <ul>
                    <li>Use the direct MySQL configuration</li>
                    <li>Use a unique <?= PASSWORD_MIN_LENGTH ?>+ character password</li>
                    <li>Keep the database and storage folders private</li>
                </ul>
            </div>
            <form class="form auth-form" method="post">
                <?= csrf_field() ?>
                <h2>Administrator details</h2>
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
