<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";
$admin = require_admin();
$errors = [];
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        require_csrf();
        $id = (int) post_string("addon_id");
        $key = strtolower(
            preg_replace("/[^a-z0-9-]/i", "-", post_string("addon_key")),
        );
        $name = mb_substr(post_string("name"), 0, 140);
        $price = (int) post_string("price");
        $billing = post_string("billing");
        $icon = preg_replace("/[^a-z0-9-]/i", "", post_string("icon"));
        $active = isset($_POST["is_active"]) ? 1 : 0;
        if ($key === "" || $name === "" || strlen($key) > 80) {
            throw new InvalidArgumentException("Enter a key and display name.");
        }
        if ($price < 0 || !in_array($billing, ["day", "rental"], true)) {
            throw new InvalidArgumentException(
                "Enter valid pricing and billing.",
            );
        }
        if ($icon === "" || strlen($icon) > 80) {
            $icon = "bi-plus-circle";
        }
        $currentTimestamp = date("Y-m-d H:i:s");
        if ($id > 0) {
            $statement = database()->prepare(
                "UPDATE addons SET addon_key=?, name=?, price=?, billing=?, icon=?, is_active=?, updated_at=? WHERE id=?",
            );
            $statement->execute([
                $key,
                $name,
                $price,
                $billing,
                $icon,
                $active,
                $currentTimestamp,
                $id,
            ]);
        } else {
            $statement = database()->prepare(
                "INSERT INTO addons (addon_key,name,price,billing,icon,is_active,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)",
            );
            $statement->execute([
                $key,
                $name,
                $price,
                $billing,
                $icon,
                $active,
                $currentTimestamp,
                $currentTimestamp,
            ]);
            $id = (int) database()->lastInsertId();
        }
        write_audit("addon_saved", "addon", $id, [
            "key" => $key,
            "active" => (bool) $active,
        ]);
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
$edit = null;
if ($editId) {
    $statement = database()->prepare("SELECT * FROM addons WHERE id=?");
    $statement->execute([$editId]);
    $edit = $statement->fetch() ?: null;
}
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
                                <td>Per <?= escape_html($addon["billing"]) ?></td>
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
                        <select class="form-select" id="addonBilling" name="billing">
                            <option value="day" <?= $value("billing", "day") ===
                            "day"
                                ? "selected"
                                : "" ?>>Per day</option>
                            <option value="rental" <?= $value("billing") ===
                            "rental"
                                ? "selected"
                                : "" ?>>Per rental</option>
                        </select>
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
