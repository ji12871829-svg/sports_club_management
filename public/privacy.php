<?php
// ============================================================
//  public/privacy.php
//  Privacy Policy — Apex Sports Club
//  Complies with Kenya Data Protection Act 2019 principles.
// ============================================================
session_start();
require_once '../config/api_config.php';
?>
<?php include '../includes/header.php'; ?>

<div class="row justify-content-center">
    <div class="col-md-10 col-lg-8">
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-shield-halved me-2"></i>Privacy Policy</h2>
            </div>
            <div class="card-body">
                <p class="text-muted">Last updated: August 2026</p>

                <h5>1. Who We Are</h5>
                <p>Apex Sports Club ("the Club," "we," "us," "our") operates the sports club management system at this website. We are committed to protecting your personal data in accordance with the Kenya Data Protection Act 2019.</p>

                <h5>2. What Data We Collect</h5>
                <p>We collect the following personal information when you register or use our services:</p>
                <ul>
                    <li><strong>Identity data:</strong> first name, last name</li>
                    <li><strong>Contact data:</strong> email address, phone number, physical address</li>
                    <li><strong>Activity data:</strong> booking history, facility usage, sport participation, payment records</li>
                    <li><strong>Technical data:</strong> IP address, browser type, pages visited (for system performance monitoring)</li>
                    <li><strong>Health-related data:</strong> injury reports (only if you voluntarily provide them for club safety purposes)</li>
                </ul>

                <h5>3. How We Collect Data</h5>
                <ul>
                    <li><strong>Directly from you:</strong> when you fill in registration forms, booking forms, or contact us</li>
                    <li><strong>Automatically:</strong> when you use our website (page load times, error logs, login attempts)</li>
                    <li><strong>From payment providers:</strong> M-Pesa (Safaricom) and Paystack provide transaction status and reference numbers. We never see or store your M-Pesa PIN or card details.</li>
                </ul>

                <h5>4. How We Use Your Data</h5>
                <ul>
                    <li>To manage your membership and club bookings</li>
                    <li>To process payments and send receipts</li>
                    <li>To communicate with you about bookings, payments, and club activities</li>
                    <li>To improve our services through anonymous analytics</li>
                    <li>To comply with legal obligations</li>
                </ul>

                <h5>5. Legal Basis for Processing</h5>
                <p>We process your data based on:</p>
                <ul>
                    <li><strong>Consent:</strong> you have ticked the consent checkbox during registration</li>
                    <li><strong>Contractual necessity:</strong> processing is required to manage your membership and bookings</li>
                    <li><strong>Legal obligation:</strong> we may need to retain financial records for tax purposes</li>
                </ul>

                <h5>6. Data Sharing</h5>
                <p>We do not sell your personal data. We may share data with:</p>
                <ul>
                    <li><strong>Payment processors</strong> (Safaricom M-Pesa, Paystack) — only what is necessary to process a transaction</li>
                    <li><strong>Email service provider</strong> (Brevo/Sendinblue) — to send booking confirmations and receipts</li>
                    <li><strong>Law enforcement</strong> — if required by Kenyan law</li>
                </ul>

                <h5>7. Data Retention</h5>
                <p>We retain your data for as long as your membership is active, plus:</p>
                <ul>
                    <li>Payment records: 7 years (tax compliance)</li>
                    <li>Activity logs: 1 year</li>
                    <li>Login attempt records: 1 hour</li>
                </ul>

                <h5>8. Your Rights Under Kenya's Data Protection Act</h5>
                <p>You have the right to:</p>
                <ul>
                    <li><strong>Access</strong> your personal data — request a copy of what we hold</li>
                    <li><strong>Rectification</strong> — correct inaccurate data</li>
                    <li><strong>Erasure</strong> — request deletion of your data (subject to legal retention requirements)</li>
                    <li><strong>Restriction</strong> — limit how we use your data</li>
                    <li><strong>Data portability</strong> — receive your data in a machine-readable format</li>
                    <li><strong>Object</strong> — to processing for direct marketing</li>
                    <li><strong>Withdraw consent</strong> — at any time, without affecting the lawfulness of processing before withdrawal</li>
                </ul>
                <p>To exercise any of these rights, contact us at <a href="mailto:ji12871829@gmail.com">ji12871829@gmail.com</a> or through your account dashboard.</p>

                <h5>9. Data Security</h5>
                <p>We implement appropriate technical and organizational measures to protect your data, including:</p>
                <ul>
                    <li>Encrypted passwords (bcrypt)</li>
                    <li>Secure session management (HttpOnly, SameSite cookies)</li>
                    <li>Rate limiting on login attempts</li>
                    <li>Two-factor authentication for administrators</li>
                    <li>Regular security updates</li>
                </ul>

                <h5>10. Cookies</h5>
                <p>We use essential session cookies to keep you logged in. We do not use tracking cookies or third-party analytics cookies. You can disable cookies in your browser, but this may prevent you from logging in.</p>

                <h5>11. Third-Party Services</h5>
                <p>Our website uses:</p>
                <ul>
                    <li><strong>Google Fonts</strong> — for typography</li>
                    <li><strong>Font Awesome</strong> — for icons</li>
                    <li><strong>Bootstrap CDN</strong> — for layout and components</li>
                    <li><strong>Leaflet (OpenStreetMap)</strong> — for facility location maps</li>
                    <li><strong>Brevo (Sendinblue)</strong> — for transactional emails</li>
                    <li><strong>Paystack</strong> — for payment processing</li>
                    <li><strong>Safaricom M-Pesa</strong> — for mobile money payments</li>
                    <li><strong>Google Gemini</strong> — for AI-powered booking review and analysis (optional, no personal data is used for training)</li>
                </ul>

                <h5>12. Changes to This Policy</h5>
                <p>We may update this policy from time to time. The latest version will always be available at this URL. We will notify you of material changes by email.</p>

                <h5>13. Contact</h5>
                <p>For questions about this policy or to exercise your data rights:</p>
                <ul>
                    <li>Email: <a href="mailto:ji12871829@gmail.com">ji12871829@gmail.com</a></li>
                    <li>In person: Visit the club administration office</li>
                </ul>

                <hr>
                <p class="text-muted small"><i class="fas fa-balance-scale me-1"></i> This privacy policy is designed to comply with the Kenya Data Protection Act, 2019. If you believe your data rights have been violated, you may lodge a complaint with the Office of the Data Protection Commissioner (ODPC).</p>
            </div>
        </div>
        <p class="text-center mt-3">
            <a href="register.php" class="btn btn-primary"><i class="fas fa-arrow-left me-1"></i> Back to Registration</a>
            <a href="index.php" class="btn btn-outline-secondary ms-2"><i class="fas fa-home me-1"></i> Home</a>
        </p>
    </div>
</div>

<?php include '../includes/footer.php'; ?>