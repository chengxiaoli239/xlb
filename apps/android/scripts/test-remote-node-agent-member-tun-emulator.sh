#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
ANDROID_ROOT="${ANDROID_HOME:-${ANDROID_SDK_ROOT:-}}"
ADB_PATH="${ANDROID_ROOT:+$ANDROID_ROOT/platform-tools/adb}"
REPO_ROOT=$(cd -- "$SCRIPT_DIR/../../.." && pwd)
ANDROID_ROOT_DIR="$REPO_ROOT/apps/android"
APP_PACKAGE='com.xlb.vpn.debug'
TEST_PACKAGE='com.xlb.vpn.debug.test'
TEST_RUNNER="$TEST_PACKAGE/androidx.test.runner.AndroidJUnitRunner"
TEST_CLASS='com.xlb.vpn.data.RemoteNodeAgentMemberTunInstrumentedTest#keystoreProofCarriesTunTrafficThroughIsolatedAwsNodeAndRevokesWithReceipt'
EXPECTED_AVD='xlb-m9-recovery'

fail() {
    printf 'ERROR: %s\n' "$1" >&2
    exit 1
}

usage() {
    printf 'Usage: %s <serial> <node-id> <control-https-loopback-origin> <bridge-https-origin> <expected-egress-ip> <node-ca.pem> <admin-cert.pem> <admin-key.pem>\n' "$0" >&2
    exit 2
}

[[ $# -eq 8 ]] || usage

SERIAL=$1
NODE_ID=$2
CONTROL_ORIGIN=$3
BRIDGE_ORIGIN=$4
EXPECTED_EGRESS_IP=$5
NODE_CA=$6
ADMIN_CERT=$7
ADMIN_KEY=$8

[[ -n "$ANDROID_ROOT" && -x "$ADB_PATH" ]] || fail 'Set ANDROID_HOME or ANDROID_SDK_ROOT to a usable Android SDK.'
[[ "$SERIAL" =~ ^[A-Za-z0-9._:-]+$ ]] || fail 'The emulator serial contains unsupported characters.'
[[ "$NODE_ID" =~ ^[A-Za-z0-9._-]{1,64}$ ]] || fail 'The isolated node ID contains unsupported characters.'
[[ "$CONTROL_ORIGIN" =~ ^https://127\.0\.0\.1:([0-9]{1,5})$ ]] || fail 'The control URL must use the local HTTPS SSM-forward origin https://127.0.0.1:<port>.'
CONTROL_PORT=${BASH_REMATCH[1]}
[[ "$BRIDGE_ORIGIN" =~ ^https://[A-Za-z0-9.-]+(:[0-9]{1,5})?$ ]] || fail 'The bridge URL must be an HTTPS origin.'

python3 - "$EXPECTED_EGRESS_IP" <<'PY'
import ipaddress
import sys

try:
    address = ipaddress.ip_address(sys.argv[1])
except ValueError:
    raise SystemExit('ERROR: expected egress IP must be a valid address.')
if address.version != 4 or not address.is_global:
    raise SystemExit('ERROR: expected egress IP must be a globally routable IPv4 address.')
PY

for credential_file in "$NODE_CA" "$ADMIN_CERT" "$ADMIN_KEY"; do
    [[ -f "$credential_file" && ! -L "$credential_file" ]] || fail 'TLS input files must be regular files, not symlinks.'
done

"$SCRIPT_DIR/validate-disposable-avd.sh" "$SERIAL"

python3 - "$CONTROL_PORT" <<'PY'
import socket
import sys

try:
    port = int(sys.argv[1])
except ValueError:
    raise SystemExit('ERROR: the local SSM-forward port is invalid.')
if not 1 <= port <= 65535:
    raise SystemExit('ERROR: the local SSM-forward port must be between 1 and 65535.')
try:
    with socket.create_connection(('127.0.0.1', port), timeout=2):
        pass
except OSError as error:
    raise SystemExit(f'ERROR: no local SSM port-forward is listening on 127.0.0.1:{port} ({type(error).__name__}).')
PY

printf 'Building the Debug app and AndroidTest APK...\n'
(
    cd "$ANDROID_ROOT_DIR"
    ./gradlew assembleDebug assembleDebugAndroidTest testDebugUnitTest lintDebug
)

"$ADB_PATH" -s "$SERIAL" install -r \
    "$ANDROID_ROOT_DIR/app/build/outputs/apk/debug/app-debug.apk" >/dev/null
"$ADB_PATH" -s "$SERIAL" install -r \
    "$ANDROID_ROOT_DIR/app/build/outputs/apk/androidTest/debug/app-debug-androidTest.apk" >/dev/null

test_token=$(python3 -c 'import secrets; print(secrets.token_hex(8))')
host_stage=$(mktemp -d "${TMPDIR:-/tmp}/xlb-node-test.XXXXXX")
device_stage="/data/local/tmp/xlb-node-test-$test_token"
app_stage="cache/xlb-node-test-$test_token"
control_reverse_added=false
cleanup() {
    "$ADB_PATH" -s "$SERIAL" shell am force-stop "$APP_PACKAGE" >/dev/null 2>&1 || true
    "$ADB_PATH" -s "$SERIAL" shell run-as "$APP_PACKAGE" rm -rf "$app_stage" >/dev/null 2>&1 || true
    "$ADB_PATH" -s "$SERIAL" shell rm -rf "$device_stage" >/dev/null 2>&1 || true
    if [[ "$control_reverse_added" == 'true' ]]; then
        "$ADB_PATH" -s "$SERIAL" reverse --remove "tcp:$CONTROL_PORT" >/dev/null 2>&1 || true
    fi
    rm -rf "$host_stage"
}
trap cleanup EXIT

existing_reverse=$($ADB_PATH -s "$SERIAL" reverse --list)
if printf '%s\n' "$existing_reverse" | awk -v port="tcp:$CONTROL_PORT" '$1 == port || $2 == port { found = 1 } END { exit !found }'; then
    fail "The dedicated AVD already has a reverse mapping for tcp:$CONTROL_PORT; refusing to replace it."
fi
control_reverse_added=true
"$ADB_PATH" -s "$SERIAL" reverse "tcp:$CONTROL_PORT" "tcp:$CONTROL_PORT"

cp -- "$NODE_CA" "$host_stage/node-ca.pem"
cp -- "$ADMIN_CERT" "$host_stage/admin-cert.pem"
cp -- "$ADMIN_KEY" "$host_stage/admin-key.pem"
chmod 600 "$host_stage/admin-key.pem"

"$ADB_PATH" -s "$SERIAL" shell mkdir -p "$device_stage"
"$ADB_PATH" -s "$SERIAL" push "$host_stage/node-ca.pem" "$device_stage/node-ca.pem" >/dev/null
"$ADB_PATH" -s "$SERIAL" push "$host_stage/admin-cert.pem" "$device_stage/admin-cert.pem" >/dev/null
"$ADB_PATH" -s "$SERIAL" push "$host_stage/admin-key.pem" "$device_stage/admin-key.pem" >/dev/null
"$ADB_PATH" -s "$SERIAL" shell run-as "$APP_PACKAGE" mkdir -p "$app_stage"
for file_name in node-ca.pem admin-cert.pem admin-key.pem; do
    "$ADB_PATH" -s "$SERIAL" shell run-as "$APP_PACKAGE" sh -c \
        "cat '$device_stage/$file_name' > '$app_stage/$file_name'"
done
"$ADB_PATH" -s "$SERIAL" shell run-as "$APP_PACKAGE" chmod 600 "$app_stage/admin-key.pem"
"$ADB_PATH" -s "$SERIAL" shell rm -rf "$device_stage"

printf 'Running isolated Node Agent member TUN instrumentation on %s...\n' "$SERIAL"
"$ADB_PATH" -s "$SERIAL" shell am instrument -w -r \
    -e disposable_avd true \
    -e node_id "$NODE_ID" \
    -e control_url "$CONTROL_ORIGIN" \
    -e bridge_endpoint "$BRIDGE_ORIGIN" \
    -e expected_egress_ip "$EXPECTED_EGRESS_IP" \
    -e node_ca_file "/data/user/0/$APP_PACKAGE/$app_stage/node-ca.pem" \
    -e admin_cert_file "/data/user/0/$APP_PACKAGE/$app_stage/admin-cert.pem" \
    -e admin_key_file "/data/user/0/$APP_PACKAGE/$app_stage/admin-key.pem" \
    -e class "$TEST_CLASS" \
    "$TEST_RUNNER"
