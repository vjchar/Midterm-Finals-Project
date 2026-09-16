<?php

declare(strict_types=1);


/**
 * FILE: pages/admin/admin-users.php
 * FILE PURPOSE: Administrator customer/admin access and user-account management page.
 * USED BY: Authenticated administrators using the corresponding management section.
 * RESPONSIBILITY: Loads the required application/services, handles only page-level request orchestration, and renders the user interface; reusable business/database logic belongs in services.
 *
 * Maintenance note: Keep this file focused on the responsibility described above.
 */
require dirname(__DIR__, 2) . "/includes/bootstrap.php";
$admin = require_admin();
$errors = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        require_csrf();
        $userId = (int) post_string("user_id");
        $role = post_string("role");
        $status = post_string("status");
        $name = admin_update_user_access(
            (int) $admin["id"],
            $userId,
            $role,
            $status,
        );
        flash("success", "Account access updated for " . $name . ".");
        redirect("admin-users.php");
    } catch (Throwable $error) {
        $errors[] = user_facing_error_message($error);
    }
}

$query = trim((string) ($_GET["q"] ?? ""));
$users = admin_users($query);
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
    humanize_label($user["status"]),
) ?></span>
                                <small><?= escape_html(
                                    humanize_label($user["role"]),
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
