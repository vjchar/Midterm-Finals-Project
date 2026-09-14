<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";
$admin = require_admin();
$errors = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        require_csrf();
        $userId = (int) post_string("user_id");
        $role = post_string("role");
        $status = post_string("status");
        if (
            !in_array($role, ["customer", "admin"], true) ||
            !in_array($status, ["active", "inactive"], true)
        ) {
            throw new InvalidArgumentException(
                "Choose a valid role and account status.",
            );
        }
        $statement = database()->prepare(
            'SELECT u.id, u.name, u.email, r.name AS role, u.status
             FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? LIMIT 1',
        );
        $statement->execute([$userId]);
        $target = $statement->fetch();
        if (!$target) {
            throw new RuntimeException("User account not found.");
        }
        if (
            $userId === (int) $admin["id"] &&
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
                ->query(
                    "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
                 WHERE r.name = 'admin' AND u.status = 'active'",
                )
                ->fetchColumn();
            if ($activeAdmins <= 1) {
                throw new RuntimeException(
                    "At least one active administrator must remain.",
                );
            }
        }
        $update = database()->prepare(
            "UPDATE users SET role_id = ?, status = ?, updated_at = ? WHERE id = ?",
        );
        $update->execute([
            role_id($role),
            $status,
            date("Y-m-d H:i:s"),
            $userId,
        ]);
        write_audit("user_access_updated", "user", $userId, [
            "role" => $role,
            "status" => $status,
        ]);
        flash(
            "success",
            "Account access updated for " . $target["name"] . ".",
        );
        redirect("admin-users.php");
    } catch (Throwable $error) {
        $errors[] = user_facing_error_message($error);
    }
}

$query = trim((string) ($_GET["q"] ?? ""));
$sql = "SELECT u.*, r.name AS role, r.label AS role_label, COALESCE(bc.booking_count, 0) AS booking_count
        FROM users u JOIN roles r ON r.id = u.role_id
        LEFT JOIN (SELECT user_id, COUNT(*) AS booking_count FROM bookings GROUP BY user_id) bc ON bc.user_id = u.id";
$parameters = [];
if ($query !== "") {
    $sql .=
        " WHERE LOWER(u.name) LIKE LOWER(?) OR LOWER(u.email) LIKE LOWER(?)";
    $parameters = ["%" . $query . "%", "%" . $query . "%"];
}
$sql .= " ORDER BY u.created_at DESC";
$statement = database()->prepare($sql);
$statement->execute($parameters);
$users = $statement->fetchAll();
$pageTitle = "Manage Users | VJ Car Rental";
require dirname(__DIR__, 2) . "/includes/header.php";
require dirname(__DIR__, 2) . "/includes/admin-nav.php";
?>
<section class="admin-page-heading">
    <div class="container">
        <div>
            <span class="section-kicker">Account administration</span>
            <h1>Customers and staff</h1>
            <p>Review registered accounts, booking history totals, roles, and access status.</p>
        </div>
    </div>
</section>
<section class="content-section admin-section">
    <div class="container">
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-danger"><?= escape_html($error) ?></div>
        <?php endforeach; ?>
        <form class="admin-search" method="get">
            <label class="visually-hidden" for="userSearch">Search users</label>
            <input class="form-control" id="userSearch" name="q" value="<?= escape_html(
                $query,
            ) ?>" placeholder="Search by name or email">
            <button class="btn btn-primary" type="submit">
                <i class="bi bi-search"></i> Search</button>
            <?php if ($query !== ""): ?>
                <a class="btn btn-outline" href="admin-users.php">Clear</a>
            <?php endif; ?>
        </form>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Phone</th>
                        <th>Bookings</th>
                        <th>Joined</th>
                        <th>Access</th>
                        <th>Update</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td>
                                <strong><?= escape_html($user["name"]) ?></strong>
                                <small><?= escape_html($user["email"]) .
                                    ((int) $user["id"] === (int) $admin["id"]
                                        ? " • You"
                                        : "") ?></small>
                            </td>
                            <td><?= escape_html(
                                $user["phone"] ?: "Not provided",
                            ) ?></td>
                            <td><?= (int) $user["booking_count"] ?></td>
                            <td><?= date(
                                "M j, Y",
                                strtotime($user["created_at"]),
                            ) ?></td>
                            <td>
                                <span class="status-badge status-badge--<?= status_class(
                                    $user["status"],
                                ) ?>"><?= escape_html(
    ucfirst($user["status"]),
) ?></span>
                                <small><?= escape_html(
                                    ucfirst($user["role"]),
                                ) ?></small>
                            </td>
                            <td>
                                <form method="post" class="admin-inline-form"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $user[
    "id"
] ?>">
                                    <select class="form-select form-select-sm" name="role" aria-label="Role for <?= escape_html(
                                        $user["name"],
                                    ) ?>">
                                        <option value="customer" <?= $user[
                                            "role"
                                        ] === "customer"
                                            ? "selected"
                                            : "" ?>>Customer</option>
                                        <option value="admin" <?= $user[
                                            "role"
                                        ] === "admin"
                                            ? "selected"
                                            : "" ?>>Admin</option>
                                    </select>
                                    <select class="form-select form-select-sm" name="status" aria-label="Status for <?= escape_html(
                                        $user["name"],
                                    ) ?>">
                                        <option value="active" <?= $user[
                                            "status"
                                        ] === "active"
                                            ? "selected"
                                            : "" ?>>Active</option>
                                        <option value="inactive" <?= $user[
                                            "status"
                                        ] === "inactive"
                                            ? "selected"
                                            : "" ?>>Inactive</option>
                                    </select>
                                    <button class="btn btn-outline btn-sm" type="submit">Save</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$users): ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-state empty-state--compact">
                                    <i class="bi bi-people"></i>
                                    <h3>No accounts found</h3>
                                    <p>Try a different search term.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
