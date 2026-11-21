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
