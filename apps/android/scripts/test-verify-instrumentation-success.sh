#!/usr/bin/env bash

set -euo pipefail

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
test_dir="$(mktemp -d "${TMPDIR:-/tmp}/xlb-instrumentation-verifier-test.XXXXXX")"
trap 'rm -rf "$test_dir"' EXIT

expect_rejected() {
    output_file=$1
    expected_count=$2
    if "$script_dir/verify-instrumentation-success.sh" "$output_file" "$expected_count" >/dev/null 2>&1; then
        printf 'Instrumentation verifier accepted an invalid result.\n' >&2
        exit 1
    fi
}

printf 'INSTRUMENTATION_STATUS: numtests=1\r\nOK (1 test)\r\nINSTRUMENTATION_CODE: -1\r\n' \
    >"$test_dir/passed.log"
"$script_dir/verify-instrumentation-success.sh" "$test_dir/passed.log" 1

printf 'INSTRUMENTATION_STATUS: numtests=1\nFAILURES!!!\nOK (1 test)\n' >"$test_dir/contradictory.log"
expect_rejected "$test_dir/contradictory.log" 1

printf 'INSTRUMENTATION_STATUS: numtests=1\nINSTRUMENTATION_FAILED: no tests found\n' \
    >"$test_dir/failed.log"
expect_rejected "$test_dir/failed.log" 1

printf 'OK (1 test)\n' >"$test_dir/wrong-count.log"
expect_rejected "$test_dir/wrong-count.log" 2

: >"$test_dir/empty.log"
expect_rejected "$test_dir/empty.log" 1

printf 'Instrumentation success verifier tests passed.\n'
