#!/usr/bin/env bash

set -euo pipefail

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
test_dir="$(mktemp -d "${TMPDIR:-/tmp}/xlb-remote-node-runner-test.XXXXXX")"
ssm_listener_pid=''
cleanup() {
    if [[ -n "$ssm_listener_pid" ]]; then
        kill "$ssm_listener_pid" >/dev/null 2>&1 || true
        wait "$ssm_listener_pid" >/dev/null 2>&1 || true
    fi
    rm -rf "$test_dir"
}
trap cleanup EXIT

fake_sdk="$test_dir/sdk"
fake_adb="$fake_sdk/platform-tools/adb"
mkdir -p "$(dirname "$fake_adb")"
cat >"$fake_adb" <<'ADB'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >>"$FAKE_ADB_LOG"

case "$*" in
    devices)
        printf 'List of devices attached\nemulator-5554\tdevice\n'
        ;;
    '-s emulator-5554 emu avd name')
        printf 'xlb-m9-recovery\nOK\n'
        ;;
    '-s emulator-5554 shell getprop ro.hardware')
        printf 'ranchu\n'
        ;;
    '-s emulator-5554 shell getprop ro.build.fingerprint')
        printf 'generic/sdk_gphone64_arm64/emulator:15/AP3A.240905.015/1234567:userdebug/test-keys\n'
        ;;
    '-s emulator-5554 reverse --list')
        printf 'emulator-5554 tcp:%s tcp:%s\n' "$FAKE_REVERSE_PORT" "$FAKE_REVERSE_PORT"
        ;;
    *)
        exit 97
        ;;
esac
ADB
chmod +x "$fake_adb"

touch "$test_dir/node-ca.pem" "$test_dir/admin-cert.pem" "$test_dir/admin-key.pem"
export ANDROID_HOME="$fake_sdk"
export FAKE_ADB_LOG="$test_dir/adb.log"

expect_rejected_before_adb() {
    expected_message=$1
    expected_egress_ip=${2:-54.187.217.237}
    probe_egress_ip=${3:-54.187.217.237}
    output="$test_dir/output.log"

    if "$script_dir/test-remote-node-agent-member-tun-emulator.sh" \
        emulator-5554 xlb-lab-test-node \
        https://127.0.0.1:19443 https://127.0.0.1 "$expected_egress_ip" "$probe_egress_ip" \
        "$test_dir/node-ca.pem" "$test_dir/admin-cert.pem" "$test_dir/admin-key.pem" \
        >"$output" 2>&1; then
        printf 'Unsafe runner arguments were accepted.\n' >&2
        exit 1
    fi

    if ! grep -Fq "$expected_message" "$output"; then
        cat "$output" >&2
        printf 'Runner rejected arguments for an unexpected reason.\n' >&2
        exit 1
    fi
}

expect_rejected_before_adb 'globally routable IPv4' 192.0.2.1
expect_rejected_before_adb 'globally routable IPv4' 54.187.217.237 192.0.2.1

invalid_origin_output="$test_dir/invalid-origin.log"
if "$script_dir/test-remote-node-agent-member-tun-emulator.sh" \
    emulator-5554 xlb-lab-test-node http://127.0.0.1:19443 https://127.0.0.1 54.187.217.237 \
    54.187.217.237 "$test_dir/node-ca.pem" "$test_dir/admin-cert.pem" "$test_dir/admin-key.pem" \
    >"$invalid_origin_output" 2>&1; then
    printf 'A non-HTTPS control origin was accepted.\n' >&2
    exit 1
fi
grep -Fq 'control URL must use the local HTTPS SSM-forward origin' "$invalid_origin_output"

invalid_node_output="$test_dir/invalid-node.log"
if "$script_dir/test-remote-node-agent-member-tun-emulator.sh" \
    emulator-5554 'xlb-lab-test-node;touch' https://127.0.0.1:19443 https://127.0.0.1 54.187.217.237 \
    54.187.217.237 "$test_dir/node-ca.pem" "$test_dir/admin-cert.pem" "$test_dir/admin-key.pem" \
    >"$invalid_node_output" 2>&1; then
    printf 'A shell-injection node ID was accepted.\n' >&2
    exit 1
fi
grep -Fq 'isolated node ID contains unsupported characters' "$invalid_node_output"

listener_port_file="$test_dir/ssm-listener-port"
python3 - "$listener_port_file" <<'PY' &
import socket
import sys
import time

listener = socket.socket()
listener.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
listener.bind(('127.0.0.1', 0))
listener.listen()
with open(sys.argv[1], 'w', encoding='ascii') as port_file:
    port_file.write(str(listener.getsockname()[1]))
while True:
    connection, _ = listener.accept()
    connection.close()
PY
ssm_listener_pid=$!
for _ in $(seq 1 50); do
    [[ -s "$listener_port_file" ]] && break
    sleep 0.1
done
[[ -s "$listener_port_file" ]] || { printf 'Local SSM listener fixture did not start.\n' >&2; exit 1; }
control_port=$(cat "$listener_port_file")
export FAKE_REVERSE_PORT="$control_port"

reverse_conflict_output="$test_dir/reverse-conflict.log"
if "$script_dir/test-remote-node-agent-member-tun-emulator.sh" \
    emulator-5554 xlb-lab-test-node "https://127.0.0.1:$control_port" https://54.187.217.237 54.187.217.237 54.187.217.237 \
    "$test_dir/node-ca.pem" "$test_dir/admin-cert.pem" "$test_dir/admin-key.pem" \
    >"$reverse_conflict_output" 2>&1; then
    printf 'An existing ADB reverse mapping was accepted and replaced.\n' >&2
    exit 1
fi
grep -Fq 'already has a reverse mapping' "$reverse_conflict_output"

if grep -Eq 'install -r|reverse tcp:' "$FAKE_ADB_LOG"; then
    cat "$FAKE_ADB_LOG" >&2
    printf 'Runner installed an APK or changed an ADB mapping before refusing the conflict.\n' >&2
    exit 1
fi

printf 'Remote Node Agent runner input guard tests passed.\n'
