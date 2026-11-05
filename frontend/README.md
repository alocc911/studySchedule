# Static frontend

The files in this directory deploy **unchanged** to GitHub Pages and to the GoDaddy public web root (except `config.js`, whose public API URL differs by environment).

- `index.html` is the preview landing page.
- `assets/css/site.css` contains responsive styles.
- `assets/js/api.js` contains the shared API URL normalization helpers.
- `invite/` is the participant booking page, with same-origin bearer-token API adapter and a strictly fictional browser demo.
- `admin/` is the professor workspace, with CodeIgniter Shield session API adapter and a fictional browser demo.
- `assets/js/main.js` initializes the page.
- `config.js` contains **only public settings**; it must never contain API secrets.

All resource paths in HTML are relative so GitHub Pages' `/studySchedule/` subdirectory works without an asset build.

GitHub Pages provides **interactive fictional-data demos** at `/studySchedule/admin/` and `/studySchedule/invite/`. Neither demo sends messages or makes live reservations. On GoDaddy, change `config.js` to `mode: 'live', apiBaseUrl: '/api/'` to call the actual PHP server. Keep private invitation tokens out of URL query strings; use the `#token=` fragment.
