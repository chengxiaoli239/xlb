#!/usr/bin/env bash

set -euo pipefail

serial="${1:-}"
android_root="${ANDROID_HOME:-${ANDROID_SDK_ROOT:-}}"
adb_path="${android_root:+$android_root/platform-tools/adb}"
expected_avd="xlb-m9-recovery"

fail() {
    printf 'ERROR: %s\n' "$1" >&2
    exit 1
}

[[ -n "$serial" ]] || fail 'An explicit disposable emulator serial is required.'
[[ -n "$adb_path" && -x "$adb_path" ]] || fail 'A usable Android SDK adb is required.'

listed_devices=$("$adb_path" devices | awk 'NR > 1 && NF >= 2 { print $1 " " $2 }')
device_count=$(printf '%s\n' "$listed_devices" | awk 'NF { count++ } END { print count + 0 }')
[[ "$device_count" -eq 1 ]] || fail 'Exactly one adb device may be online during instrumentation.'
[[ "$listed_devices" == "$serial device" ]] || fail 'The only online adb device must match the explicit serial.'

avd_name=$("$adb_path" -s "$serial" emu avd name | sed -n '1p' | tr -d '\r')
[[ "$avd_name" == "$expected_avd" ]] || fail "The target must be the dedicated $expected_avd AVD."

hardware=$("$adb_path" -s "$serial" shell getprop ro.hardware | tr -d '\r')
fingerprint=$("$adb_path" -s "$serial" shell getprop ro.build.fingerprint | tr '[:upper:]' '[:lower:]' | tr -d '\r')
case "$hardware:$fingerprint" in
    ranchu:*|goldfish:*|*:sdk_gphone*) ;;
    *) fail 'The selected device does not identify as an Android emulator.' ;;
esac

printf 'Validated the only online adb target as %s (%s).\n' "$serial" "$avd_name"
