<?php

declare(strict_types=1);


/**
 * FILE: pages/auth/login.php
 * FILE PURPOSE: Customer sign-in page.
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
    redirect(admin() ? "admin.php" : $returnTo);
}
$errorMessage = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        require_csrf();
        $email = post_string("email");
        $password = (string) ($_POST["password"] ?? "");
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === "") {
            throw new InvalidArgumentException(
                "Enter a valid email address and password.",
            );
        }
        if (!attempt_login($email, $password)) {
            throw new RuntimeException("The email or password is incorrect.");
        }

        if (admin()) {
            flash("success", "Administrator access granted.");
            redirect("admin.php");
        }

        flash("success", "Welcome back.");
        redirect($returnTo);
    } catch (Throwable $error) {
        $errorMessage = user_facing_error_message($error);
    }
}
$pageTitle = "Sign In | VJ Car Rental";
$pageDescription =
    "One secure sign-in for VJ Car Rental customers and administrators.";
require dirname(__DIR__, 2) . "/includes/header.php";
?>
<section class="auth-section pattern-layer">
    <div class="container">
        <div class="auth-shell">
            <div class="auth-intro">
                <span class="section-kicker">Secure account access</span>
                <h1>Welcome back</h1>
                <p>Customers and administrators use the same secure sign-in. Your account role automatically opens the correct dashboard.</p>
                <div class="auth-trust">
                    <span>
                        <i class="bi bi-shield-check"></i> Secure session</span>
                    <span>
                        <i class="bi bi-calendar-check"></i> Real booking history</span>
                    <span>
                        <i class="bi bi-star"></i> Verified reviews</span>
                </div>
            </div>
            <form class="form auth-form" method="post">
                <?= csrf_field() ?>
                <input
                    type="hidden"
                    name="return_to"
                    value="<?= escape_html($returnTo) ?>">
                <h2>Sign in</h2>
                <?php if ($errorMessage): ?>
                    <div class="alert alert-danger"><?= escape_html(
                        $errorMessage,
                    ) ?></div>
                <?php endif; ?>
                <label class="form-label" for="loginEmail">Email address</label>
                <input
                    class="form-control"
                    id="loginEmail"
                    name="email"
                    type="email"
                    value="<?= escape_html(post_string("email")) ?>"
                    maxlength="190"
                    autocomplete="email"
                    required
                    autofocus>
                <label class="form-label mt-3" for="loginPassword">Password</label>
                <input
                    class="form-control"
                    id="loginPassword"
                    name="password"
                    type="password"
                    minlength="<?= PASSWORD_MIN_LENGTH ?>"
                    autocomplete="current-password"
                    required>
                <div class="auth-form-links">
                    <a href="forgot-password.php">Forgot password?</a>
                </div>
                <button class="btn btn-primary w-100" type="submit">Sign In</button>
                <p class="auth-switch">New to VJ Car Rental? <a href="register.php?return_to=<?= urlencode(
                    $returnTo,
                ) ?>">Create an account</a>
                </p>
            </form>
        </div>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
