const STORAGE_KEY = 'study-schedule-professor-demo-v1';

function sampleDate(days, hour) {
  const date = new Date();
  date.setUTCDate(date.getUTCDate() + days);
  date.setUTCHours(hour, 0, 0, 0);
  return date.toISOString();
}

function seed() {
  const first = sampleDate(9, 16);
  const second = sampleDate(10, 17);
  const third = sampleDate(12, 19);
  return {
    sequence: { study: 3, session: 4, invitation: 4, booking: 3 },
    studies: [
      { id: 1, title: 'Winery Guest Experiences', description: 'Conversations about memorable winery visits.',
        instructions: 'Meet the researcher near the tasting room entrance.', timezone: 'America/Chicago', status: 'active' },
      { id: 2, title: 'Tasting Room Operations', description: 'Staff and owner experiences.',
        instructions: 'Please arrive five minutes early.', timezone: 'America/Chicago', status: 'draft' },
    ],
    sessions: [
      { id: 1, study_id: 1, starts_at: first, ends_at: new Date(Date.parse(first) + 3600000).toISOString(),
        location: 'Willow Creek Winery', capacity: 1, status: 'open' },
      { id: 2, study_id: 1, starts_at: second, ends_at: new Date(Date.parse(second) + 5400000).toISOString(),
        location: 'Willow Creek Winery — meeting room', capacity: 6, status: 'open' },
      { id: 3, study_id: 2, starts_at: third, ends_at: new Date(Date.parse(third) + 3600000).toISOString(),
        location: 'Tasting room', capacity: 1, status: 'open' },
    ],
    invitations: [
      { id: 1, study_id: 1, participant_email: 'alex@example.com', participant_name: 'Alex Rivera', revoked_at: null, expires_at: null },
      { id: 2, study_id: 1, participant_email: 'jordan@example.com', participant_name: 'Jordan Lee', revoked_at: null, expires_at: null },
      { id: 3, study_id: 2, participant_email: 'casey@example.com', participant_name: 'Casey Morgan', revoked_at: null, expires_at: null },
    ],
    bookings: [
      { id: 1, invitation_id: 1, study_id: 1, session_id: 1, status: 'booked', created_at: new Date().toISOString() },
      { id: 2, invitation_id: 2, study_id: 1, session_id: 2, status: 'booked', created_at: new Date().toISOString() },
    ],
  };
}

function error(reason, message = reason.replaceAll('_', ' ').toLowerCase()) {
  const result = new Error(message);
  result.reason = reason;
  return result;
}

function utcFromStudyLocal(local, timezone) {
  // Approximation is solely for the visual demo; the PHP server uses actual
  // IANA time zone conversion and rejects ambiguous DST transition times.
  const desired = Date.parse(local + ':00Z');
  if (!Number.isFinite(desired)) throw error('INVALID_LOCAL_TIME');
  let instant = desired;
  const fmt = new Intl.DateTimeFormat('en-US', {
    timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
  });
  for (let i = 0; i < 4; i++) {
    const parts = Object.fromEntries(fmt.formatToParts(new Date(instant)).map(p => [p.type, p.value]));
    const actual = Date.UTC(+parts.year, +parts.month - 1, +parts.day, +parts.hour, +parts.minute, +parts.second);
    const correction = desired - actual;
    instant += correction;
    if (correction === 0) break;
  }
  return new Date(instant).toISOString();
}

function csvCell(value) {
  let text = String(value ?? '');
  if (/^\s*[=+@-]/.test(text)) text = "'" + text;
  return '"' + text.replaceAll('"', '""') + '"';
}

export class DemoProfessorStore {
  constructor(storage = globalThis.localStorage) {
    this.storage = storage;
    try {
      this.data = JSON.parse(storage.getItem(STORAGE_KEY)) || seed();
    } catch {
      this.data = seed();
    }
  }

  persist() {
    try { this.storage.setItem(STORAGE_KEY, JSON.stringify(this.data)); } catch { /* private mode */ }
  }

  reset() {
    this.data = seed();
    this.persist();
  }

  async me() { return { email: 'professor@demo.example' }; }

  async dashboard() {
    const now = Date.now();
    return {
      studies: this.data.studies.length,
      upcoming_sessions: this.data.sessions.filter(s => s.status === 'open' && Date.parse(s.starts_at) > now).length,
      invitations: this.data.invitations.length,
      active_bookings: this.data.bookings.filter(b => b.status === 'booked').length,
    };
  }

  async studies() {
    return this.data.studies.slice().reverse().map(study => ({
      ...study,
      session_count: this.data.sessions.filter(s => s.study_id === study.id).length,
      invitation_count: this.data.invitations.filter(i => i.study_id === study.id).length,
      active_bookings: this.data.bookings.filter(b => b.study_id === study.id && b.status === 'booked').length,
    }));
  }

  async createStudy(payload) {
    if (!payload.title?.trim()) throw error('INVALID_STUDY');
    const id = this.data.sequence.study++;
    this.data.studies.push({
      id, title: payload.title.trim(), timezone: payload.timezone,
      description: payload.description || '', instructions: payload.instructions || '', status: 'draft',
    });
    this.persist();
    return { id };
  }

  async updateStudy(id, payload) {
    const s = this.data.studies.find(x => x.id === +id);
    if (!s) throw error('STUDY_NOT_FOUND');
    Object.assign(s, payload);
    this.persist();
    return { updated: true };
  }

  async studyStatus(id, status) {
    const s = this.data.studies.find(x => x.id === +id);
    if (!s) throw error('STUDY_NOT_FOUND');
    s.status = status;
    this.persist();
    return { status };
  }

  async sessions() {
    return this.data.sessions.map(s => ({
      ...s,
      study_title: this.data.studies.find(x => x.id === s.study_id)?.title ?? '',
      timezone: this.data.studies.find(x => x.id === s.study_id)?.timezone ?? 'UTC',
      booked_seats: this.data.bookings.filter(b => b.session_id === s.id && b.status === 'booked').length,
    })).sort((a,b) => a.starts_at.localeCompare(b.starts_at));
  }

  async createSessions(studyId, rows) {
    const study = this.data.studies.find(s => s.id === +studyId);
    if (!study) throw error('STUDY_NOT_FOUND');
    if (!rows.length || rows.length > 30) throw error('INVALID_BATCH');
    const candidates = rows.map(row => {
      const start = utcFromStudyLocal(row.start_local, study.timezone);
      const end = utcFromStudyLocal(row.end_local, study.timezone);
      if (Date.parse(start) <= Date.now() || Date.parse(end) <= Date.parse(start)) throw error('INVALID_SESSION_TIME');
      if (!(row.capacity >= 1)) throw error('INVALID_CAPACITY');
      return { study_id: +studyId, starts_at: start, ends_at: end,
        capacity: +row.capacity, location: row.location, status: 'open' };
    });
    const all = this.data.sessions.filter(s => s.status !== 'canceled').concat(candidates);
    for (let i = 0; i < all.length; i++) {
      for (let j = i + 1; j < all.length; j++) {
        if (all[i].starts_at < all[j].ends_at && all[i].ends_at > all[j].starts_at) {
          throw error('SCHEDULE_CONFLICT', 'Another session overlaps these times.');
        }
      }
    }
    const ids = [];
    for (const candidate of candidates) {
      const id = this.data.sequence.session++;
      this.data.sessions.push({ id, ...candidate });
      ids.push(id);
    }
    this.persist();
    return { session_ids: ids };
  }

  async sessionCapacity(id, capacity) {
    const s = this.data.sessions.find(x => x.id === +id);
    if (!s) throw error('SESSION_NOT_FOUND');
    if (capacity < this.data.bookings.filter(b => b.session_id === +id && b.status === 'booked').length) {
      throw error('CAPACITY_BELOW_BOOKINGS');
    }
    s.capacity = +capacity;
    this.persist();
    return { capacity };
  }

  async sessionStatus(id, status) {
    const s = this.data.sessions.find(x => x.id === +id);
    if (!s) throw error('SESSION_NOT_FOUND');
    if (status === 'canceled' && this.data.bookings.some(b => b.session_id === +id && b.status === 'booked')) {
      throw error('OCCUPIED_SESSION', 'Cancel or move booked participants first.');
    }
    s.status = status;
    this.persist();
    return { status };
  }

  async invitations() {
    return this.data.invitations.slice().reverse().map(i => ({
      ...i, study_title: this.data.studies.find(s => s.id === i.study_id)?.title ?? '',
      active_booking_id: this.data.bookings.find(b => b.invitation_id === i.id && b.status === 'booked')?.id || null,
    }));
  }

  async issueInvitation(payload) {
    const id = this.data.sequence.invitation++;
    this.data.invitations.push({
      id, study_id: +payload.study_id, participant_email: payload.email,
      participant_name: payload.name || null, expires_at: payload.expires_at || null, revoked_at: null,
    });
    this.persist();
    return { id, token: `DEMO-ONLY-${id}-NOT-A-REAL-TOKEN` };
  }

  async revokeInvitation(id) {
    const i = this.data.invitations.find(x => x.id === +id);
    if (!i) throw error('INVITATION_NOT_FOUND');
    i.revoked_at = new Date().toISOString();
    this.persist();
    return { revoked: true };
  }

  async bookings() {
    return this.data.bookings.slice().reverse().map(b => {
      const study = this.data.studies.find(s => s.id === b.study_id);
      const session = this.data.sessions.find(s => s.id === b.session_id);
      const invitation = this.data.invitations.find(i => i.id === b.invitation_id);
      return { ...b, study_title: study?.title, participant_name: invitation?.participant_name,
        participant_email: invitation?.participant_email, starts_at: session?.starts_at,
        ends_at: session?.ends_at, location: session?.location, timezone: study?.timezone };
    });
  }

  async cancelBooking(id) {
    const b = this.data.bookings.find(x => x.id === +id && x.status === 'booked');
    if (!b) throw error('BOOKING_NOT_ACTIVE');
    b.status = 'canceled';
    this.persist();
    return { canceled: true };
  }

  async rescheduleBooking(id, targetId) {
    const b = this.data.bookings.find(x => x.id === +id && x.status === 'booked');
    const session = this.data.sessions.find(x => x.id === +targetId);
    if (!b) throw error('BOOKING_NOT_ACTIVE');
    if (!session || session.study_id !== b.study_id) throw error('WRONG_STUDY');
    if (session.status !== 'open' || Date.parse(session.starts_at) <= Date.now()) throw error('SESSION_UNAVAILABLE');
    if (this.data.bookings.filter(x => x.session_id === +targetId && x.status === 'booked').length >= session.capacity) {
      throw error('SESSION_FULL');
    }
    b.status = 'rescheduled';
    const newId = this.data.sequence.booking++;
    this.data.bookings.push({ id: newId, invitation_id: b.invitation_id, study_id: b.study_id,
      session_id: +targetId, status: 'booked', created_at: new Date().toISOString() });
    this.persist();
    return { booking_id: newId };
  }

  async exportBookings() {
    const rows = await this.bookings();
    const fields = ['id','study_title','participant_name','participant_email','status','starts_at','timezone','location'];
    const csv = [fields.map(csvCell).join(','),
      ...rows.map(row => fields.map(field => csvCell(row[field])).join(','))].join('\r\n');
    return new Blob([csv], { type: 'text/csv;charset=utf-8' });
  }
}
