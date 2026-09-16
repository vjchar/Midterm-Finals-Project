<?php

declare(strict_types=1);


/**
 * FILE: includes/auth.php
 * FILE PURPOSE: Authentication, session, account, password-reset, and access-control service.
 * USED BY: Login/register/account pages and any page requiring customer or admin authorization.
 * RESPONSIBILITY: Handles current-user lookup, login security, password hashing/verification, role checks, profile changes, password reset persistence, and admin user-access operations.
 *
 * Maintenance note: Keep this file focused on the responsibility described above.
 */
function current_user(bool $refresh = false): ?array
{
    static $cachedUser = false;
    if ($refresh) {
        $cachedUser = false;
    }
    if ($cachedUser !== false) {
        return $cachedUser;
    }
    $userId = (int) ($_SESSION["user_id"] ?? 0);
    if ($userId < 1) {
        return $cachedUser = null;
    }
    $statement = database()->prepare(
        'SELECT id, name, email, phone, role, status, last_login_at, created_at
         FROM users WHERE id = ? LIMIT 1',
    );
    $statement->execute([$userId]);
    $user = $statement->fetch();
    if (!$user || $user["status"] !== "active") {
        unset($_SESSION["user_id"]);
        return $cachedUser = null;
    }
    $user["id"] = (int) $user["id"];
    return $cachedUser = $user;
}

function authenticated(): bool
{
    return current_user() !== null;
}

function admin(): bool
{
    return (current_user()["role"] ?? "") === "admin";
}

function admin_exists(): bool
{
    return (int) database()
        ->query(
            "SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'",
        )
        ->fetchColumn() > 0;
}

function require_auth(): array
{
    $user = current_user();
    if (!$user) {
        $requestedDestination = basename($_SERVER["PHP_SELF"] ?? "account.php");
        if (!empty($_SERVER["QUERY_STRING"])) {
            $requestedDestination .= "?" . $_SERVER["QUERY_STRING"];
        }
        flash("warning", "Please sign in to continue.");
        redirect("login.php?return_to=" . urlencode($requestedDestination));
    }
    return $user;
}

function require_admin(): array
{
    $user = require_auth();
    if ($user["role"] !== "admin") {
        http_response_code(403);
        $errorCode = 403;
        require ROOT . DIRECTORY_SEPARATOR . "errors" . DIRECTORY_SEPARATOR . "error.php";
        exit();
    }
    return $user;
}

function require_customer(): array
{
    $user = require_auth();
    if ($user["role"] !== "customer") {
        http_response_code(403);
        $errorCode = 403;
        require ROOT . DIRECTORY_SEPARATOR . "errors" . DIRECTORY_SEPARATOR . "error.php";
        exit();
    }
    return $user;
}

function login_attempt_key(string $email): string
{
    $source =
        strtolower(trim($email)) .
        "|" .
        ($_SERVER["REMOTE_ADDR"] ?? "cli") .
        "|" .
        APP_KEY;
    return hash("sha256", $source);
}

function login_throttled(string $email): bool
{
    $statement = database()->prepare(
        "SELECT COUNT(*) FROM login_attempts WHERE identity_hash = ? AND succeeded = 0 AND attempted_at >= ?",
    );
    $statement->execute([
        login_attempt_key($email),
        date("Y-m-d H:i:s", time() - 900),
    ]);
    return (int) $statement->fetchColumn() >= 5;
}

function record_login_attempt(string $email, bool $succeeded): void
{
    $statement = database()->prepare(
        "INSERT INTO login_attempts (identity_hash, succeeded, attempted_at) VALUES (?, ?, ?)",
    );
    $statement->execute([
        login_attempt_key($email),
        $succeeded ? 1 : 0,
        date("Y-m-d H:i:s"),
    ]);
    if (random_int(1, 20) === 1) {
        $cleanupStatement = database()->prepare(
            "DELETE FROM login_attempts WHERE attempted_at < ?",
        );
        $cleanupStatement->execute([date("Y-m-d H:i:s", time() - 86400)]);
    }
}

function attempt_login(string $email, string $password): bool
{
    if (login_throttled($email)) {
        throw new RuntimeException(
            "Too many unsuccessful attempts. Try again in 15 minutes.",
        );
    }
    $statement = database()->prepare(
        "SELECT * FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1",
    );
    $statement->execute([trim($email)]);
    $user = $statement->fetch();
    $credentialsAreValid =
        $user &&
        $user["status"] === "active" &&
        password_verify($password, (string) $user["password_hash"]);
    record_login_attempt($email, (bool) $credentialsAreValid);
    if (!$credentialsAreValid) {
        return false;
    }
    if (
        password_needs_rehash((string) $user["password_hash"], PASSWORD_DEFAULT)
    ) {
        $passwordUpdateStatement = database()->prepare(
            "UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?",
        );
        $passwordUpdateStatement->execute([
            password_hash($password, PASSWORD_DEFAULT),
            date("Y-m-d H:i:s"),
            $user["id"],
        ]);
    }
    session_regenerate_id(true);
    $_SESSION["user_id"] = (int) $user["id"];
    unset($_SESSION["csrf_token"]);
    $lastLoginUpdateStatement = database()->prepare(
        "UPDATE users SET last_login_at = ?, updated_at = ? WHERE id = ?",
    );
    $currentTimestamp = date("Y-m-d H:i:s");
    $lastLoginUpdateStatement->execute([
        $currentTimestamp,
        $currentTimestamp,
        $user["id"],
    ]);
    current_user(true);
    write_audit("login", "user", (int) $user["id"]);
    return true;
}

function logout(): void
{
    $userId = current_user()["id"] ?? null;
    if ($userId) {
        write_audit("logout", "user", (int) $userId);
    }
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $sessionCookieParameters = session_get_cookie_params();
        setcookie(
            session_name(),
            "",
            time() - 42000,
            $sessionCookieParameters["path"],
            $sessionCookieParameters["domain"] ?? "",
            (bool) $sessionCookieParameters["secure"],
            (bool) $sessionCookieParameters["httponly"],
        );
    }
    session_destroy();
}

function password_errors(string $password): array
{
    $errors = [];

    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $errors[] =
            "Password must contain at least " .
            PASSWORD_MIN_LENGTH .
            " characters.";
    }
    if (!preg_match("/[A-Z]/", $password)) {
        $errors[] = "Password must include an uppercase letter.";
    }
    if (!preg_match("/[a-z]/", $password)) {
        $errors[] = "Password must include a lowercase letter.";
    }
    if (!preg_match("/\d/", $password)) {
        $errors[] = "Password must include a number.";
    }

    return $errors;
}

function register_user(array $input, string $role = "customer"): int
{
    $name = trim((string) ($input["name"] ?? ""));
    $email = strtolower(trim((string) ($input["email"] ?? "")));
    $phone = trim((string) ($input["phone"] ?? ""));
    $password = (string) ($input["password"] ?? "");
    $errors = [];

    if (!in_array($role, ["customer", "admin"], true)) {
        $errors[] = "Choose a valid account role.";
    }
    if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
        $errors[] = "Enter your full name.";
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        $errors[] = "Enter a valid email address.";
    }
    if ($phone !== "" && !preg_match('/^[0-9+()\-\s]{7,40}$/', $phone)) {
        $errors[] = "Enter a valid phone number.";
    }

    $errors = array_merge($errors, password_errors($password));
    if ($errors) {
        throw new InvalidArgumentException(implode(" ", $errors));
    }

    $existingAccountStatement = database()->prepare(
        "SELECT COUNT(*) FROM users WHERE LOWER(email) = LOWER(?)",
    );
    $existingAccountStatement->execute([$email]);
    if ((int) $existingAccountStatement->fetchColumn() > 0) {
        throw new InvalidArgumentException(
            "An account already uses that email address.",
        );
    }
    $currentTimestamp = date("Y-m-d H:i:s");
    $statement = database()->prepare(
        "INSERT INTO users (role, name, email, phone, password_hash, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
    );
    $statement->execute([
        $role,
        $name,
        $email,
        $phone,
        password_hash($password, PASSWORD_DEFAULT),
        "active",
        $currentTimestamp,
        $currentTimestamp,
    ]);
    $id = (int) database()->lastInsertId();
    write_audit("register", "user", $id, ["role" => $role]);
    return $id;
}

/****************************************************************************
 * ACCOUNT PROFILE AND PASSWORD RECOVERY
 ****************************************************************************/

/**
 * Update a customer's profile while keeping sensitive account changes behind
 * current-password verification.
 */
function update_customer_profile(int $userId, array $input, array $currentUser): void
{
    $name = trim((string) ($input["name"] ?? ""));
    $email = strtolower(trim((string) ($input["email"] ?? "")));
    $phone = trim((string) ($input["phone"] ?? ""));

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
    $check->execute([$email, $userId]);
    if ((int) $check->fetchColumn() > 0) {
        throw new InvalidArgumentException("That email is already registered.");
    }

    $newPassword = (string) ($input["new_password"] ?? "");
    $sensitiveChange =
        strcasecmp($email, (string) ($currentUser["email"] ?? "")) !== 0 ||
        $newPassword !== "";

    if ($sensitiveChange) {
        $hashStatement = database()->prepare(
            "SELECT password_hash FROM users WHERE id = ?",
        );
        $hashStatement->execute([$userId]);
        $storedHash = (string) $hashStatement->fetchColumn();
        if (
            $storedHash === "" ||
            !password_verify(
                (string) ($input["current_password"] ?? ""),
                $storedHash,
            )
        ) {
            throw new InvalidArgumentException(
                "Your current password is required for email or password changes.",
            );
        }
    }

    $parameters = [$name, $email, $phone];
    $sql = "UPDATE users SET name = ?, email = ?, phone = ?";

    if ($newPassword !== "") {
        if (
            $newPassword !==
            (string) ($input["new_password_confirmation"] ?? "")
        ) {
            throw new InvalidArgumentException(
                "The new password confirmation does not match.",
            );
        }
        $passwordErrors = password_errors($newPassword);
        if ($passwordErrors) {
            throw new InvalidArgumentException(implode(" ", $passwordErrors));
        }
        $sql .= ", password_hash = ?";
        $parameters[] = password_hash($newPassword, PASSWORD_DEFAULT);
    }

    $sql .= ", updated_at = ? WHERE id = ?";
    $parameters[] = date("Y-m-d H:i:s");
    $parameters[] = $userId;

    $statement = database()->prepare($sql);
    $statement->execute($parameters);
    current_user(true);
    write_audit("profile_updated", "user", $userId);
}

/**
 * Create a one-hour password reset token when the account exists and has not
 * requested another reset during the last 15 minutes.
 */
function request_password_reset(string $email): void
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException("Enter a valid email address.");
    }

    $statement = database()->prepare(
        "SELECT id, email FROM users WHERE LOWER(email) = LOWER(?) AND status = 'active' LIMIT 1",
    );
    $statement->execute([$email]);
    $user = $statement->fetch();
    if (!$user) {
        return;
    }

    $recent = database()->prepare(
        "SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at >= ?",
    );
    $recent->execute([(int) $user["id"], date("Y-m-d H:i:s", time() - 900)]);
    if ((int) $recent->fetchColumn() > 0) {
        return;
    }

    $token = bin2hex(random_bytes(32));
    $insert = database()->prepare(
        "INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)",
    );
    $insert->execute([
        (int) $user["id"],
        hash("sha256", $token),
        date("Y-m-d H:i:s", time() + 3600),
        date("Y-m-d H:i:s"),
    ]);
    send_password_reset(
        (string) $user["email"],
        url("reset-password.php?token=" . urlencode($token)),
    );
}

/** Return one valid, unused password reset record for a raw token. */
function password_reset_record(string $token): ?array
{
    $token = trim($token);
    if ($token === "") {
        return null;
    }

    $statement = database()->prepare(
        "SELECT pr.*, u.email FROM password_resets pr JOIN users u ON u.id = pr.user_id WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at >= ? LIMIT 1",
    );
    $statement->execute([hash("sha256", $token), date("Y-m-d H:i:s")]);
    $reset = $statement->fetch();
    return $reset ?: null;
}

/** Consume a valid reset record and replace the user's password securely. */
function complete_password_reset(array $reset, string $password, string $confirmation): void
{
    if ($password !== $confirmation) {
        throw new InvalidArgumentException("The password confirmation does not match.");
    }
    $errors = password_errors($password);
    if ($errors) {
        throw new InvalidArgumentException(implode(" ", $errors));
    }

    $database = database();
    $database->beginTransaction();
    try {
        $now = date("Y-m-d H:i:s");
        $update = $database->prepare(
            "UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?",
        );
        $update->execute([
            password_hash($password, PASSWORD_DEFAULT),
            $now,
            (int) $reset["user_id"],
        ]);
        $consume = $database->prepare(
            "UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL",
        );
        $consume->execute([$now, (int) $reset["user_id"]]);
        $database->commit();
    } catch (Throwable $error) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $error;
    }
}

/**
 * Update a user's administrator/customer access while ensuring at least one
 * active administrator always remains.
 */
function admin_update_user_access(
    int $actingAdminId,
    int $userId,
    string $role,
    string $status,
): string {
    if (
        !in_array($role, ["customer", "admin"], true) ||
        !in_array($status, ["active", "inactive"], true)
    ) {
        throw new InvalidArgumentException("Choose a valid role and account status.");
    }

    $statement = database()->prepare(
        "SELECT id, name, email, role, status FROM users WHERE id = ? LIMIT 1",
    );
    $statement->execute([$userId]);
    $target = $statement->fetch();
    if (!$target) {
        throw new RuntimeException("User account not found.");
    }

    if (
        $userId === $actingAdminId &&
        ($role !== "admin" || $status !== "active")
    ) {
        throw new RuntimeException(
            "You cannot remove or deactivate your own administrator access.",
        );
    }

    if (
        $target["role"] === "admin" &&
        ($role !== "admin" || $status !== "active")
    ) {
        $activeAdmins = (int) database()
            ->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")
            ->fetchColumn();
        if ($activeAdmins <= 1) {
            throw new RuntimeException("At least one active administrator must remain.");
        }
    }

    $update = database()->prepare(
        "UPDATE users SET role = ?, status = ?, updated_at = ? WHERE id = ?",
    );
    $update->execute([$role, $status, date("Y-m-d H:i:s"), $userId]);
    write_audit("user_access_updated", "user", $userId, [
        "role" => $role,
        "status" => $status,
    ]);

    return (string) $target["name"];
}

/** Return users for the administration account-management page. */
function admin_users(string $query = ""): array
{
    $query = trim($query);
    $sql = "SELECT u.*, COALESCE(bc.booking_count, 0) AS booking_count
            FROM users u
            LEFT JOIN (SELECT user_id, COUNT(*) AS booking_count FROM bookings GROUP BY user_id) bc ON bc.user_id = u.id";
    $parameters = [];
    if ($query !== "") {
        $sql .= " WHERE LOWER(u.name) LIKE LOWER(?) OR LOWER(u.email) LIKE LOWER(?)";
        $parameters = ["%" . $query . "%", "%" . $query . "%"];
    }
    $sql .= " ORDER BY u.created_at DESC";
    $statement = database()->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchAll();
}
