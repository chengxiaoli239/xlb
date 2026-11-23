#!/usr/bin/env bash

set -euo pipefail

ANDROID_ROOT="${ANDROID_HOME:-${ANDROID_SDK_ROOT:-}}"
EMULATOR_SERIAL="${EMULATOR_SERIAL:-}"
API_PORT="4181"
EXPECTED_AVD_NAME="xlb-m9-recovery"
TEST_APP_KEY="base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY="
ANDROID_STUDIO_JAVA="/Applications/Android Studio.app/Contents/jbr/Contents/Home"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../../.." && pwd)"
PHP_ROOT="$REPO_ROOT/apps/php-api"
API_URL="http://127.0.0.1:$API_PORT"
TEST_DIR=""
API_PID=""
REVERSE_ACTIVE="false"

fail() {
    printf 'ERROR: %s\n' "$1" >&2
    exit 1
}

cleanup() {
    if [[ "$REVERSE_ACTIVE" == "true" ]]; then
        current_reverse=$("$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" reverse --list 2>/dev/null || true)
        if printf '%s\n' "$current_reverse" | awk -v remote="tcp:$API_PORT" '$2 == remote && $3 == remote { found = 1 } END { exit !found }'; then
            "$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" reverse --remove "tcp:$API_PORT" >/dev/null 2>&1 || true
        fi
    fi
    if [[ -n "$API_PID" ]]; then
        kill "$API_PID" >/dev/null 2>&1 || true
        wait "$API_PID" >/dev/null 2>&1 || true
    fi
    if [[ -n "$TEST_DIR" && "$TEST_DIR" == "${TMPDIR:-/tmp}/xlb-m9-recovery."* ]]; then
        rm -rf "$TEST_DIR"
    fi
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

trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

[[ "$(uname -s)" == "Darwin" ]] || fail 'Run this harness on macOS with an Apple Silicon Android emulator.'
if [[ -z "${JAVA_HOME:-}" && -x "$ANDROID_STUDIO_JAVA/bin/java" ]]; then
    export JAVA_HOME="$ANDROID_STUDIO_JAVA"
    export PATH="$JAVA_HOME/bin:$PATH"
fi
[[ -n "$ANDROID_ROOT" && -x "$ANDROID_ROOT/platform-tools/adb" ]] || fail 'Set ANDROID_HOME or ANDROID_SDK_ROOT to an installed Android SDK.'
[[ -n "$EMULATOR_SERIAL" ]] || fail 'Set EMULATOR_SERIAL to the disposable Android emulator serial.'
command -v php >/dev/null || fail 'PHP is required for the local sandbox API.'
[[ -f "$PHP_ROOT/vendor/autoload.php" ]] || fail 'Run composer install in apps/php-api before using this harness.'
command -v curl >/dev/null || fail 'curl is required for the loopback health check.'
command -v lsof >/dev/null || fail 'lsof is required to ensure the isolated API port is unused.'

DEVICE_HARDWARE=$("$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" shell getprop ro.hardware | tr -d '\r')
DEVICE_FINGERPRINT=$("$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" shell getprop ro.build.fingerprint | tr -d '\r')
DEVICE_AVD_NAME=$("$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" emu avd name | sed -n '1p' | tr -d '\r')
case "$DEVICE_HARDWARE:$DEVICE_FINGERPRINT" in
    ranchu:*|goldfish:*|*:sdk_gphone*) ;;
    *) fail 'The selected serial is not a recognized Android emulator; physical devices are never used by this test.' ;;
esac
[[ "$DEVICE_AVD_NAME" == "$EXPECTED_AVD_NAME" ]] || fail "The selected emulator must be the dedicated $EXPECTED_AVD_NAME AVD."

REVERSE_LIST=$("$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" reverse --list)
if printf '%s\n' "$REVERSE_LIST" | awk -v remote="tcp:$API_PORT" '$2 == remote { found = 1 } END { exit !found }'; then
    fail "The dedicated emulator already has a reverse mapping for tcp:$API_PORT; refusing to replace it."
fi

if lsof -nP -iTCP:"$API_PORT" -sTCP:LISTEN >/dev/null 2>&1; then
    fail "Port $API_PORT is already in use; refusing to attach the test to an existing service."
fi

TEST_DIR=$(mktemp -d "${TMPDIR:-/tmp}/xlb-m9-recovery.XXXXXX")
TEST_DB="$TEST_DIR/database.sqlite"
touch "$TEST_DB"

printf 'Preparing a disposable local SQLite database...\n'
(
    cd "$PHP_ROOT"
    APP_ENV=testing APP_KEY="$TEST_APP_KEY" APP_CONFIG_CACHE="$TEST_DIR/config.php" \
        DB_CONNECTION=sqlite DB_DATABASE="$TEST_DB" SESSION_DRIVER=database \
        php artisan migrate:fresh --seed --force --no-interaction
)

printf 'Building Android app and instrumentation APK...\n'
(
    cd "$REPO_ROOT/apps/android"
    ./gradlew assembleDebug assembleDebugAndroidTest testDebugUnitTest lintDebug --no-daemon
)

printf 'Starting the isolated API on loopback...\n'
(
    cd "$PHP_ROOT"
    export APP_ENV=testing APP_KEY="$TEST_APP_KEY" APP_CONFIG_CACHE="$TEST_DIR/config.php"
    export DB_CONNECTION=sqlite DB_DATABASE="$TEST_DB" SESSION_DRIVER=database
    exec php artisan serve --host=127.0.0.1 --port="$API_PORT" --no-reload
) >"$TEST_DIR/api.log" 2>&1 &
API_PID=$!

api_ready=false
for _ in $(seq 1 30); do
    if kill -0 "$API_PID" >/dev/null 2>&1; then
        listener_pid=$(lsof -nP -tiTCP:"$API_PORT" -sTCP:LISTEN 2>/dev/null | head -n 1 || true)
        if [[ -n "$listener_pid" ]] && is_api_process_descendant "$listener_pid" \
            && curl --fail --silent --show-error --max-time 2 "$API_URL/api/health" >/dev/null 2>&1; then
            api_ready=true
            break
        fi
    fi
    sleep 1
done
[[ "$api_ready" == "true" ]] || {
    sed -n '1,80p' "$TEST_DIR/api.log" >&2
    fail 'The disposable API did not become ready on loopback.'
}

"$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" reverse "tcp:$API_PORT" "tcp:$API_PORT"
REVERSE_ACTIVE="true"
printf 'Installing test APKs on %s only...\n' "$EMULATOR_SERIAL"
"$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" install -r "$REPO_ROOT/apps/android/app/build/outputs/apk/debug/app-debug.apk"
"$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" install -r "$REPO_ROOT/apps/android/app/build/outputs/apk/androidTest/debug/app-debug-androidTest.apk"

printf 'Running Keystore and anonymous recovery instrumentation...\n'
"$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" shell am instrument -w -r \
    -e disposable_avd true \
    -e isolated_api true \
    com.xlb.vpn.debug.test/androidx.test.runner.AndroidJUnitRunner

printf 'Running proof-bridge and member-session native integration tests...\n'
"$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" shell am instrument -w -r \
    -e disposable_avd true \
    -e class com.xlb.vpn.data.DeviceProofCertificateInstrumentedTest#keystoreSignerConnectsThroughNativeBridgeToAuthorizedIsolatedNodePeer \
    com.xlb.vpn.debug.test/androidx.test.runner.AndroidJUnitRunner
"$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" shell am instrument -w -r \
    -e disposable_avd true \
    -e class com.xlb.vpn.tunnel.MemberTunnelSessionInstrumentedTest#serverAuthorizedGrantLoadsAuthenticatedProfileAndClosesNativeBridgeResources \
    com.xlb.vpn.debug.test/androidx.test.runner.AndroidJUnitRunner
