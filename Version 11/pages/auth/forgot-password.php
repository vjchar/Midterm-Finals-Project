<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";
$message = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        require_csrf();
        $email = strtolower(post_string("email"));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Enter a valid email address.");
        }
        $statement = database()->prepare(
            "SELECT id, email FROM users WHERE LOWER(email) = LOWER(?) AND status = 'active' LIMIT 1",
        );
        $statement->execute([$email]);
        $user = $statement->fetch();
        if ($user) {
            $recent = database()->prepare(
                "SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at >= ?",
            );
            $recent->execute([$user["id"], date("Y-m-d H:i:s", time() - 900)]);
            if ((int) $recent->fetchColumn() === 0) {
                $token = bin2hex(random_bytes(32));
                $insert = database()->prepare(
                    "INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)",
                );
                $insert->execute([
                    $user["id"],
                    hash("sha256", $token),
                    date("Y-m-d H:i:s", time() + 3600),
                    date("Y-m-d H:i:s"),
                ]);
                send_password_reset(
                    $user["email"],
                    url("reset-password.php?token=" . urlencode($token)),
                );
            }
        }
        $message =
            "If that account exists, a password-reset link has been prepared.";
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
