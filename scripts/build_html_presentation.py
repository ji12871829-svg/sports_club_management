#!/usr/bin/env python3
"""
Apex Sports Club — HTML Presentation Generator
Creates a standalone HTML slideshow presentation with embedded screenshots.
"""
import os
import base64

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
SCREENSHOTS_DIR = os.path.join(SCRIPT_DIR, '..', 'screenshots')

def img_to_base64(name):
    path = os.path.join(SCREENSHOTS_DIR, f"{name}.png")
    if os.path.exists(path):
        with open(path, "rb") as f:
            return base64.b64encode(f.read()).decode()
    return ""

# Pre-load screenshots
screenshots = {}
for name in ["homepage", "login", "register", "view_sports", "view_facilities",
             "view_fixtures", "booking", "admin_login", "admin_dashboard"]:
    b64 = img_to_base64(name)
    if b64:
        screenshots[name] = f"data:image/png;base64,{b64}"
        print(f"  Loaded: {name}.png")
    else:
        print(f"  Missing: {name}.png")

html = f"""<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Apex Sports Club — System Presentation</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
* {{ margin: 0; padding: 0; box-sizing: border-box; }}
body {{ font-family: 'Inter', sans-serif; background: #0f172a; color: #fff; overflow: hidden; }}

.slide {{
    display: none; width: 100vw; height: 100vh;
    padding: 48px 64px; position: relative; flex-direction: column;
}}
.slide.active {{ display: flex; }}

/* Navigation */
.nav {{ position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); display: flex; gap: 12px; z-index: 100; }}
.nav button {{
    background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2);
    color: #fff; padding: 10px 24px; border-radius: 12px; cursor: pointer;
    font-size: 14px; font-weight: 600; transition: all 0.2s;
}}
.nav button:hover {{ background: #2563eb; border-color: #2563eb; }}
.nav .counter {{ color: #94a3b8; padding: 10px 16px; font-size: 14px; font-weight: 500; }}

/* Slide indicator dots */
.dots {{ position: fixed; bottom: 80px; left: 50%; transform: translateX(-50%); display: flex; gap: 8px; z-index: 100; }}
.dot {{
    width: 10px; height: 10px; border-radius: 50%; background: rgba(255,255,255,0.2);
    cursor: pointer; transition: all 0.3s;
}}
.dot.active {{ background: #2563eb; transform: scale(1.3); }}

/* Typography */
h1 {{ font-size: 3rem; font-weight: 800; letter-spacing: -2px; line-height: 1.1; margin-bottom: 12px; }}
h2 {{ font-size: 2.2rem; font-weight: 800; letter-spacing: -1px; margin-bottom: 8px; }}
h3 {{ font-size: 1.1rem; font-weight: 600; }}
.subtitle {{ color: #94a3b8; font-size: 1.1rem; line-height: 1.6; margin-bottom: 24px; }}
.tag {{
    display: inline-block; padding: 6px 16px; border-radius: 999px;
    font-size: 12px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase;
    position: absolute; top: 48px; right: 64px;
}}

/* Cards */
.card {{
    background: #1e293b; border: 1px solid #334055; border-radius: 16px;
    overflow: hidden; flex: 1;
}}
.card-header {{
    padding: 12px 20px; font-weight: 700; font-size: 14px; color: #fff;
}}
.card-body {{ padding: 16px 20px; }}
.card-body li {{
    color: #94a3b8; font-size: 13px; margin-bottom: 6px; list-style: none;
    padding-left: 12px; position: relative;
}}
.card-body li::before {{ content: "•"; position: absolute; left: 0; }}

/* Screenshots */
.screenshot {{
    border-radius: 12px; border: 1px solid #334055; width: 100%;
    object-fit: cover;
}}
.screenshot-label {{
    text-align: center; color: #94a3b8; font-size: 12px; margin-top: 6px;
}}

/* Grid layouts */
.grid-2 {{ display: grid; grid-template-columns: 1fr 1fr; gap: 24px; flex: 1; }}
.grid-3 {{ display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; flex: 1; }}
.grid-5 {{ display: grid; grid-template-columns: repeat(5, 1fr); gap: 16px; flex: 1; }}

/* Stats */
.stat-card {{
    background: #1e293b; border: 1px solid #334055; border-radius: 16px;
    padding: 20px; text-align: center;
}}
.stat-card .label {{ color: #94a3b8; font-size: 13px; margin-bottom: 8px; }}
.stat-card .value {{ font-size: 2rem; font-weight: 800; }}

/* DB Table cards */
.db-card {{
    background: #1e293b; border-radius: 12px; overflow: hidden; font-size: 11px;
}}
.db-card-header {{
    padding: 8px 12px; font-weight: 700; font-size: 12px; color: #fff;
    font-family: 'Consolas', monospace; text-align: center;
}}
.db-card-body {{
    padding: 8px 12px; font-family: 'Consolas', monospace;
    color: #94a3b8; font-size: 10px; line-height: 1.6;
}}

/* Accent bar */
.accent {{ position: absolute; top: 0; left: 0; right: 0; height: 4px; }}

/* Center layout */
.center {{ display: flex; align-items: center; justify-content: center; flex: 1; flex-direction: column; }}
.flex-1 {{ flex: 1; }}

/* Fallback placeholder for missing screenshots */
.screenshot-fallback {{
    height: 50vh; background: #1e293b; border-radius: 12px;
    display: flex; align-items: center; justify-content: center; color: #94a3b8;
    border: 1px dashed #334055;
}}
.screenshot-fallback-sm {{
    height: 32vh; background: #1e293b; border-radius: 12px;
    display: flex; align-items: center; justify-content: center; color: #94a3b8;
    border: 1px dashed #334055;
}}

/* Reduced motion */
@media (prefers-reduced-motion: reduce) {{
    .slide {{ transition: none !important; }}
    .dot {{ transition: none !important; }}
    .nav button {{ transition: none !important; }}
}}

/* Focus visible for keyboard nav */
.nav button:focus-visible,
.dot:focus-visible {{
    outline: 2px solid #2563eb;
    outline-offset: 2px;
}}

/* Skip to content link */
.skip-link {{
    position: absolute; top: -40px; left: 0; background: #2563eb; color: #fff;
    padding: 8px 16px; z-index: 200; font-size: 14px; font-weight: 600;
    text-decoration: none; border-radius: 0 0 8px 0;
}}
.skip-link:focus {{ top: 0; }}
</style>
</head>
<body>

<!-- Skip link for keyboard users -->
<a href="#s1" class="skip-link">Skip to first slide</a>

<!-- Live region for screen readers -->
<div id="sr-announcer" aria-live="polite" aria-atomic="true" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);"></div>

<!-- SLIDE 1: Title -->
<div class="slide active" id="s1" role="region" aria-label="Slide 1: Title">
    <div class="accent" style="background: #2563eb;"></div>
    <div class="center">
        <div style="background: #2563eb; padding: 10px 24px; border-radius: 999px; font-weight: 700; font-size: 14px; letter-spacing: 2px; margin-bottom: 32px;">APEX SPORTS CLUB</div>
        <h1 style="font-size: 4rem; margin-bottom: 16px;">Apex Sports Club</h1>
        <p class="subtitle" style="font-size: 1.4rem; max-width: 600px; text-align: center;">Comprehensive Sports Management Platform</p>
        <p style="color: #64748b; font-size: 14px; margin-top: 24px;">PHP &middot; MySQL &middot; Bootstrap 5 &middot; Cloudflare Turnstile &middot; Paystack &middot; Brevo Email &middot; Gemini AI</p>
        <p style="color: #94a3b8; font-size: 13px; margin-top: 48px;">System Presentation &nbsp;|&nbsp; July 2026</p>
    </div>
</div>

<!-- SLIDE 2: Homepage & Login -->
<div class="slide" id="s2" role="region" aria-label="Slide 2: Homepage and Login">
    <div class="accent" style="background: #2563eb;"></div>
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2>Live UI — Homepage &amp; Login</h2>
        <span class="tag" style="background: #10b98120; color: #10b981;">LIVE SCREENSHOTS</span>
    </div>
    <div class="grid-2" style="flex: 1;">
        <div>
            {"<img src='" + screenshots.get('homepage', '') + "' alt='Apex Sports Club homepage with hero section and club stats' class='screenshot' style='height: 50vh; object-fit: cover;'>" if screenshots.get('homepage') else "<div class='screenshot-fallback'>Screenshot unavailable</div>"}
            <p class="screenshot-label">Homepage — Hero section with 3D background</p>
        </div>
        <div>
            {"<img src='" + screenshots.get('login', '') + "' alt='Login page with email and password fields' class='screenshot' style='height: 50vh; object-fit: cover;'>" if screenshots.get('login') else "<div class='screenshot-fallback'>Screenshot unavailable</div>"}
            <p class="screenshot-label">Login Page — Secure authentication</p>
        </div>
    </div>
</div>

<!-- SLIDE 3: Registration -->
<div class="slide" id="s3" role="region" aria-label="Slide 3: Registration">
    <div class="accent" style="background: #10b981;"></div>
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2>Live UI — Registration</h2>
        <span class="tag" style="background: #10b98120; color: #10b981;">ONBOARDING</span>
    </div>
    <div style="flex: 1; display: flex; justify-content: center;">
        {"<img src='" + screenshots.get('register', '') + "' alt='Registration form with first name, last name, email, password fields and Turnstile CAPTCHA' class='screenshot' style='max-height: 70vh; object-fit: contain;'>" if screenshots.get('register') else "<div class='screenshot-fallback'>Screenshot unavailable</div>"}
    </div>
</div>

<!-- SLIDE 4: Public Pages -->
<div class="slide" id="s4" role="region" aria-label="Slide 4: Public Pages">
    <div class="accent" style="background: #f97316;"></div>
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2>Live UI — Public Pages</h2>
        <span class="tag" style="background: #f9731620; color: #f97316;">4 PAGES</span>
    </div>
    <div class="grid-2" style="flex: 1; gap: 16px;">
        <div>
            {"<img src='" + screenshots.get('view_sports', '') + "' alt='Sports catalog page showing available sports' class='screenshot' style='height: 32vh; object-fit: cover;'>" if screenshots.get('view_sports') else "<div class='screenshot-fallback-sm'>Screenshot unavailable</div>"}
            <p class="screenshot-label">Browse Sports</p>
        </div>
        <div>
            {"<img src='" + screenshots.get('view_facilities', '') + "' alt='Facilities page showing available venues' class='screenshot' style='height: 32vh; object-fit: cover;'>" if screenshots.get('view_facilities') else "<div class='screenshot-fallback-sm'>Screenshot unavailable</div>"}
            <p class="screenshot-label">View Facilities</p>
        </div>
        <div>
            {"<img src='" + screenshots.get('view_fixtures', '') + "' alt='Fixtures and standings page with upcoming matches' class='screenshot' style='height: 32vh; object-fit: cover;'>" if screenshots.get('view_fixtures') else "<div class='screenshot-fallback-sm'>Screenshot unavailable</div>"}
            <p class="screenshot-label">Fixtures &amp; Standings</p>
        </div>
        <div>
            {"<img src='" + screenshots.get('booking', '') + "' alt='Booking page with facility and coach selection' class='screenshot' style='height: 32vh; object-fit: cover;'>" if screenshots.get('booking') else "<div class='screenshot-fallback-sm'>Screenshot unavailable</div>"}
            <p class="screenshot-label">Book a Session</p>
        </div>
    </div>
</div>

<!-- SLIDE 5: Admin Panel -->
<div class="slide" id="s5" role="region" aria-label="Slide 5: Admin Panel">
    <div class="accent" style="background: #ef4444;"></div>
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2>Live UI — Admin Panel</h2>
        <span class="tag" style="background: #ef444420; color: #ef4444;">ADMIN</span>
    </div>
    <div class="grid-2" style="margin-bottom: 20px;">
        <div>
            {"<img src='" + screenshots.get('admin_login', '') + "' alt='Admin login page with email and password fields' class='screenshot' style='height: 32vh; object-fit: cover;'>" if screenshots.get('admin_login') else "<div class='screenshot-fallback-sm'>Screenshot unavailable</div>"}
            <p class="screenshot-label">Admin Login</p>
        </div>
        <div>
            {"<img src='" + screenshots.get('admin_dashboard', '') + "' alt='Admin dashboard with stats cards and management modules' class='screenshot' style='height: 32vh; object-fit: cover;'>" if screenshots.get('admin_dashboard') else "<div class='screenshot-fallback-sm'>Screenshot unavailable</div>"}
            <p class="screenshot-label">Admin Dashboard</p>
        </div>
    </div>
    <div class="card">
        <div class="card-header" style="background: #ef4444;">Admin Capabilities</div>
        <div class="card-body" style="display: grid; grid-template-columns: 1fr 1fr; gap: 4px 24px;">
            <li>Real-time stats: Members, Bookings, Revenue, Leagues</li>
            <li>AI Booking Review with configurable strictness</li>
            <li>35+ management pages for full CRUD operations</li>
            <li>Churn prediction, AI match reports, Smart scheduling</li>
        </div>
    </div>
</div>

<!-- SLIDE 6: Database Schema -->
<div class="slide" id="s6" role="region" aria-label="Slide 6: Database Schema">
    <div class="accent" style="background: #06b6d4;"></div>
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
        <h2>Database Schema</h2>
        <span class="tag" style="background: #06b6d420; color: #06b6d4;">49 MIGRATIONS</span>
    </div>
    <div class="grid-5" style="margin-bottom: 16px;">
        <div class="db-card"><div class="db-card-header" style="background: #2563eb;">members</div><div class="db-card-body">member_id INT PK<br>first_name VARCHAR<br>last_name VARCHAR<br>email VARCHAR UNQ<br>password VARCHAR<br>phone VARCHAR<br>referral_code VARCHAR<br>role ENUM</div></div>
        <div class="db-card"><div class="db-card-header" style="background: #f97316;">bookings</div><div class="db-card-body">booking_id INT PK<br>member_id INT FK<br>facility_id INT FK<br>coach_id INT FK<br>sport_id INT FK<br>booking_date DATE<br>start_time TIME<br>status ENUM</div></div>
        <div class="db-card"><div class="db-card-header" style="background: #10b981;">payments</div><div class="db-card-body">payment_id INT PK<br>member_id INT FK<br>booking_id INT FK<br>amount DECIMAL<br>payment_method VARCHAR<br>payment_date DATETIME<br>transaction_id VARCHAR<br>status ENUM</div></div>
        <div class="db-card"><div class="db-card-header" style="background: #7c3aed;">leagues</div><div class="db-card-body">league_id INT PK<br>name VARCHAR<br>sport_id INT FK<br>season VARCHAR<br>status ENUM<br>start_date DATE<br>end_date DATE</div></div>
        <div class="db-card"><div class="db-card-header" style="background: #ef4444;">fixtures</div><div class="db-card-body">fixture_id INT PK<br>league_id INT FK<br>home_team_id INT FK<br>away_team_id INT FK<br>match_date DATE<br>home_score INT<br>away_score INT<br>status ENUM</div></div>
    </div>
    <div class="grid-5" style="margin-bottom: 16px;">
        <div class="db-card"><div class="db-card-header" style="background: #2563eb;">sports</div><div class="db-card-body">sport_id INT PK<br>name VARCHAR<br>description TEXT<br>icon VARCHAR</div></div>
        <div class="db-card"><div class="db-card-header" style="background: #f97316;">coaches</div><div class="db-card-body">coach_id INT PK<br>first_name VARCHAR<br>last_name VARCHAR<br>specialization VARCHAR<br>hourly_rate DECIMAL</div></div>
        <div class="db-card"><div class="db-card-header" style="background: #10b981;">facilities</div><div class="db-card-body">facility_id INT PK<br>name VARCHAR<br>sport_id INT FK<br>capacity INT<br>hourly_rate DECIMAL<br>status ENUM</div></div>
        <div class="db-card"><div class="db-card-header" style="background: #7c3aed;">teams</div><div class="db-card-body">team_id INT PK<br>name VARCHAR<br>league_id INT FK<br>logo_url VARCHAR<br>manager_name VARCHAR</div></div>
        <div class="db-card"><div class="db-card-header" style="background: #ef4444;">admins</div><div class="db-card-body">admin_id INT PK<br>email VARCHAR UNQ<br>password VARCHAR<br>role ENUM<br>totp_secret VARCHAR<br>is_2fa_enabled TINYINT</div></div>
    </div>
    <p style="text-align: center; color: #06b6d4; font-size: 13px;">Total: 60+ tables &nbsp;|&nbsp; 49 migration files &nbsp;|&nbsp; Foreign key relationships across all core entities</p>
</div>

<!-- SLIDE 7: Features -->
<div class="slide" id="s7" role="region" aria-label="Slide 7: Platform Features">
    <div class="accent" style="background: #2563eb;"></div>
    <h2 style="margin-bottom: 16px;">Platform Features</h2>
    <div class="grid-3" style="flex: 1;">
        <div class="card"><div class="card-header" style="background: #2563eb;">MEMBERSHIP</div><div class="card-body"><li>Member registration &amp; login</li><li>Digital membership cards (QR)</li><li>Referral &amp; loyalty system</li><li>Membership pause &amp; renewal</li><li>Parent portal &amp; compliance</li><li>GDPR data export</li></div></div>
        <div class="card"><div class="card-header" style="background: #f97316;">BOOKING</div><div class="card-body"><li>Facility &amp; coach booking</li><li>Booking calendar view</li><li>AI automated review</li><li>Smart scheduling</li><li>Attendance tracking</li><li>Session notes &amp; ratings</li></div></div>
        <div class="card"><div class="card-header" style="background: #10b981;">COMPETITION</div><div class="card-body"><li>League &amp; team management</li><li>Fixture scheduling</li><li>Live scoring &amp; match events</li><li>Standings auto-recalculation</li><li>Top scorers leaderboard</li><li>MOTM voting</li></div></div>
        <div class="card"><div class="card-header" style="background: #7c3aed;">FINANCE</div><div class="card-body"><li>Paystack checkout</li><li>M-Pesa Daraja integration</li><li>Revenue dashboard</li><li>Refund management</li><li>Promo codes</li><li>Membership billing</li></div></div>
        <div class="card"><div class="card-header" style="background: #06b6d4;">AI / ML</div><div class="card-body"><li>Booking review automation</li><li>Churn prediction</li><li>AI match reports (Gemini)</li><li>Insights engine</li><li>Custom AI prompts</li><li>Multi-model fallback</li></div></div>
        <div class="card"><div class="card-header" style="background: #ef4444;">ENGAGEMENT</div><div class="card-body"><li>Forum &amp; fan wall</li><li>Polls &amp; announcements</li><li>Gallery &amp; highlight reels</li><li>Gear marketplace</li><li>Volunteer management</li><li>WhatsApp widget</li></div></div>
    </div>
</div>

<!-- SLIDE 8: Tech Stack -->
<div class="slide" id="s8" role="region" aria-label="Slide 8: Technical Architecture">
    <div class="accent" style="background: #2563eb;"></div>
    <h2 style="margin-bottom: 16px;">Technical Architecture</h2>
    <div class="grid-2" style="margin-bottom: 20px;">
        <div class="card"><div class="card-header" style="background: #2563eb;">Backend</div><div class="card-body"><li>PHP 7.4+</li><li>MySQL / MariaDB</li><li>mysqli extension</li><li>Session management</li></div></div>
        <div class="card"><div class="card-header" style="background: #10b981;">Frontend</div><div class="card-body"><li>Bootstrap 5</li><li>Vanilla JavaScript</li><li>Google Fonts (Inter)</li><li>Font Awesome 6</li></div></div>
        <div class="card"><div class="card-header" style="background: #f97316;">Integrations</div><div class="card-body"><li>Paystack payments</li><li>M-Pesa Daraja</li><li>Brevo email</li><li>Cloudflare Turnstile</li></div></div>
        <div class="card"><div class="card-header" style="background: #7c3aed;">AI / ML</div><div class="card-body"><li>Google Gemini API</li><li>OpenRouter (200+ models)</li><li>Churn prediction</li><li>Smart scheduling</li></div></div>
    </div>
    <h3 style="margin-bottom: 12px;">Project Structure</h3>
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 6px 32px;">
        <div style="display: flex; gap: 12px;"><span style="color: #2563eb; font-weight: 700; min-width: 100px;">admin/</span><span style="color: #94a3b8; font-size: 13px;">35+ admin pages — dashboard, CRUD, AI tools</span></div>
        <div style="display: flex; gap: 12px;"><span style="color: #2563eb; font-weight: 700; min-width: 100px;">public/</span><span style="color: #94a3b8; font-size: 13px;">40+ member pages — booking, profile, leagues</span></div>
        <div style="display: flex; gap: 12px;"><span style="color: #2563eb; font-weight: 700; min-width: 100px;">includes/</span><span style="color: #94a3b8; font-size: 13px;">30+ shared modules — auth, email, payments, AI</span></div>
        <div style="display: flex; gap: 12px;"><span style="color: #2563eb; font-weight: 700; min-width: 100px;">config/</span><span style="color: #94a3b8; font-size: 13px;">Database &amp; API configuration loaders</span></div>
        <div style="display: flex; gap: 12px;"><span style="color: #2563eb; font-weight: 700; min-width: 100px;">callbacks/</span><span style="color: #94a3b8; font-size: 13px;">Payment callback endpoints (Paystack, M-Pesa)</span></div>
        <div style="display: flex; gap: 12px;"><span style="color: #2563eb; font-weight: 700; min-width: 100px;">migrations/</span><span style="color: #94a3b8; font-size: 13px;">49 SQL migration files — full schema</span></div>
        <div style="display: flex; gap: 12px;"><span style="color: #2563eb; font-weight: 700; min-width: 100px;">scripts/</span><span style="color: #94a3b8; font-size: 13px;">Utility scripts — roster generation, testing</span></div>
        <div style="display: flex; gap: 12px;"><span style="color: #2563eb; font-weight: 700; min-width: 100px;">dev/</span><span style="color: #94a3b8; font-size: 13px;">Development &amp; testing utilities</span></div>
    </div>
</div>

<!-- SLIDE 9: Thank You -->
<div class="slide" id="s9" role="region" aria-label="Slide 9: Thank You">
    <div class="accent" style="background: #2563eb;"></div>
    <div class="center">
        <div style="background: #2563eb; padding: 10px 24px; border-radius: 999px; font-weight: 700; font-size: 14px; letter-spacing: 2px; margin-bottom: 32px;">APEX SPORTS CLUB</div>
        <h1 style="font-size: 4rem; margin-bottom: 16px;">Thank You</h1>
        <p class="subtitle" style="font-size: 1.2rem;">Apex Sports Club — Built with passion for the game</p>
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-top: 48px; max-width: 800px;">
            <div style="text-align: center; color: #2563eb; font-weight: 700;">Real-time Booking</div>
            <div style="text-align: center; color: #2563eb; font-weight: 700;">AI-Powered Insights</div>
            <div style="text-align: center; color: #2563eb; font-weight: 700;">Secure Payments</div>
            <div style="text-align: center; color: #2563eb; font-weight: 700;">League Management</div>
            <div style="text-align: center; color: #2563eb; font-weight: 700;">Mobile Responsive</div>
            <div style="text-align: center; color: #2563eb; font-weight: 700;">Enterprise Security</div>
        </div>
        <p style="color: #64748b; font-size: 13px; margin-top: 48px;">PHP &middot; MySQL &middot; Bootstrap 5 &middot; Cloudflare &middot; Paystack &middot; Brevo &middot; Gemini AI</p>
    </div>
</div>

<!-- Navigation -->
<div class="dots" id="dots"></div>
<div class="nav" role="navigation" aria-label="Slide navigation">
    <button onclick="prev()" aria-label="Previous slide">← Prev</button>
    <span class="counter" id="counter" aria-live="polite">1 / 9</span>
    <button onclick="next()" aria-label="Next slide">Next →</button>
</div>

<script>
const slides = document.querySelectorAll('.slide');
const total = slides.length;
let current = 0;

function show(n) {{
    slides[current].classList.remove('active');
    current = (n + total) % total;
    slides[current].classList.add('active');
    document.getElementById('counter').textContent = `${{current + 1}} / ${{total}}`;
    document.getElementById('sr-announcer').textContent = `Slide ${{current + 1}} of ${{total}}: ${{slides[current].getAttribute('aria-label') || ''}}`; 
    document.querySelectorAll('.dot').forEach((d, i) => d.classList.toggle('active', i === current));
}}

function next() {{ show(current + 1); }}
function prev() {{ show(current - 1); }}

// Create dots
const dotsEl = document.getElementById('dots');
for (let i = 0; i < total; i++) {{
    const d = document.createElement('div');
    d.className = 'dot' + (i === 0 ? ' active' : '');
    d.onclick = () => show(i);
    dotsEl.appendChild(d);
}}

// Keyboard navigation
document.addEventListener('keydown', e => {{
    if (e.key === 'ArrowRight' || e.key === ' ') next();
    if (e.key === 'ArrowLeft') prev();
}});

// Touch/swipe support
let touchStartX = 0;
document.addEventListener('touchstart', e => {{ touchStartX = e.touches[0].clientX; }});
document.addEventListener('touchend', e => {{
    const diff = touchStartX - e.changedTouches[0].clientX;
    if (Math.abs(diff) > 50) {{ diff > 0 ? next() : prev(); }}
}});
</script>
</body>
</html>"""

output_path = os.path.join(SCRIPT_DIR, '..', 'Apex_Sports_Club_Presentation.html')
with open(output_path, 'w', encoding='utf-8') as f:
    f.write(html)

print(f"\nHTML presentation saved to: {os.path.abspath(output_path)}")
print(f"Slides: 9")
print(f"Open in browser: file:///{os.path.abspath(output_path)}")
