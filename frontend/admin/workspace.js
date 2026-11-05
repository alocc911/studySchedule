import { createProfessorApi } from './professor-api.js';
import { DemoProfessorStore } from './demo-store.js';

const $ = id => document.getElementById(id);
const cfg = window.STUDY_SCHEDULE_CONFIG ?? { mode: 'preview', apiBaseUrl: null };
const preview = cfg.mode === 'preview' || !cfg.apiBaseUrl;

let store;
let data = { dashboard: {}, studies: [], sessions: [], invitations: [], bookings: [] };

function message(value, isError = false) {
  $('workspace-message').textContent = value;
  $('workspace-message').classList.toggle('error', isError);
}

function el(tag, text = '', className = '') {
  const node = document.createElement(tag);
  if (text !== null) node.textContent = String(text);
  if (className) node.className = className;
  return node;
}

function button(text, action, id, className = 'outline-button') {
  const node = el('button', text, className);
  node.type = 'button';
  node.dataset.action = action;
  node.dataset.id = String(id);
  return node;
}

function option(value, title) {
  const element = document.createElement('option');
  element.value = String(value);
  element.textContent = title;
  return element;
}

function choose(select, values, current) {
  select.replaceChildren(...values.map(([value, title]) => option(value, title)));
  if (current !== undefined && [...select.options].some(o => o.value === String(current))) {
    select.value = String(current);
  }
}

function utcDate(value) {
  if (!value) return null;
  const normalized = /Z$|[+-]\d\d:\d\d$/.test(value)
    ? value : value.replace(' ', 'T').replace(/\.\d+$/, '') + 'Z';
  return new Date(normalized);
}

function formatTime(value, timezone = 'America/Chicago') {
  const parsed = utcDate(value);
  if (!parsed || !Number.isFinite(parsed.getTime())) return '—';
  try {
    return new Intl.DateTimeFormat('en-US', {
      timeZone: timezone, month: 'short', day: 'numeric', year: 'numeric',
      hour: 'numeric', minute: '2-digit', timeZoneName: 'short',
    }).format(parsed);
  } catch { return parsed.toISOString(); }
}

function localFieldDate(value) {
  if (!value) return null;
  // Invitation expiry uses an explicit ISO offset. The input is in the
  // professor's browser zone; session times are separately study-zone aware.
  const date = new Date(value);
  if (Number.isNaN(+date)) throw new Error('Choose a valid expiration date.');
  return date.toISOString();
}

async function action(operation, success) {
  try {
    await operation();
    await refresh();
    message(success);
    return true;
  } catch (error) {
    message(error.message || 'Unable to complete the operation.', true);
    return false;
  }
}

async function refresh() {
  const [dashboard, studies, sessions, invitations, bookings] = await Promise.all([
    store.dashboard(), store.studies(), store.sessions(), store.invitations(), store.bookings(),
  ]);
  data = { dashboard, studies, sessions, invitations, bookings };
  $('stat-studies').textContent = dashboard.studies;
  $('stat-sessions').textContent = dashboard.upcoming_sessions;
  $('stat-invitations').textContent = dashboard.invitations;
  $('stat-bookings').textContent = dashboard.active_bookings;
  renderStudies();
  renderSessions();
  renderInvitations();
  renderBookings();
}

function record(title, sub, status) {
  const row = el('div', null, 'record');
  const heading = el('div', null, 'record-header');
  heading.append(el('h4', title), el('span', status, 'pill ' + status.toLowerCase()));
  row.append(heading, el('p', sub));
  return row;
}

function fillStudySelect(id) {
  const select = $(id);
  const old = select.value;
  choose(select, data.studies.map(s => [s.id, s.title]), old);
  select.disabled = data.studies.length === 0;
}

function renderStudies() {
  const list = $('studies-list');
  list.replaceChildren();
  if (!data.studies.length) list.append(el('p', 'No studies yet. Create one to get started.', 'empty'));
  for (const study of data.studies) {
    const row = record(study.title, `${study.session_count} sessions · ${study.invitation_count} invitations · ${study.active_bookings} booked`, study.status);
    const actions = el('div', null, 'record-actions');
    actions.append(button('Edit details', 'edit-study', study.id));
    const select = document.createElement('select');
    select.setAttribute('aria-label', 'Change study status for ' + study.title);
    select.dataset.action = 'study-status';
    select.dataset.id = String(study.id);
    choose(select, [['draft','Draft'],['active','Active'],['closed','Closed'],['archived','Archived']], study.status);
    actions.append(select);
    row.append(actions);
    list.append(row);
  }
  fillStudySelect('session-study');
  fillStudySelect('invitation-study');
  updateStudyTimezoneNote();
}

function updateStudyTimezoneNote() {
  const study = data.studies.find(x => x.id === +$('session-study').value);
  $('session-timezone-note').textContent = study
    ? `Enter all times in ${study.timezone}, regardless of your computer's time zone.`
    : 'Create a study first.';
}

function editStudy(id) {
  const s = data.studies.find(x => x.id === +id);
  if (!s) return;
  $('study-edit-id').value = String(s.id);
  $('study-form-title').textContent = 'Edit study';
  $('save-study').textContent = 'Save changes';
  $('cancel-study-edit').hidden = false;
  $('study-title').value = s.title;
  $('study-timezone').value = s.timezone;
  $('study-description').value = s.description || '';
  $('study-instructions').value = s.instructions || '';
  $('study-form').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function clearStudyForm() {
  $('study-form').reset();
  $('study-edit-id').value = '';
  $('study-form-title').textContent = 'Create a study';
  $('save-study').textContent = 'Create study';
  $('cancel-study-edit').hidden = true;
}

function sessionRow() {
  const row = el('div', null, 'session-row');
  for (const [label, type, name] of [
    ['Start (study time zone)','datetime-local','start_local'],
    ['End (study time zone)','datetime-local','end_local'],
    ['Seats','number','capacity'],
    ['Location','text','location'],
  ]) {
    const wrapper = el('label', label, name === 'location' ? 'location-cell' : '');
    const input = document.createElement('input');
    input.type = type;
    input.name = name;
    input.required = name !== 'location';
    if (name === 'capacity') { input.min = '1'; input.max = '1000'; input.value = '1'; }
    if (name === 'location') input.maxLength = 300;
    wrapper.append(input);
    row.append(wrapper);
  }
  const remove = el('button', 'Remove');
  remove.type = 'button';
  remove.setAttribute('aria-label', 'Remove session row');
  remove.addEventListener('click', () => {
    if ($('session-rows').children.length > 1) row.remove();
  });
  row.append(remove);
  return row;
}

function renderSessions() {
  const table = $('sessions-table');
  table.replaceChildren();
  if (!data.sessions.length) {
    const cell = el('td','No sessions yet.');cell.colSpan = 6;
    const row = document.createElement('tr');row.append(cell);table.append(row);
  }
  for (const s of data.sessions) {
    const tr = document.createElement('tr');
    const study = el('td', s.study_title);
    const start = el('td', formatTime(s.starts_at, s.timezone));
    start.append(el('small', s.capacity === 1 ? 'Individual interview' : 'Group discussion'));
    const loc = el('td', s.location || 'To be confirmed');
    const seats = el('td', `${s.booked_seats} / ${s.capacity}`);
    const status = el('td', null);
    const statusSelect = document.createElement('select');
    statusSelect.dataset.action = 'session-status';
    statusSelect.dataset.id = String(s.id);
    statusSelect.setAttribute('aria-label', 'Change session status');
    choose(statusSelect, [['open','Open'],['closed','Closed'],['canceled','Canceled']], s.status);
    status.append(statusSelect);
    const manage = el('td', null);
    manage.append(button('Change seats', 'session-capacity', s.id));
    tr.append(study, start, loc, seats, status, manage);
    table.append(tr);
  }
}

function renderInvitations() {
  const list = $('invitations-list');
  list.replaceChildren();
  if (!data.invitations.length) list.append(el('p', 'No invitations have been generated.', 'empty'));
  for (const i of data.invitations) {
    const status = i.revoked_at ? 'revoked' : i.active_booking_id ? 'booked' : 'issued';
    const row = record(i.participant_name || i.participant_email, `${i.participant_email} · ${i.study_title}`, status);
    if (i.expires_at) row.append(el('p', 'Expires: ' + formatTime(i.expires_at)));
    if (!i.revoked_at) {
      const actions = el('div', null, 'record-actions');
      actions.append(button('Revoke link', 'revoke-invitation', i.id));
      row.append(actions);
    }
    list.append(row);
  }
}

function renderBookings() {
  const table = $('bookings-table');
  table.replaceChildren();
  if (!data.bookings.length) {
    const cell = el('td','No reservations yet.');cell.colSpan = 5;
    const row = document.createElement('tr');row.append(cell);table.append(row);
  }
  for (const b of data.bookings) {
    const tr = document.createElement('tr');
    const participant = el('td', b.participant_name || b.participant_email || 'Unknown');
    participant.append(el('small', b.participant_email || ''));
    const study = el('td', b.study_title || '—');
    const session = el('td', formatTime(b.starts_at, b.timezone));
    const status = el('td', null);
    status.append(el('span', b.status, 'pill ' + b.status));
    const manage = el('td', null);
    if (b.status === 'booked') {
      const targets = data.sessions.filter(s => s.study_id === +b.study_id && s.id !== +b.session_id &&
        s.status === 'open' && +s.booked_seats < +s.capacity && utcDate(s.starts_at) > new Date());
      if (targets.length) {
        const select = document.createElement('select');
        select.dataset.targetBooking = String(b.id);
        select.setAttribute('aria-label', 'Move booking to another session');
        choose(select, targets.map(s => [s.id, formatTime(s.starts_at, s.timezone)]));
        manage.append(select, button('Move', 'move-booking', b.id));
      }
      manage.append(button('Cancel', 'cancel-booking', b.id));
    }
    tr.append(participant, study, session, status, manage);
    table.append(tr);
  }
}

function showTab(tab) {
  for (const button of document.querySelectorAll('.tab')) {
    const active = button.dataset.tab === tab;
    button.classList.toggle('active', active);
    if (active) button.setAttribute('aria-current', 'page');
    else button.removeAttribute('aria-current');
  }
  for (const name of ['studies','sessions','invitations','bookings']) {
    $('panel-' + name).hidden = name !== tab;
  }
}

function setupEvents() {
  for (const button of document.querySelectorAll('.tab')) {
    button.addEventListener('click', () => showTab(button.dataset.tab));
  }

  $('study-form').addEventListener('submit', event => {
    event.preventDefault();
    const id = $('study-edit-id').value;
    const payload = {
      title: $('study-title').value.trim(),
      timezone: $('study-timezone').value,
      description: $('study-description').value,
      instructions: $('study-instructions').value,
    };
    action(() => id ? store.updateStudy(+id, payload) : store.createStudy(payload),
      id ? 'Study updated.' : 'Study created as a draft.').then(ok => { if (ok) clearStudyForm(); });
  });
  $('cancel-study-edit').addEventListener('click', clearStudyForm);

  $('studies-list').addEventListener('click', event => {
    const control = event.target.closest('button[data-action]');
    if (control?.dataset.action === 'edit-study') editStudy(control.dataset.id);
  });
  $('studies-list').addEventListener('change', event => {
    const select = event.target.closest('select[data-action="study-status"]');
    if (select) action(() => store.studyStatus(+select.dataset.id, select.value), 'Study status updated.');
  });
  $('session-study').addEventListener('change', updateStudyTimezoneNote);
  $('add-session-row').addEventListener('click', () => {
    if ($('session-rows').children.length >= 30) { message('Maximum 30 sessions per batch.', true); return; }
    $('session-rows').append(sessionRow());
  });
  $('sessions-form').addEventListener('submit', event => {
    event.preventDefault();
    const studyId = +$('session-study').value;
    const sessions = [...$('session-rows').children].map(row => ({
      start_local: row.querySelector('[name="start_local"]').value,
      end_local: row.querySelector('[name="end_local"]').value,
      capacity: +row.querySelector('[name="capacity"]').value,
      location: row.querySelector('[name="location"]').value.trim(),
    }));
    action(() => store.createSessions(studyId, sessions), 'Sessions saved.').then(ok => { if (ok) $('session-rows').replaceChildren(sessionRow()); });
  });
  $('sessions-table').addEventListener('click', event => {
    const target = event.target.closest('button[data-action]');
    if (!target || target.dataset.action !== 'session-capacity') return;
    const s = data.sessions.find(x => x.id === +target.dataset.id);
    const value = prompt('New seat capacity (must include current reservations):', String(s.capacity));
    if (value === null) return;
    const capacity = Number(value);
    if (!Number.isInteger(capacity) || capacity < 1 || capacity > 1000) {
      message('Enter a whole number between 1 and 1000.', true); return;
    }
    action(() => store.sessionCapacity(s.id, capacity), 'Session capacity updated.');
  });
  $('sessions-table').addEventListener('change', event => {
    const select = event.target.closest('select[data-action="session-status"]');
    if (!select) return;
    const changed = data.sessions.find(s => s.id === +select.dataset.id);
    if (select.value === 'canceled' && !confirm('Cancel this session? Only empty sessions can be canceled.')) {
      select.value = changed.status; return;
    }
    action(() => store.sessionStatus(+select.dataset.id, select.value), 'Session status updated.');
  });

  $('invitation-form').addEventListener('submit', event => {
    event.preventDefault();
    const payload = {
      study_id: +$('invitation-study').value,
      email: $('invitation-email').value.trim(),
      name: $('invitation-name').value.trim(),
      expires_at: localFieldDate($('invitation-expiry').value),
    };
    (async () => {
      try {
        const issued = await store.issueInvitation(payload);
        const url = new URL('../invite/#token=' + encodeURIComponent(issued.token), location.href).href;
        $('link-value').value = url;
        $('generated-link').hidden = false;
        $('link-advice').textContent = preview
          ? 'Demo link only. It opens the fictional participant booking experience in this browser. No real appointment is created.'
          : 'Copy this private link now. It cannot be recovered; issue another invitation if it is lost. The participant can book, cancel, or reschedule through the link.';
        $('invitation-email').value = '';
        $('invitation-name').value = '';
        await refresh();
        message(preview ? 'Demo invitation generated. No email was sent.' : 'Invitation generated. No email was sent.');
      } catch (error) { message(error.message, true); }
    })();
  });
  $('copy-link').addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText($('link-value').value);
      message(preview ? 'Demo-only link copied.' : 'Invitation link copied.');
    } catch {
      $('link-value').focus(); $('link-value').select();
      message('Select the link and copy it manually.');
    }
  });
  $('invitations-list').addEventListener('click', event => {
    const target = event.target.closest('button[data-action="revoke-invitation"]');
    if (target && confirm('Revoke this link? Existing bookings remain until handled separately.')) {
      action(() => store.revokeInvitation(+target.dataset.id), 'Invitation revoked.');
    }
  });
  $('bookings-table').addEventListener('click', event => {
    const target = event.target.closest('button[data-action]');
    if (!target) return;
    const id = +target.dataset.id;
    if (target.dataset.action === 'cancel-booking' && confirm('Cancel this booking and release its seat?')) {
      action(() => store.cancelBooking(id), 'Booking canceled.');
    }
    if (target.dataset.action === 'move-booking') {
      const select = [...$('bookings-table').querySelectorAll('[data-target-booking]')]
        .find(s => s.dataset.targetBooking === String(id));
      if (select && confirm('Move this participant to the selected session?')) {
        action(() => store.rescheduleBooking(id, +select.value), 'Booking moved.');
      }
    }
  });
  $('export-bookings').addEventListener('click', async () => {
    try {
      const csv = await store.exportBookings();
      const url = URL.createObjectURL(csv);
      const link = document.createElement('a');
      link.href = url; link.download = 'research-bookings.csv';
      document.body.append(link); link.click(); link.remove();
      setTimeout(() => URL.revokeObjectURL(url), 5000);
      message(preview ? 'Sample CSV exported.' : 'Bookings CSV exported.');
    } catch (error) { message(error.message, true); }
  });

  $('reset-demo').addEventListener('click', async () => {
    if (!confirm('Restore the original fictional sample data?')) return;
    store.reset(); await refresh(); message('Demo data restored.');
  });
  $('exit-button').addEventListener('click', async () => {
    if (preview) return;
    try { await store.logout(); location.assign('./'); }
    catch (error) { message(error.message, true); }
  });
}

async function start() {
  $('mode-badge').textContent = preview ? 'Demo mode' : 'Secure workspace';
  $('demo-notice').hidden = !preview;
  $('reset-demo').hidden = !preview;
  $('exit-button').hidden = preview;
  setupEvents();
  $('session-rows').append(sessionRow());

  if (preview) {
    store = new DemoProfessorStore();
  } else {
    try {
      store = createProfessorApi(cfg.apiBaseUrl, location.href);
      $('login-link').href = store.loginUrl;
      const user = await store.me();
      $('account-name').textContent = user.email;
    } catch (error) {
      $('auth-prompt').hidden = false;
      message(error.status === 401 ? 'Sign in to the professor account.' :
        'Unable to connect to the professor API. Check the hosting configuration.', true);
      return;
    }
  }

  $('workspace').hidden = false;
  try { await refresh(); } catch (error) { message(error.message, true); }
}

start();
