</main>

<?php
$currentScript = basename($_SERVER["PHP_SELF"] ?? "");
$isAdminPage =
    str_starts_with($currentScript, "admin") || $currentScript === "health.php";
?>

<?php if (!$isAdminPage): ?>
    <section class="cta-band section-shell">
        <div class="container">
            <div class="cta-band__inner">
                <div>
                    <h2>Ready to hit the road?</h2>
                    <p>
                        Book your car today and enjoy a smooth and worry-free journey
                        with VJ Car Rental.
                    </p>
                </div>

                <a class="btn btn-light" href="booking.php">
                    Book Your Car Now
                    <i class="bi bi-arrow-right" aria-hidden="true"></i>
                </a>
            </div>
        </div>
    </section>
<?php endif; ?>

<footer class="site-footer">
    <div class="container">
        <div class="footer-grid">
            <div class="footer-brand">
                <a class="footer-logo" href="index.php">
                    <img
                        src="assets/images/logo/Logo.png"
                        alt="VJ Car Rental - Your Journey Starts Here"
                        loading="lazy"
                        decoding="async">
                </a>

                <p>
                    Premium cars. Reliable service.<br>
                    Your journey, our commitment.
                </p>

                <ul class="footer-contact list-unstyled">
                    <li>
                        <i class="bi bi-geo-alt" aria-hidden="true"></i>
                        <span>Manjuyod, Negros Oriental, Philippines</span>
                    </li>
                    <li>
                        <i class="bi bi-telephone" aria-hidden="true"></i>
                        <span>+63 994 894 5174</span>
                    </li>
                    <li>
                        <i class="bi bi-envelope" aria-hidden="true"></i>
                        <span>bernardinovjcharles@gmail.com</span>
                    </li>
                    <li>
                        <i class="bi bi-clock" aria-hidden="true"></i>
                        <span>Mon-Sun: 7:00 AM-9:00 PM</span>
                    </li>
                </ul>
            </div>

            <nav class="footer-column" aria-label="Company links">
                <h3>Company</h3>
                <a href="index.php">Home</a>
                <a href="about.php">About Us</a>
                <a href="vehicles.php">Our Fleet</a>
                <a href="services.php">Services</a>
                <a href="contact.php">Contact Us</a>
                <a href="terms.php">Terms &amp; Conditions</a>
                <a href="privacy.php">Privacy Policy</a>
            </nav>

            <nav class="footer-column" aria-label="Rental tools">
                <h3>Rental Tools</h3>
                <a href="compare.php">Compare Vehicles</a>
                <a href="favorites.php">Saved Favorites</a>
                <a href="my-bookings.php">My Bookings</a>
                <a href="manage-booking.php">Manage Booking</a>
                <a href="documents.php">My Documents</a>
                <a href="payments.php">My Payments</a>
                <a href="account.php">My Account</a>
            </nav>

            <div class="footer-column footer-support">
                <h3>Customer Support</h3>
                <p>Questions about a booking or vehicle? Our team is ready to help.</p>
                <a href="faq.php">Frequently Asked Questions</a>
                <a href="rate-trip.php">Rate a Completed Trip</a>
                <a href="notifications.php">Notifications</a>
                <a href="login.php">Account Sign In</a>

                <a class="btn btn-outline-light btn-sm" href="contact.php">
                    <i class="bi bi-headset" aria-hidden="true"></i>
                    Contact Support
                </a>

                <span class="footer-label">Follow us</span>
                <div class="social-links" aria-label="Social platforms">
                    <span role="img" aria-label="Facebook">
                        <i class="bi bi-facebook" aria-hidden="true"></i>
                    </span>
                    <span role="img" aria-label="X">
                        <i class="bi bi-twitter-x" aria-hidden="true"></i>
                    </span>
                    <span role="img" aria-label="Instagram">
                        <i class="bi bi-instagram" aria-hidden="true"></i>
                    </span>
                    <span role="img" aria-label="TikTok">
                        <i class="bi bi-tiktok" aria-hidden="true"></i>
                    </span>
                </div>
            </div>
        </div>

        <div class="footer-bottom">
            <span>&copy; <?= date(
                "Y",
            ) ?> VJ Car Rental. All rights reserved.</span>
            <span>VJ Car Rental — Version 10 · Flexible Rental System</span>
        </div>
    </div>
</footer>

<script src="assets/js/vendor/bootstrap.bundle.js" defer></script>
<script src="assets/js/app.js" defer></script>
<?php foreach ($pageScripts ?? [] as $pageScript): ?>
    <script src="<?= escape_html((string) $pageScript) ?>" defer></script>
<?php endforeach; ?>

</body>

</html>
