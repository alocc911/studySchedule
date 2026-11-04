# Phase 1 acceptance checklist

- [x] Frontend is plain HTML/CSS/JavaScript without a Node production runtime.
- [x] Frontend assets use relative paths for both `/studySchedule/` and `/`.
- [x] Browser configuration contains no credentials.
- [x] Backend source follows the official CodeIgniter 4 app starter structure.
- [x] Backend exposes `GET /health` and `GET /ready` (MySQL readiness).
- [x] Local MySQL Compose service supplied with development-only credentials.
- [x] Tests configured for frontend and backend in GitHub Actions.
- [x] GitHub Pages workflow publishes only `frontend/`, after it is enabled in repository settings.
- [x] Sample GoDaddy private/backend and public/frontend layout documented.
- [ ] GitHub CI green on this pull request (to verify after commit).
- [ ] GitHub Pages enabled for this account and public preview deployed (requires repository settings).
- [ ] GoDaddy PHP extensions and account document-root behavior verified (requires hosting account).

## Out of scope

- Production GoDaddy account setup and real email service
- Studies, sessions, invitations, bookings, cancellations, rescheduling
- Professor authentication and authorization
- Participant data storage
