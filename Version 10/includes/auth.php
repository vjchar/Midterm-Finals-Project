<?php

declare(strict_types=1);

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
        'SELECT u.id, u.name, u.email, u.phone, r.name AS role, r.label AS role_label,
                u.status, u.last_login_at, u.created_at
         FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? LIMIT 1',
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
            "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
         WHERE r.name = 'admin' AND u.status = 'active'",
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
        require ROOT .
            DIRECTORY_SEPARATOR .
            "errors" .
            DIRECTORY_SEPARATOR .
            "403.php";
        exit();
    }
    return $user;
}

function require_customer(): array
{
    $user = require_auth();
    if ($user["role"] !== "customer") {
        http_response_code(403);
        require ROOT .
            DIRECTORY_SEPARATOR .
            "errors" .
            DIRECTORY_SEPARATOR .
            "403.php";
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
        "INSERT INTO users (role_id, name, email, phone, password_hash, status, created_at, updated_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
    );
    $statement->execute([
        role_id($role),
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
