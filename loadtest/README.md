# Local k6 scripts

These scripts are for the local development stack only. `API_BASE_URL` is restricted to `localhost`, `127.0.0.1`, or `[::1]`; the default is `http://127.0.0.1:4175`. The web dev server is `http://127.0.0.1:5174` (`WEB_BASE_URL` is provided for convenience). The API base must not be redirected through a public or cloud endpoint.

Start the local stack from the repository root with `pnpm dev`. The login scripts use the seeded local member `member@xlb.local` / `member-local-2026`; override with `TEST_EMAIL` and `TEST_PASSWORD` when the local seed password has been changed. Keep credentials in the shell environment and do not paste them into reports.

Run an individual scenario from the repository root:

```sh
k6 run loadtest/k6/login-overview.js
k6 run loadtest/k6/subscriptions.js
k6 run loadtest/k6/order-callback.js
```

`login-overview.js` measures the documented CSRF/login flow and `GET /api/v1/me/overview`. `subscriptions.js` measures the authenticated subscription list. To also fetch the configuration, provide a valid local test-account token without printing it:

```sh
SUBSCRIPTION_TOKEN='local-test-token' k6 run loadtest/k6/subscriptions.js
```

The order scenario is intentionally disabled by default. Project documentation says the current payment is a local sandbox simulation and that a real payment callback/signature integration does not exist. No live callback is guessed or invoked. To exercise a locally implemented sandbox adapter, supply its relative `/api/...` path and JSON body explicitly; the script rejects absolute URLs and paths outside `/api/`:

```sh
ORDER_SANDBOX_PATH='/api/your-local-sandbox-route' \
ORDER_SANDBOX_JSON='{"test":true}' \
k6 run loadtest/k6/order-callback.js
```

Only configure this against a disposable local database and a test-only endpoint that cannot charge money. The route/body are not established by the current interface docs, so they must be confirmed against the local sandbox implementation before enabling the scenario. Do not point this at a provider or any non-loopback environment.

## Load and thresholds

Thresholds and load are environment-controlled. Defaults are deliberately small for a developer machine. The values in [`thresholds.example.json`](thresholds.example.json) are suggestions pending calibration, not release gates. Increase virtual users in steps and monitor local PHP/SQLite resource use:

```sh
K6_VUS=10 K6_DURATION=1m K6_P95_MS=500 K6_FAILED_RATE=0.01 \
  k6 run loadtest/k6/login-overview.js
```

Supported variables: `API_BASE_URL`, `WEB_BASE_URL`, `TEST_EMAIL`, `TEST_PASSWORD`, `K6_VUS`, `K6_DURATION`, `K6_P95_MS`, `K6_FAILED_RATE`, and the scenario-specific variables above. P95 latency is in milliseconds; failed rate is a fraction from `0` to `1`. The suggested P95/error thresholds need measurement on the intended local machine and workload before they can be treated as baselines.

The scripts do not load test static downloads, VPN protocol handshakes, real payment providers, production systems, or cloud resources. Login is performed during each iteration, so these scenarios include authentication cost. Subscription profile fetching is optional because the token is secret and must come from the local test account.
