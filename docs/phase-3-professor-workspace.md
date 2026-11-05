# Phase 3 — Professor administration and preview

## What you can test on GitHub Pages

Open **https://alocc911.github.io/studySchedule/admin/** after the main branch is deployed. The workspace demonstrates:

- Study creation, editing, activation, closing and archiving
- Individual and group session creation, including multiple times in one submission
- Capacity changes, session opening/closing and cancellation guards
- Generating fictional one-time invitation links and revoking them
- Viewing fictional reservations, moving and canceling them
- Downloading sample CSV records and resetting the demo

**Demo data is fictional and stored in your browser's localStorage.** It is not shared with other computers, not secured as an administrative account, and never connected to the GoDaddy/MySQL backend. Demo invitation links are intentionally nonfunctional. No messages are sent. Avoid entering real participant details into the demo.

## Actual PHP administration

When deployed to GoDaddy, the same frontend at `/admin/` uses `config.js` configured for:

```js
window.STUDY_SCHEDULE_CONFIG = Object.freeze({
  mode: 'live',
  apiBaseUrl: '/api/',
});
```

The `/api/` CodeIgniter server requires a Shield **session login** for the professor. It also requires the **superadmin** group on every admin API route, including CSV exports. Cross-origin requests are not authorized. All changes use session-based CSRF protection; the CSRF token is returned only after authentication, and the admin JavaScript stores it only in memory.

### First-time account setup

1. Install backend Composer dependencies, create a private `backend/.env` with MySQL credentials, and run `php spark migrate --all`.
2. Use `php spark shield:user --help` to inspect the installed Shield CLI actions. Use its interactive `create` command to add the professor.
3. Assign that user to the **superadmin** group using the Shield CLI `addgroup` action.
4. Configure a trusted HTTPS domain, private session storage, backups, outgoing email, and two-factor authentication before using real participant information.
5. Keep `backend/` outside the web root; only the `/api/` front controller should be public.

`frontend/admin/index.html` and the homepage are public static files; only the API handles privileged operations or private data. A public GitHub Pages copy of the frontend cannot access the live administration session.

### API routes

| Method | Route (relative to /api/) | Description |
|---|---|---|
| GET/POST | login | Shield form login, registration disabled |
| GET | admin/me | Session identity and CSRF token |
| POST | admin/logout | End session |
| GET | admin/dashboard | Summary counts |
| GET/POST | admin/studies | List/create studies |
| PUT | admin/studies/{id} | Edit study details |
| POST | admin/studies/{id}/status | Set study lifecycle |
| GET | admin/sessions | List sessions and booked seats |
| POST | admin/studies/{id}/sessions | Create 1–30 sessions as an atomic batch |
| POST | admin/sessions/{id}/capacity | Change group capacity safely |
| POST | admin/sessions/{id}/status | Open/close/cancel unoccupied session |
| GET/POST | admin/invitations | List/generate invitation |
| POST | admin/invitations/{id}/revoke | Revoke a bearer link |
| GET | admin/bookings | List bookings and participant contact details |
| GET | admin/bookings.csv | Download authorized CSV |
| POST | admin/bookings/{id}/cancel | Admin cancel; releases seat |
| POST | admin/bookings/{id}/reschedule | Admin reschedule; transactional |

All routes except `login` require the `professor` guard. The server never exposes `token_hash`. An invitation token is returned once to the authenticated professor on issuance; the professor personally emails that link. The participant `/invite/` page is not implemented until Phase 4 — do **not** distribute links yet.

## Time zones

The sessions form explicitly accepts wall-clock times **in the selected study's IANA time zone**. The PHP API interprets them in that time zone and stores UTC. It rejects nonexistent spring-forward times and ambiguous fall-back times instead of silently moving the interview by an hour. The preview approximates time zone conversion for fictional demonstrations; the PHP server is authoritative.

## Security and known limitations

- Administrator passwords use Shield, not custom JavaScript authentication.
- There is no public registration or magic-link login.
- HTTP requests have session-based CSRF protection and must originate on the same origin.
- Session cancellation is blocked if active reservations remain. The professor must move/cancel affected bookings explicitly first.
- Admin booking changes retain history but **do not yet generate email notifications**.
- MFA/email transport, rate limiting review, hosting configuration and backups must be finalized before real participants are invited.
- GitHub Pages is a static demo, not a live administrator or participant database.
- One research professor, one seat per invitation, one study per invitation; no recurring availability or external calendars.

## Tests

GitHub CI installs Shield, migrates both framework/auth and scheduling tables into a dedicated MySQL database, runs integration tests, checks the anonymous admin API returns 401, and runs frontend demo/API tests. Do not use the testing suite against actual participant records.
