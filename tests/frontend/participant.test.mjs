import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { DemoParticipantApi } from '../../frontend/invite/demo-participant.js';
import { createParticipantApi } from '../../frontend/invite/participant-api.js';

function storage() {
  const map = new Map();
  return {
    getItem: key => map.get(key) ?? null,
    setItem: (key, value) => map.set(key, value),
  };
}

test('fictional participant can book, reschedule, cancel and return to the link', async () => {
  const api = new DemoParticipantApi(storage());
  const token = await api.exampleInvitation();
  const initial = await api.invitation(token);
  assert.equal(initial.study.title, 'Winery Guest Experiences');
  assert.equal(initial.booking, null);
  assert.ok(initial.sessions.every(s => Number(s.id) !== 3));
  const group = initial.sessions.find(s => s.capacity === 6);
  assert.equal(group.available_seats, 5);

  const made = await api.book(token, group.id);
  assert.equal(made.session_id, group.id);
  assert.equal((await api.invitation(token)).booking.session.id, group.id);

  await assert.rejects(api.book(token, group.id), { reason: 'ALREADY_BOOKED' });
  const single = initial.sessions.find(s => s.capacity === 1);
  // The individual appointment is already reserved by a different sample guest.
  await assert.rejects(api.reschedule(token, single.id), { reason: 'SESSION_FULL' });
  assert.equal((await api.invitation(token)).booking.session.id, group.id);
  await api.cancel(token);
  assert.equal((await api.invitation(token)).booking, null);
  assert.equal((await api.invitation(token)).sessions.find(s => s.id === group.id).available_seats, 5);
});

test('fictional existing guest can reschedule and preserve booking history', async () => {
  const api = new DemoParticipantApi(storage());
  const token = api.existingInvitation();
  const before = await api.invitation(token);
  assert.equal(before.booking.session.id, 1);
  assert.equal(before.booking.can_reschedule, true);
  const moved = await api.reschedule(token, 2);
  assert.equal(moved.session_id, 2);
  assert.equal((await api.invitation(token)).booking.session.id, 2);
  const records = await api.admin.bookings();
  assert.equal(records.find(b => b.id === 1).status, 'rescheduled');
  assert.equal(records.filter(b => b.invitation_id === 1 && b.status === 'booked').length, 1);
});

test('one-study invitations never expose another study', async () => {
  const api = new DemoParticipantApi(storage());
  const token = await api.exampleInvitation();
  await assert.rejects(api.book(token, 3), { reason: 'WRONG_STUDY' });
  const invite = await api.invitation(token);
  assert.ok(invite.sessions.every(s => s.id !== 3));
  assert.ok(!JSON.stringify(invite).includes('casey@example.com'));
});

test('revocation and expiry invalidate unbooked links but expired booked link can cancel', async () => {
  const api = new DemoParticipantApi(storage());
  const a = await api.exampleInvitation();
  const id = Number(a.match(/DEMO-ONLY-(\d+)/)[1]);
  api.admin.data.invitations.find(x => x.id === id).revoked_at = new Date().toISOString();
  await assert.rejects(api.invitation(a), { reason: 'INVITATION_REVOKED' });

  const b = await api.exampleInvitation();
  const bid = Number(b.match(/DEMO-ONLY-(\d+)/)[1]);
  api.admin.data.invitations.find(x => x.id === bid).expires_at = new Date(Date.now()-60000).toISOString();
  await assert.rejects(api.invitation(b), { reason: 'INVITATION_EXPIRED' });

  const current = api.existingInvitation();
  api.admin.data.invitations.find(x => x.id === 1).expires_at = new Date(Date.now()-60000).toISOString();
  const active = await api.invitation(current);
  assert.equal(active.booking.can_cancel, true);
  await api.cancel(current);
  await assert.rejects(api.invitation(current), { reason: 'INVITATION_EXPIRED' });
});

test('participant API requires same-origin HTTPS (except localhost) and omits cookies', async () => {
  assert.throws(
    () => createParticipantApi('https://api.vendor.example/', 'https://research.example/invite/'),
    /same origin/,
  );
  assert.throws(
    () => createParticipantApi('http://research.example/api/', 'https://research.example/invite/'),
    /HTTPS/,
  );

  const requests = [];
  const token = 'A'.repeat(43);
  const fetchImpl = async (url, options) => {
    requests.push({ url: url.href, options });
    return { ok: true, json: async () => ({ data: { study: { title: 'Sample' } } }) };
  };
  const api = createParticipantApi('/api/', 'https://research.example/invite/', fetchImpl);
  await api.invitation(token);
  await api.book(token, 31);
  await api.cancel(token);
  await api.reschedule(token, 32);

  assert.deepEqual(requests.map(r => r.options.method), ['GET','POST','POST','POST']);
  for (const req of requests) {
    assert.equal(req.options.credentials, 'omit');
    assert.equal(req.options.mode, 'same-origin');
    assert.equal(req.options.headers.Authorization, 'Bearer ' + token);
    assert.ok(!req.url.includes(token));
    assert.ok(!String(req.options.body).includes(token));
  }
});

test('fragment based links avoid sending bearer secrets in initial HTTP request URLs', () => {
  const admin = readFileSync(new URL('../../frontend/admin/workspace.js', import.meta.url), 'utf8');
  const invite = readFileSync(new URL('../../frontend/invite/index.html', import.meta.url), 'utf8');
  const script = readFileSync(new URL('../../frontend/invite/invitation.js', import.meta.url), 'utf8');
  assert.match(admin, /invite\/#token=/);
  assert.doesNotMatch(admin, /invite\/\?token=/);
  assert.match(invite, /name="referrer" content="no-referrer"/);
  assert.match(script, /window\.history\.replaceState/);
  assert.match(script, /window\.sessionStorage/);
});
