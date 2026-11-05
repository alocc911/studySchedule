# studySchedule

A small, independently hosted research appointment scheduler for invitation-only studies.

**Status: Phase 2 engine implemented.** The GitHub Pages frontend is a static design preview. The CodeIgniter backend has a MySQL schema and an internal transaction-safe scheduling service, but participant booking endpoints, professor login/UI, and email notifications are **not yet available**.

## Architecture

```text
frontend/        Static HTML, CSS, JavaScript — GitHub Pages and GoDaddy
backend/         CodeIgniter 4 PHP API — GoDaddy or local PHP server
  app/           Application code and framework configuration
  public/        Backend-only document root (for local development)
  writable/      Private logs, cache and session storage
deploy/          GoDaddy cPanel split public/private deployment example
tests/frontend/  Node built-in test runner, no frontend build dependencies
.github/         Automated CI and GitHub Pages preview workflows
compose.yaml     Local MySQL database
```

The frontend has a single API adapter in `frontend/assets/js/api.js`. Its URL comes from `frontend/config.js` (a **public**, non-secret configuration file). The GitHub Pages copy intentionally sets `apiBaseUrl: null` and does **not** make live booking calls. The production GoDaddy copy will use `apiBaseUrl: '/api/'`.

The PHP API runs through CodeIgniter 4, and MySQL/MariaDB is the intended datastore. No external scheduling vendor or second paid hosting account is required.

## Prerequisites

- PHP 8.2+ with extensions `intl`, `mbstring`, `mysqli/mysqlnd` and `json`
- Composer 2
- Node.js 20+ for the frontend checks
- MySQL 8 / compatible MariaDB (optional to start the static preview)
- Docker Compose, or a local MySQL server, for backend readiness checks

**Before purchasing a GoDaddy plan**, confirm it includes PHP 8.2+, the required extensions, and MySQL/MariaDB. The deployment model does not need Python, PostgreSQL or a persistent Node server.

## Run the static frontend locally

From the repository root:

```bash
python3 -m http.server 4173 --directory frontend
```

Open `http://127.0.0.1:4173/`. Python is used here only as a static file server; it is **not** part of the production system. You can also upload the same `frontend/` directory to GitHub Pages or any ordinary web host.

## Run the CodeIgniter API locally

```bash
docker compose up -d db
cd backend
composer install
cp .env.example .env
php spark migrate
php spark serve --host 127.0.0.1 --port 8080
```

Visit `http://127.0.0.1:8080/health` to verify the PHP application, or `http://127.0.0.1:8080/ready` to verify MySQL connectivity. The local credentials in `compose.yaml` and `.env.example` are explicitly for development and must be replaced before deployment.

## Run tests

```bash
npm run check
npm test
cd backend && composer test
```

GitHub Actions runs JavaScript checks, PHPUnit, PHP syntax checks, and a real CodeIgniter-to-MySQL readiness smoke test.

## Preview on GitHub Pages

1. Merge the Phase 1 rebuild branch after reviewing its green CI checks.
2. Go to **Settings → Pages** in this repository. Select **GitHub Actions** as the deployment source.
3. Run the **Static frontend preview (GitHub Pages)** workflow manually if it does not deploy automatically after the merge.
4. The expected site address, subject to GitHub enabling Pages for this private repository, is `https://alocc911.github.io/studySchedule/`.

GitHub Pages eligibility for a **private** repository depends on the GitHub account plan. GitHub Pages sites are publicly accessible even when the repository is private. Never deploy secrets, participant records or backend code into the `frontend/` folder.

**Important:** GitHub Pages hosts only the static frontend. Later end-to-end reservation tests will use a GoDaddy **staging** API and a separate test database. The same frontend code can connect to that API once it exists.

## GoDaddy deployment

See [deploy/README.md](deploy/README.md) for the planned inexpensive cPanel layout. The static frontend goes into `public_html/`; PHP framework files, credentials and vendor libraries stay **outside** the web root. A small `/api/` front controller forwards requests into CodeIgniter. Staging and production will have separate MySQL databases.

There is **no** live GoDaddy deployment configured in Phase 1.

## Security and scope

This repository is public. GitHub Pages publishes only public static files; keep credentials, participant data, and private administrative information out of source control. The website will eventually store limited scheduling data (study, invitation, session, booking, name and email), not consent forms or research responses. Secrets and real participant data must never be committed.

No professor login or participant booking HTTP endpoints exist yet; don't share the preview URL as an active scheduler.

## Roadmap

1. Phase 1: Static frontend, PHP API framework, MySQL connection, CI, Pages preview. **Complete.**
2. Phase 2: Study, session, invitation, booking tables and transaction-safe booking services. **Complete, backend only.** See [Phase 2 documentation](docs/phase-2-booking-engine.md).
3. Phase 3: Professor administrative interface and bulk session entry.
4. Phase 4: Invitation-based booking, cancellation, rescheduling, group capacity.
5. Phase 5: Confirmation emails and lifecycle notifications.
6. Phase 6: Polish, accessibility, security, GoDaddy staging and production deployment.

The old Django/PostgreSQL foundation remains available in Git history only and is removed from this branch.
