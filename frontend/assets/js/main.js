import { createApiClient } from './api.js';

const config = window.STUDY_SCHEDULE_CONFIG ?? { mode: 'preview', apiBaseUrl: null };
const year = document.querySelector('#current-year');
if (year) year.textContent = String(new Date().getFullYear());

// The Phase 1 site does not attempt live bookings.
// Later phases will use this client for study-specific invitation requests.
export const api = createApiClient(config.apiBaseUrl, window.location.href);
