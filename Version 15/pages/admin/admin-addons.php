<?php

declare(strict_types=1);


/**
 * FILE: pages/admin/admin-addons.php
 * FILE PURPOSE: Administrator add-on catalog management page.
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
        save_addon($_POST);
        flash("success", "Rental add-on saved.");
        redirect("admin-addons.php");
    } catch (Throwable $error) {
        $errors[] =
            $error instanceof PDOException
                ? "That add-on key is already in use."
                : user_facing_error_message($error);
    }
}
$editId = (int) ($_GET["edit"] ?? 0);
$edit = $editId > 0 ? addon_find($editId) : null;
$addons = addon_all(false);
$value = static fn(string $key, mixed $default = ""): string => escape_html(
    $_POST[$key] ?? ($edit[$key] ?? $default),
);
$pageTitle = "Rental Add-ons | VJ Car Rental";
require dirname(__DIR__, 2) . "/includes/header.php";
require dirname(__DIR__, 2) . "/includes/admin-nav.php";
?>
<section class="admin-page-heading">
    <div class="container">
        <div>
            <span class="section-kicker">Rental extras</span>
            <h1>Manage add-ons</h1>
            <p>Control optional items, their billing method, pricing, and customer availability.</p>
        </div>
        <a class="btn btn-primary" href="admin-addons.php">
            <i class="bi bi-plus-lg"></i> New Add-on</a>
    </div>
</section>
<section class="content-section admin-section">
    <div class="container">
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-danger"><?= escape_html($error) ?></div>
        <?php endforeach; ?>
        <div class="admin-split">
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Add-on</th>
                            <th>Price</th>
                            <th>Billing</th>
                            <th>Status</th>
                            <th>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($addons as $addon): ?>
                            <tr>
                                <td>
                                    <strong>
                                        <i class="bi <?= escape_html(
                                            $addon["icon"],
                                        ) ?>">
                                        </i> <?= escape_html(
                                            $addon["name"],
                                        ) ?></strong>
                                    <small><?= escape_html($addon["key"]) ?></small>
                                </td>
                                <td><?= money($addon["price"]) ?></td>
                                <td>One-time</td>
                                <td>
                                    <span class="status-badge status-badge--<?= $addon[
                                        "is_active"
                                    ]
                                        ? "success"
                                        : "danger" ?>"><?= $addon["is_active"]
    ? "Active"
    : "Inactive" ?></span>
                                </td>
                                <td>
                                    <a class="btn btn-outline btn-sm" href="admin-addons.php?edit=<?= (int) $addon[
                                        "id"
                                    ] ?>">Edit</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <form class="form admin-editor" method="post"><?= csrf_field() ?><input type="hidden" name="addon_id" value="<?= (int) ($edit[
    "id"
] ?? 0) ?>">
                <span class="section-kicker"><?= $edit
                    ? "Edit add-on"
                    : "New add-on" ?></span>
                <h2><?= $edit ? escape_html($edit["name"]) : "Create an extra" ?></h2>
                <label class="form-label" for="addonKey">Key</label>
                <input class="form-control" id="addonKey" name="addon_key" value="<?= $value(
                    "addon_key",
                ) ?>" maxlength="80" placeholder="child-seat" required>
                <label class="form-label mt-3" for="addonName">Name</label>
                <input class="form-control" id="addonName" name="name" value="<?= $value(
                    "name",
                ) ?>" maxlength="140" required>
                <div class="row g-3 mt-0">
                    <div class="col-6">
                        <label class="form-label" for="addonPrice">Price</label>
                        <input class="form-control" id="addonPrice" name="price" type="number" min="0" value="<?= $value(
                            "price",
                            "0",
                        ) ?>" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="addonBilling">Billing</label>
                        <input type="hidden" name="billing" value="rental">
                        <input class="form-control" id="addonBilling" value="One-time per booking" readonly>
                    </div>
                </div>
                <label class="form-label mt-3" for="addonIcon">Bootstrap icon class</label>
                <input class="form-control" id="addonIcon" name="icon" value="<?= $value(
                    "icon",
                    "bi-plus-circle",
                ) ?>" maxlength="80">
                <div class="form-check form-switch mt-3">
                    <input class="form-check-input" id="addonActive" name="is_active" type="checkbox" value="1" <?= (!$_POST &&
                        (!$edit || $edit["is_active"])) ||
                    isset($_POST["is_active"])
                        ? "checked"
                        : "" ?>>
                    <label class="form-check-label" for="addonActive">Available during booking</label>
                </div>
                <button class="btn btn-primary w-100 mt-4" type="submit">Save Add-on</button>
            </form>
        </div>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
