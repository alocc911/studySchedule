import { test } from 'node:test';
import assert from 'node:assert/strict';
import { normalizeApiBaseUrl, createApiClient } from '../../frontend/assets/js/api.js';

test('preview mode intentionally has no API', () => {
  assert.equal(normalizeApiBaseUrl(null, 'https://alocc911.github.io/studySchedule/'), null);
  assert.equal(createApiClient(null, 'https://alocc911.github.io/studySchedule/'), null);
});

test('normalizes the production same-origin API under /api', () => {
  assert.equal(
    normalizeApiBaseUrl('/api/', 'https://research.example/index.html'),
    'https://research.example/api/',
  );
});

test('rejects remote HTTP and userinfo-bearing API URLs', () => {
  assert.throws(
    () => normalizeApiBaseUrl('http://not-local.example/api/', 'https://research.example/'),
    /HTTPS/,
  );
  assert.throws(
    () => normalizeApiBaseUrl('https://user:pass@example.com/api/', 'https://research.example/'),
    /credentials/,
  );
});

test('API client health uses GET and does not send cookies', async () => {
  let requestedUrl;
  let requestedOptions;
  const client = createApiClient('/api/', 'https://research.example/', async (url, options) => {
    requestedUrl = url.href;
    requestedOptions = options;
    return { ok: true, json: async () => ({ status: 'ok' }) };
  });

  assert.deepEqual(await client.health(), { status: 'ok' });
  assert.equal(requestedUrl, 'https://research.example/api/health');
  assert.equal(requestedOptions.credentials, 'omit');
  assert.equal(requestedOptions.method, 'GET');
});
