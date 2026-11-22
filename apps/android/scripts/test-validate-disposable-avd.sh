#!/usr/bin/env bash

set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
test_dir=$(mktemp -d "${TMPDIR:-/tmp}/xlb-avd-guard-test.XXXXXX")
trap 'rm -rf "$test_dir"' EXIT

fake_sdk="$test_dir/sdk"
fake_adb="$fake_sdk/platform-tools/adb"
mkdir -p "$(dirname "$fake_adb")"
cat >"$fake_adb" <<'ADB'
#!/usr/bin/env bash
set -euo pipefail

case "$*" in
    devices)
        printf 'List of devices attached\n'
        case "${FAKE_ADB_MODE:-single}" in
            single) printf 'emulator-5554\tdevice\n' ;;
            multiple) printf 'emulator-5554\tdevice\nA8NMBB5414104798\tdevice\n' ;;
            offline) printf 'emulator-5554\tdevice\nA8NMBB5414104798\toffline\n' ;;
        esac
        ;;
    '-s emulator-5554 emu avd name')
        printf '%s\nOK\n' "${FAKE_ADB_AVD:-xlb-m9-recovery}"
        ;;
    '-s emulator-5554 shell getprop ro.hardware')
        printf '%s\n' "${FAKE_ADB_HARDWARE:-ranchu}"
        ;;
    '-s emulator-5554 shell getprop ro.build.fingerprint')
        printf '%s\n' "${FAKE_ADB_FINGERPRINT:-generic/sdk_gphone64_arm64/emulator:15/AP3A.240905.015/1234567:userdebug/test-keys}"
        ;;
    *)
        printf 'Unexpected fake adb invocation: %s\n' "$*" >&2
        exit 2
        ;;
esac
ADB
chmod +x "$fake_adb"

export ANDROID_HOME="$fake_sdk"

bash "$script_dir/validate-disposable-avd.sh" emulator-5554 >/dev/null

if bash "$script_dir/validate-disposable-avd.sh" >/dev/null 2>&1; then
    printf 'Missing serial was accepted.\n' >&2
    exit 1
fi

if FAKE_ADB_MODE=multiple bash "$script_dir/validate-disposable-avd.sh" emulator-5554 >/dev/null 2>&1; then
    printf 'Multiple online devices were accepted.\n' >&2
    exit 1
fi

if FAKE_ADB_MODE=offline bash "$script_dir/validate-disposable-avd.sh" emulator-5554 >/dev/null 2>&1; then
    printf 'An extra offline physical device was accepted.\n' >&2
    exit 1
fi

if bash "$script_dir/validate-disposable-avd.sh" A8NMBB5414104798 >/dev/null 2>&1; then
    printf 'A serial that does not match the sole online AVD was accepted.\n' >&2
    exit 1
fi

if FAKE_ADB_AVD='not-disposable' bash "$script_dir/validate-disposable-avd.sh" emulator-5554 >/dev/null 2>&1; then
    printf 'A non-dedicated AVD was accepted.\n' >&2
    exit 1
fi

if FAKE_ADB_HARDWARE='generic' FAKE_ADB_FINGERPRINT='vendor/product/device:user/release-keys' \
    bash "$script_dir/validate-disposable-avd.sh" emulator-5554 >/dev/null 2>&1; then
    printf 'A non-emulator device was accepted.\n' >&2
    exit 1
fi

printf 'Disposable AVD guard tests passed.\n'
