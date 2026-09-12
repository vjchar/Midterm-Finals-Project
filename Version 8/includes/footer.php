</main>

    <section class="cta-band section-shell">
        <div class="container">
            <div class="cta-band__inner">
                <div>
                    <h2>Ready to hit the road?</h2>
                    <p>
                        Explore our vehicles and enjoy a smooth and worry-free journey
                        with VJ Car Rental.
                    </p>
                </div>

                <a class="btn btn-light" href="vehicles.php">
                    View Our Vehicles
                    <i class="bi bi-arrow-right" aria-hidden="true"></i>
                </a>
            </div>
        </div>
    </section>
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
            </nav>

            <nav class="footer-column" aria-label="Explore VJ Car Rental">
                <h3>Explore</h3>
                <a href="vehicles.php">Our Fleet</a>
                <a href="compare.php">Compare Vehicles</a>
                <a href="favorites.php">Saved Favorites</a>
                <a href="rate-trip.php">Rate a Completed Trip</a>
                <a href="services.php">Services</a>
                <a href="about.php">About Us</a>
                <a href="contact.php">Contact Us</a>
            </nav>

            <div class="footer-column footer-support">
                <h3>Customer Support</h3>
                <p>Questions about a vehicle or service? Our team is ready to help.</p>

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
            <span>Reliable. Clean. Affordable.</span>
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
