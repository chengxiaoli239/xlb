# Local k6 scripts

These k6 scenarios target local development APIs only. `API_BASE_URL` and `WEB_BASE_URL` are restricted to loopback hosts; they must not be redirected through a public or cloud endpoint. The independent standard-library control-plane smoke tool is stricter: it accepts only an explicit `http://127.0.0.1:<port>` origin and refuses shared API port `4175` and M9 isolated-device port `4181`.

Start the local stack from the repository root with `pnpm dev`. Authenticated k6 scenarios require `TEST_PASSWORD` to be injected through the shell environment; there is no password fallback in source. `TEST_EMAIL` defaults to the local seed account name, but use a disposable test-only account and never paste credentials into reports.

Run an individual scenario from the repository root:

```sh
TEST_PASSWORD="$LOCAL_MEMBER_PASSWORD" k6 run loadtest/k6/login-overview.js
TEST_PASSWORD="$LOCAL_MEMBER_PASSWORD" k6 run loadtest/k6/subscriptions.js
k6 run loadtest/k6/order-callback.js
```

Populate `LOCAL_MEMBER_PASSWORD` from an approved local secret source before running; do not type a real password into command history or commit it.

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

## Local control-plane smoke baseline

The Python standard-library runner measures only public health/catalog, anonymous session, and anonymous overview denial. It sends no account credentials, never follows redirects, bypasses proxy environment settings, and does not retain response bodies, cookies, or response headers. The origin, concurrency (`1` or `4`), total requests (maximum `100`), timeout (maximum `5` seconds), and isolated-test confirmation are bounded.

Run its isolated harness from the repository root:

```sh
bash loadtest/run-isolated-control-baseline.sh --confirm-isolated
```

The harness creates a temporary SQLite database and Laravel config cache, chooses an ephemeral loopback port, checks that the listener belongs to its API process tree, and cleans up only those resources. It explicitly refuses `4175` and `4181`; it does not access the two member test phones or any cloud resource. The tests require no extra packages:

```sh
python3 -m unittest discover -s loadtest -p 'test_*.py'
node --test loadtest/test/auth.test.js
```

The generated report is a PHP development-server + SQLite smoke measurement only. It is not a production capacity baseline, a 3,000-concurrency result, a node stability result, or evidence of production VPN availability.

The scripts do not load test static downloads, VPN protocol handshakes, real payment providers, production systems, or cloud resources. Login is performed during each iteration, so these scenarios include authentication cost. Subscription profile fetching is optional because the token is secret and must come from the local test account.
