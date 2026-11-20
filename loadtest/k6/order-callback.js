import http from 'k6/http';
import { check } from 'k6';
import { API_BASE_URL, login, localPath, options } from './config.js';

export { options };

const sandboxPath = __ENV.ORDER_SANDBOX_PATH || '';
const sandboxPayload = __ENV.ORDER_SANDBOX_JSON || '';
const enabled = Boolean(sandboxPath && sandboxPayload);

if (sandboxPath) localPath(sandboxPath);

export function setup() {
  if (!enabled) {
    console.warn('Order sandbox scenario is disabled; set ORDER_SANDBOX_PATH and ORDER_SANDBOX_JSON for a local-only sandbox adapter.');
  }
}

export default function () {
  if (!enabled || !login()) return;

  const response = http.post(`${API_BASE_URL}${sandboxPath}`, sandboxPayload, {
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
    },
    tags: { name: 'local_sandbox_order_callback' },
  });
  check(response, {
    'local sandbox endpoint returns 2xx': (res) => res.status >= 200 && res.status < 300,
  });
}
