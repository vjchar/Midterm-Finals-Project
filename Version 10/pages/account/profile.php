<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";
$user = require_customer();
$errors = [];
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        require_csrf();
        $name = post_string("name");
        $email = strtolower(post_string("email"));
        $phone = post_string("phone");
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            throw new InvalidArgumentException("Enter your full name.");
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Enter a valid email address.");
        }
        if ($phone !== "" && !preg_match('/^[0-9+()\-\s]{7,40}$/', $phone)) {
            throw new InvalidArgumentException("Enter a valid phone number.");
        }
        $check = database()->prepare(
            "SELECT COUNT(*) FROM users WHERE LOWER(email) = LOWER(?) AND id <> ?",
        );
        $check->execute([$email, $user["id"]]);
        if ((int) $check->fetchColumn() > 0) {
            throw new InvalidArgumentException(
                "That email is already registered.",
            );
        }
        $password = (string) ($_POST["new_password"] ?? "");
        $sensitiveChange =
            strcasecmp($email, (string) $user["email"]) !== 0 ||
            $password !== "";
        if ($sensitiveChange) {
            $hashStatement = database()->prepare(
                "SELECT password_hash FROM users WHERE id = ?",
            );
            $hashStatement->execute([$user["id"]]);
            if (
                !password_verify(
                    (string) ($_POST["current_password"] ?? ""),
                    (string) $hashStatement->fetchColumn(),
                )
            ) {
                throw new InvalidArgumentException(
                    "Your current password is required for email or password changes.",
                );
            }
        }
        $parameters = [$name, $email, $phone];
        $sql = "UPDATE users SET name = ?, email = ?, phone = ?";
        if ($password !== "") {
            if (
                $password !==
                (string) ($_POST["new_password_confirmation"] ?? "")
            ) {
                throw new InvalidArgumentException(
                    "The new password confirmation does not match.",
                );
            }
            $passwordErrors = password_errors($password);
            if ($passwordErrors) {
                throw new InvalidArgumentException(
                    implode(" ", $passwordErrors),
                );
            }
            $sql .= ", password_hash = ?";
            $parameters[] = password_hash($password, PASSWORD_DEFAULT);
        }
        $sql .= ", updated_at = ? WHERE id = ?";
        $parameters[] = date("Y-m-d H:i:s");
        $parameters[] = $user["id"];
        $statement = database()->prepare($sql);
        $statement->execute($parameters);
        current_user(true);
        write_audit("profile_updated", "user", $user["id"]);
        flash("success", "Profile updated successfully.");
        redirect("profile.php");
    } catch (Throwable $error) {
        $errors[] = user_facing_error_message($error);
    }
}
$user = current_user(true);
$pageTitle = "My Profile | VJ Car Rental";
require dirname(__DIR__, 2) . "/includes/header.php";
?>
<section class="page-hero page-hero--compact pattern-layer">
    <div class="container">
        <span class="section-kicker">Account settings</span>
        <h1>My profile</h1>
        <p>Keep your contact information current and protect your account with a strong password.</p>
    </div>
</section>
<section class="content-section">
    <div class="container">
        <div class="row g-4 justify-content-center">
            <div class="col-lg-8">
                <form class="form" method="post"><?= csrf_field() ?><div class="form-heading">
                        <span class="section-kicker">Personal information</span>
                        <h2>Contact details</h2>
                    </div>
                    <?php foreach ($errors as $error): ?>
                        <div class="alert alert-danger"><?= escape_html(
                            $error,
                        ) ?></div>
                    <?php endforeach; ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="profileName">Full name</label>
                            <input class="form-control" id="profileName" name="name" value="<?= escape_html(
                                $_POST["name"] ?? $user["name"],
                            ) ?>" maxlength="120" autocomplete="name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="profileEmail">Email address</label>
                            <input class="form-control" id="profileEmail" name="email" type="email" value="<?= escape_html(
                                $_POST["email"] ?? $user["email"],
                            ) ?>" maxlength="190" autocomplete="email" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="profilePhone">Phone number</label>
                            <input class="form-control" id="profilePhone" name="phone" type="tel" value="<?= escape_html(
                                $_POST["phone"] ?? $user["phone"],
                            ) ?>" maxlength="40" autocomplete="tel">
                        </div>
                    </div>
                    <hr>
                    <div class="form-heading form-heading--section">
                        <span class="section-kicker">Account security</span>
                        <h2>Email and password changes</h2>
                        <p>Enter your current password when changing your email or password. Leave the new password fields blank to keep it.</p>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="currentPassword">Current password</label>
                            <input class="form-control" id="currentPassword" name="current_password" type="password" autocomplete="current-password">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="newPassword">New password</label>
                            <input class="form-control" id="newPassword" name="new_password" type="password" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="newPasswordConfirm">Confirm new password</label>
                            <input class="form-control" id="newPasswordConfirm" name="new_password_confirmation" type="password" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password">
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-4">
                        <button class="btn btn-primary" type="submit">Save Profile</button>
                        <a class="btn btn-outline" href="account.php">Back to Account</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
