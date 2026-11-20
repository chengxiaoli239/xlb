#!/bin/sh

set -u

BASE_URL=${BASE_URL:-http://127.0.0.1:4175}
BASE_URL=${BASE_URL%/}
CONNECT_TIMEOUT=${CONNECT_TIMEOUT:-2}
REQUEST_TIMEOUT=${REQUEST_TIMEOUT:-5}
failures=0
checks=0

if ! printf '%s\n' "$BASE_URL" | grep -Eq '^https?://(127\.0\.0\.1|localhost|\[::1\])(:[0-9]+)?$'; then
    printf 'FAIL BASE_URL must be a local origin (localhost, 127.0.0.1, or [::1]): %s\n' "$BASE_URL" >&2
    exit 2
fi

body_file=$(mktemp "${TMPDIR:-/tmp}/vpn-smoke.XXXXXX") || {
    printf 'FAIL could not create a temporary response file\n' >&2
    exit 2
}
trap 'rm -f "$body_file"' 0 HUP INT TERM

request() {
    request_path=$1
    request_accept=${2:-application/json}

    if HTTP_STATUS=$(curl --silent --show-error \
        --connect-timeout "$CONNECT_TIMEOUT" \
        --max-time "$REQUEST_TIMEOUT" \
        --header "Accept: $request_accept" \
        --output "$body_file" \
        --write-out '%{http_code}' \
        "${BASE_URL}${request_path}"); then
        CURL_STATUS=0
    else
        CURL_STATUS=$?
        HTTP_STATUS=000
    fi
}

pass() {
    checks=$((checks + 1))
    printf 'PASS %s\n' "$1"
}

fail() {
    checks=$((checks + 1))
    failures=$((failures + 1))
    printf 'FAIL %s\n' "$1"
}

report_curl_failure() {
    label=$1
    if [ "$CURL_STATUS" -eq 28 ]; then
        fail "$label (curl timed out; connect limit ${CONNECT_TIMEOUT}s, total limit ${REQUEST_TIMEOUT}s)"
    else
        fail "$label (curl could not connect or complete the request; exit $CURL_STATUS)"
    fi
}

request /api/health
if [ "$CURL_STATUS" -ne 0 ]; then
    report_curl_failure 'health check';
    printf 'Hint: start the local stack with pnpm dev; expected API at %s\n' "$BASE_URL" >&2
    printf '%s checks, %s failures\n' "$checks" "$failures"
    exit 1
elif [ "$HTTP_STATUS" = 200 ] && grep -Eq '"status"[[:space:]]*:[[:space:]]*"ok"' "$body_file" && grep -Eq '"mode"[[:space:]]*:[[:space:]]*"local-development"' "$body_file"; then
    pass 'GET /api/health returns local-development status ok'
else
    fail "GET /api/health expected HTTP 200 and status=ok (got HTTP $HTTP_STATUS)"
fi

request /api/v1/auth/csrf
if [ "$CURL_STATUS" -ne 0 ]; then
    report_curl_failure 'CSRF bootstrap'
elif [ "$HTTP_STATUS" = 200 ] && grep -Eq '"data"[[:space:]]*:[[:space:]]*\{[^}]*"token"[[:space:]]*:[[:space:]]*"[^"]+"' "$body_file"; then
    pass 'GET /api/v1/auth/csrf returns a CSRF token'
else
    fail "GET /api/v1/auth/csrf expected HTTP 200 with data.token (got HTTP $HTTP_STATUS)"
fi

request /api/v1/auth/session
if [ "$CURL_STATUS" -ne 0 ]; then
    report_curl_failure 'anonymous session';
elif [ "$HTTP_STATUS" = 200 ] && grep -Eq '"data"[[:space:]]*:[[:space:]]*null' "$body_file"; then
    pass 'GET /api/v1/auth/session reports an anonymous session'
else
    fail "GET /api/v1/auth/session expected HTTP 200 with data=null (got HTTP $HTTP_STATUS)"
fi

request /api/v1/catalog/plans
if [ "$CURL_STATUS" -ne 0 ]; then
    report_curl_failure 'public plan catalog'
elif [ "$HTTP_STATUS" = 200 ] && grep -Eq '"data"[[:space:]]*:[[:space:]]*\[' "$body_file" && grep -Eq '"duration_days"' "$body_file" && grep -Eq '"price_cents"' "$body_file" && grep -Eq '"max_devices"' "$body_file"; then
    pass 'GET /api/v1/catalog/plans returns plans with duration, price, and device limit fields'
else
    fail "GET /api/v1/catalog/plans expected HTTP 200 with a data array and plan fields (got HTTP $HTTP_STATUS)"
fi

request /api/v1/me/overview
if [ "$CURL_STATUS" -ne 0 ]; then
    report_curl_failure 'member overview authorization'
elif [ "$HTTP_STATUS" = 401 ] && grep -Eq '"error"[[:space:]]*:[[:space:]]*\{' "$body_file"; then
    pass 'GET /api/v1/me/overview rejects anonymous access with JSON 401'
else
    fail "GET /api/v1/me/overview expected JSON HTTP 401 (got HTTP $HTTP_STATUS)"
fi

printf '%s checks, %s failures\n' "$checks" "$failures"
[ "$failures" -eq 0 ]
