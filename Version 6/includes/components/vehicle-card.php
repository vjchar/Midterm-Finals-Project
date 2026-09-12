<?php

declare(strict_types=1);

/**
 * Render one reusable vehicle catalog card.
 *
 * @param array{
 *     slug: string,
 *     name: string,
 *     category: string,
 *     image: string,
 *     price: int,
 *     seats: int,
 *     transmission: string
 * } $vehicle
 */
function vehicle_card(array $vehicle, bool $compact = false): void
{
    $compactCardClass = $compact ? " vehicle-card--compact" : "";
    $vehicleImageFilename = basename((string) $vehicle["image"]);
    $vehicleImageRelativePath = "assets/images/cars/" . $vehicleImageFilename;
    $vehicleImageSource = is_file(ROOT . "/" . $vehicleImageRelativePath)
        ? $vehicleImageRelativePath
        : "assets/images/placeholders/vehicle-placeholder.svg";
    ?>
    <article class="vehicle-card<?= $compactCardClass ?>">
        <a
            class="vehicle-image-wrap"
            href="vehicle-details.php?vehicle=<?= urlencode($vehicle["slug"]) ?>"
        >
            <img
                src="<?= escape_html($vehicleImageSource) ?>"
                alt="<?= escape_html($vehicle["name"]) ?>"
                loading="lazy"
                decoding="async"
            >
        </a>

        <div class="vehicle-card-body">
            <div class="d-flex align-items-center justify-content-between gap-2">
                <span class="vehicle-category"><?= escape_html(
                    $vehicle["category"],
                ) ?></span>
            </div>

            <h3>
                <a href="vehicle-details.php?vehicle=<?= urlencode($vehicle["slug"]) ?>">
                    <?= escape_html($vehicle["name"]) ?>
                </a>
            </h3>

            <?php if (!$compact): ?>
                <div class="vehicle-specs" aria-label="Vehicle specifications">
                    <span>
                        <i class="bi bi-people" aria-hidden="true"></i>
                        <?= (int) $vehicle["seats"] ?> seats
                    </span>
                    <span>
                        <i class="bi bi-gear" aria-hidden="true"></i>
                        <?= escape_html($vehicle["transmission"]) ?>
                    </span>
                </div>
            <?php endif; ?>

            <div class="vehicle-price-row">
                <strong>
                    ₱<?= number_format($vehicle["price"]) ?><small>/ day</small>
                </strong>

                <?php if (!$compact): ?>
                    <a
                        href="booking.php?vehicle=<?= urlencode($vehicle["slug"]) ?>"
                        aria-label="Book <?= escape_html($vehicle["name"]) ?>"
                    >
                        <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </article>
    <?php
}
