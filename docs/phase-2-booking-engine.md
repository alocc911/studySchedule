# Phase 2 — Database schema and reservation engine

This is an internal PHP/domain milestone. The **GitHub Pages preview is unchanged** because there is no authenticated participant or professor API/UI yet.

## MySQL / MariaDB schema

The migration `backend/app/Database/Migrations/2026-11-04-000001_CreateSchedulingSchema.php` creates:

| Table | Purpose |
| --- | --- |
| `studies` | Study title, time zone, instructions, draft/active/closed/archived lifecycle |
| `sessions` | One fixed meeting time, location, group seat capacity (1 = individual) and open/closed/canceled state |
| `invitations` | Exactly one study, email/name, SHA-256 token hash, expiry and revocation; no stored raw token |
| `bookings` | Active and historical reservations, exactly one booked seat per invitation |
| `booking_events` | Append-only booking/cancel/reschedule events without participant email or raw token |
| `schedule_mutex` | A single lock row to serialize session creation and enforce the sole professor's non-overlapping schedule |

Reservations are protected by **InnoDB transactions and `SELECT ... FOR UPDATE` row locks**. A unique nullable `active_invitation_id` plus a check constraint prevents multiple active bookings for one invitation, while composite foreign keys make cross-study booking references invalid even when inserted directly.

Only sessions from the invitation's own study can be selected. Session times are stored as UTC `DATETIME(6)`; the study's IANA time zone is retained for display in a future UI. The appointment times used by the service are `DateTimeImmutable`, converted to UTC.

## Business rules

- The professor creates draft studies and activates them when ready.
- Session capacity 1 means individual interview; capacity >1 allows group participants.
- The professor cannot host overlapping sessions across studies.
- Every participant receives a one-study, 32-byte random invitation token; only its SHA-256 hash is stored.
- The service rejects revoked, invalid, and expired tokens for new reservations.
- One active booking per invitation; cancellation releases a seat, preserves the historical booking, and writes a booking event.
- Expired links can **reschedule/cancel an existing booking** but cannot create a new one after cancellation. Revoked links cannot self-manage; the professor handles existing bookings.
- A reschedule is atomic. The existing booking survives unavailable/full target sessions, cutoff failures, or transaction errors.
- Cancellation/rescheduling cutoff defaults to **24 hours** before the affected session; configurable via the service constructor.
- Only an active study and open, future session can accept new bookings.
- Group capacity cannot be reduced below booked seats. Editing session times, canceling occupied sessions, and notification workflows remain later phases.

## Local use

With the `backend/.env` pointing at a development MySQL instance:

```bash
docker compose up -d db
cd backend
composer install
php spark migrate
```

### Automated tests

The integration suite is intentionally guarded and runs **only** with `STUDY_SCHEDULE_INTEGRATION=1` against a database whose name ends in `_ci`. Never run it against real scheduling records: it deletes all scheduling rows before each test. GitHub Actions configures this isolated database automatically.

The CI suite covers individual capacity, group capacity, cancellations, release/reuse, one-active-booking enforcement, cross-study isolation, invalid/expired/revoked invitations, atomic rescheduling, cutoff rules, overlapping sessions, and two concurrent races against MySQL InnoDB (2 clients / 1 seat and 7 clients / 6 seats).

## API status and next phase

Only `/health` and `/ready` are public endpoints. **No reservation or invitation token endpoint is exposed** until participant authorization, rate limiting, CSRF/CORS and admin authentication are implemented.

The next phase will add the professor's authenticated interface to create studies, sessions and invitations, manage participants, and generate links. Participant booking pages follow after that.

This service is designed for MySQL 8 and compatible MariaDB 10.6+ with working `CHECK` constraints, `InnoDB`, and transactional row locks. Verify the specific GoDaddy hosting database version and enabled extensions before production deployment.
