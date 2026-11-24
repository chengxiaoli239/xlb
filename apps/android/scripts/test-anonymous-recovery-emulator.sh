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
INSTRUMENTATION_PID=""
REVERSE_ACTIVE="false"
INSTRUMENTATION_LOG=""
LOCAL_MEMBER_PASSWORD="${LOCAL_MEMBER_PASSWORD:-}"
PROCESS_DEATH_ONLY="${PROCESS_DEATH_ONLY:-false}"

fail() {
    printf 'ERROR: %s\n' "$1" >&2
    exit 1
}

cleanup() {
    if [[ "$REVERSE_ACTIVE" == "true" ]]; then
        current_reverse=$("$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" reverse --list 2>/dev/null || true)
        if printf '%s\n' "$current_reverse" | awk '$2 == "tcp:4175" && $3 == "tcp:4181" { found = 1 } END { exit !found }'; then
            "$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" reverse --remove tcp:4175 >/dev/null 2>&1 || true
        fi
        if printf '%s\n' "$current_reverse" | awk -v remote="tcp:$API_PORT" '$2 == remote && $3 == remote { found = 1 } END { exit !found }'; then
            "$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" reverse --remove "tcp:$API_PORT" >/dev/null 2>&1 || true
        fi
    fi
    if [[ -n "$API_PID" ]]; then
        kill "$API_PID" >/dev/null 2>&1 || true
        wait "$API_PID" >/dev/null 2>&1 || true
    fi
    if [[ -n "$INSTRUMENTATION_PID" ]]; then
        kill "$INSTRUMENTATION_PID" >/dev/null 2>&1 || true
        wait "$INSTRUMENTATION_PID" >/dev/null 2>&1 || true
    fi
    if [[ -n "$TEST_DIR" && "$TEST_DIR" == "${TMPDIR:-/tmp}/xlb-m9-recovery."* ]]; then
        rm -rf "$TEST_DIR"
    fi
}

run_instrumentation() {
    local description="$1"
    shift
    INSTRUMENTATION_LOG="$TEST_DIR/instrumentation.log"
    printf '%s\n' "$description"
    if ! "$@" 2>&1 | tee "$INSTRUMENTATION_LOG"; then
        fail "$description failed to launch."
    fi
    if ! grep -Eq 'OK \([1-9][0-9]* tests?\)' "$INSTRUMENTATION_LOG"; then
        fail "$description reported an instrumentation failure."
    fi
}

run_process_death_instrumentation() {
    local description='Running member authorization recovery after target app process death...'
    local host_package='com.xlb.vpn.processdeathhost'
    local test_package='com.xlb.vpn.processdeathhost.test'
    local target_package='com.xlb.vpn.debug'
    local ready_marker='permission-process-death-ready'
    local continue_marker='permission-process-death-continue'
    local ready=false
    local target_pid=''
    local runner_pid=''
    printf '%s\n' "$description"
    INSTRUMENTATION_LOG="$TEST_DIR/instrumentation.log"
    "$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" shell am instrument -w -r \
        -e member_email 'member@xlb.local' \
        -e member_password "$LOCAL_MEMBER_PASSWORD" \
        -e class com.xlb.vpn.processdeathhost.MemberPermissionProcessDeathTest#memberPermissionRequestRecoversAfterTargetProcessDeathAndNextLaunch \
        "$test_package/androidx.test.runner.AndroidJUnitRunner" >"$INSTRUMENTATION_LOG" 2>&1 &
    INSTRUMENTATION_PID=$!

    for _ in $(seq 1 60); do
        if ! kill -0 "$INSTRUMENTATION_PID" >/dev/null 2>&1; then
            cat "$INSTRUMENTATION_LOG" >&2
            fail "$description ended before publishing its host handshake."
        fi
        if "$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" shell \
            run-as "$host_package" test -f "files/$ready_marker" >/dev/null 2>&1; then
            ready=true
            break
        fi
        sleep 1
    done
    [[ "$ready" == 'true' ]] || {
        cat "$INSTRUMENTATION_LOG" >&2
        fail 'The process-death instrumentation did not reach the VPN permission dialog.'
    }

    target_pid=$("$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" shell pidof "$target_package" | tr -d '\r')
    [[ "$target_pid" =~ ^[0-9]+$ ]] || fail 'Could not identify the exact Android target-app PID.'
    "$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" shell kill -9 "$target_pid"

    for _ in $(seq 1 100); do
        current_target_pid=$("$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" shell pidof "$target_package" 2>/dev/null | tr -d '\r' || true)
        if [[ -z "$current_target_pid" ]]; then
            break
        fi
        sleep 0.1
    done
    [[ -z "${current_target_pid:-}" ]] || fail "The target process survived termination. pid=$current_target_pid"

    runner_pid=$("$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" shell pidof "$host_package" | tr -d '\r')
    [[ "$runner_pid" =~ ^[0-9]+$ ]] || fail 'The instrumentation runner did not survive target-app termination.'
    "$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" shell \
        run-as "$host_package" touch "files/$continue_marker"

    if ! wait "$INSTRUMENTATION_PID"; then
        cat "$INSTRUMENTATION_LOG" >&2
        fail "$description failed."
    fi
    INSTRUMENTATION_PID=''
    if ! grep -Eq 'OK \([1-9][0-9]* tests?\)' "$INSTRUMENTATION_LOG"; then
        cat "$INSTRUMENTATION_LOG" >&2
        fail "$description reported an instrumentation failure."
    fi
}

run_denied_permission_instrumentation() {
    run_instrumentation 'Running denied VPN permission callback regression on a fresh AVD...' \
        "$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" shell am instrument -w -r \
        -e disposable_avd true \
        -e class com.xlb.vpn.tunnel.PlatformApiMemberGrantInstrumentedTest#deniedPermissionCallbackDoesNotReauthorizeOrStartMemberService \
        com.xlb.vpn.debug.test/androidx.test.runner.AndroidJUnitRunner
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
if [[ -z "$LOCAL_MEMBER_PASSWORD" ]]; then
    LOCAL_MEMBER_PASSWORD=$(cd "$PHP_ROOT" && php -r 'require "vendor/autoload.php"; Dotenv\Dotenv::createImmutable(getcwd())->safeLoad(); echo $_ENV["LOCAL_MEMBER_PASSWORD"] ?? "";')
fi
if [[ -z "$LOCAL_MEMBER_PASSWORD" ]]; then
    LOCAL_MEMBER_PASSWORD=$(sed -n "s/.*env('LOCAL_MEMBER_PASSWORD', '\([^']*\)').*/\1/p" \
        "$PHP_ROOT/database/seeders/DatabaseSeeder.php" | head -n 1)
fi
[[ -n "$LOCAL_MEMBER_PASSWORD" ]] || fail 'Set LOCAL_MEMBER_PASSWORD locally or in apps/php-api/.env for the sandbox member fixture.'
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
"$SCRIPT_DIR/validate-disposable-avd.sh" "$EMULATOR_SERIAL"
"$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" root >/dev/null
"$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" wait-for-device
[[ "$("$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" shell id -u | tr -d '\r')" == '0' ]] \
    || fail 'The dedicated recovery AVD must provide root shell access for process-death verification.'

REVERSE_LIST=$("$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" reverse --list)
if printf '%s\n' "$REVERSE_LIST" | awk -v remote="tcp:$API_PORT" '$2 == remote { found = 1 } END { exit !found }'; then
    fail "The dedicated emulator already has a reverse mapping for tcp:$API_PORT; refusing to replace it."
fi
if printf '%s\n' "$REVERSE_LIST" | awk '$2 == "tcp:4175" { found = 1 } END { exit !found }'; then
    fail 'The dedicated emulator already has a reverse mapping for tcp:4175; refusing to replace it.'
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
    ./gradlew assembleDebug assembleDebugAndroidTest testDebugUnitTest lintDebug \
        :process-death-host:assembleDebug :process-death-host:assembleDebugAndroidTest --no-daemon
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
"$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" uninstall com.xlb.vpn.debug.test >/dev/null 2>&1 || true
"$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" uninstall com.xlb.vpn.debug >/dev/null 2>&1 || true
"$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" install -r "$REPO_ROOT/apps/android/app/build/outputs/apk/debug/app-debug.apk"
"$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" install -r "$REPO_ROOT/apps/android/app/build/outputs/apk/androidTest/debug/app-debug-androidTest.apk"
"$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" install -r "$REPO_ROOT/apps/android/process-death-host/build/outputs/apk/debug/process-death-host-debug.apk"
"$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" install -r "$REPO_ROOT/apps/android/process-death-host/build/outputs/apk/androidTest/debug/process-death-host-debug-androidTest.apk"

if [[ "$PROCESS_DEATH_ONLY" == 'true' ]]; then
    run_process_death_instrumentation
    exit 0
fi

run_denied_permission_instrumentation

run_instrumentation 'Running Keystore and anonymous recovery instrumentation...' \
    "$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" shell am instrument -w -r \
    -e disposable_avd true \
    -e isolated_api true \
    -e notAnnotation androidx.test.filters.LargeTest \
    com.xlb.vpn.debug.test/androidx.test.runner.AndroidJUnitRunner

run_instrumentation 'Running proof-bridge and member-session native integration tests...' \
    "$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" shell am instrument -w -r \
    -e disposable_avd true \
    -e class com.xlb.vpn.data.DeviceProofCertificateInstrumentedTest#keystoreSignerConnectsThroughNativeBridgeToAuthorizedIsolatedNodePeer \
    com.xlb.vpn.debug.test/androidx.test.runner.AndroidJUnitRunner
run_instrumentation 'Running server-authorized member tunnel session test...' \
    "$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" shell am instrument -w -r \
    -e disposable_avd true \
    -e class com.xlb.vpn.tunnel.MemberTunnelSessionInstrumentedTest#serverAuthorizedGrantLoadsAuthenticatedProfileAndClosesNativeBridgeResources \
    com.xlb.vpn.debug.test/androidx.test.runner.AndroidJUnitRunner
run_instrumentation 'Running isolated Android system TUN and proof-bridge echo test...' \
    "$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" shell am instrument -w -r \
    -e disposable_avd true \
    -e class com.xlb.vpn.tunnel.MemberTunnelTunInstrumentedTest#authorizedFixturePeerCarriesTcpEchoThroughSystemTunWithoutConnectedState \
    com.xlb.vpn.debug.test/androidx.test.runner.AndroidJUnitRunner
run_instrumentation 'Running authorization refresh and pending-grant lifecycle test...' \
    "$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" shell am instrument -w -r \
    -e disposable_avd true \
    -e class com.xlb.vpn.tunnel.PlatformApiMemberGrantInstrumentedTest#authorizedSyncGrantIsConsumedByClosedServiceGateAndClearedOnLogout \
    com.xlb.vpn.debug.test/androidx.test.runner.AndroidJUnitRunner
run_instrumentation 'Running permission callback with revoked fresh authorization test...' \
    "$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" shell am instrument -w -r \
    -e disposable_avd true \
    -e class com.xlb.vpn.tunnel.PlatformApiMemberGrantInstrumentedTest#approvedPermissionCallbackWithRevokedAuthorizationDoesNotPublishGrantOrServiceState \
    com.xlb.vpn.debug.test/androidx.test.runner.AndroidJUnitRunner
run_instrumentation 'Running profile digest rejection test...' \
    "$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" shell am instrument -w -r \
    -e disposable_avd true \
    -e class com.xlb.vpn.tunnel.PlatformApiMemberGrantInstrumentedTest#profileDigestMismatchNeverPublishesReadyGrant \
    com.xlb.vpn.debug.test/androidx.test.runner.AndroidJUnitRunner

printf 'Resetting the disposable app before the isolated process-death recovery stage...\n'
"$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" uninstall com.xlb.vpn.debug >/dev/null 2>&1 || true
"$ANDROID_ROOT/platform-tools/adb" -s "$EMULATOR_SERIAL" install -r "$REPO_ROOT/apps/android/app/build/outputs/apk/debug/app-debug.apk"

run_process_death_instrumentation
