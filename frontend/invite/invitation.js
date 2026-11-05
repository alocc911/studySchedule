import { createParticipantApi } from './participant-api.js';
import { DemoParticipantApi } from './demo-participant.js';

const $ = id => document.getElementById(id);
const config = window.STUDY_SCHEDULE_CONFIG ?? { mode: 'preview', apiBaseUrl: null };
const preview = config.mode === 'preview';
const TOKEN_STORAGE_KEY = 'study-schedule-invitation-token-v1';

let api;
let token = null;
let snapshot = null;
let moving = false;
let pending = false;

const errorText = {
  INVALID_INVITATION: ['Invalid invitation', 'This invitation link could not be recognized. Ask the researcher for a new link.'],
  INVITATION_EXPIRED: ['Invitation expired', 'This invitation has expired. Please contact the researcher using the original email.'],
  INVITATION_REVOKED: ['Invitation unavailable', 'This invitation is no longer active. Please contact the researcher.'],
  TOO_MANY_REQUESTS: ['Too many requests', 'Please wait a minute and try opening your invitation again.'],
};

function notice(text, isError = false) {
  $('participant-feedback').textContent = text;
  $('participant-feedback').classList.toggle('error', isError);
}

function parseDate(raw) {
  if (!raw) return null;
  const normalized = /Z$|[+-]\d\d:\d\d$/.test(raw)
    ? raw : raw.replace(' ', 'T').replace(/\.\d+$/, '') + 'Z';
  return new Date(normalized);
}

export function formatSessionTime(raw, timezone) {
  const date = parseDate(raw);
  if (!date || !Number.isFinite(+date)) return 'Date unavailable';
  return new Intl.DateTimeFormat('en-US', {
    timeZone: timezone,
    weekday: 'long',
    month: 'long',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
    timeZoneName: 'short',
  }).format(date);
}

function getLinkToken() {
  const hash = window.location.hash.startsWith('#')
    ? window.location.hash.slice(1) : window.location.hash;
  const fromLink = new URLSearchParams(hash).get('token');

  // A fragment is never sent in the initial HTTP request. Remove it from
  // the address bar/history after reading it. Store only for this browser tab.
  if (fromLink) {
    window.history.replaceState(null, '', window.location.pathname);
    try { window.sessionStorage.setItem(TOKEN_STORAGE_KEY, fromLink); } catch { /* no session storage */ }
    return fromLink;
  }

  try { return window.sessionStorage.getItem(TOKEN_STORAGE_KEY); }
  catch { return null; }
}

function applyToken(value) {
  token = value;
  moving = false;
  if (token) {
    try { window.sessionStorage.setItem(TOKEN_STORAGE_KEY, token); } catch { /* private mode */ }
  }
}

function displayEmpty() {
  $('invitation-empty').hidden = false;
  $('invitation-error').hidden = true;
  $('invitation-details').hidden = true;
  $('forget-invitation').hidden = true;
}

function displayError(error) {
  $('invitation-empty').hidden = true;
  $('invitation-error').hidden = false;
  $('invitation-details').hidden = true;
  $('forget-invitation').hidden = !token;
  const [title, details] = errorText[error.reason] ||
    ['Unable to load invitation', error.message || 'Please try again.'];
  $('error-title').textContent = title;
  $('error-message').textContent = details;
}

function element(tag, value, className = '') {
  const node = document.createElement(tag);
  node.textContent = String(value || '');
  if (className) node.className = className;
  return node;
}

function formatSeats(s) {
  const count = Math.max(0, Number(s.available_seats) || 0);
  return count === 0 ? 'No places remaining' :
    count === 1 ? '1 place remaining' : `${count} places remaining`;
}

function renderSessions() {
  const study = snapshot.study;
  const bookings = snapshot.booking;
  const list = $('sessions-list');
  list.replaceChildren();

  const eligible = snapshot.sessions.filter(s =>
    !bookings || Number(s.id) !== Number(bookings.session.id));
  const active = study.status === 'active' &&
    (!bookings || moving && bookings.can_reschedule);
  $('session-section').hidden = !active;
  $('stop-reschedule').hidden = !bookings || !moving;
  $('session-mode-label').textContent = moving ? 'CHANGE YOUR RESERVATION' : 'SELECT A TIME';
  $('sessions-heading').textContent = moving ? 'Choose a new session' : 'Available sessions';
  $('timezone-description').textContent = `All times are shown in ${study.timezone}.`;

  for (const session of eligible) {
    const row = element('article', '', 'session-card');
    const when = element('h3', formatSessionTime(session.starts_at, study.timezone));
    const type = element('p', Number(session.capacity) === 1 ? 'Individual interview' : 'Group discussion');
    const place = element('p', session.location || 'Location to be confirmed');
    const seats = element('span', formatSeats(session), 'seat-count' +
      (Number(session.available_seats) <= 0 ? ' full' : ''));
    const cutoff = bookings && moving &&
      parseDate(session.starts_at).getTime() -
        Number(snapshot.rules.change_cutoff_hours) * 3600000 <= Date.now();
    const bookButton = element('button', moving ? 'Reschedule to this time' : 'Reserve this time', 'primary-button');
    bookButton.type = 'button';
    bookButton.dataset.sessionId = String(session.id);
    bookButton.dataset.action = moving ? 'reschedule' : 'book';
    bookButton.disabled = Number(session.available_seats) <= 0 || Boolean(cutoff) || pending;
    row.append(when, type, place, seats, bookButton);
    if (cutoff) row.append(element('p', 'Too close to the session start to reschedule.', 'helper'));
    list.append(row);
  }

  const noSessions = active && eligible.length === 0;
  $('no-sessions').hidden = !noSessions;
  $('no-sessions').textContent = moving
    ? 'There are no alternative sessions available right now. Your current reservation is unchanged.'
    : 'No sessions are currently available for this study. Please contact the researcher using the original invitation email.';
}

function renderSnapshot() {
  $('invitation-empty').hidden = true;
  $('invitation-error').hidden = true;
  $('invitation-details').hidden = false;
  $('forget-invitation').hidden = false;

  const { study, invitation, booking } = snapshot;
  $('study-name').textContent = study.title;
  $('study-description').textContent = study.description || '';
  $('study-description').hidden = !study.description;
  $('study-greeting').textContent = invitation.name ? `Welcome, ${invitation.name}.` : '';
  $('study-greeting').hidden = !invitation.name;
  $('study-instructions-card').hidden = !study.instructions;
  $('study-instructions').textContent = study.instructions || '';

  $('existing-booking').hidden = !booking;
  if (booking) {
    $('booking-time').textContent = formatSessionTime(booking.session.starts_at, study.timezone);
    $('booking-place').textContent = booking.session.location || 'Location to be confirmed';
    $('booking-deadline').textContent = `Changes are allowed until ${formatSessionTime(booking.change_deadline, study.timezone)}.`;
    $('reschedule-button').hidden = !booking.can_reschedule;
    $('cancel-button').hidden = !booking.can_cancel;
    $('booking-locked').hidden = booking.can_cancel || booking.can_reschedule;
    $('booking-locked').textContent = 'This appointment can no longer be changed online. Please contact the researcher.';
  }

  renderSessions();

  if (!booking && study.status !== 'active') {
    $('session-section').hidden = true;
    $('no-sessions').hidden = false;
    $('no-sessions').textContent = 'This study is not currently accepting reservations. Please contact the researcher.';
  }
}

async function refresh() {
  if (!token) {
    snapshot = null;
    displayEmpty();
    return;
  }
  try {
    snapshot = await api.invitation(token);
    renderSnapshot();
  } catch (error) {
    snapshot = null;
    displayError(error);
  }
}

async function mutate(action, successText) {
  if (pending) return;
  pending = true;
  try {
    await action();
    moving = false;
    await refresh();
    notice(successText);
  } catch (error) {
    notice(error.message || 'Unable to complete this request. Please try again.', true);
    await refresh();
  } finally {
    pending = false;
  }
}

async function startDemoNew() {
  const newToken = await api.exampleInvitation();
  applyToken(newToken);
  notice('A new fictional invitation is open. Choose an available time.');
  await refresh();
}

function closeInvitation() {
  token = null;
  snapshot = null;
  moving = false;
  try { window.sessionStorage.removeItem(TOKEN_STORAGE_KEY); } catch { /* private mode */ }
  window.history.replaceState(null, '', window.location.pathname);
  notice('');
  displayEmpty();
}

function setupEvents() {
  $('retry-invitation').addEventListener('click', refresh);
  $('forget-invitation').addEventListener('click', closeInvitation);
  $('demo-new').addEventListener('click', async () => {
    try { await startDemoNew(); } catch (error) { notice(error.message, true); }
  });
  $('demo-existing').addEventListener('click', async () => {
    applyToken(api.existingInvitation());
    notice('Opening a fictional reservation.');
    await refresh();
  });
  $('reschedule-button').addEventListener('click', () => {
    moving = true;
    renderSnapshot();
    $('session-section').scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
  $('stop-reschedule').addEventListener('click', () => {
    moving = false;
    renderSnapshot();
  });
  $('cancel-button').addEventListener('click', () => {
    if (!snapshot?.booking?.can_cancel) return;
    if (!window.confirm('Cancel your reservation and release this place?')) return;
    mutate(() => api.cancel(token), 'Your reservation has been canceled. No email was sent.');
  });
  $('sessions-list').addEventListener('click', event => {
    const target = event.target.closest('button[data-action]');
    if (!target || pending || target.disabled) return;
    const id = Number(target.dataset.sessionId);
    if (!Number.isInteger(id) || id < 1) return;
    if (target.dataset.action === 'reschedule') {
      if (!window.confirm('Move your reservation to this new time?')) return;
      mutate(() => api.reschedule(token, id), 'Your appointment has been rescheduled. Please save the updated details.');
    } else {
      if (!window.confirm('Confirm this reservation?')) return;
      mutate(() => api.book(token, id), 'Your appointment is booked. Please save the details shown above; automatic email is not yet enabled.');
    }
  });
  window.addEventListener('hashchange', async () => {
    const newToken = getLinkToken();
    if (newToken) {
      applyToken(newToken);
      notice('');
      await refresh();
    }
  });
}

async function main() {
  $('preview-alert').hidden = !preview;
  $('demo-choices').hidden = !preview;
  setupEvents();
  if (preview) {
    api = new DemoParticipantApi();
  } else {
    try {
      api = createParticipantApi(config.apiBaseUrl, window.location.href);
    } catch {
      displayError({ message: 'This website is not connected to the scheduling server.' });
      return;
    }
  }
  applyToken(getLinkToken());
  await refresh();
}

main();
