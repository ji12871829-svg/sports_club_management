# Sports Club Management System Documentation

## Overview

This project is a Sports Club Management System built with vanilla PHP, MySQL, and Bootstrap 5.
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

1. Place the `sports_club_management` folder inside your web server document root.
   - For XAMPP on Windows: `C:\xampp\htdocs\sports_club_management`

2. Start Apache and MySQL.

3. Create the database using `database.sql`.
   - Open `http://localhost/phpmyadmin`
   - Select `Import`
   - Choose `sports_club_management/database.sql`
   - Click `Go`

4. Add league and team support using `league_team_schema.sql`.
   - Import `sports_club_management/league_team_schema.sql` after `database.sql`.
   - This creates `leagues`, `teams`, and `team_memberships`.
   - It seeds football with 15 teams, rugby with 14 teams, and adds teams for hockey, volleyball, chess, horse riding, and badminton.

5. Copy `.env.example` to `.env` and configure your local values.
   - `.env` is ignored by Git and should never be committed.
   - Default XAMPP database credentials are already shown in `.env.example`.
   - Change the database values if your MySQL credentials differ.

6. Set API keys in `.env`.
   - Use real keys only in development or production, not placeholders.
   - `PAYSTACK_SECRET_KEY` and `PAYSTACK_PUBLIC_KEY` for Paystack checkout
   - `PAYSTACK_CALLBACK_URL` should point to `paystack_callback.php`
   - `BREVO_API_KEY` for email sending
   - `RECAPTCHA_SITE_KEY` and `RECAPTCHA_SECRET_KEY` for reCAPTCHA protection

7. Open the application in your browser.
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
CLUB_EMAIL_NAME=Sports Club Management
```

### Google reCAPTCHA v2

The project is prepared to use reCAPTCHA, with keys configured in `.env`.

### API-SPORTS

The `.env.example` file includes an `API_SPORTS_KEY` placeholder. This can be used for external sports data integration.

## Database Schema

The `database.sql` file contains the schema and sample data for:
- `members`
- `sports`
- `coaches`
- `facilities`
- `equipment`
- `bookings`
- `payments`

The `league_team_schema.sql` migration adds:
- `leagues`
- `teams`
- `team_memberships`

Use `database.sql` first, then import `league_team_schema.sql` to enable league team registration.

## Configuration Files

### `config/db_connect.php`

Creates the MySQL connection used by all pages. It reads database settings from `.env` through `config/api_config.php`.

### `config/api_config.php`

Central loader for API keys and service settings. Real values come from `.env`; safe placeholders live in `.env.example`.

## Testing and Utilities

The project includes helper scripts:
- `test_apisports.php` – verify API-Sports configuration and response
- `test_email.php` – verify Brevo email sending

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
