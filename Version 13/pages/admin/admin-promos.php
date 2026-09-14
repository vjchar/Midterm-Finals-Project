<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";

require_admin();
$errors = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        require_csrf();

        $action = post_string("action", "save");
        $promoId =
            filter_var($_POST["promo_id"] ?? null, FILTER_VALIDATE_INT, [
                "options" => ["min_range" => 1],
            ]) ?:
            0;

        if ($action === "delete") {
            if ($promoId < 1) {
                throw new InvalidArgumentException(
                    "Choose a valid promotion to delete.",
                );
            }

            $find = database()->prepare(
                "SELECT id, code, used_count FROM promos WHERE id = :id LIMIT 1",
            );
            $find->execute(["id" => $promoId]);
            $promotion = $find->fetch();

            if (!$promotion) {
                throw new RuntimeException("Promotion not found.");
            }
            if ((int) $promotion["used_count"] > 0) {
                throw new RuntimeException(
                    "A promotion that has already been used cannot be deleted. Deactivate it instead.",
                );
            }

            $delete = database()->prepare("DELETE FROM promos WHERE id = :id");
            $delete->execute(["id" => $promoId]);
            if ($delete->rowCount() !== 1) {
                throw new RuntimeException(
                    "The promotion could not be deleted.",
                );
            }

            write_audit("promotion_deleted", "promo", $promoId, [
                "code" => $promotion["code"],
            ]);
            flash(
                "success",
                "Promotion " . $promotion["code"] . " was deleted.",
            );
            redirect("admin-promos.php");
        }

        if ($action !== "save") {
            throw new InvalidArgumentException(
                "Choose a valid promotion action.",
            );
        }

        $code = strtoupper(preg_replace("/[^A-Z0-9_-]/i", "", post_string("code")));
        $description = post_string("description");
        $discountType = post_string("discount_type");
        $discountValue = filter_var(
            $_POST["discount_value"] ?? null,
            FILTER_VALIDATE_INT,
            ["options" => ["min_range" => 1, "max_range" => 100000]],
        );
        $maxUsesInput = post_string("max_uses");
        $maxUses =
            $maxUsesInput === ""
                ? null
                : filter_var($maxUsesInput, FILTER_VALIDATE_INT, [
                    "options" => ["min_range" => 1, "max_range" => 1000000],
                ]);
        $startsAt = post_string("starts_at");
        $endsAt = post_string("ends_at");
        $isActive = isset($_POST["is_active"]) ? 1 : 0;

        if ($code === "" || strlen($code) > 40) {
            throw new InvalidArgumentException(
                "Enter a promotion code using letters, numbers, underscores, or hyphens.",
            );
        }
        if (mb_strlen($description) < 3 || mb_strlen($description) > 255) {
            throw new InvalidArgumentException(
                "Enter a description between 3 and 255 characters.",
            );
        }
        if (!in_array($discountType, ["percent", "fixed"], true)) {
            throw new InvalidArgumentException("Choose a valid discount type.");
        }
        if (
            $discountValue === false ||
            ($discountType === "percent" && $discountValue > 100)
        ) {
            throw new InvalidArgumentException("Enter a valid discount value.");
        }
        if ($maxUsesInput !== "" && $maxUses === false) {
            throw new InvalidArgumentException(
                "Maximum uses must be a positive whole number or blank.",
            );
        }

        foreach ([$startsAt, $endsAt] as $dateValue) {
            if ($dateValue !== "" && !valid_date($dateValue)) {
                throw new InvalidArgumentException(
                    "Use valid promotion dates.",
                );
            }
        }
        if ($startsAt !== "" && $endsAt !== "" && $endsAt < $startsAt) {
            throw new InvalidArgumentException(
                "End date must be after the start date.",
            );
        }

        $currentTimestamp = date("Y-m-d H:i:s");
        $startsAt = $startsAt !== "" ? $startsAt . " 00:00:00" : null;
        $endsAt = $endsAt !== "" ? $endsAt . " 23:59:59" : null;

        $parameters = [
            "code" => $code,
            "description" => $description,
            "discount_type" => $discountType,
            "discount_value" => $discountValue,
            "starts_at" => $startsAt,
            "ends_at" => $endsAt,
            "max_uses" => $maxUses,
            "is_active" => $isActive,
            "updated_at" => $currentTimestamp,
        ];

        if ($promoId > 0) {
            $exists = database()->prepare(
                "SELECT COUNT(*) FROM promos WHERE id = :id",
            );
            $exists->execute(["id" => $promoId]);
            if ((int) $exists->fetchColumn() !== 1) {
                throw new RuntimeException("Promotion not found.");
            }

            $parameters["id"] = $promoId;
            $save = database()->prepare(
                'UPDATE promos
                 SET code = :code, description = :description,
                     discount_type = :discount_type, discount_value = :discount_value,
                     starts_at = :starts_at, ends_at = :ends_at,
                     max_uses = :max_uses, is_active = :is_active, updated_at = :updated_at
                 WHERE id = :id',
            );
        } else {
            $parameters["created_at"] = $currentTimestamp;
            $save = database()->prepare(
                'INSERT INTO promos
                    (code, description, discount_type, discount_value, starts_at, ends_at,
                     max_uses, used_count, is_active, created_at, updated_at)
                 VALUES
                    (:code, :description, :discount_type, :discount_value, :starts_at, :ends_at,
                     :max_uses, 0, :is_active, :created_at, :updated_at)',
            );
        }

        $save->execute($parameters);
        if ($promoId < 1) {
            $promoId = (int) database()->lastInsertId();
        }

        write_audit("promotion_saved", "promo", $promoId, [
            "code" => $code,
            "active" => (bool) $isActive,
        ]);
        flash("success", "Promotion saved.");
        redirect("admin-promos.php");
    } catch (Throwable $error) {
        $message = strtolower(user_facing_error_message($error));
        if ($error instanceof PDOException) {
            error_log("Promotion database error: " . user_facing_error_message($error));
            $errors[] = str_contains($message, "duplicate")
                ? "That promotion code is already in use."
                : "The promotion could not be saved. Please try again.";
        } elseif (
            $error instanceof InvalidArgumentException ||
            $error instanceof RuntimeException
        ) {
            $errors[] = user_facing_error_message($error);
        } else {
            error_log("Promotion error: " . user_facing_error_message($error));
            $errors[] =
                "The promotion request could not be completed. Please try again.";
        }
    }
}

$editId =
    filter_var($_GET["edit"] ?? null, FILTER_VALIDATE_INT, [
        "options" => ["min_range" => 1],
    ]) ?:
    0;
$edit = null;

if ($editId > 0) {
    $statement = database()->prepare(
        "SELECT * FROM promos WHERE id = :id LIMIT 1",
    );
    $statement->execute(["id" => $editId]);
    $edit = $statement->fetch() ?: null;
}

$promos = database()
    ->query("SELECT * FROM promos ORDER BY created_at DESC")
    ->fetchAll();
$value = static fn(string $key, mixed $default = ""): string => escape_html(
    $_POST[$key] ?? ($edit[$key] ?? $default),
);

$pageTitle = "Promotions | VJ Car Rental";
require dirname(__DIR__, 2) . "/includes/header.php";
require dirname(__DIR__, 2) . "/includes/admin-nav.php";
?>
<section class="admin-page-heading">
    <div class="container">
        <div>
            <span class="section-kicker">Pricing controls</span>
            <h1>Promotion codes</h1>
            <p>Create, review, update, or safely delete discount codes.</p>
        </div>
        <a class="btn btn-primary" href="admin-promos.php">
            <i class="bi bi-plus-lg" aria-hidden="true"></i>
            New Code
        </a>
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
                            <th>Code</th>
                            <th>Discount</th>
                            <th>Usage</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($promos as $promo): ?>
                            <tr>
                                <td>
                                    <strong><?= escape_html($promo["code"]) ?></strong>
                                    <small><?= escape_html(
                                        $promo["description"],
                                    ) ?></small>
                                </td>
                                <td>
                                    <?= $promo["discount_type"] === "percent"
                                        ? (int) $promo["discount_value"] . "%"
                                        : money(
                                            (int) $promo["discount_value"],
                                        ) ?>
                                </td>
                                <td>
                                    <?= (int) $promo["used_count"] ?>
                                    <?= $promo["max_uses"] !== null
                                        ? " / " . (int) $promo["max_uses"]
                                        : "" ?>
                                </td>
                                <td>
                                    <span class="status-badge status-badge--<?= $promo[
                                        "is_active"
                                    ]
                                        ? "success"
                                        : "danger" ?>">
                                        <?= $promo["is_active"]
                                            ? "Active"
                                            : "Inactive" ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="admin-row-actions">
                                        <a class="btn btn-outline btn-sm" href="admin-promos.php?edit=<?= (int) $promo[
                                            "id"
                                        ] ?>">Edit</a>
                                        <?php if (
                                            (int) $promo["used_count"] === 0
                                        ): ?>
                                            <form
                                                method="post"
                                                action="admin-promos.php"
                                                data-confirm="Delete this unused promotion? This action cannot be undone."
                                            >
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="promo_id" value="<?= (int) $promo[
                                                    "id"
                                                ] ?>">
                                                <button class="btn btn-outline-danger btn-sm" type="submit">Delete</button>
                                            </form>
                                        <?php else: ?>
                                            <small>Deactivate to retain usage history</small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <form class="form admin-editor" method="post" action="admin-promos.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="promo_id" value="<?= (int) ($edit[
                    "id"
                ] ?? 0) ?>">

                <span class="section-kicker"><?= $edit
                    ? "Edit promotion"
                    : "New promotion" ?></span>
                <h2><?= $edit ? escape_html($edit["code"]) : "Create a code" ?></h2>

                <label class="form-label" for="promoAdminCode">Code</label>
                <input
                    class="form-control"
                    id="promoAdminCode"
                    name="code"
                    value="<?= $value("code") ?>"
                    minlength="1"
                    maxlength="40"
                    pattern="[A-Za-z0-9_-]+"
                    required>

                <label class="form-label mt-3" for="promoAdminDescription">Description</label>
                <input
                    class="form-control"
                    id="promoAdminDescription"
                    name="description"
                    value="<?= $value("description") ?>"
                    minlength="3"
                    maxlength="255"
                    required>

                <div class="row g-3 mt-0">
                    <div class="col-6">
                        <label class="form-label" for="promoAdminType">Type</label>
                        <select class="form-select" id="promoAdminType" name="discount_type" required>
                            <option value="percent" <?= $value(
                                "discount_type",
                                "percent",
                            ) === "percent"
                                ? "selected"
                                : "" ?>>Percent</option>
                            <option value="fixed" <?= $value(
                                "discount_type",
                            ) === "fixed"
                                ? "selected"
                                : "" ?>>Fixed amount</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="promoAdminValue">Value</label>
                        <input
                            class="form-control"
                            id="promoAdminValue"
                            name="discount_value"
                            type="number"
                            min="1"
                            max="100000"
                            value="<?= $value("discount_value", "10") ?>"
                            required>
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="promoStart">Starts</label>
                        <input
                            class="form-control"
                            id="promoStart"
                            name="starts_at"
                            type="date"
                            value="<?= $value("starts_at")
                                ? substr($value("starts_at"), 0, 10)
                                : "" ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="promoEnd">Ends</label>
                        <input
                            class="form-control"
                            id="promoEnd"
                            name="ends_at"
                            type="date"
                            value="<?= $value("ends_at")
                                ? substr($value("ends_at"), 0, 10)
                                : "" ?>">
                    </div>
                </div>

                <label class="form-label mt-3" for="promoMax">Maximum uses</label>
                <input
                    class="form-control"
                    id="promoMax"
                    name="max_uses"
                    type="number"
                    min="1"
                    max="1000000"
                    value="<?= $value("max_uses") ?>"
                    placeholder="No limit">

                <div class="form-check form-switch mt-3">
                    <input
                        class="form-check-input"
                        id="promoActive"
                        name="is_active"
                        type="checkbox"
                        value="1"
                        <?= (!$_POST && (!$edit || $edit["is_active"])) ||
                        isset($_POST["is_active"])
                            ? "checked"
                            : "" ?>>
                    <label class="form-check-label" for="promoActive">Promotion is active</label>
                </div>

                <button class="btn btn-primary w-100 mt-4" type="submit">Save Promotion</button>
            </form>
        </div>
    </div>
</section>

<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
