<?php

declare(strict_types=1);

/**
 * Render the customer-facing five-step onboarding journey.
 */
function render_booking_progress(array $journey): void
{
    if (!$journey["step_index"] && !in_array($journey["stage"], ["preparing", "ready_pickup", "ready_delivery"], true)) {
        return;
    }
    ?>
    <nav class="journey-progress" aria-label="Booking progress">
        <?php foreach ($journey["steps"] as $number => $step): ?>
            <?php $state = $step["state"]; ?>
            <div class="journey-progress__step is-<?= escape_html($state) ?>" aria-current="<?= $state === "current" ? "step" : "false" ?>">
                <span class="journey-progress__number" aria-hidden="true"><?= $state === "complete" ? "✓" : (int) $number ?></span>
                <span class="journey-progress__label"><?= escape_html($step["label"]) ?></span>
            </div>
            <?php if ($number < count($journey["steps"])): ?>
                <i class="journey-progress__line" aria-hidden="true"></i>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <?php
}

/**
 * Render one consistent next-step / no-action card.
 */
function render_booking_next_step(array $journey, string $kicker = "Next step"): void
{
    $icon = match ($journey["stage"]) {
        "payment", "extension_payment", "return_payment" => "bi-credit-card",
        "documents" => "bi-person-vcard",
        "verification" => "bi-shield-check",
        "preparing" => "bi-tools",
        "ready_pickup" => "bi-key",
        "ready_delivery" => "bi-truck",
        "active" => "bi-car-front",
        "review" => "bi-star",
        "closed" => "bi-x-circle",
        default => "bi-arrow-right-circle",
    };
    ?>
    <article class="journey-next journey-next--<?= escape_html($journey["severity"]) ?>">
        <div class="journey-next__icon"><i class="bi <?= escape_html($icon) ?>"></i></div>
        <div class="journey-next__body">
            <span class="section-kicker"><?= escape_html($kicker) ?></span>
            <h2><?= escape_html($journey["title"]) ?></h2>
            <p><?= escape_html($journey["message"]) ?></p>
            <?php if ($journey["no_action"]): ?>
                <strong class="journey-next__quiet"><i class="bi bi-clock-history"></i> No Action Required Right Now</strong>
            <?php endif; ?>
        </div>
        <?php if ($journey["button_label"] !== ""): ?>
            <a class="btn <?= $journey["action_required"] ? "btn-primary" : "btn-outline" ?>" href="<?= escape_html($journey["target_url"]) ?>">
                <?= escape_html($journey["button_label"]) ?>
            </a>
        <?php endif; ?>
    </article>
    <?php
}
