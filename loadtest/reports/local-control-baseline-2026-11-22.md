# Local control-plane smoke measurement

Measurement date: 2026-11-22.

This report contains only endpoint names, HTTP status counts, latency percentiles, and error rates. No response bodies, cookies, account data, or credentials are recorded.

### Concurrency 1

| Endpoint | Status counts | P50 ms | P95 ms | Error rate |
| --- | --- | ---: | ---: | ---: |
| GET /api/health | 200: 20 | 3.43 | 3.91 | 0.00% |
| GET /api/v1/catalog/plans | 200: 20 | 3.53 | 3.71 | 0.00% |
| GET /api/v1/auth/session (anonymous) | 200: 20 | 3.50 | 3.67 | 0.00% |
| GET /api/v1/me/overview (anonymous denial) | 401: 20 | 3.51 | 3.88 | 0.00% |

### Concurrency 4

| Endpoint | Status counts | P50 ms | P95 ms | Error rate |
| --- | --- | ---: | ---: | ---: |
| GET /api/health | 200: 20 | 13.17 | 14.05 | 0.00% |
| GET /api/v1/catalog/plans | 200: 20 | 13.22 | 13.99 | 0.00% |
| GET /api/v1/auth/session (anonymous) | 200: 20 | 13.17 | 13.73 | 0.00% |
| GET /api/v1/me/overview (anonymous denial) | 401: 20 | 13.21 | 14.12 | 0.00% |

## Scope and limitations

This is a bounded local PHP development-server + disposable SQLite smoke measurement with 80 total requests per concurrency setting. It is not a production capacity benchmark, a 3,000-concurrent-user result, a node stability result, or evidence of production VPN availability. The tool calls only loopback health, public plan catalog, anonymous session, and anonymous overview-denial endpoints; it does not log in, create orders, invoke payment, or contact cloud services.
