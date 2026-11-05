import { test } from 'node:test';
import assert from 'node:assert/strict';
import { DemoProfessorStore } from '../../frontend/admin/demo-store.js';
import { createProfessorApi } from '../../frontend/admin/professor-api.js';

function memory() {
  const data = new Map();
  return {
    getItem: key => data.get(key) ?? null,
    setItem: (key,value) => data.set(key,value),
  };
}

test('demo has isolated fictional data and a working dashboard', async () => {
  const store = new DemoProfessorStore(memory());
  const dashboard = await store.dashboard();
  assert.equal(dashboard.studies, 2);
  assert.equal(dashboard.active_bookings, 2);
  const original = await store.bookings();
  assert.equal(original.length, 2);
  assert.ok(original.every(b => b.participant_email.endsWith('@example.com')));
});

test('studies can be created, updated and activated in demo', async () => {
  const store = new DemoProfessorStore(memory());
  const { id } = await store.createStudy({ title: 'New interviews', timezone: 'America/Chicago',
    description: '', instructions: '' });
  await store.updateStudy(id, { title: 'Updated interviews', timezone: 'America/Chicago',
    description: 'Demo', instructions: '' });
  await store.studyStatus(id, 'active');
  const study = (await store.studies()).find(s => s.id === id);
  assert.equal(study.title, 'Updated interviews');
  assert.equal(study.status, 'active');
});

test('demo batch of sessions is atomic on overlap conflict', async () => {
  const store = new DemoProfessorStore(memory());
  const study = (await store.studies()).find(s => s.id === 1);
  const initial = (await store.sessions()).length;
  const start = new Date(Date.now() + 40 * 86400000);
  const formDate = d => new Date(d).toISOString().slice(0,16);
  await store.createSessions(study.id, [{ start_local: formDate(start),
    end_local: formDate(new Date(+start + 3600000)), capacity: 4, location: 'Sample' }]);
  assert.equal((await store.sessions()).length, initial + 1);
  await assert.rejects(
    store.createSessions(study.id, [
      { start_local: formDate(new Date(+start + 2*3600000)), end_local: formDate(new Date(+start + 3*3600000)), capacity: 1, location:'' },
      { start_local: formDate(start), end_local: formDate(new Date(+start+3600000)), capacity: 1, location:'' },
    ]),
    /overlap/i,
  );
  assert.equal((await store.sessions()).length, initial + 1);
});

test('preview invitations are explicitly fake and do not send email', async () => {
  const store = new DemoProfessorStore(memory());
  const result = await store.issueInvitation({ study_id: 1, email: 'sample@example.com', name: 'Sample' });
  assert.match(result.token, /^DEMO-ONLY-/);
  assert.equal((await store.invitations()).length, 4);
  await store.revokeInvitation(result.id);
  assert.ok((await store.invitations()).find(i => i.id === result.id).revoked_at);
});

test('admin can move and cancel a fictional reservation', async () => {
  const store = new DemoProfessorStore(memory());
  const move = await store.rescheduleBooking(1, 2);
  assert.notEqual(move.booking_id, 1);
  const moved = (await store.bookings()).find(b => b.id === move.booking_id);
  assert.equal(moved.session_id, 2);
  const historical = (await store.bookings()).find(b => b.id === 1);
  assert.equal(historical.status, 'rescheduled');
  await store.cancelBooking(move.booking_id);
  assert.equal((await store.dashboard()).active_bookings, 1);
  const blob = await store.exportBookings();
  assert.match(await blob.text(), /research|Winery/i);
});

test('live admin API requires same-origin and sends CSRF on mutations', async () => {
  assert.throws(
    () => createProfessorApi('https://api.other.example/', 'https://host.example/admin/'),
    /same origin/i,
  );
  const calls = [];
  const fetchImpl = async (url, options) => {
    calls.push({ url: url.href, options });
    if (url.pathname.endsWith('/me')) {
      return { ok: true, json: async () => ({
        data: { email: 'researcher@example.com' },
        csrf: { header: 'X-CSRF-TOKEN', token: 'test-csrf' },
      }) };
    }
    return { ok: true, json: async () => ({ data: { id: 1 } }) };
  };
  const api = createProfessorApi('/api/', 'https://host.example/admin/', fetchImpl);
  await api.me();
  await api.createStudy({ title: 'Study', timezone: 'UTC' });
  assert.equal(calls[1].options.credentials, 'same-origin');
  assert.equal(calls[1].options.headers['X-CSRF-TOKEN'], 'test-csrf');
  assert.equal(calls[1].options.mode, 'same-origin');
});
