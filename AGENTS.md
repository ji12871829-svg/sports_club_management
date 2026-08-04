# AGENTS.md — Apex Sports Club

This project is a vanilla PHP + MySQL app (XAMPP stack). It lives in **two places** that must stay in sync:

- **Main checkout** (served by Apache): `C:\xampp\htdocs\Apex Sports Club`
- **Worktree** (used by this session): `C:\xampp\htdocs\Apex Sports Club\.freebuff\worktrees\<id>`

## Session start (mandatory)

At the **start of every session**, before making or verifying changes:

1. Run the sync checker to list files that diverged between the worktree and the main checkout:
   ```bash
   bash sync_check.sh
   ```
   (The script lives at the project root in both checkouts. It prints a diff of worktree ↔ main files.)
2. If the sync check reports diverged files, **reconcile them** — decide which version is correct and copy it to the other checkout (`cp`) so the two stay identical. Do not silently overwrite: check the diff first.
3. Note the **`.env`** file: it is host-local and usually exists only in the main checkout. Copy it into the worktree if missing (`cp "/c/xampp/htdocs/Apex Sports Club/.env" .env`), but never commit or print its values.

## Conventions

- **PHP files:** lint before finishing — `C:\xampp\php\php.exe -l <file>`
- **Database:** single shared `sports_club_db` on `localhost` (root, no password). Schema changes go in `migrations/NNN_*.sql`; apply them with the MySQL CLI or `php -r`.
- **CLI tests:** `C:\xampp\php\php.exe tests/churn_analytics_test.php` (churn analytics; deterministic, no API key needed).
- **API keys:** resolved by `asc_ai_key()` in `includes/gemini_client.php` — settings table first, `.env` fallback. Manage keys from the admin panel at `admin/gemini_hub.php` (saved to the `settings` table) or edit `.env`.
- **Error pages:** `includes/error_handler.php` is intentionally safe to call after output has started (uses `headers_sent()` + recursion guard) — keep it that way.
- When finished with a batch of edits, **copy changed files to the main checkout** so Apache-served localhost matches the worktree, then re-run `bash sync_check.sh` to confirm zero divergence.
