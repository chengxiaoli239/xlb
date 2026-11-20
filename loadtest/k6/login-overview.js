import http from 'k6/http';
import { check } from 'k6';
import { API_BASE_URL, login, options } from './config.js';

export { options };

export default function () {
  if (!login()) return;

  const response = http.get(`${API_BASE_URL}/api/v1/me/overview`, {
    headers: { Accept: 'application/json' },
    tags: { name: 'member_overview' },
  });
  check(response, {
    'member overview returns 2xx': (res) => res.status >= 200 && res.status < 300,
  });
}
