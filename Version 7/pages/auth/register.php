<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";
$returnTo = safe_return_to(
    $_GET["return_to"] ?? ($_POST["return_to"] ?? null),
    "index.php",
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

        register_user($_POST, "customer");
        flash("success", "Your account is ready. Please sign in.");
        redirect("login.php?return_to=" . urlencode($returnTo));
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
                <h1>Create your account</h1>
                <p>Create a customer account for secure identification while continuing to browse the public VJ Car Rental vehicle catalog.</p>
                <ul>
                    <li>Secure password hashing</li>
                    <li>Customer and administrator roles</li>
                    <li>Protected vehicle management for administrators</li>
                </ul>
            </div>
            <form class="form auth-form" method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="return_to" value="<?= escape_html($returnTo) ?>">
                <h2>Create account</h2>
                <?php foreach ($errors as $error): ?>
                    <div class="alert alert-danger"><?= escape_html($error) ?></div>
                <?php endforeach; ?>
                <label class="form-label" for="registerName">Full name</label>
                <input class="form-control" id="registerName" name="name" value="<?= escape_html(post_string("name")) ?>" maxlength="120" autocomplete="name" required>
                <div class="row g-3 mt-0">
                    <div class="col-md-6">
                        <label class="form-label" for="registerEmail">Email address</label>
                        <input class="form-control" id="registerEmail" name="email" type="email" value="<?= escape_html(post_string("email")) ?>" maxlength="190" autocomplete="email" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="registerPhone">Phone number</label>
                        <input class="form-control" id="registerPhone" name="phone" type="tel" value="<?= escape_html(post_string("phone")) ?>" maxlength="40" autocomplete="tel">
                    </div>
                </div>
                <div class="row g-3 mt-0">
                    <div class="col-md-6">
                        <label class="form-label" for="registerPassword">Password</label>
                        <input class="form-control" id="registerPassword" name="password" type="password" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="registerConfirm">Confirm password</label>
                        <input class="form-control" id="registerConfirm" name="password_confirmation" type="password" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required>
                    </div>
                </div>
                <small class="form-help">Use <?= PASSWORD_MIN_LENGTH ?>+ characters with uppercase, lowercase, and a number.</small>
                <button class="btn btn-primary w-100 mt-4" type="submit">Create Account</button>
                <p class="auth-switch">Already registered? <a href="login.php?return_to=<?= urlencode($returnTo) ?>">Sign in</a></p>
            </form>
        </div>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
