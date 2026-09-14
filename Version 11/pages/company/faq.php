<?php

declare(strict_types=1);

$pageTitle = "Rental FAQs | VJ Car Rental";
$pageDescription =
    "Review renter requirements, policies, pickup guidance, add-ons, booking changes, and roadside information.";
require dirname(__DIR__, 2) . "/includes/header.php";
$faqs = [
    [
        "id" => "requirements",
        "question" => "What do I need to rent a vehicle?",
        "answer" =>
            "Prepare a valid driver’s license, an accepted government-issued ID, the refundable deposit, and the signed rental agreement. Final age, license-history, and payment requirements will be confirmed before release.",
    ],
    [
        "id" => "availability",
        "question" => "How is vehicle availability checked?",
        "answer" =>
            "Choose your pick-up and return schedule on a vehicle page or during booking. The server checks saved pending, confirmed, and active reservations before it accepts a new booking.",
    ],
    [
        "id" => "pricing",
        "question" => "What is included in the displayed daily rate?",
        "answer" =>
            "Each vehicle page lists its specific mileage allowance, standard rental protection, sanitation, roadside assistance, and category-specific inclusions. Deposits and optional extras are shown separately.",
    ],
    [
        "id" => "deposit",
        "question" =>
            "Is the security deposit included in the estimated total?",
        "answer" =>
            "No. The estimate presents the refundable deposit separately. Refund timing and deductions will follow the signed rental agreement and documented return inspection.",
    ],
    [
        "id" => "fuel",
        "question" => "How should I return the fuel or charge level?",
        "answer" =>
            "Return the vehicle at the fuel or charge level stated during release. EV customers receive a charging orientation and supplied cable when listed in the vehicle inclusions.",
    ],
    [
        "id" => "delivery",
        "question" => "Can the vehicle be delivered?",
        "answer" =>
            "Yes. Choose vehicle delivery during booking, provide a complete service address, and review the delivery fee in the live estimate. Staff will confirm service coverage with the reservation.",
    ],
    [
        "id" => "additional-driver",
        "question" => "Can another person drive the rental?",
        "answer" =>
            "An additional driver can be requested as an add-on. Every driver must meet the final renter requirements and be listed in the rental agreement before driving.",
    ],
    [
        "id" => "changes",
        "question" => "Can I reschedule or cancel?",
        "answer" =>
            "Pending and confirmed bookings can be rescheduled when the new dates are available, or cancelled from My Bookings. Completed and already cancelled reservations cannot be changed.",
    ],
    [
        "id" => "rating",
        "question" => "Who can leave a vehicle rating?",
        "answer" =>
            "Only the signed-in renter linked to a completed booking can submit one verified review for that trip. Reviews are moderated before they appear publicly.",
    ],
    [
        "id" => "roadside",
        "question" => "What happens during a breakdown or emergency?",
        "answer" =>
            "Move to a safe location when possible, contact the support number supplied with the vehicle, and follow the rental agreement. Emergency services should be contacted first when anyone is in danger.",
    ],
];
?>
<section class="page-hero page-hero--compact pattern-layer">
    <div class="container">
        <span class="section-kicker">Rental help center</span>
        <h1>Questions before you drive</h1>
        <p>Clear guidance for requirements, pricing, availability, pickup, booking changes, ratings, and roadside support.</p>
    </div>
</section>
<section class="content-section faq-page">
    <div class="container">
        <div class="row g-4 g-xl-5">
            <div class="col-lg-8">
                <div class="faq-accordion">
                    <?php foreach ($faqs as $faqIndex => $faqEntry): ?>
                        <details
                            class="faq-item"
                            id="<?= escape_html($faqEntry["id"]) ?>"
                            <?= $faqIndex === 0 ? "open" : "" ?>
                        >
                            <summary><?= escape_html(
                                $faqEntry["question"],
                            ) ?></summary>
                            <div class="faq-item__answer">
                                <p><?= escape_html($faqEntry["answer"]) ?></p>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-lg-4">
                <aside class="support-sidebar">
                    <article>
                        <i class="bi bi-headset"></i>
                        <h2>Need rental help?</h2>
                        <p>Contact the VJ team for vehicle guidance, accessibility needs, or policy clarification.</p>
                        <a class="btn btn-primary w-100" href="contact.php">Contact Support</a>
                    </article>
                    <article class="support-sidebar__emergency">
                        <i class="bi bi-cone-striped"></i>
                        <h3>Roadside support</h3>
                        <p>Support instructions and the assigned vehicle details are provided with every confirmed rental.</p>
                    </article>
                    <article>
                        <i class="bi bi-person-vcard"></i>
                        <h3>Renter checklist</h3>
                        <ul>
                            <li>Driver’s license</li>
                            <li>Government ID</li>
                            <li>Security deposit</li>
                            <li>Rental agreement</li>
                        </ul>
                    </article>
                </aside>
            </div>
        </div>
    </div>
</section>
<?php require dirname(__DIR__, 2) . "/includes/footer.php"; ?>
