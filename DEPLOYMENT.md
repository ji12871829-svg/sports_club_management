# Apex Sports Club — Deployment Guide

This document explains how to enable the staged CI/CD pipeline added in
`.github/workflows/ci.yml` (the `deploy` and `load-test` jobs) and push
the app to a live host for the first time.

## How the pipeline works

```
push to main ──► lint ──► security-scan ──► test ──► migration-dry-run ──► smoke-test
                                                      │
                                                      ▼
                                      deploy (rsync + migrate)     ⚠️ skips unless DEPLOY_HOST is set
                                                      │
                                                      ▼
                                      load-test (ApacheBench p95 < 2 s @ 50 concurrent)
                                                      ▲
                                                      ⚠️ skips unless the deploy ran
```

Both jobs are **feature-flagged**: they are no-ops (skipped) until you
configure the repository settings below. Nothing deploys by accident.

- The `deploy` job rsyncs the repo to the host, runs any pending
  migrations, and optionally hits `health.php` afterwards.
- The `load-test` job runs `bin/load_test.sh` against the deployed
  `DEPLOY_BASE_URL` and fails the pipeline if p95 ≥ 2 s at 50 concurrent
  users.
- Secrets are **never** transferred: `.env` / `.env.*` are excluded from
  the rsync, so the server keeps its own credentials.

---

## 1. Target server prerequisites

The host needs:

- PHP 8.2+ CLI (`php -v`) with `mysqli`, `mbstring` extensions.
- MySQL/MariaDB with a database already created for the app.
- A web root writable by the SSH user (rsync `--delete` target).
- OpenSSH server with key auth enabled.

There are **no composer runtime dependencies** (`vendor/` is excluded from
the rsync), so no composer install is needed on the host.

## 2. Create a deploy key

A dedicated deploy keypair has already been generated for this project and
sits in the git-ignored `output/` folder (never committed):

```bash
# private key (do NOT share, do NOT commit)
output/deploy/apex_deploy
# public key (safe to share)
output/deploy/apex_deploy.pub
```

(To regenerate from scratch on any machine: `ssh-keygen -t ed25519 -C
"apex-deploy" -f output/deploy/apex_deploy -N ""`)

Install the public half on the server:

```bash
ssh-copy-id -i output/deploy/apex_deploy.pub deploy@your-host.example
# verify:
ssh -i output/deploy/apex_deploy deploy@your-host.example 'echo ok'
```

## 3. GitHub repository settings

Open **Settings → Secrets and variables → Actions** for the repo and add:

**Repository variables** (readable in job `if` conditions):

| Name             | Value                                            |
|------------------|--------------------------------------------------|
| `DEPLOY_HOST`    | the hostname/IP, e.g. `your-host.example`        |
| `DEPLOY_USER`    | the SSH user, e.g. `deploy`                      |
| `DEPLOY_PATH`    | absolute web root on the host, e.g. `/var/www/apex` |
| `DEPLOY_PORT`    | `22` (change only if non-standard)               |
| `DEPLOY_BASE_URL`| public URL of the app, e.g. `https://apex.example.com/public` |

> **Why variables and not secrets for `DEPLOY_HOST`?** GitHub does not
> expose the `secrets` context inside a job-level `if` condition, so the
> gate is checked against the *variable*. Values that must stay private
> (`DEPLOY_SSH_KEY`) are read via `env:` inside the steps instead.

**Repository secrets** (read inside steps via `env:`):

| Name             | Value                                             |
|------------------|---------------------------------------------------|
| `DEPLOY_SSH_KEY` | the **private** key — paste the full contents of `output/deploy/apex_deploy` (`cat output/deploy/apex_deploy`) |

That's it. The next `push` to `main` runs the deploy job; branch
protection can later mark `deploy` as a required check.

## 4. Prepare the target server

Create the app directory and a production `.env` on the host **before**
the first deploy (the rsync never touches it):

```bash
sudo mkdir -p /var/www/apex && sudo chown deploy: /var/www/apex
cd /var/www/apex
cp /path/to/.env.example .env
$EDITOR .env   # set real DB creds + payment keys + APP_ENV=production
```

> `APP_ENV=production` on the host enables the `.env.production` overlay
> convention (`config/api_config.php`): create `/var/www/apex/.env.production`
> for anything that should win over `.env`.
>
> ⚠️ The local `.env` uses a placeholder ngrok domain for the payment
> callbacks (fine for the dashboard's "Secure" badge). Before processing
> live payments, set `MPESA_CALLBACK_URL` / `PAYSTACK_CALLBACK_URL` to the
> real tunnel/production https URLs Safaricom & Paystack can reach.

## 5. First deploy (runbook)

1. **Dry run** (safe, shows what would change):
   ```bash
   rsync -azn --delete -e "ssh -i ~/.ssh/apex_deploy -p 22" \
     --exclude '.git/' --exclude '.env' --exclude '.env.*' \
     --exclude 'tests/' --exclude 'output/' --exclude 'backups/' \
     --exclude '*.log' --exclude 'vendor/' \
     ./ deploy@your-host.example:/var/www/apex/
   ```
2. **Push to main** — the `deploy` job rsyncs, runs
   `php scripts/migrate.php`, then the health check.
3. **Watch the run** in Actions → the `deploy` and `load-test` jobs.
4. **Verify manually**: open `https://apex.example.com/public/health.php`
   and confirm `"status": "ok"`; log into the admin panel.

### Health endpoint access token

If `HEALTH_TOKEN` is set in `.env`, `health.php` returns `401` unless the
request carries the token via `Authorization: Bearer <token>`, the
`X-Health-Token` header, or `?token=`. Configure your load balancer / uptime
monitor to send the header (never put secrets in URLs). The CI deploy job's
post-deploy health check sends the header automatically when `DEPLOY_BASE_URL`
is set; leave `HEALTH_TOKEN` empty to keep the endpoint open for simple
setups. This prevents the endpoint's metadata (table lists, disk free space,
backup filenames, Redis state) from being readable by anonymous clients.

### Rollback

The rsync `--delete` mirrors the repo, so reverting is a normal `git
revert`/`git push` away. For the database, restore a backup taken with
`bin/backup.sh` on the host (see [PRR.md](PRR.md) — restore is guarded by
`bin/restore.sh --yes`).

## 6. Local sanity before pushing

Always run the same gate locally first:

```bash
bash sync_check.sh          # keep the two checkouts in sync
bin/load_test.sh            # p95 < 2 s @ 50 concurrent against local Apache
/c/xampp/php/php.exe vendor/bin/phpunit --configuration=phpunit.xml
```

---

## 7. Security headers on Nginx

The root `.htaccess` ships the security headers (CSP, HSTS, nosniff, XFO,
Referrer-Policy, Permissions-Policy), but **`.htaccess` only applies under
Apache**. If the production target runs Nginx, set the same headers in the
server block so the response headers match between environments:

```nginx
add_header X-Content-Type-Options "nosniff" always;
add_header X-Frame-Options "SAMEORIGIN" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
add_header Permissions-Policy "geolocation=(), microphone=(), camera=()" always;
add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; font-src 'self' data: https://fonts.gstatic.com; img-src 'self' data: blob: https://quickchart.io https://*.tile.openstreetmap.org https://www.openstreetmap.org; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'; frame-src 'self'" always;
```

Keep the CSP values identical to `.htaccess` — any drift creates a confusing
"works locally, breaks in prod" class of bug.

---

*See [AUDIT_REPORT.md](AUDIT_REPORT.md) §9 for the blocker this closes and
[PRR.md](PRR.md) for the sign-off criteria.*
