# Phase 4 — Invitation-based participant booking

Phase 4 adds the **participant-facing interface and authenticated-by-bearer API** while retaining the shared-hosting architecture: static HTML/CSS/JavaScript + CodeIgniter 4 / MySQL on one inexpensive GoDaddy cPanel account.

## Browser testing on GitHub Pages

Open [Participant demo](https://alocc911.github.io/studySchedule/invite/) and choose **Try booking a session**. You can pick a fictional group slot, return to your link, move to another available session, or cancel. Choose **Manage a sample booking** to see an already-booked individual interview and attempt rescheduling. The [Professor workspace demo](https://alocc911.github.io/studySchedule/admin/) uses the **same fictional localStorage data**, so you can see how participant reservations affect group capacity and the professor's bookings table.

All demo data is fictional and held in the **browser**. It is not encrypted or appropriate for real participant information, creates no real shared reservations, and sends no email. Reset demo data in the professor workspace if you need fresh sample appointments. The backend remains unavailable on GitHub Pages.

## Live invitation and API design

The professor generates a 256-bit random invitation link associated with one research study. Only a SHA-256 **hash** is stored in MySQL. The professor copies and manually emails the one-time link.

A link has the form:

```text
https://YOUR_DOMAIN/invite/#token=PERSONAL_BEARER_TOKEN
```

The URL fragment is not sent to the server when loading the static HTML page. On opening the link, JavaScript stores the token in the **current tab's sessionStorage** (not localStorage), removes the token from the address bar/history with `history.replaceState`, and sends it only as `Authorization: Bearer ...` to the **same-origin HTTPS** CodeIgniter API. No third-party scripts, identity-provider login, participant account, or calendar connection is involved. Anyone who obtains the link can access and manage that invitation: it is a bearer credential and **not proof of identity**.

| Method | API route | Behavior |
|---|---|---|
| GET | `/api/participant/invitation` | Return invited study details, current booking, available seats and change deadline |
| POST | `/api/participant/book` | Reserve one seat in a session belonging to the invited study |
| POST | `/api/participant/cancel` | Cancel current booking and release its seat |
| POST | `/api/participant/reschedule` | Atomically move to another eligible session in the same study |

All requests require the invitation bearer header; no token, study identifier, or private participant contact details are placed in a URL path or query string. Only the page-serving HTML is public. All API responses use `Cache-Control: no-store` and `Referrer-Policy: no-referrer`, and participant API requests are IP-throttled.

## Scheduling rules

- Only active studies and open, future sessions accept bookings.
- A session with capacity 1 is an individual interview; larger capacity permits group conversations. Participants see only available-seat totals, never the identities of other participants.
- One active booking per invitation, enforced by database uniqueness and transactions. Concurrent bookings cannot overfill an individual or group session.
- A participant can cancel/reschedule until **24 hours before the existing session's start** (the backend service's configurable default). The target of rescheduling must also start more than 24 hours from now.
- An expired link **can manage an existing booked session**, until its cutoff, but cannot make a new booking once there is no active reservation. A revoked link cannot self-manage; the professor handles any active reservation.
- Failures during rescheduling leave the previous reservation intact. Cancellation and rescheduling preserve the booking-event history.
- Opening a link, previewing a study, and refreshing a page are read-only; destructive actions always require a confirmed button action and an authenticated POST.
- Participants do not have passwords, accounts, identity verification or a consent/data-collection process in this website.

## Running with PHP/MySQL locally

From the repository root:

```bash
docker compose up -d db
cd backend
composer install
cp .env.example .env
php spark migrate --all
php spark serve --host 127.0.0.1 --port 8080
```

Configure the static frontend's local `config.js` with `mode: 'live'` and `apiBaseUrl: 'http://127.0.0.1:8080/'` **only if served from that exact origin**. To avoid cross-origin requests, serve both static assets and API through one local reverse proxy or single GoDaddy domain. Do not configure a GitHub Pages production API URL; the public Pages build is a fictional demo only.

## CI and limitations

GitHub Actions runs Node frontend tests for the demo/API transport, PHP integration tests on a real MySQL database for invitation-scoped HTTP endpoints, and the previous concurrency-race tests (2 clients/1 seat and 7 clients/6 seats). Invalid/revoked/expired links and study-boundary violations are checked.

**This is not a live deployment.** We still need to verify GoDaddy's PHP Authorization header forwarding, production HTTPS, email notifications for confirmations/cancellations/reschedules, administrator MFA, backups, monitoring and a real staging installation before inviting actual research participants.
