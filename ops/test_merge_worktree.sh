#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TEST_DIR="$(mktemp -d)"
trap 'rm -rf "$TEST_DIR"' EXIT

MAIN_DIR="$TEST_DIR/main"
TOPIC_DIR="$TEST_DIR/topic"
REMOTE_DIR="$TEST_DIR/origin.git"

git init -q -b main "$MAIN_DIR"
git -C "$MAIN_DIR" config user.name "Merge Worktree Test"
git -C "$MAIN_DIR" config user.email "merge-worktree@example.invalid"
git init -q --bare "$REMOTE_DIR"
git -C "$MAIN_DIR" remote add origin "$REMOTE_DIR"

printf 'base\n' > "$MAIN_DIR/base.txt"
git -C "$MAIN_DIR" add base.txt
git -C "$MAIN_DIR" commit -qm "test: create base"
git -C "$MAIN_DIR" push -qu origin main
git -C "$MAIN_DIR" worktree add -qb topic "$TOPIC_DIR" main

printf 'same patch\n' > "$TOPIC_DIR/shared.txt"
git -C "$TOPIC_DIR" add shared.txt
git -C "$TOPIC_DIR" commit -qm "test: add equivalent patch"
printf 'same patch\n' > "$MAIN_DIR/shared.txt"
git -C "$MAIN_DIR" add shared.txt
git -C "$MAIN_DIR" commit -qm "test: add equivalent patch independently"
git -C "$MAIN_DIR" push -qu origin main

printf 'topic result\n' > "$TOPIC_DIR/topic.txt"
git -C "$TOPIC_DIR" add topic.txt
git -C "$TOPIC_DIR" commit -qm "test: add unique patch"

mkdir -p "$MAIN_DIR/ops"
cp "$SCRIPT_DIR/merge-worktree.sh" "$MAIN_DIR/ops/merge-worktree.sh"
(
  cd "$MAIN_DIR"
  ./ops/merge-worktree.sh topic >/dev/null
)

git -C "$MAIN_DIR" show origin/main:topic.txt >/dev/null
test "$(git -C "$MAIN_DIR" rev-list --count origin/main)" -eq 3
echo "merge-worktree equivalent-patch test passed"

# A failed push must be recoverable by rerunning the same branch.
git -C "$MAIN_DIR" worktree add -qb retry "$TEST_DIR/retry" main
printf 'retry result\n' > "$TEST_DIR/retry/retry.txt"
git -C "$TEST_DIR/retry" add retry.txt
git -C "$TEST_DIR/retry" commit -qm "test: retry push"
printf '#!/bin/sh\nexit 1\n' > "$REMOTE_DIR/hooks/pre-receive"
chmod +x "$REMOTE_DIR/hooks/pre-receive"
if (cd "$MAIN_DIR" && ./ops/merge-worktree.sh retry >"$TEST_DIR/rejected.log" 2>&1); then
  echo "expected initial push rejection" >&2
  exit 1
fi
rm "$REMOTE_DIR/hooks/pre-receive"
(cd "$MAIN_DIR" && ./ops/merge-worktree.sh retry >/dev/null)
git -C "$MAIN_DIR" show origin/main:retry.txt >/dev/null
test "$(git -C "$MAIN_DIR" rev-parse main)" = "$(git -C "$MAIN_DIR" rev-parse origin/main)"
echo "merge-worktree failed-push retry test passed"

# Merges may carry conflict-resolution-only changes; reject before mutation.
git -C "$MAIN_DIR" worktree add -qb merge-topic "$TEST_DIR/merge-topic" main
git -C "$MAIN_DIR" worktree add -qb merge-side "$TEST_DIR/merge-side" main
printf 'topic conflict\n' > "$TEST_DIR/merge-topic/base.txt"
git -C "$TEST_DIR/merge-topic" commit -qam "test: topic change"
printf 'side conflict\n' > "$TEST_DIR/merge-side/base.txt"
git -C "$TEST_DIR/merge-side" commit -qam "test: side change"
if git -C "$TEST_DIR/merge-topic" merge merge-side >"$TEST_DIR/conflict.log" 2>&1; then
  echo "expected a merge conflict fixture" >&2
  exit 1
fi
printf 'merge-only resolution\n' > "$TEST_DIR/merge-topic/base.txt"
git -C "$TEST_DIR/merge-topic" add base.txt
git -C "$TEST_DIR/merge-topic" commit -qm "test: merge-only resolution"
MAIN_BEFORE="$(git -C "$MAIN_DIR" rev-parse main)"
if (cd "$MAIN_DIR" && ./ops/merge-worktree.sh merge-topic >"$TEST_DIR/merge-rejected.log" 2>&1); then
  echo "expected non-linear branch rejection" >&2
  exit 1
fi
test "$(git -C "$MAIN_DIR" rev-parse main)" = "$MAIN_BEFORE"
test "$(git -C "$MAIN_DIR" rev-parse origin/main)" = "$MAIN_BEFORE"
echo "merge-worktree non-linear branch rejection test passed"
