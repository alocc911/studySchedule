# CodeIgniter API

A CodeIgniter 4 backend initialized from the official `codeigniter4/appstarter` v4.7.6 source (MIT license; see `LICENSE`). Composer retrieves the framework's runtime libraries under `vendor/`. PHP 8.2+, `intl`, `mbstring`, and `mysqli` are required.

## Local development

From the repository root run `docker compose up -d db`, then inside `backend/`:

```bash
composer install
cp .env.example .env
php spark serve --host 127.0.0.1 --port 8080
```

- `GET /health` confirms the API starts.
- `GET /ready` confirms the MySQL database connection.

The framework is not installed on GitHub Pages; it will be hosted in the professor's GoDaddy account later. See `../deploy/README.md`.

## Security

Use `CI_ENVIRONMENT=production` in deployment. Keep `.env`, `vendor/`, `app/`, and `writable/` outside the public web root. Route only the front controller through a public `/api/` path. Do not open additional routes without server-side access controls and CSRF/CORS review.

Phase 2 established the internal MySQL booking engine. Phase 3 added authenticated professor administration routes, and Phase 4 added a study-isolated participant booking API. See `../docs/phase-2-booking-engine.md` and `../docs/phase-4-participant-booking.md`.

## Phase 3 professor workspace

The server now uses CodeIgniter Shield for password/session login. Public registration and magic links are disabled; only accounts assigned the `superadmin` group can use `/admin/*` API routes. CSRF is required on login and administration mutations. The browser UI is in `frontend/admin/` and must be hosted **on the same origin** as the PHP API for live use.

Create the first professor account through the interactive Shield CLI (`php spark shield:user --help`, then `php spark shield:user create` and `php spark shield:user addgroup`). Assign the new account to `superadmin`; **never commit credentials**. Run `php spark migrate --all` after installing PHP dependencies to create Shield's authentication tables.

For deployment readiness, enable MFA and configure outgoing email before any real participant data is entered. See `../docs/phase-3-professor-workspace.md`.

## Phase 4 participant API

`GET /participant/invitation` and `POST /participant/book`, `/participant/cancel`, `/participant/reschedule` accept a 256-bit invitation bearer token in the `Authorization: Bearer <token>` **header**, never a query parameter. The frontend receives the token in the URL `#token=` fragment, strips it from browser history and retains it in the current tab's sessionStorage for refreshes. It does not require a participant login or create a participant session cookie. Route-level throttling, high-entropy tokens, per-study authorization, MySQL transactions, `no-store` response headers and fixed cutoff rules protect bookings. The professor **manually emails** invitations. Confirmation and cancellation email delivery is a later milestone.

See `../docs/phase-4-participant-booking.md` for tests, self-service rules and GoDaddy deployment limitations.
