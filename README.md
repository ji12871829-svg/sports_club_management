# Apex Sports Club

A vanilla PHP and MySQL web application for managing sports members, bookings, league teams, fixtures, standings, payments, and admin operations.

The project is designed for a local XAMPP-style setup, but it can also be deployed to any PHP/MySQL hosting environment.

## Features

- Member registration, login, profile management, and session handling
- Public pages for sports, coaches, facilities, bookings, fixtures, and team registration
- Admin dashboard for managing members, sports, coaches, facilities, equipment, bookings, payments, leagues, teams, fixtures, and standings
- League team registration with seeded teams for multiple sports
- Fixtures and standings management with automatic standings recalculation after completed results
- Paystack checkout support for payments
- Brevo email integration for receipts and booking emails
- Optional API-SPORTS integration for external sports data
- Environment-based configuration so API keys are not committed to GitHub

## Tech Stack

- PHP 7.4+
- MySQL or MariaDB
- Bootstrap 5
- Vanilla JavaScript
- Apache or another PHP-capable web server

## Project Structure

```text
sports_club_management/
├── admin/                         Admin dashboard pages
├── config/                        Database and environment config loaders
├── includes/                      Shared headers, email, Paystack, and CAPTCHA helpers
├── public/                        Public member-facing pages and assets
├── migrations/                    Numbered SQL migrations (canonical schema, 001–056)
├── scripts/migrate.php            Migration runner (apply / --status / --baseline)
├── bin/setup.sh                   One-command local setup
├── .env.example                   Safe environment variable template
└── DOCUMENTATION.md               Full project documentation
```

## Requirements

- PHP 7.4 or newer
- MySQL or MariaDB
- `mysqli` PHP extension enabled
- XAMPP, WAMP, MAMP, or a PHP hosting environment
- Paystack, Brevo, and API-SPORTS accounts only if you want to use those integrations

## Local Setup

1. Place the project folder inside your web server document root.

   For XAMPP on Windows:

   ```text
   C:\xampp\htdocs\sports_club_management
   ```

2. Start Apache and MySQL.

3. Create a MySQL database named `sports_club_db` (the migration runner creates it automatically if missing).

4. Build the schema. The old `database.sql` files were removed — the canonical schema is the numbered migrations in `migrations/`, applied with the runner:

   ```powershell
   C:\xampp\php\php.exe scripts/migrate.php
   ```

   Or run the full one-command setup (database, migrations, test DB, PHPUnit):

   ```bash
   bash bin/setup.sh
   ```

5. Copy the environment template:

   ```powershell
   copy .env.example .env
   ```

6. Open `.env` and add your local database settings and API keys.

7. Open the app:

   ```text
   http://localhost/sports_club_management/public/index.php
   ```

8. Open the admin panel:

   ```text
   http://localhost/sports_club_management/admin/admin_login.php
   ```

## Environment Variables

Real secrets belong in `.env`. Commit `.env.example`, but never commit `.env`.

```dotenv
DB_HOST=localhost
DB_USER=root
DB_PASSWORD=
DB_NAME=sports_club_db

BREVO_API_KEY=
CLUB_EMAIL_FROM=your_email@example.com
CLUB_EMAIL_NAME=Apex Sports Club

RECAPTCHA_SITE_KEY=
RECAPTCHA_SECRET_KEY=

API_SPORTS_KEY=

PAYSTACK_SECRET_KEY=
PAYSTACK_PUBLIC_KEY=
PAYSTACK_CALLBACK_URL=http://localhost/sports_club_management/paystack_callback.php

CLUB_LAT=-1.286389
CLUB_LNG=36.817223
CLUB_CITY=Nairobi
```

## Portable URL Handling

The app no longer depends on the project folder being named `sports_club_management`. Two constants in `config/api_config.php` are auto-detected from the request:

- `BASE_URL` — the app's mount point (e.g. `/sports_club_management`, `/Apex Sports Club`, or empty at the web root). Derived automatically from `SCRIPT_NAME`; not configurable. Used by `includes/header.php` and `includes/footer.php` for all nav links, the stylesheet, and `script.js`, so navigation and assets work from any folder.
- `SITE_URL` — a full `scheme://host/BASE_URL` URL used by `includes/send_email.php` for absolute links in outgoing emails (email clients cannot resolve relative paths). Override in `.env` if auto-detection is wrong — e.g. behind a reverse proxy, or when emails are sent from CLI/cron where there is no `HTTP_HOST`:
  ```dotenv
  SITE_URL=https://example.com
  ```

Note: the built-in defaults for `PAYSTACK_CALLBACK_URL` and `MPESA_CALLBACK_URL` still assume a `sports_club_management` folder — set both explicitly in `.env` for your actual domain/path.

**M-Pesa callback URL must be HTTPS.** Safaricom's Daraja API rejects plain-HTTP callback URLs with `400.002.02 Bad Request - Invalid CallBackURL`. `localhost` is also unreachable from Safaricom's servers. For local/sandbox testing, expose the app through an HTTPS tunnel (ngrok, Cloudflare Tunnel, etc.) and point `MPESA_CALLBACK_URL` at `https://<your-tunnel>/callbacks/mpesa_callback.php`. The app fails fast with a clear message if the URL does not start with `https://` — see `mpesa_callback_url_error()` in `includes/mpesa.php` (covered by `tests/MpesaCallbackUrlTest.php`). The `/public/health.php` `payment_config` check reports this misconfiguration so it is caught before users hit it.

## Default Admin Login

The seed database creates a default admin account:

```text
Email: admin@sportsclub.com
Password: admin123
```

Change this password before using the system beyond local testing.

## Security Notes

- Keep `.env` private and out of GitHub.
- Rotate any API keys that were ever committed or shared.
- Use HTTPS in production, especially for payment callbacks and login pages.
- Set production Paystack callback URLs to your live domain.
- Replace default admin credentials before deployment.

## Deployment Notes

GitHub stores the source code, but it does not host PHP/MySQL apps directly. To make the app live, deploy it to PHP hosting such as cPanel hosting, a VPS, or another PHP-capable platform.

On the server:

1. Upload or clone the repository.
2. Create the MySQL database.
3. Run the migrations to build the schema: `php scripts/migrate.php`.
4. Create a server-side `.env` file with production values.
5. Point the web server to this project folder.
6. Confirm that `public/index.php` and `admin/admin_login.php` load correctly.

## Documentation

See [DOCUMENTATION.md](DOCUMENTATION.md) for more detailed setup notes, file descriptions, payment configuration, and troubleshooting.
