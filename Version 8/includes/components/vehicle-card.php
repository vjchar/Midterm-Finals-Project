<?php

declare(strict_types=1);

/**
 * Return the signed-in customer's saved vehicle slugs once per request.
 *
 * Administrators do not own a customer favorites list, so they receive an
 * empty result rather than being offered customer-only controls.
 *
 * @return list<string>
 */
function customer_favorite_vehicle_slugs(): array
{
    static $favoriteVehicleSlugs = null;

    if (is_array($favoriteVehicleSlugs)) {
        return $favoriteVehicleSlugs;
    }

    $authenticatedUser = current_user();
    if (($authenticatedUser["role"] ?? null) !== "customer") {
        $favoriteVehicleSlugs = [];
        return $favoriteVehicleSlugs;
    }

    $favoriteVehicleSlugs = favorite_slugs((int) $authenticatedUser["id"]);
    return $favoriteVehicleSlugs;
}

/** Render one reusable vehicle catalog card. */
function vehicle_card(array $vehicle, bool $compact = false): void
{
    $compactCardClass = $compact ? " vehicle-card--compact" : "";
    $currentPageName = basename($_SERVER["PHP_SELF"] ?? "vehicles.php");
    $currentQueryString = trim((string) ($_SERVER["QUERY_STRING"] ?? ""));
    $currentRequestPath = $currentPageName;
    if ($currentQueryString !== "") {
        $currentRequestPath .= "?" . $currentQueryString;
    }

    $authenticatedUser = current_user();
    $isCustomer = ($authenticatedUser["role"] ?? null) === "customer";
    $isAnonymousVisitor = $authenticatedUser === null;
    $showComparisonLink = !$compact && $currentPageName === "vehicles.php";
    $vehicleIsFavorite = in_array(
        $vehicle["slug"],
        customer_favorite_vehicle_slugs(),
        true,
    );
    $favoriteActionLabel = $vehicleIsFavorite
        ? "Remove {$vehicle["name"]} from favorites"
        : "Save {$vehicle["name"]} to favorites";

    $vehicleImageFilename = basename((string) $vehicle["image"]);
    $vehicleImageRelativePath = "assets/images/cars/" . $vehicleImageFilename;
    $vehicleImageSource = is_file(ROOT . "/" . $vehicleImageRelativePath)
        ? $vehicleImageRelativePath
        : "assets/images/placeholders/vehicle-placeholder.svg";
    ?>
    <article class="vehicle-card<?= $compactCardClass ?>">
        <?php if ($isCustomer): ?>
            <form class="favorite-form" method="post" action="favorite-action.php">
                <?= csrf_field() ?>
                <input type="hidden" name="vehicle" value="<?= escape_html($vehicle["slug"]) ?>">
                <input type="hidden" name="return_to" value="<?= escape_html($currentRequestPath) ?>">
                <button class="favorite-button<?= $vehicleIsFavorite ? " is-active" : "" ?>"
                        type="submit" aria-label="<?= escape_html($favoriteActionLabel) ?>">
                    <i class="bi <?= $vehicleIsFavorite ? "bi-heart-fill" : "bi-heart" ?>" aria-hidden="true"></i>
                </button>
            </form>
        <?php elseif ($isAnonymousVisitor): ?>
            <a class="favorite-button"
               href="login.php?return_to=<?= urlencode($currentRequestPath) ?>"
               aria-label="Sign in to save <?= escape_html($vehicle["name"]) ?> to favorites">
                <i class="bi bi-heart" aria-hidden="true"></i>
            </a>
        <?php endif; ?>

        <a class="vehicle-image-wrap" href="vehicle-details.php?vehicle=<?= urlencode($vehicle["slug"]) ?>">
            <img src="<?= escape_html($vehicleImageSource) ?>" alt="<?= escape_html($vehicle["name"]) ?>" loading="lazy" decoding="async">
        </a>

        <div class="vehicle-card-body">
            <div class="d-flex align-items-center justify-content-between gap-2">
                <span class="vehicle-category"><?= escape_html($vehicle["category"]) ?></span>
            </div>

            <h3><a href="vehicle-details.php?vehicle=<?= urlencode($vehicle["slug"]) ?>"><?= escape_html($vehicle["name"]) ?></a></h3>

            <?php if (!$compact): ?>
                <?php if (($vehicle["review_count"] ?? 0) > 0): ?>
                    <div class="vehicle-rating" aria-label="Rating <?= number_format((float) $vehicle["rating"], 1) ?> out of 5">
                        <i class="bi bi-star-fill" aria-hidden="true"></i>
                        <strong><?= number_format((float) $vehicle["rating"], 1) ?></strong>
                        <span>(<?= (int) $vehicle["review_count"] ?>)</span>
                    </div>
                <?php else: ?>
                    <div class="vehicle-rating vehicle-rating--new">
                        <i class="bi bi-stars" aria-hidden="true"></i>
                        <strong>New to the fleet</strong>
                    </div>
                <?php endif; ?>

                <div class="vehicle-specs" aria-label="Vehicle specifications">
                    <span><i class="bi bi-people" aria-hidden="true"></i> <?= (int) $vehicle["seats"] ?> seats</span>
                    <span><i class="bi bi-gear" aria-hidden="true"></i> <?= escape_html($vehicle["transmission"]) ?></span>
                </div>

                <?php if ($showComparisonLink): ?>
                    <a class="compare-toggle" href="compare.php?compare[]=<?= urlencode($vehicle["slug"]) ?>">
                        <i class="bi bi-columns-gap" aria-hidden="true"></i>
                        <span>Compare this vehicle</span>
                    </a>
                <?php endif; ?>
            <?php endif; ?>

            <div class="vehicle-price-row">
                <strong>₱<?= number_format($vehicle["price"]) ?><small>/ day</small></strong>
                <?php if (!$compact): ?>
                    <a href="booking.php?vehicle=<?= urlencode($vehicle["slug"]) ?>" aria-label="Book <?= escape_html($vehicle["name"]) ?>">
                        <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </article>
    <?php
}
