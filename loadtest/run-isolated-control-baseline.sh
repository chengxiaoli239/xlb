#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP_ROOT="$REPO_ROOT/apps/php-api"
CONFIRM_FLAG="${1:-}"
TEMP_ROOT="${TMPDIR:-/tmp}"
TEST_DIR=""
API_PID=""
LISTENER_PID=""
API_PORT=""

fail() {
    printf 'ERROR: %s\n' "$1" >&2
    exit 1
}

is_api_process_descendant() {
    local candidate_pid="$1"
    for _ in 1 2 3 4 5 6 7 8; do
        if [[ "$candidate_pid" == "$API_PID" ]]; then
            return 0
        fi
        candidate_pid=$(ps -p "$candidate_pid" -o ppid= 2>/dev/null | tr -d ' ')
        [[ -n "$candidate_pid" && "$candidate_pid" != "1" ]] || return 1
    done
    return 1
}

cleanup() {
    local current_listener=""
    if [[ -n "$API_PID" ]]; then
        current_listener=$(lsof -nP -tiTCP:"$API_PORT" -sTCP:LISTEN 2>/dev/null | head -n 1 || true)
        if [[ -n "$current_listener" ]] && is_api_process_descendant "$current_listener"; then
            kill "$current_listener" >/dev/null 2>&1 || true
        fi
        kill "$API_PID" >/dev/null 2>&1 || true
        wait "$API_PID" >/dev/null 2>&1 || true
    fi
    if [[ -n "$TEST_DIR" && "$TEST_DIR" == "$TEMP_ROOT"/xlb-control-baseline.* && -d "$TEST_DIR" && ! -L "$TEST_DIR" ]]; then
        rm -rf "$TEST_DIR"
    fi
}

trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

[[ "$CONFIRM_FLAG" == "--confirm-isolated" && "$#" -eq 1 ]] || fail 'Pass --confirm-isolated only when using a disposable local/testing SQLite database.'
command -v php >/dev/null || fail 'PHP CLI is required.'
command -v python3 >/dev/null || fail 'Python 3 standard library is required.'
command -v lsof >/dev/null || fail 'lsof is required for listener ownership checks.'
[[ -f "$PHP_ROOT/vendor/autoload.php" ]] || fail 'Run composer install in apps/php-api first.'

API_PORT=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1", 0)); print(s.getsockname()[1]); s.close()')
[[ "$API_PORT" != "4175" && "$API_PORT" != "4181" ]] || fail 'Chosen test port is reserved for an existing API/device workflow.'
if lsof -nP -iTCP:"$API_PORT" -sTCP:LISTEN >/dev/null 2>&1; then
    fail 'The selected ephemeral port is already listening; refusing to attach to an existing service.'
fi

TEST_DIR=$(mktemp -d "$TEMP_ROOT/xlb-control-baseline.XXXXXX") || fail 'Cannot create disposable test directory.'
[[ "$TEST_DIR" == "$TEMP_ROOT"/xlb-control-baseline.* && -d "$TEST_DIR" && ! -L "$TEST_DIR" ]] || fail 'Temporary directory did not match the isolated prefix.'
TEST_DB="$TEST_DIR/database.sqlite"
touch "$TEST_DB"
APP_KEY='base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY='
APP_CONFIG_CACHE="$TEST_DIR/config.php"

printf 'Preparing disposable local/testing SQLite API on loopback port %s...\n' "$API_PORT"
if ! (
    cd "$PHP_ROOT"
    APP_ENV=testing APP_KEY="$APP_KEY" APP_CONFIG_CACHE="$APP_CONFIG_CACHE" \
        DB_CONNECTION=sqlite DB_URL= DB_DATABASE="$TEST_DB" SESSION_DRIVER=database \
        php artisan migrate:fresh --seed --force --no-interaction
) >"$TEST_DIR/migrate.log" 2>&1; then
    fail 'Disposable database migration/seed failed; temporary details were not printed.'
fi

(
    cd "$PHP_ROOT"
    export APP_ENV=testing APP_KEY="$APP_KEY" APP_CONFIG_CACHE="$APP_CONFIG_CACHE"
    export DB_CONNECTION=sqlite DB_URL= DB_DATABASE="$TEST_DB" SESSION_DRIVER=database
    exec php artisan serve --host=127.0.0.1 --port="$API_PORT" --no-reload
) >"$TEST_DIR/api.log" 2>&1 &
API_PID=$!

for _ in $(seq 1 30); do
    if ! kill -0 "$API_PID" >/dev/null 2>&1; then
        fail 'Isolated API exited before readiness; temporary server log was not printed.'
    fi
    LISTENER_PID=$(lsof -nP -tiTCP:"$API_PORT" -sTCP:LISTEN 2>/dev/null | head -n 1 || true)
    if [[ -n "$LISTENER_PID" ]]; then
        is_api_process_descendant "$LISTENER_PID" || fail 'Ephemeral port is owned by a process outside this harness.'
        break
    fi
    sleep 1
done
[[ -n "$LISTENER_PID" ]] || fail 'Isolated API did not open its loopback listener.'

REPORT_DIR="$SCRIPT_DIR/reports"
mkdir -p "$REPORT_DIR"
REPORT_PATH="$REPORT_DIR/local-control-baseline-$(date +%F).md"
[[ ! -e "$REPORT_PATH" ]] || fail 'A report for today already exists; refusing to overwrite it.'

python3 "$SCRIPT_DIR/local_control_baseline.py" \
    --origin "http://127.0.0.1:$API_PORT" --requests 80 --concurrency 1 --timeout 2 \
    --confirm-isolated >"$TEST_DIR/concurrency-1.json"
python3 "$SCRIPT_DIR/local_control_baseline.py" \
    --origin "http://127.0.0.1:$API_PORT" --requests 80 --concurrency 4 --timeout 2 \
    --confirm-isolated >"$TEST_DIR/concurrency-4.json"

python3 "$SCRIPT_DIR/write_local_control_report.py" \
    --single "$TEST_DIR/concurrency-1.json" \
    --quad "$TEST_DIR/concurrency-4.json" \
    --output "$REPORT_PATH"
printf 'Wrote bounded smoke results to %s\n' "$REPORT_PATH"
