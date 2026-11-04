import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const html = readFileSync(new URL('../../frontend/index.html', import.meta.url), 'utf8');
const config = readFileSync(new URL('../../frontend/config.js', import.meta.url), 'utf8');

test('homepage has one accessible main landmark and skip link', () => {
  assert.match(html, /href="#main-content"/);
  assert.match(html, /<main id="main-content">/);
  assert.match(html, /<h1 id="hero-heading">/);
});

test('static assets use relative paths for Pages subpaths and GoDaddy root', () => {
  assert.match(html, /href="\.\/assets\/css\/site\.css"/);
  assert.match(html, /src="\.\/assets\/js\/main\.js"/);
  assert.match(html, /src="\.\/config\.js"/);
});

test('preview never claims booking is operational', () => {
  assert.match(html, /Appointment booking is not active yet/);
  assert.match(config, /apiBaseUrl: null/);
});
