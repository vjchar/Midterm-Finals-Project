<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/includes/bootstrap.php";
$user = require_customer();
$errors = [];
$reference = trim((string) ($_GET["reference"] ?? ($_POST["booking_reference"] ?? "")));
$contextBooking = $reference !== "" ? booking_find_by_reference($reference) : null;
if ($contextBooking && (int) $contextBooking["user_id"] !== (int) $user["id"]) {
    http_response_code(404);
    $contextBooking = null;
    $reference = "";
}
if (!$contextBooking && $reference === "") {
    foreach (bookings_for_user((int) $user["id"]) as $candidate) {
        if (in_array($candidate["status"], ["pending", "confirmed", "ready"], true)) {
            $contextBooking = $candidate;
            $reference = (string) $candidate["reference"];
            break;
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        require_csrf();
        $filename = upload_file(
            $_FILES["document_file"] ?? [],
            ROOT . DIRECTORY_SEPARATOR . "storage" . DIRECTORY_SEPARATOR . "documents",
            ["image/jpeg" => "jpg", "image/png" => "png", "application/pdf" => "pdf"],
            5 * 1024 * 1024,
            "user-" . $user["id"] . "-" . post_string("document_type"),
        );
        upsert_customer_document(
            (int) $user["id"],
            post_string("document_type"),
            post_string("document_number"),
            post_string("expiry_date") ?: null,
            $filename,
        );
        flash("success", "Document uploaded securely and sent for verification.");
        $destination = "documents.php";
        if ($reference !== "") {
            $destination .= "?reference=" . urlencode($reference) . "&uploaded=1";
        }
        redirect($destination);
    } catch (Throwable $error) {
        $errors[] = user_facing_error_message($error);
    }
}

$documents = customer_documents((int) $user["id"]);
$documentTypes = [
    "drivers_license" => ["Driver’s License", "bi-person-vcard", "Required for the primary driver. It must still be valid on the return date."],
    "government_id" => ["Government-issued ID", "bi-card-heading", "Use a clear passport, national ID, or another accepted government ID."],
];
$journey = $contextBooking ? booking_next_step($contextBooking) : null;
$pageTitle = "Rental Documents | VJ Car Rental";
$pageDescription = "Upload and track the documents required to confirm a VJ Car Rental booking.";
require dirname(__DIR__, 2) . "/includes/header.php";
?>
<section class="page-hero page-hero--compact pattern-layer">
    <div class="container">
        <span class="section-kicker">Step 3 · Secure verification</span>
        <h1>Rental documents</h1>
        <p>Upload your driver’s license and government-issued ID. You can submit these while your payment is still awaiting verification.</p>
    </div>
</section>
<section class="content-section operations-page">
    <div class="container">
        <?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= escape_html($error) ?></div><?php endforeach; ?>
        <?php if (isset($_GET["payment_submitted"])): ?>
            <div class="alert alert-success"><strong>Payment submitted.</strong> This page is shown only because at least one required document still needs your attention.</div>
        <?php endif; ?>
        <?php if (isset($_GET["uploaded"])): ?>
            <div class="alert alert-success">Document saved. Continue with the next missing document, or wait for verification when both are submitted.</div>
        <?php endif; ?>

        <?php if ($journey): ?>
            <?php render_booking_progress($journey); ?>
            <?php render_booking_next_step($journey, "Booking " . $contextBooking["reference"] . " · Current step"); ?>
        <?php endif; ?>

        <div class="operations-grid">
            <?php foreach ($documentTypes as $type => [$label, $icon, $description]): ?>
                <?php
                $document = $documents[$type] ?? null;
                $effectiveDocumentStatus = $document["status"] ?? "missing";
                if (
                    $document &&
                    $type === "drivers_license" &&
                    !empty($document["expiry_date"]) &&
                    new DateTimeImmutable((string) $document["expiry_date"]) <= new DateTimeImmutable("today")
                ) {
                    $effectiveDocumentStatus = "expired";
                }
                ?>
                <article class="operation-card">
                    <div class="operation-card__heading">
                        <i class="bi <?= $icon ?>"></i>
                        <div><h2><?= escape_html($label) ?></h2><p><?= escape_html($description) ?></p></div>
                        <span class="status-badge status-badge--<?= status_class($effectiveDocumentStatus) ?>"><?= escape_html(ucfirst($effectiveDocumentStatus)) ?></span>
                    </div>
                    <?php if ($document): ?>
                        <dl class="operation-meta">
                            <div><dt>Document number</dt><dd>•••• <?= escape_html(substr((string) $document["document_number"], -4)) ?></dd></div>
                            <div><dt>Expiry</dt><dd><?= $document["expiry_date"] ? date("M j, Y", strtotime($document["expiry_date"])) : "Not supplied" ?></dd></div>
                            <div><dt>Submitted</dt><dd><?= date("M j, Y g:i A", strtotime($document["updated_at"])) ?></dd></div>
                        </dl>
                        <?php if ($document["admin_notes"]): ?>
                            <div class="review-note"><strong>Reviewer note</strong><p><?= nl2br(escape_html($document["admin_notes"])) ?></p></div>
                        <?php endif; ?>
                        <a class="btn btn-outline btn-sm" href="secure-file.php?type=document&amp;id=<?= (int) $document["id"] ?>"><i class="bi bi-eye"></i> View current file</a>
                    <?php endif; ?>
                    <details class="operation-upload" <?= !$document || in_array($effectiveDocumentStatus, ["rejected", "expired"], true) ? " open" : "" ?>>
                        <summary><?= $document
                            ? (in_array($effectiveDocumentStatus, ["rejected", "expired"], true) ? "Replace " . $effectiveDocumentStatus . " document" : "Replace document")
                            : "Upload document" ?></summary>
                        <form method="post" enctype="multipart/form-data" class="row g-3">
                            <?= csrf_field() ?>
                            <?php if ($reference !== ""): ?><input type="hidden" name="booking_reference" value="<?= escape_html($reference) ?>"><?php endif; ?>
                            <input type="hidden" name="document_type" value="<?= escape_html($type) ?>">
                            <div class="col-md-<?= $type === "drivers_license" ? "6" : "12" ?>">
                                <label class="form-label" for="number-<?= escape_html($type) ?>">Document number</label>
                                <input class="form-control" id="number-<?= escape_html($type) ?>" name="document_number" maxlength="120" required autocomplete="off">
                            </div>
                            <?php if ($type === "drivers_license"): ?>
                                <div class="col-md-6"><label class="form-label" for="expiryDate">Expiry date</label><input class="form-control" id="expiryDate" name="expiry_date" type="date" required></div>
                            <?php endif; ?>
                            <div class="col-12"><label class="form-label" for="file-<?= escape_html($type) ?>">JPG, PNG, or PDF (maximum 5 MB)</label><input class="form-control" id="file-<?= escape_html($type) ?>" name="document_file" type="file" accept=".jpg,.jpeg,.png,.pdf" required></div>
                            <div class="col-12"><button class="btn btn-primary" type="submit"><?= $document && in_array($effectiveDocumentStatus, ["rejected", "expired"], true) ? "Replace Document" : "Submit for Verification" ?></button></div>
                        </form>
                    </details>
                </article>
            <?php endforeach; ?>
        </div>
        <div class="privacy-callout"><i class="bi bi-shield-lock"></i><div><strong>Your documents are private.</strong><p>Only your account and authenticated administrators can retrieve these files. The public cannot open the storage directory.</p></div></div>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
