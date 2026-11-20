# Local smoke test

The smoke test checks the local Laravel control plane without k6, credentials, or write requests. It only accepts a loopback `BASE_URL`.

Start the development stack from the repository root:

```sh
pnpm dev
```

In another terminal, run:

```sh
sh loadtest/smoke.sh
```

The default target is `http://127.0.0.1:4175`. To use another local port, set `BASE_URL`, for example `BASE_URL=http://localhost:4180 sh loadtest/smoke.sh`. Each curl request has a 2 second connection timeout and a 5 second total timeout. Override them with `CONNECT_TIMEOUT` and `REQUEST_TIMEOUT` when needed. A missing or unresponsive service reports a clear failure and exits nonzero.

The checks are:

- `GET /api/health`: the control plane responds with `status: ok` and `mode: local-development`.
- `GET /api/v1/auth/csrf`: the public CSRF bootstrap returns a token.
- `GET /api/v1/auth/session`: without a cookie, the session reports `data: null`.
- `GET /api/v1/catalog/plans`: the public catalog returns a data array with plan duration, price, and `max_devices` fields. The smoke test checks the shape, not database contents or the commercial correctness of configured values.
- `GET /api/v1/me/overview`: an anonymous request is rejected with JSON HTTP 401.

These HTTP checks establish basic control-plane behavior only. They cannot prove strict per-device enforcement across real clients, simultaneous or staggered credential-copy rejection, VPN tunnel handshakes, traffic accounting, or that a revoked device has actually stopped passing traffic. A flow must not be described as disconnected until the matching node revocation receipt is confirmed. The test does not exercise CDN behavior; CDN delivery of static assets does not prove API or VPN data-plane availability, and a CDN is not the VPN traffic path. Those claims require the relevant isolated node, real devices, and node-side evidence.
