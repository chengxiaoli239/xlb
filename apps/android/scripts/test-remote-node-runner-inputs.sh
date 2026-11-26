#!/usr/bin/env bash

set -euo pipefail

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
test_dir="$(mktemp -d "${TMPDIR:-/tmp}/xlb-remote-node-runner-test.XXXXXX")"
trap 'rm -rf "$test_dir"' EXIT

fake_sdk="$test_dir/sdk"
fake_adb="$fake_sdk/platform-tools/adb"
mkdir -p "$(dirname "$fake_adb")"
cat >"$fake_adb" <<'ADB'
#!/usr/bin/env bash
set -euo pipefail

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
    *)
        printf '%s\n' "$*" >>"$FAKE_ADB_LOG"
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
    shift
    output="$test_dir/output.log"

    if "$script_dir/test-remote-node-agent-member-tun-emulator.sh" \
        emulator-5554 xlb-lab-test https://127.0.0.1:19443 https://127.0.0.1 "$@" \
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

invalid_origin_output="$test_dir/invalid-origin.log"
if "$script_dir/test-remote-node-agent-member-tun-emulator.sh" \
    emulator-5554 xlb-lab-test http://127.0.0.1:19443 https://127.0.0.1 54.187.217.237 \
    "$test_dir/node-ca.pem" "$test_dir/admin-cert.pem" "$test_dir/admin-key.pem" \
    >"$invalid_origin_output" 2>&1; then
    printf 'A non-HTTPS control origin was accepted.\n' >&2
    exit 1
fi
grep -Fq 'control URL must use the local HTTPS SSM-forward origin' "$invalid_origin_output"

invalid_node_output="$test_dir/invalid-node.log"
if "$script_dir/test-remote-node-agent-member-tun-emulator.sh" \
    emulator-5554 'xlb-lab-test;touch' https://127.0.0.1:19443 https://127.0.0.1 54.187.217.237 \
    "$test_dir/node-ca.pem" "$test_dir/admin-cert.pem" "$test_dir/admin-key.pem" \
    >"$invalid_node_output" 2>&1; then
    printf 'A shell-injection node ID was accepted.\n' >&2
    exit 1
fi
grep -Fq 'isolated node ID contains unsupported characters' "$invalid_node_output"

if [[ -e "$FAKE_ADB_LOG" ]]; then
    cat "$FAKE_ADB_LOG" >&2
    printf 'Argument validation contacted adb before rejecting unsafe inputs.\n' >&2
    exit 1
fi

printf 'Remote Node Agent runner input guard tests passed.\n'
