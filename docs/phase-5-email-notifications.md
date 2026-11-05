# Phase 5 — Transactional email notifications

Status: **implemented and tested with local/CI Mailpit; NOT configured to deliver production email.** The final hosting tier, SMTP relay/mailbox credentials, sending domain and administrator MFA still need verification on GoDaddy.

## What gets emailed

The website sends email **after** a booking, cancellation, or rescheduling action, regardless of whether it was performed by the participant or the professor. The initial personal study invitation remains **manually emailed by the professor**, as planned. Nobody needs a participant account.

| Event | Participant email | Professor email |
|---|---|---|
| Booked | Confirmation with study, time, time zone, location | Optional copy if configured |
| Canceled | Cancellation notice showing the canceled appointment | Optional copy |
| Rescheduled | Previous time and new time/location | Optional copy |

Messages have both plain-text and accessible HTML bodies. All times are formatted in the **study's** configured IANA time zone, not the recipient's computer time zone. Raw invitation tokens, hashed tokens, study responses, and participant booking links are **never** inserted into notification bodies. Instead, participants are told to use their original personal invitation link to manage the booking. Study title and participant name are escaped in HTML.

## How the durable queue works

1. A valid scheduling operation writes the reservation, its booking event, and one or two `notification_outbox` rows within the **same InnoDB transaction**. If the booking transaction fails, the event and its email are rolled back together.
2. The public HTTP response completes **without waiting for SMTP**. The appointment remains valid if mail is unavailable.
3. A scheduled CLI command, `php spark notifications:send 20`, claims queued records with database row locks, then sends using standard SMTP via CodeIgniter's built-in email library.
4. A delivery failure records an error **code**, never the SMTP debug response, password, participant address, or email contents in logs. Attempts back off for approximately 1, 5, 25 and 60 minutes, stopping after five failed attempts. The professor's authenticated dashboard reports the number of exhausted failures.
5. A successful message clears its recipient address and message snapshot from the outbox. The booking-event audit history is retained separately.
6. After repairing the SMTP configuration, an administrator can explicitly requeue **one** failed message with `php spark notifications:retry 123`, substituting the outbox row ID. Requeuing never itself sends mail. The next scheduled sender processes it.

Queue entries contain just the event-specific appointment snapshot, participant recipient address, and optional professor address. A unique key on (booking event, recipient role) prevents duplicate queue entries for the same event/recipient. The queue is **at-least-once delivery**, not exactly once: an unlikely crash after SMTP accepts a message but before the sent status is recorded may cause a duplicate email. This is a standard limitation of basic SMTP without an external delivery-idempotency service.

## Test emails before GoDaddy

All of the following uses **fictional records only**, sends **no emails over the internet**, and requires no subscription.

### Quick visual preview (no MySQL needed)

On a computer with Docker, PHP 8.2+, and Composer 2:

```bash
git clone https://github.com/alocc911/studySchedule.git
cd studySchedule
docker compose up -d mailpit
cd backend
composer install
cp .env.example .env
php spark notifications:preview
```

On Windows Command Prompt, use `copy .env.example .env` instead of `cp`.

Open **http://127.0.0.1:8025** on the same computer. Mailpit receives three realistic **fictional** examples — confirmed, canceled, and rescheduled. Inspect the text, HTML, subject, headers and time zones. The preview command is intentionally blocked in production and when SMTP is not configured to use the loopback address `localhost`/ `127.0.0.1` / `::1` with no encryption.

Mailpit receives SMTP on `127.0.0.1:1025` and shows its browser inbox on `127.0.0.1:8025`. Docker Compose binds both ports to loopback; **never deploy this SMTP inbox publicly** or put real participant information into the preview.

### Integration tests

```bash
# From repository root
docker compose up -d db mailpit
cd backend
composer install
cp .env.example .env
php spark migrate --all
```

The existing automated PHP integration tests require a **dedicated disposable database whose name ends with `_ci`**, and deliberately clear scheduling records during each test. Do not set `STUDY_SCHEDULE_INTEGRATION=1` against your usual development or production database.

GitHub Actions performs the full isolated MySQL migration/test sequence, sends a real booking confirmation via Mailpit's SMTP port, runs `notifications:preview`, checks Mailpit's API for the expected subjects, and uploads a `mailpit-email-previews` artifact containing fictional `.eml` files and JSON message captures. You can download this from the successful CI workflow's **Artifacts** section and view the emails without installing Docker or configuring GoDaddy. GitHub Pages' static participant demo **does not** send any email.

### Deliver local booking events

When the PHP/MySQL-backed local website is active (not the GitHub Pages demo), enable email delivery in the **private** `backend/.env`:

```dotenv
notifications.enabled = true
notifications.fromEmail = 'scheduler@example.test'
notifications.fromName = 'Hospitality Research'
notifications.professorCopyEmail = ''
notifications.smtp.host = '127.0.0.1'
notifications.smtp.port = 1025
notifications.smtp.crypto = 'none'
notifications.smtp.username = ''
notifications.smtp.password = ''
```

After a reservation or rescheduling event, run `php spark notifications:send 20`. The local Mailpit inbox will capture the notification. In production, the professor won't run this command manually; a private cPanel cron task runs it every few minutes.

## Eventually configuring GoDaddy

1. Verify the exact hosting plan supports PHP 8.2+ with the required extensions, MySQL/MariaDB **and scheduled cron tasks**. Verify that outbound authenticated SMTP is permitted and identify a sender mailbox/relay, ideally on the researcher's own domain. It is not yet established that the cheapest GoDaddy plan includes a mailbox.
2. Configure `notifications.fromEmail`, `.fromName`, `.professorCopyEmail` (optional), `notifications.smtp.host`, `.port`, `.username`, `.password` and `.crypto` in the private `.env`. Choose `tls` (typically port 587) or `ssl` (often port 465), according to the SMTP provider. `none` is allowed **only** with loopback Mailpit for testing.
3. Configure a cPanel cron job, for example once every 5 minutes, using the PHP executable path from your actual account:

```cron
*/5 * * * * cd /home/ACCOUNT/study-schedule-backend && /usr/local/bin/php spark notifications:send 20 >> /home/ACCOUNT/study-schedule-backend/writable/logs/email-cron.log 2>&1
```

4. Confirm a successful end-to-end test with an actual mailbox. Set up SPF/DKIM/DMARC for the sender domain, inspect real spam-folder behavior, and verify receipt, sender identity and time-zone accuracy.
5. Only after SMTP, cron, HTTPS, database backups, and administrator MFA have been verified should real invitations be distributed. **Set `notifications.enabled=true` only when these prerequisites are satisfied.**

If SMTP is disabled, reservations still create pending outbox rows. Do not create real participant bookings while the mail channel is unconfigured, or previously queued notices may be delivered later out of context.

## Guarantees and limitations

- **No extra hosting provider** or queue service is required; MySQL is the outbox.
- **No emails leave GitHub Pages.** Its sample-data demo remains separate from the PHP app.
- **No initial invitations are automatically sent**, even when SMTP is enabled.
- A booking can succeed even when an email delivery fails. The UI says a message was **queued**, not delivered.
- Email delivery typically occurs on the next cron run, not instantly.
- A rare duplicate email is possible under sender crashes. Existing appointment state in MySQL is always authoritative.
- Failed terminal notifications must be reviewed by an operator. The authenticated professor dashboard shows a warning, and the CLI can retry one selected outbox ID.
- The GoDaddy environment and its SMTP relay have **not yet been provisioned or tested**.
