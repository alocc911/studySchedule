import { DemoProfessorStore } from '../admin/demo-store.js';

const CHANGE_CUTOFF_MS = 24 * 60 * 60 * 1000;

function fail(reason, message) {
  const error = new Error(message);
  error.reason = reason;
  throw error;
}

function parseFakeToken(token) {
  const match = /^DEMO-ONLY-(\d+)-NOT-A-REAL-TOKEN$/.exec(token || '');
  if (!match) fail('INVALID_INVITATION', 'This is not a valid demonstration link.');
  return Number(match[1]);
}

export class DemoParticipantApi {
  constructor(storage = globalThis.localStorage) {
    this.admin = new DemoProfessorStore(storage);
  }

  async exampleInvitation() {
    // Generated invitation uses the same fictional browser state as the
    // professor workspace, so the two demos reflect each other's changes.
    const result = await this.admin.issueInvitation({
      study_id: 1,
      email: 'guest@example.com',
      name: 'Demo Guest',
    });
    return result.token;
  }

  existingInvitation() {
    return 'DEMO-ONLY-1-NOT-A-REAL-TOKEN';
  }

  context(token) {
    const id = parseFakeToken(token);
    const invitation = this.admin.data.invitations.find(item => item.id === id);
    if (!invitation) fail('INVALID_INVITATION', 'That sample invitation is not available.');
    if (invitation.revoked_at) fail('INVITATION_REVOKED', 'This sample invitation has been revoked.');
    const study = this.admin.data.studies.find(item => item.id === invitation.study_id);
    if (!study) fail('INVALID_INVITATION', 'This sample study could not be found.');
    const booked = this.admin.data.bookings.find(item =>
      item.invitation_id === id && item.status === 'booked') || null;
    const expired = invitation.expires_at && Date.parse(invitation.expires_at) <= Date.now();
    if (expired && !booked) fail('INVITATION_EXPIRED', 'This sample invitation has expired.');
    return { invitation, study, booked, expired: Boolean(expired) };
  }

  async invitation(token) {
    const { invitation, study, booked, expired } = this.context(token);
    const now = Date.now();
    const session = booked ? this.admin.data.sessions.find(s => s.id === booked.session_id) : null;
    const deadline = session ? Date.parse(session.starts_at) - CHANGE_CUTOFF_MS : null;
    const canChange = Boolean(session && now < deadline);
    const sessions = this.admin.data.sessions
      .filter(s => s.study_id === study.id && s.status === 'open' &&
        Date.parse(s.starts_at) > now)
      .map(s => ({
        ...s, available_seats: s.capacity - this.admin.data.bookings
          .filter(b => b.session_id === s.id && b.status === 'booked').length,
      }))
      .sort((a,b) => a.starts_at.localeCompare(b.starts_at));

    return {
      study: {
        title: study.title, description: study.description,
        instructions: study.instructions, timezone: study.timezone,
        status: study.status,
      },
      invitation: {
        name: invitation.participant_name,
        expires_at: invitation.expires_at,
        expired_for_new_booking: expired,
      },
      booking: !booked ? null : {
        id: booked.id,
        session: {
          ...session,
          available_seats: undefined,
        },
        can_cancel: canChange,
        can_reschedule: canChange && study.status === 'active',
        change_deadline: new Date(deadline).toISOString(),
      },
      sessions: study.status === 'active' ? sessions : [],
      rules: { change_cutoff_hours: 24 },
    };
  }

  validateTarget(context, id, { requireChangeDeadline = false } = {}) {
    const session = this.admin.data.sessions.find(s => s.id === id);
    if (!session || session.study_id !== context.study.id) {
      fail('WRONG_STUDY', 'This session belongs to a different study.');
    }
    if (context.study.status !== 'active' || session.status !== 'open' ||
        Date.parse(session.starts_at) <= Date.now()) {
      fail('SESSION_UNAVAILABLE', 'This session is not accepting reservations.');
    }
    if (requireChangeDeadline && Date.parse(session.starts_at) - CHANGE_CUTOFF_MS <= Date.now()) {
      fail('CUTOFF_PASSED', 'This session is too close to its starting time for rescheduling.');
    }
    const booked = this.admin.data.bookings.filter(b =>
      b.session_id === id && b.status === 'booked').length;
    if (booked >= session.capacity) {
      fail('SESSION_FULL', 'All places in this session have been reserved.');
    }
    return session;
  }

  async book(token, sessionId) {
    const context = this.context(token);
    if (context.booked) fail('ALREADY_BOOKED', 'You already have a reservation.');
    const session = this.validateTarget(context, sessionId);
    const booking = {
      id: this.admin.data.sequence.booking++,
      invitation_id: context.invitation.id,
      session_id: session.id,
      study_id: context.study.id,
      status: 'booked',
      created_at: new Date().toISOString(),
    };
    this.admin.data.bookings.push(booking);
    this.admin.persist();
    return { id: booking.id, session_id: session.id, invitation_id: context.invitation.id };
  }

  async cancel(token) {
    const context = this.context(token);
    if (!context.booked) fail('NO_ACTIVE_BOOKING', 'There is no active reservation.');
    const current = this.admin.data.sessions.find(s => s.id === context.booked.session_id);
    if (Date.parse(current.starts_at) - CHANGE_CUTOFF_MS <= Date.now()) {
      fail('CUTOFF_PASSED', 'This appointment is too close to cancel online.');
    }
    context.booked.status = 'canceled';
    this.admin.persist();
    return { booking_id: context.booked.id, canceled: true };
  }

  async reschedule(token, targetId) {
    const context = this.context(token);
    if (!context.booked) fail('NO_ACTIVE_BOOKING', 'There is no active reservation.');
    if (context.booked.session_id === targetId) fail('SAME_SESSION', 'That is your current session.');
    const current = this.admin.data.sessions.find(s => s.id === context.booked.session_id);
    if (Date.parse(current.starts_at) - CHANGE_CUTOFF_MS <= Date.now()) {
      fail('CUTOFF_PASSED', 'This appointment is too close to reschedule online.');
    }
    const target = this.validateTarget(context, targetId, { requireChangeDeadline: true });
    context.booked.status = 'rescheduled';
    const replacement = {
      id: this.admin.data.sequence.booking++,
      study_id: context.study.id,
      invitation_id: context.invitation.id,
      session_id: target.id,
      status: 'booked',
      created_at: new Date().toISOString(),
    };
    this.admin.data.bookings.push(replacement);
    this.admin.persist();
    return { id: replacement.id, session_id: target.id, invitation_id: context.invitation.id };
  }
}
