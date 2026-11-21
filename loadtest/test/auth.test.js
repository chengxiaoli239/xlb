import assert from 'node:assert/strict';
import test from 'node:test';
import { csrfTokenFrom, requiredTestPassword } from '../k6/auth.js';

test('reads the Laravel CSRF token from data.token', () => {
  assert.equal(csrfTokenFrom({ data: { token: 'csrf-fixture' } }), 'csrf-fixture');
});

test('requires an injected local test password without a source fallback', () => {
  assert.equal(requiredTestPassword('injected-fixture'), 'injected-fixture');
  assert.throws(() => requiredTestPassword(''), /Set TEST_PASSWORD/);
  assert.throws(() => requiredTestPassword(undefined), /Set TEST_PASSWORD/);
});

test('rejects missing, empty, and wrongly-shaped CSRF values', () => {
  assert.equal(csrfTokenFrom({ data: {} }), null);
  assert.equal(csrfTokenFrom({ token: 'root-token' }), null);
  assert.equal(csrfTokenFrom({ data: { token: '  ' } }), null);
  assert.equal(csrfTokenFrom(null), null);
});
