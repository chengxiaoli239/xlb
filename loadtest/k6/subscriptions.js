import http from 'k6/http';
import { check } from 'k6';
import { API_BASE_URL, login, options } from './config.js';

export { options };

const token = __ENV.SUBSCRIPTION_TOKEN || '';

export default function () {
  if (!login()) return;

  const response = http.get(`${API_BASE_URL}/api/v1/me/subscriptions`, {
    headers: { Accept: 'application/json' },
    tags: { name: 'member_subscriptions' },
  });
  check(response, {
    'subscriptions list returns 2xx': (res) => res.status >= 200 && res.status < 300,
  });

  if (token) {
    const profile = http.get(`${API_BASE_URL}/subscribe/${encodeURIComponent(token)}`, {
      headers: { Accept: 'text/yaml' },
      tags: { name: 'subscription_profile' },
    });
    check(profile, {
      'subscription profile returns 2xx': (res) => res.status >= 200 && res.status < 300,
      'subscription response is not publicly cacheable': (res) =>
        /private|no-store/i.test(res.headers['Cache-Control'] || ''),
    });
  }
}
