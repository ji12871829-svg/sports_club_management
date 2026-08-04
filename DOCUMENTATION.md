# Apex Sports Club Documentation

## Overview

This project is Apex Sports Club, built with vanilla PHP, MySQL, and Bootstrap 5.
It supports both public member-facing pages and an admin dashboard for club staff.

The system includes:
- Member registration, login, profile update, and booking pages
- League, team, and member team registration management
- Club admin dashboard for managing members, sports, coaches, facilities, equipment, bookings, and payments
- Payments support with Paystack checkout and manual payment records
- Email receipt sending via Brevo (Sendinblue)
- reCAPTCHA protection and optional sports data integration

## Requirements

- PHP 7.4 or higher
- MySQL / MariaDB
- Web server such as Apache or Nginx
- `mysqli` extension enabled
- Composer is not required because this is a plain PHP project

## Setup and Installation

1. Place the project folder inside your web server document root.
   - For XAMPP on Windows: `C:\xampp\htdocs\sports_club_management`

2. Start Apache and MySQL.

3. Build the database. The old `database.sql`, `league_team_schema.sql`, and `fixtures_standings_schema.sql` files were removed — the canonical schema is now the numbered migrations in `migrations/` (001 → 056), applied with the migration runner:

   ```powershell
   C:\xampp\php\php.exe scripts/migrate.php
   ```

   - The runner creates the `sports_club_db` database if it does not exist.
   - Add `--status` to list applied/pending migrations.
   - If your database was created by the old SQL files, run `php scripts/migrate.php --baseline` to mark migrations as applied without replaying them.

   Or run the full one-command setup, which does everything (`.env`, uploads dir, database, migrations, test DB, PHPUnit):

   ```bash
   bash bin/setup.sh
   ```

4. Copy `.env.example` to `.env` and configure your local values.
   - `.env` is ignored by Git and should never be committed.
   - Default XAMPP database credentials are already shown in `.env.example`.
   - Change the database values if your MySQL credentials differ.

5. Set API keys in `.env`.
   - Use real keys only in development or production, not placeholders.
   - `PAYSTACK_SECRET_KEY` and `PAYSTACK_PUBLIC_KEY` for Paystack checkout
   - `PAYSTACK_CALLBACK_URL` should point to `paystack_callback.php`
   - `BREVO_API_KEY` for email sending
   - `RECAPTCHA_SITE_KEY` and `RECAPTCHA_SECRET_KEY` for reCAPTCHA protection

6. Open the application in your browser.
   - Public site: `http://localhost/sports_club_management/public/index.php`
   - Admin site: `http://localhost/sports_club_management/admin/admin_login.php`

## Public Member Pages

The public section allows club members to:
- Register: `public/register.php`
- Log in: `public/login.php`
- Log out: `public/logout.php`
- View and update profile: `public/update_profile.php`
- Browse sports: `public/view_sports.php`
- Browse coaches: `public/view_coaches.php`
- Browse facilities: `public/view_facilities.php`
- Book a facility or session: `public/booking.php`
- View bookings: `public/view_bookings.php`
- Join league teams: `public/team_registration.php`

### Common public files

- `public/index.php` – Homepage and member entry point
- `public/dashboard.php` – Member dashboard after login
- `public/get_sport_data.php` – AJAX helper for sport-related data
- `public/css/` – Frontend styling
- `public/js/` – Client-side scripts

## Admin Section

The admin system is under `admin/` and includes:
- `admin/manage_leagues.php` - manage leagues, teams, and team registrations
- `admin/admin_login.php` – administrator login
- `admin/admin_dashboard.php` – control panel overview
- `admin/manage_members.php` – manage club members
- `admin/manage_sports.php` – manage offered sports
- `admin/manage_coaches.php` – manage coaches
- `admin/manage_facilities.php` – manage facilities
- `admin/manage_equipment.php` – manage equipment inventory
- `admin/manage_bookings.php` – approve, reject, and complete bookings
- `admin/manage_payments.php` – record payments and start Paystack checkout
- `admin/admin_logout.php` – admin logout

### Shared admin includes

- `includes/admin_header.php` – admin page header and navigation
- `includes/footer.php` – shared footer for pages

## Payment Integrations

### Paystack

The system supports Paystack as a payment option in `admin/manage_payments.php`.

Key files:
- `includes/paystack.php` – helper functions to initialize and verify Paystack transactions
- `paystack_callback.php` – callback endpoint for Paystack transaction verification and payment recording
- `config/api_config.php` – loads Paystack configuration values from `.env`

Required `.env` values:
```dotenv
PAYSTACK_SECRET_KEY=
PAYSTACK_PUBLIC_KEY=
PAYSTACK_CALLBACK_URL=http://localhost/sports_club_management/paystack_callback.php
```

## Email and API Services

### Brevo (Sendinblue)

Emails are sent through `includes/send_email.php` using the Brevo API.

Required `.env` values:
```dotenv
BREVO_API_KEY=
CLUB_EMAIL_FROM=your-email@example.com
CLUB_EMAIL_NAME=Apex Sports Club
```

### Google reCAPTCHA v2

The project is prepared to use reCAPTCHA, with keys configured in `.env`.

### API-SPORTS

The `.env.example` file includes an `API_SPORTS_KEY` placeholder. This can be used for external sports data integration.

## Database Schema

The schema lives as numbered, idempotent SQL migrations in `migrations/` and is applied with `php scripts/migrate.php` (or `bash bin/setup.sh`). Key tables created across the 56 migrations include:

- `members`, `sports`, `coaches`, `facilities`, `equipment`
- `bookings`, `payments`
- `leagues`, `teams`, `team_memberships`
- `fixtures`, `standings`
- plus AI, engagement, ops, and compliance features (AI review log, notifications, polls, sponsors, damage reports, membership plans, privacy consent, and more)

Migration `001` seeds the base sample data (members, sports, coaches, facilities, equipment, bookings, payments, and the default admin account); later migrations seed league teams and player rosters.

## Configuration Files

### `config/db_connect.php`

Creates the MySQL connection used by all pages. It reads database settings from `.env` through `config/api_config.php`.

### `config/api_config.php`

Central loader for API keys and service settings. Real values come from `.env`; safe placeholders live in `.env.example`.

## Portable URL Handling

The app works from any folder name; it no longer depends on being deployed as `sports_club_management`. `config/api_config.php` auto-detects two constants from the current request:

- `BASE_URL` — the app's mount point (e.g. `/sports_club_management`, `/Apex Sports Club`, or empty when the project is at the web root). Derived automatically from `SCRIPT_NAME`; it is not configurable. `includes/header.php` and `includes/footer.php` use it for all nav links, the stylesheet, and `script.js`, so navigation and assets resolve correctly regardless of where the project lives.
- `SITE_URL` — full `scheme://host/BASE_URL` used by `includes/send_email.php` for absolute links in outgoing emails (welcome, booking confirmation, booking status, payment receipt). Email clients cannot resolve relative URLs, so `SITE_URL` is always a complete URL.

`SITE_URL` can be overridden in `.env` when auto-detection is wrong — e.g. behind a reverse proxy, or when emails are sent from CLI/cron jobs where there is no `HTTP_HOST` to detect:

```dotenv
SITE_URL=https://example.com
```

## Testing and Utilities

- `scripts/migrate.php` – apply / status / baseline database migrations
- `bin/setup.sh` – one-command setup (env, database, migrations, test DB, PHPUnit)
- `php phpunit.phar --configuration=phpunit.xml` – run the PHPUnit suite (payment idempotency, security, M-Pesa callback URL/cycle, Paystack webhook signature)
- `cron/*` – scheduled jobs (AI booking review, database backup, membership renewals, email campaigns, reminders)
- `public/health.php` and `admin/system_health.php` – health checks for CI and monitoring

## Cron Jobs and Alerting

The root-level `cron_*.php` scripts send alert emails on a schedule (daily, via Windows Task Scheduler or cron):

| Script | What it does | Required env var |
|---|---|---|
| `cron_payment_health.php` | Validates payment config — M-Pesa callback URL must be `https://` and reachable (Safaricom rejects `http://` with `400.002.02 Invalid CallBackURL`), no placeholder callback domains, provider keys present. Emails the admin with the problem list. Throttled to one email per 24h per problem. | `ASC_PAYMENT_ALERT_EMAIL_TO` |
| `cron_profiler_alert.php` | Emails a daily digest of slow pages (from the request profiler, `page_timings`, >100 ms in the last 7 days). | `ASC_PROFILER_EMAIL_TO` |
| `cron_security_alert.php` | Daily digest of `security_events` (rate-limit hits, CSRF rejections, callback rejections, lockouts) plus real-time email on critical events / lockouts. | `ASC_SECURITY_EMAIL_TO` |

Register the profiler/payment crons as Windows scheduled tasks with `schedule_alert_cron.php`:

```bash
php schedule_alert_cron.php                    # profiler digest (default), daily 06:00
php schedule_alert_cron.php --cron payment     # payment health check, daily 06:00
php schedule_alert_cron.php --time 08:30       # different time
php schedule_alert_cron.php --dry-run          # preview the schtasks command
php schedule_alert_cron.php --remove           # remove the selected task
```

### Duplicate-membership detection

`admin/manage_members.php` flags members holding **two or more overlapping Active memberships for the same plan** (typically a double-activated payment or manual data-entry error) and shows a warning banner with each member, plan, and overlap count. Sequential renewals (one period ending before the next starts) are **not** flagged. The admin dashboard's **Payment Health** card shows the current duplicate count and payment-config status at a glance.

## Security Notes

- Store real API secrets only on the server.
- Do not commit real secret keys to version control.
- Commit `.env.example`, but never commit `.env`.
- Keep the Paystack secret key private.
- Use HTTPS in production, especially for payment callback URLs.

## Deployment Notes

1. Set production DB credentials in the server's `.env`.
2. Set live API keys in the server's `.env`.
3. Update callback URLs to your real domain.
4. Configure your web server to point to the `sports_club_management` directory.

## Troubleshooting

- If Paystack redirects fail, make sure no HTML output is sent before `header('Location: ...')`.
- If email fails, verify your Brevo API key and check response status codes.
- If login fails, verify session support and correct database connection.

## Recommended Improvements

- Add member-side payment checkout and booking confirmation
- Add role-based access control for admin and members
- Add logs for payment and booking events
- Rotate any API keys that were previously committed or shared
- Add stronger validation and CSRF protection
