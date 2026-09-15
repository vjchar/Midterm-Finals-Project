<?php

declare(strict_types=1);

require_once __DIR__ . "/includes/bootstrap.php";

$vehicles = vehicle_all();
$pageTitle = "VJ Car Rental | Your Journey Starts Here";
$pageDescription =
    "Find reliable, clean, and affordable vehicles with fast booking, transparent pricing, and trusted VJ Car Rental service.";
$pageScripts = ["assets/js/fleet-carousel.js?v=20260910-1"];
require __DIR__ . "/includes/header.php";

$featuredVehicles = array_values(
    array_filter(
        [
            $vehicles[0] ?? null,
            $vehicles[12] ?? null,
            $vehicles[14] ?? null,
            $vehicles[15] ?? null,
        ],
        static fn(mixed $catalogVehicle): bool => is_array($catalogVehicle),
    ),
);
$featuredVehicleSlugs = array_column($featuredVehicles, "slug");
$featuredBrowseVehicles = array_slice(
    array_merge(
        $featuredVehicles,
        array_values(
            array_filter(
                $vehicles,
                static fn(array $catalogVehicle): bool => !in_array(
                    $catalogVehicle["slug"],
                    $featuredVehicleSlugs,
                    true,
                ),
            ),
        ),
    ),
    0,
    4,
);
$vehiclesByCategory = [];
foreach (["Luxury", "SUV", "Pickup", "Van", "EV"] as $vehicleCategory) {
    $vehiclesByCategory[$vehicleCategory] = array_values(
        array_filter(
            $vehicles,
            static fn(array $catalogVehicle): bool => $catalogVehicle[
                "category"
            ] === $vehicleCategory,
        ),
    );
}
?>

<section class="home-hero pattern-layer">
    <div class="container hero-content">
        <div class="row align-items-center g-4 g-xl-5">
            <div class="col-lg-5">
                <span class="section-kicker tracking-[0.08em]">Reliable, clean, affordable.</span>
                <h1 aria-label="Your Journey Starts Here. Drive More. Worry Less.">Your Journey<br>Starts Here.<br>
                    <span>Drive More.<br>Worry Less.</span>
                </h1>
                <p class="hero-lead">Rent the perfect car for any occasion. <br>Fast booking, transparent pricing, <br>and trusted by thousands.</p>
                <div class="d-flex flex-wrap gap-3 hero-actions">
                    <a class="btn btn-primary" href="booking.php">Book a Car</a>
                    <a class="btn btn-outline" href="vehicles.php">View Vehicles</a>
                </div>
                <div class="hero-benefits" aria-label="Rental benefits">
                    <div>
                        <i class="bi bi-shield-check"></i>
                        <span>
                            <strong>Best Prices</strong>
                            <small>Guaranteed</small>
                        </span>
                    </div>
                    <div>
                        <i class="bi bi-car-front-fill"></i>
                        <span>
                            <strong>Wide Range</strong>
                            <small>of Vehicles</small>
                        </span>
                    </div>
                    <div>
                        <i class="bi bi-headset"></i>
                        <span>
                            <strong>24/7 Customer</strong>
                            <small>Support</small>
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="hero-car-stage">
                    <img src="assets/images/cars/porsche-911.png" alt="White Porsche 911 featured by VJ Car Rental" decoding="async" fetchpriority="high">
                </div>
            </div>
        </div>
    </div>
    <div class="container search-card-wrap">
        <form class="search-card" action="vehicles.php" method="get">
            <label class="search-field search-field--location">
                <i class="bi bi-geo-alt"></i>
                <span>
                    <small>Pick-up Location</small>
                    <select name="location" aria-label="Pick-up location">
                        <option>Select Location</option>
                        <option>Manjuyod</option>
                        <option>Bais City</option>
                        <option>Dumaguete City</option>
                    </select>
                </span>
            </label>
            <label class="search-field">
                <span>
                    <small>Pick-up Date</small>
                    <input
                        type="date"
                        name="pickup"
                        min="<?= escape_html(date("Y-m-d")) ?>"
                        aria-label="Pick-up date">
                </span>
            </label>
            <label class="search-field">
                <span>
                    <small>Return Date</small>
                    <input
                        type="date"
                        name="return"
                        min="<?= escape_html(date("Y-m-d")) ?>"
                        aria-label="Return date">
                </span>
            </label>
            <label class="search-field">
                <span>
                    <small>Select Vehicle Type</small>
                    <select name="category" aria-label="Vehicle type">
                        <option value="">All Types</option>
                        <option>Luxury</option>
                        <option>SUV</option>
                        <option>Pickup</option>
                        <option>Van</option>
                        <option>EV</option>
                    </select>
                </span>
            </label>
            <button class="btn btn-primary search-button" type="submit">Search Cars</button>
        </form>
    </div>
</section>
<section class="content-section fleet-preview pattern-layer">
    <div class="container">
        <div class="section-heading heading-with-action">
            <div>
                <span class="section-kicker">Explore our fleet</span>
                <h2>Find the perfect car<br>for your next trip</h2>
            </div>
            <a class="btn btn-outline" href="vehicles.php">View All Vehicles</a>
        </div>
        <nav class="filter-pills" aria-label="Browse vehicle categories">
            <a class="active" href="vehicles.php">All Vehicles</a>
            <a href="vehicles.php?category=Luxury">Luxury</a>
            <a href="vehicles.php?category=SUV">SUV</a>
            <a href="vehicles.php?category=Pickup">Pickup</a>
            <a href="vehicles.php?category=Van">Van</a>
            <a href="vehicles.php?category=EV">EV</a>
        </nav>
        <div class="row g-3 featured-vehicle-row">
            <?php foreach ($featuredBrowseVehicles as $featuredVehicle): ?>
                <div class="col-xl-3 col-md-6" data-grid-item>
                    <?php vehicle_card($featuredVehicle); ?>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="mobile-swipe-hint"><i class="bi bi-arrow-left-right"></i> Swipe to browse featured vehicles</p>
        <div class="trust-strip">
            <div>
                <i class="bi bi-clipboard2-check"></i>
                <span>
                    <small>Easy Booking</small>
                    <strong>Book your car in just<br>a few simple steps.</strong>
                </span>
            </div>
            <div>
                <i class="bi bi-shield"></i>
                <span>
                    <small>Clean &amp; Safe Cars</small>
                    <strong>Well-maintained cars<br>for your safety.</strong>
                </span>
            </div>
            <div>
                <i class="bi bi-currency-dollar"></i>
                <span>
                    <small>No Hidden Fees</small>
                    <strong>Transparent pricing<br>with no surprises.</strong>
                </span>
            </div>
            <div>
                <i class="bi bi-people-fill"></i>
                <span>
                    <small>Trusted by Thousands</small>
                    <strong>Join satisfied customers<br>nationwide.</strong>
                </span>
            </div>
        </div>
    </div>
</section>
<section class="content-section how-section pattern-layer">
    <div class="container">
        <div class="row align-items-center g-4">
            <div class="col-lg-4">
                <span class="section-kicker">How it works</span>
                <h2>Rent a car in<br>4 easy steps</h2>
            </div>
            <div class="col-lg-8">
                <div class="steps-row">
                    <article>
                        <div class="step-icon">
                            <i class="bi bi-calendar3"></i>
                        </div>
                        <b>1</b>
                        <h3>Choose Dates</h3>
                        <p>Select your pick-up and return dates.</p>
                    </article>
                    <i class="bi bi-arrow-right step-arrow" aria-hidden="true"></i>
                    <article>
                        <div class="step-icon">
                            <i class="bi bi-car-front-fill"></i>
                        </div>
                        <b>2</b>
                        <h3>Select Vehicle</h3>
                        <p>Browse and choose the perfect vehicle.</p>
                    </article>
                    <i class="bi bi-arrow-right step-arrow" aria-hidden="true"></i>
                    <article>
                        <div class="step-icon">
                            <i class="bi bi-person-vcard"></i>
                        </div>
                        <b>3</b>
                        <h3>Provide Details</h3>
                        <p>Enter your details and driving information.</p>
                    </article>
                    <i class="bi bi-arrow-right step-arrow" aria-hidden="true"></i>
                    <article>
                        <div class="step-icon">
                            <i class="bi bi-check-lg"></i>
                        </div>
                        <b>4</b>
                        <h3>Confirm Booking</h3>
                        <p>Review your request and receive staff confirmation.</p>
                    </article>
                </div>
            </div>
        </div>
    </div>
</section>
<section class="content-section fleet-shelves pattern-layer">
    <div class="container">
        <span class="section-kicker">Our fleet</span>
        <?php foreach (
            $vehiclesByCategory
            as $categoryName => $categoryVehicles
        ): ?>
            <?php $categorySectionId = "fleet-" . strtolower($categoryName); ?>
            <div
                class="fleet-shelf"
                id="<?= escape_html($categorySectionId) ?>"
                data-fleet-carousel>
                <div class="fleet-shelf__heading">
                    <h2><?= escape_html($categoryName) ?></h2>
                    <div class="fleet-carousel__actions">
                        <span class="fleet-carousel__hint">
                            <i class="bi bi-arrow-left-right" aria-hidden="true"></i>
                            Scroll to browse
                        </span>
                        <div
                            class="fleet-carousel__controls"
                            aria-label="<?= escape_html($categoryName) ?> carousel controls">
                            <button
                                type="button"
                                data-carousel-previous
                                aria-controls="<?= escape_html($categorySectionId) ?>-cars"
                                aria-label="Show previous <?= escape_html($categoryName) ?> vehicles"
                                disabled>
                                <i class="bi bi-chevron-left" aria-hidden="true"></i>
                            </button>
                            <button
                                type="button"
                                data-carousel-next
                                aria-controls="<?= escape_html($categorySectionId) ?>-cars"
                                aria-label="Show next <?= escape_html($categoryName) ?> vehicles">
                                <i class="bi bi-chevron-right" aria-hidden="true"></i>
                            </button>
                        </div>
                        <a
                            class="fleet-carousel__view-all"
                            href="vehicles.php?category=<?= urlencode(
                                $categoryName,
                            ) ?>">
                            View All
                        </a>
                    </div>
                </div>
                <div
                    class="fleet-carousel__viewport"
                    id="<?= escape_html($categorySectionId) ?>-cars"
                    tabindex="0"
                    aria-label="<?= escape_html(
                        $categoryName,
                    ) ?> vehicles; use the arrow buttons or scroll horizontally to browse">
                    <div class="fleet-shelf__cars">
                        <?php foreach (
                            $categoryVehicles
                            as $categoryVehicle
                        ): ?>
                            <?php vehicle_card($categoryVehicle, true); ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<section class="content-section difference-section pattern-layer">
    <div class="container">
        <div class="row align-items-center g-4 g-xl-5">
            <div class="col-lg-4">
                <span class="section-kicker">Why choose VJ Car Rental?</span>
                <h2>Experience the<br>VJ Difference</h2>
                <p>We are committed to providing the best car rental experience with quality vehicles, 
                    excellent service, and unbeatable value.</p>
                <a class="btn btn-outline" href="about.php">Learn More About Us</a>
            </div>
            <div class="col-lg-8">
                <div class="stats-grid">
                    <article>
                        <i class="bi bi-car-front-fill"></i>
                        <strong>100+</strong>
                        <span>Well-maintained<br>Vehicles</span>
                    </article>
                    <article>
                        <i class="bi bi-emoji-smile"></i>
                        <strong>10,000+</strong>
                        <span>Happy<br>Customers</span>
                    </article>
                    <article>
                        <i class="bi bi-geo-alt"></i>
                        <strong>50+</strong>
                        <span>Locations Across<br>Key Cities</span>
                    </article>
                    <article>
                        <i class="bi bi-headset"></i>
                        <strong>24/7</strong>
                        <span>Customer<br>Support</span>
                    </article>
                </div>
            </div>
        </div>
    </div>
</section>
<section class="content-section customer-tools-section pattern-layer">
    <div class="container">
        <div class="section-heading heading-with-action">
            <div>
                <span class="section-kicker">Customer tools</span>
                <h2>Plan, compare, and manage<br>with clearer information</h2>
            </div>
            <a class="btn btn-outline" href="faq.php">Read Rental FAQs</a>
        </div>
        <div class="customer-tools-grid">
            <a href="compare.php">
                <i class="bi bi-columns-gap"></i>
                <span>
                    <strong>Compare Vehicles</strong>
                    <small>Review pricing, capacity, mileage, deposit, and availability side by side.</small>
                </span>
                <i class="bi bi-arrow-right"></i>
            </a>
            <a href="favorites.php">
                <i class="bi bi-heart"></i>
                <span>
                    <strong>Saved Favorites</strong>
                    <small>Keep a shortlist on this device while you decide which car fits.</small>
                </span>
                <i class="bi bi-arrow-right"></i>
            </a>
            <a href="manage-booking.php">
                <i class="bi bi-calendar2-check"></i>
                <span>
                    <strong>Manage Booking</strong>
                    <small>Open your saved reservation to review, reschedule, cancel, or print it.</small>
                </span>
                <i class="bi bi-arrow-right"></i>
            </a>
            <a href="rate-trip.php">
                <i class="bi bi-star"></i>
                <span>
                    <strong>Post-Trip Rating</strong>
                    <small>Completed renters can submit verified scores, written feedback, and trip photos.</small>
                </span>
                <i class="bi bi-arrow-right"></i>
            </a>
        </div>
    </div>
</section>
<section class="content-section testimonials-section pattern-layer">
    <div class="container">
        <span class="section-kicker">What our customers say</span>
        <div class="row g-4 mt-1">
            <div class="col-lg-4">
                <article class="testimonial-card">
                    <blockquote>The car was clean, the booking process was easy and the staff were very accommodating.</blockquote>
                    <div class="stars" aria-label="5 out of 5 stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
                    <div class="reviewer">
                        <span>JL</span>
                        <div>
                            <strong>James Lebron</strong>
                            <small>Business Traveler</small>
                        </div>
                    </div>
                </article>
            </div>
            <div class="col-lg-4">
                <article class="testimonial-card">
                    <blockquote>Affordable rates and excellent service! Highly recommended for family trips.</blockquote>
                    <div class="stars" aria-label="5 out of 5 stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
                    <div class="reviewer">
                        <span>MJ</span>
                        <div>
                            <strong>Michael Jackson</strong>
                            <small>Family Customer</small>
                        </div>
                    </div>
                </article>
            </div>
            <div class="col-lg-4">
                <article class="testimonial-card">
                    <blockquote>Fast and hassle-free. Will definitely rent again for our next travel.</blockquote>
                    <div class="stars" aria-label="5 out of 5 stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
                    <div class="reviewer">
                        <span>DJ</span>
                        <div>
                            <strong>Durant James</strong>
                            <small>Frequent Traveler</small>
                        </div>
                    </div>
                </article>
            </div>
        </div>
    </div>
</section>
<?php require __DIR__ . "/includes/footer.php"; ?>
