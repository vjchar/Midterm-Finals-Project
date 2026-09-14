<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";
$user = require_customer();
$errors = [];
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        require_csrf();
        $filename = upload_file(
            $_FILES["document_file"] ?? [],
            ROOT .
                DIRECTORY_SEPARATOR .
                "storage" .
                DIRECTORY_SEPARATOR .
                "documents",
            [
                "image/jpeg" => "jpg",
                "image/png" => "png",
                "application/pdf" => "pdf",
            ],
            5 * 1024 * 1024,
            "user-" . $user["id"] . "-" . post_string("document_type"),
        );
        upsert_customer_document(
            $user["id"],
            post_string("document_type"),
            post_string("document_number"),
            post_string("expiry_date") ?: null,
            $filename,
        );
        flash(
            "success",
            "Document uploaded securely and sent for verification.",
        );
        redirect("documents.php");
    } catch (Throwable $error) {
        $errors[] = user_facing_error_message($error);
    }
}
$documents = customer_documents($user["id"]);
$documentTypes = [
    "drivers_license" => [
        "Driver’s License",
        "bi-person-vcard",
        "Required for the primary driver. It must still be valid on the return date.",
    ],
    "government_id" => [
        "Government-issued ID",
        "bi-card-heading",
        "Use a clear passport, national ID, or another accepted government ID.",
    ],
];
$pageTitle = "Rental Documents | VJ Car Rental";
$pageDescription =
    "Upload and track the documents required to confirm a VJ Car Rental booking.";
require dirname(__DIR__, 2) . "/includes/header.php";
?>
<section class="page-hero page-hero--compact pattern-layer">
    <div class="container">
        <span class="section-kicker">Secure verification</span>
        <h1>Rental documents</h1>
        <p>Submit your driver’s license and one government ID once. Files are stored outside public access and reviewed by an administrator.</p>
    </div>
</section>
<section class="content-section operations-page">
    <div class="container">
        <?php foreach (
            $errors
            as $error
        ): ?><div class="alert alert-danger"><?= escape_html(
    $error,
) ?></div><?php endforeach; ?>
        <div class="operations-grid">
            <?php foreach (
                $documentTypes
                as $type => [$label, $icon, $description]
            ):
                $document = $documents[$type] ?? null; ?>
                <article class="operation-card">
                    <div class="operation-card__heading">
                        <i class="bi <?= $icon ?>"></i>
                        <div>
                            <h2><?= escape_html($label) ?></h2>
                            <p><?= escape_html($description) ?></p>
                        </div>
                        <span class="status-badge status-badge--<?= status_class(
                            $document["status"] ?? "missing",
                        ) ?>"><?= escape_html(
    ucfirst($document["status"] ?? "missing"),
) ?></span>
                    </div>
                    <?php if ($document): ?>
                        <dl class="operation-meta">
                            <div>
                                <dt>Document number</dt>
                                <dd>•••• <?= escape_html(
                                    substr(
                                        (string) $document["document_number"],
                                        -4,
                                    ),
                                ) ?></dd>
                            </div>
                            <div>
                                <dt>Expiry</dt>
                                <dd><?= $document["expiry_date"]
                                    ? date(
                                        "M j, Y",
                                        strtotime($document["expiry_date"]),
                                    )
                                    : "Not supplied" ?></dd>
                            </div>
                            <div>
                                <dt>Submitted</dt>
                                <dd><?= date(
                                    "M j, Y g:i A",
                                    strtotime($document["updated_at"]),
                                ) ?></dd>
                            </div>
                        </dl>
                        <?php if (
                            $document["admin_notes"]
                        ): ?><div class="review-note"><strong>Reviewer note</strong>
                                <p><?= nl2br(
                                    escape_html($document["admin_notes"]),
                                ) ?></p>
                            </div><?php endif; ?>
                        <a class="btn btn-outline btn-sm" href="secure-file.php?type=document&amp;id=<?= (int) $document[
                            "id"
                        ] ?>"><i class="bi bi-eye"></i> View current file</a>
                    <?php endif; ?>
                    <details class="operation-upload" <?= !$document ||
                    $document["status"] === "rejected"
                        ? " open"
                        : "" ?>>
                        <summary><?= $document
                            ? "Replace document"
                            : "Upload document" ?></summary>
                        <form method="post" enctype="multipart/form-data" class="row g-3"><?= csrf_field() ?>
                            <input type="hidden" name="document_type" value="<?= escape_html(
                                $type,
                            ) ?>">
                            <div class="col-md-<?= $type === "drivers_license"
                                ? "6"
                                : "12" ?>">
                                <label class="form-label" for="number-<?= escape_html(
                                    $type,
                                ) ?>">Document number</label>
                                <input class="form-control" id="number-<?= escape_html(
                                    $type,
                                ) ?>" name="document_number" maxlength="120" required autocomplete="off">
                            </div>
                            <?php if ($type === "drivers_license"): ?>
                                <div class="col-md-6"><label class="form-label" for="expiryDate">Expiry date</label><input class="form-control" id="expiryDate" name="expiry_date" type="date" required></div>
                            <?php endif; ?>
                            <div class="col-12"><label class="form-label" for="file-<?= escape_html(
                                $type,
                            ) ?>">JPG, PNG, or PDF (maximum 5 MB)</label><input class="form-control" id="file-<?= escape_html(
    $type,
) ?>" name="document_file" type="file" accept=".jpg,.jpeg,.png,.pdf" required></div>
                            <div class="col-12"><button class="btn btn-primary" type="submit">Submit for Verification</button></div>
                        </form>
                    </details>
                </article>
            <?php
            endforeach; ?>
        </div>
        <div class="privacy-callout"><i class="bi bi-shield-lock"></i>
            <div><strong>Your documents are private.</strong>
                <p>Only your account and authenticated administrators can retrieve these files. The public cannot open the storage directory.</p>
            </div>
        </div>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
