# Static frontend

The files in this directory deploy **unchanged** to GitHub Pages and to the GoDaddy public web root (except `config.js`, whose public API URL differs by environment).

- `index.html` is the preview landing page.
- `assets/css/site.css` contains responsive styles.
- `assets/js/api.js` is the future CodeIgniter API adapter.
- `assets/js/main.js` initializes the page.
- `config.js` contains **only public settings**; it must never contain API secrets.

All resource paths in HTML are relative so GitHub Pages' `/studySchedule/` subdirectory works without an asset build.

The current preview is informational and intentionally contains no functional booking controls.
