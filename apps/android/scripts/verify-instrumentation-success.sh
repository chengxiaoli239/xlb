#!/usr/bin/env bash

set -euo pipefail

fail() {
    printf 'ERROR: %s\n' "$1" >&2
    exit 1
}

[[ $# -eq 2 ]] || fail 'Usage: verify-instrumentation-success.sh <log-file> <expected-test-count>'
log_file=$1
expected_count=$2

[[ -f "$log_file" && ! -L "$log_file" ]] || fail 'Instrumentation output must be a regular file.'
[[ "$expected_count" =~ ^[1-9][0-9]{0,3}$ ]] || fail 'Expected test count must be between 1 and 9999.'

normalized_log=$(mktemp "${TMPDIR:-/tmp}/xlb-instrumentation-log.XXXXXX")
trap 'rm -f "$normalized_log"' EXIT
tr -d '\r' <"$log_file" >"$normalized_log"

summary_count=$(grep -Ec "^[[:space:]]*OK \($expected_count tests?\)[[:space:]]*$" "$normalized_log" || true)
[[ "$summary_count" == 1 ]] || fail "Instrumentation log must contain exactly one OK summary for $expected_count test(s)."
if grep -Eq 'FAILURES!!!|INSTRUMENTATION_FAILED|INSTRUMENTATION_ABORTED|Process crashed' "$normalized_log"; then
    fail 'Instrumentation output contains a failure or aborted-run marker.'
fi
