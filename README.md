# studySchedule

A lightweight, invitation-only scheduling website for academic hospitality research.

**Status: Phase 1 foundation.** The public landing page, admin login, development database configuration and automated checks are included. Study/session models, invitations and actual booking are **not yet implemented**. Do not send participants to this prototype.

## Tech stack

- Python 3.12+, Django 5.2 LTS and server-rendered templates
- PostgreSQL 16 for development and production
- Built-in Django administration, to be customized in Phase 3
- GitHub Actions for lint, Django checks, migration and tests

## Start locally

Prerequisites: Python 3.12+ and Docker Compose (or local PostgreSQL).

1. Clone the repository and enter its directory.
2. Create a virtual environment: `python -m venv .venv` and activate it.
3. Install dependencies: `python -m pip install -r requirements-dev.txt`.
4. Copy `.env.example` to `.env`, and set a new local `DJANGO_SECRET_KEY`.
5. Start PostgreSQL: `docker compose up -d db`.
6. Initialize the database: `python manage.py migrate`.
7. Start the website: `python manage.py runserver`.

Browse to `http://127.0.0.1:8000/`, `/health/`, or `/admin/`. To create a local admin account use `python manage.py createsuperuser`.

Django loads `.env` via python-dotenv. Never commit it. Passwords in `.env.example` and `compose.yaml` are **local development examples only**.

## Quick UI smoke tests without Docker

Set `DB_ENGINE=sqlite` and `DJANGO_DEBUG=true` in `.env`, then run `python manage.py migrate` and `python manage.py runserver`. SQLite is debug-only and **not** the supported database for the future booking engine.

## Checks

- `ruff check .`
- `python manage.py check`
- `python manage.py test`

The GitHub Actions workflow runs these checks with a PostgreSQL service and applies Django migrations.

## Deployment and privacy

Phase 1 is not production ready. Before launch, configure a real domain, HTTPS, a trusted proxy, strong secrets, administrator MFA, persistent backups, proper static file serving, and email delivery. `DJANGO_DEBUG` defaults to false; production uses secure cookies and HTTPS redirects. HSTS must be enabled only after the production TLS setup is validated.

The `/health/` endpoint is a simple liveness check, not a database-readiness check.

This app will store only scheduling information. Consents, research responses, recordings and waivers remain outside the site. No research participant data or production credentials belong in the repository.

## Next steps

1. Booking domain models and concurrency-safe reservations.
2. Professor administration: studies, sessions and invitation links.
3. Participant booking, cancellations and rescheduling.
4. Confirmation emails and notifications.
5. Final design, security, accessibility, deployment.
