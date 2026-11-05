import { createApiClient } from './api.js';

const config = window.STUDY_SCHEDULE_CONFIG ?? { mode: 'preview', apiBaseUrl: null };
const year = document.querySelector('#current-year');
if (year) year.textContent = String(new Date().getFullYear());

// Public homepage: the participant invitation experience is reached through a private link.
export const api = createApiClient(config.apiBaseUrl, window.location.href);

// Only the public, fictional demo is linked from the Pages preview.
if (config.mode === 'preview') {
  const nav = document.querySelector('.main-nav');
  if (nav) {
    const link = document.createElement('a');
    link.href = './admin/';
    link.textContent = 'Workspace demo';
    nav.append(link);
    const participant = document.createElement('a');
    participant.href = './invite/';
    participant.textContent = 'Participant demo';
    nav.append(participant);
  }
}
