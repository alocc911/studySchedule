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

The current API has no booking or admin endpoints.
