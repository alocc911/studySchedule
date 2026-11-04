# GoDaddy/cPanel deployment layout (planned)

No GoDaddy hosting credentials are connected yet, so this is a **deployment recipe**, not a claim that production hosting is configured.

Expected layout for a domain using `public_html` as its document root:

```
/home/ACCOUNT/
  study-schedule-backend/   # backend/ contents, including app/, vendor/, .env and writable/
  public_html/              # frontend/ contents
    index.html
    config.js
    assets/
    api/
      index.php             # copy deploy/cpanel-api-index.php
      .htaccess             # copy deploy/cpanel-api.htaccess
```

1. Confirm cPanel hosting includes PHP 8.2+ with `intl`, `mbstring`, `mysqli/mysqlnd`, `json`, plus a MySQL/MariaDB database. **Do this before purchasing or promising deployment.**
2. In CI or locally, run `composer install --no-dev --optimize-autoloader` inside `backend/`. Upload the resulting backend project (including `vendor/`) **outside the web document root**.
3. Copy `frontend/` into `public_html/` (not the repository root or `backend/`).
4. Create `public_html/api/`; copy `deploy/cpanel-api-index.php` as `index.php` and `deploy/cpanel-api.htaccess` as `.htaccess`. Adjust the private backend folder name in the front controller if different.
5. Copy `backend/.env.example` to the private backend's `.env`. Set `CI_ENVIRONMENT=production`, `app.baseURL='https://YOUR_DOMAIN/api/'`, `app.forceGlobalSecureRequests=true`, and real MySQL credentials (not committed).
6. For the real domain, supply `frontend/config.js` with `apiBaseUrl: '/api/'` and `mode: 'live'`. This file is **public configuration only** and must never contain credentials.
7. Verify `https://YOUR_DOMAIN/api/health` and `/api/ready`; the latter must report `database: connected`. Never run tests against a production database.
8. Verify the static homepage. Later phases will require migrations, invitation token protections, email configuration, HTTPS verification, backups, and administrator MFA before accepting participants.

If your GoDaddy plan enforces a different folder layout or does not support the split private/public deployment, adapt this recipe before uploading code. The CodeIgniter app must not expose its private files over HTTP.
