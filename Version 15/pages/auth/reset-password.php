<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";
$token = trim((string) ($_GET["token"] ?? ($_POST["token"] ?? "")));
$tokenHash = $token !== "" ? hash("sha256", $token) : "";
$statement = database()->prepare(
    "SELECT pr.*, u.email FROM password_resets pr JOIN users u ON u.id = pr.user_id WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at >= ? LIMIT 1",
);
$statement->execute([$tokenHash, date("Y-m-d H:i:s")]);
$reset = $statement->fetch();
$errorMessage = "";
if ($_SERVER["REQUEST_METHOD"] === "POST" && $reset) {
    try {
        require_csrf();
        $password = (string) ($_POST["password"] ?? "");
        if ($password !== (string) ($_POST["password_confirmation"] ?? "")) {
            throw new InvalidArgumentException(
                "The password confirmation does not match.",
            );
        }
        $errors = password_errors($password);
        if ($errors) {
            throw new InvalidArgumentException(implode(" ", $errors));
        }
        database()->beginTransaction();
        $update = database()->prepare(
            "UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?",
        );
        $update->execute([
            password_hash($password, PASSWORD_DEFAULT),
            date("Y-m-d H:i:s"),
            $reset["user_id"],
        ]);
        $consume = database()->prepare(
            "UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL",
        );
        $consume->execute([date("Y-m-d H:i:s"), $reset["user_id"]]);
        database()->commit();
        flash("success", "Password updated. You can now sign in.");
        redirect("login.php");
    } catch (Throwable $error) {
        if (database()->inTransaction()) {
            database()->rollBack();
        }
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
