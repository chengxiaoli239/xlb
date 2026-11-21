import http from 'k6/http';
import { check } from 'k6';
import { csrfTokenFrom, requiredTestPassword } from './auth.js';

const trimSlash = (value) => value.replace(/\/+$/, '');
const apiBase = trimSlash(__ENV.API_BASE_URL || 'http://127.0.0.1:4175');
const webBase = trimSlash(__ENV.WEB_BASE_URL || 'http://127.0.0.1:5174');
const allowedLocalBase = /^https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(?::\d+)?$/;

if (!allowedLocalBase.test(apiBase) || !allowedLocalBase.test(webBase)) {
  throw new Error('API_BASE_URL and WEB_BASE_URL must use localhost, 127.0.0.1, or [::1].');
}

function positiveNumber(value, fallback, name) {
  const parsed = Number(value || fallback);
  if (!Number.isFinite(parsed) || parsed <= 0) {
    throw new Error(`${name} must be a positive number.`);
  }
  return parsed;
}

const p95Ms = positiveNumber(__ENV.K6_P95_MS, '500', 'K6_P95_MS');
const failedRate = Number(__ENV.K6_FAILED_RATE || '0.01');
if (!Number.isFinite(failedRate) || failedRate < 0 || failedRate > 1) {
  throw new Error('K6_FAILED_RATE must be a number from 0 to 1.');
}
const vus = positiveNumber(__ENV.K6_VUS, '2', 'K6_VUS');

export const API_BASE_URL = apiBase;
export const WEB_BASE_URL = webBase;
export const TEST_EMAIL = __ENV.TEST_EMAIL || 'member@xlb.local';

export const options = {
  vus,
  duration: __ENV.K6_DURATION || '30s',
  thresholds: {
    http_req_duration: [`p(95)<${p95Ms}`],
    http_req_failed: [`rate<${failedRate}`],
  },
};

export function login() {
  const testPassword = requiredTestPassword(__ENV.TEST_PASSWORD);

  const csrfResponse = http.get(`${API_BASE_URL}/api/v1/auth/csrf`, {
    tags: { name: 'auth_csrf' },
  });
  const csrfBody = parseJson(csrfResponse);
  const cookieValues = http.cookieJar().cookiesForURL(API_BASE_URL);
  const csrfToken = csrfTokenFrom(csrfBody) || cookieValues['XSRF-TOKEN']?.[0];

  const csrfOk = check(csrfResponse, {
    'CSRF bootstrap returns 2xx': (response) => response.status >= 200 && response.status < 300,
    'CSRF token is available': () => Boolean(csrfToken),
  });
  if (!csrfOk) return false;

  const response = http.post(
    `${API_BASE_URL}/api/v1/auth/login`,
    JSON.stringify({ email: TEST_EMAIL, password: testPassword }),
    {
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': decodeURIComponent(String(csrfToken)),
        Accept: 'application/json',
      },
      tags: { name: 'auth_login' },
    },
  );

  return check(response, {
    'local test login returns 2xx': (res) => res.status >= 200 && res.status < 300,
  });
}

export function parseJson(response) {
  try {
    return response.json();
  } catch (_) {
    return null;
  }
}

export function localPath(path) {
  if (!path || !path.startsWith('/api/') || path.includes('..') || path.includes('://')) {
    throw new Error('Configured sandbox endpoint must be a relative /api/ path.');
  }
  return path;
}
