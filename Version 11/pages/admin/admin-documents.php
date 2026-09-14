<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";
$admin = require_admin();
$errors = [];
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        require_csrf();
        $documentId = filter_var(
            $_POST["document_id"] ?? null,
            FILTER_VALIDATE_INT,
        );
        if (!$documentId) {
            throw new InvalidArgumentException("Choose a valid document.");
        }
        review_customer_document(
            $documentId,
            post_string("status"),
            post_string("admin_notes"),
            $admin["id"],
        );
        flash("success", "Document review saved.");
        redirect("admin-documents.php");
    } catch (Throwable $error) {
        $errors[] = user_facing_error_message($error);
    }
}
$status = trim((string) ($_GET["status"] ?? "all"));
if (!in_array($status, ["all", "pending", "approved", "rejected"], true)) {
    $status = "all";
}
$sql =
    "SELECT d.*, u.name AS customer_name, u.email AS customer_email,
            verifier.name AS verifier_name
     FROM customer_documents d
     JOIN users u ON u.id = d.user_id
     LEFT JOIN users verifier ON verifier.id = d.verified_by";
$params = [];
if ($status !== "all") {
    $sql .= " WHERE d.status = ?";
    $params[] = $status;
}
$sql .=
    ' ORDER BY CASE d.status WHEN \'pending\' THEN 0 ELSE 1 END, d.updated_at DESC';
$statement = database()->prepare($sql);
$statement->execute($params);
$documents = $statement->fetchAll();
$pageTitle = "Document Verification | VJ Car Rental";
require dirname(__DIR__, 2) . "/includes/header.php";
require dirname(__DIR__, 2) . "/includes/admin-nav.php";
?>
<section class="admin-page-heading">
    <div class="container">
        <div>
            <span class="section-kicker">Identity controls</span>
            <h1>Document verification</h1>
            <p>Review private customer files before confirming a rental. Every decision records the responsible administrator.</p>
        </div>
    </div>
</section>
<section class="content-section admin-section">
    <div class="container">
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-danger"><?= escape_html($error) ?></div>
        <?php endforeach; ?>

        <div class="admin-filter-tabs">
            <?php foreach (["all", "pending", "approved", "rejected"] as $filter): ?>
                <a
                    class="<?= $status === $filter ? "active" : "" ?>"
                    href="admin-documents.php?status=<?= $filter ?>"
                >
                    <?= ucfirst($filter) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Document</th>
                        <th>Expiry</th>
                        <th>Submitted</th>
                        <th>Status</th>
                        <th>Review</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($documents as $document): ?>
                        <tr>
                            <td>
                                <strong><?= escape_html($document["customer_name"]) ?></strong>
                                <small><?= escape_html($document["customer_email"]) ?></small>
                            </td>
                            <td>
                                <?= escape_html(
                                    ucwords(
                                        str_replace(
                                            "_",
                                            " ",
                                            $document["document_type"],
                                        ),
                                    ),
                                ) ?>
                                <small>
                                    •••• <?= escape_html(
                                        substr(
                                            (string) $document["document_number"],
                                            -4,
                                        ),
                                    ) ?> ·
                                    <a
                                        href="secure-file.php?type=document&amp;id=<?= (int) $document["id"] ?>"
                                        target="_blank"
                                        rel="noopener"
                                    >
                                        Open secure file
                                    </a>
                                </small>
                            </td>
                            <td><?= $document["expiry_date"]
                                ? date(
                                    "M j, Y",
                                    strtotime($document["expiry_date"]),
                                )
                                : "—" ?></td>
                            <td><?= date(
                                "M j, Y g:i A",
                                strtotime($document["updated_at"]),
                            ) ?></td>
                            <td>
                                <span class="status-badge status-badge--<?= status_class(
                                    $document["status"],
                                ) ?>">
                                    <?= escape_html(ucfirst($document["status"])) ?>
                                </span>
                                <?php if ($document["verifier_name"]): ?>
                                    <small>by <?= escape_html($document["verifier_name"]) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form class="admin-inline-form" method="post">
                                    <?= csrf_field() ?>
                                    <input
                                        type="hidden"
                                        name="document_id"
                                        value="<?= (int) $document["id"] ?>"
                                    >
                                    <select class="form-select form-select-sm" name="status" required>
                                        <option value="">Decision</option>
                                        <option value="approved">Approve</option>
                                        <option value="rejected">Reject</option>
                                    </select>
                                    <input
                                        class="form-control form-control-sm"
                                        name="admin_notes"
                                        maxlength="2000"
                                        placeholder="Reviewer note"
                                    >
                                    <button class="btn btn-primary btn-sm" type="submit">Save</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$documents): ?>
                        <tr>
                            <td colspan="6">No documents match this filter.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
