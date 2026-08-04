# Database Migrations

This folder is the canonical place for database changes.

## Run migrations

From the project root:

```powershell
php scripts/migrate.php
```

If `php` is not recognized on Windows, use XAMPP's PHP executable:

```powershell
C:\xampp\php\php.exe scripts/migrate.php
```

Check status:

```powershell
php scripts/migrate.php --status
```

If your local database was already created by manually importing the old SQL files, create migration history without replaying the SQL:

```powershell
php scripts/migrate.php --baseline
```

## Naming rule

Migration files must be numbered and named like this:

```text
001_create_core_schema.sql
002_create_damage_reports.sql
003_create_leagues_and_teams.sql
011_competition_and_ops_features.sql
012_admin_two_factor.sql
013_password_reset_tokens.sql
015_premier_league_football_teams.sql
```

`015` renames Football Premier League teams to English Premier League clubs (existing databases).

`011` adds: match events (goals/cards), MOTM votes, coach availability, facility maintenance, promo codes, and equipment `reorder_level`.

`012` adds: admin TOTP two-factor authentication and recovery codes.

`013` adds: member password reset tokens for forgot-password email flow.

Future changes get new numbered files. Do not edit files that have already been applied.

Examples:

```text
007_add_coach_ratings.sql
008_add_loyalty_points.sql
```

The runner stores applied versions and file checksums in `schema_migrations`. If an applied file is renamed, deleted, or edited, the runner stops and asks you to create a new numbered migration instead.

