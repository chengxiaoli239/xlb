#!/usr/bin/env bash
# 把一个已完成阶段的 worktree 分支合并回 main 并推送远程。
#
# 用法:
#   ./ops/merge-worktree.sh <branch> [commit-message]
#
# 流程（每个阶段完成即执行，避免并行成果滞留未提交）:
#   1. 校验分支对应 worktree 存在且工作区干净（无未提交改动）。
#   2. 在 main 上 fetch 远程最新，确认 origin/main 是 main 的祖先。
#   3. 把该分支相对 merge-base 的提交按序 cherry-pick 到 main（保留逐阶段历史）。
#   4. push main 到 origin。
#
# 前置条件：开发分支应已在自己的 worktree 内 commit 完本阶段改动。
# 无冲突时全自动；有冲突时停下并提示人工处理（不会强行提交）。
set -euo pipefail

BRANCH="${1:-}"
MSG="${2:-}"

die() { echo "ERROR: $*" >&2; exit 1; }

[ -n "$BRANCH" ] || die "用法: $0 <branch> [commit-message]"

ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"

# 找到该分支的 worktree 路径
WT_PATH="$(git worktree list --porcelain | awk -v b="$BRANCH" '
  /^worktree / { p=$2 }
  /^branch refs\/heads\// { if ($2 == "refs/heads/" b) print p }
')"
[ -n "$WT_PATH" ] || die "找不到分支 $BRANCH 对应的 worktree"

# 可选：如果给了 commit message，先在 worktree 内提交改动
if [ -n "$MSG" ]; then
  echo "[1/4] 在 $WT_PATH 提交本阶段改动 ..."
  git -C "$WT_PATH" add -A
  if git -C "$WT_PATH" diff --cached --quiet; then
    echo "      无改动可提交，跳过 commit。"
  else
    git -C "$WT_PATH" commit -m "$MSG"
  fi
fi

# 确认 worktree 干净
[ -z "$(git -C "$WT_PATH" status --porcelain)" ] || die "$WT_PATH 仍有未提交改动，先提交再合并"

echo "[2/4] fetch 远程并校验 main ..."
git checkout main -q
git fetch origin -q
git merge-base --is-ancestor origin/main main || die "main 与 origin/main 分叉，请先人工 rebase"

# 收集该分支相对 main 的独有提交（按时间正序）
COMMITS="$(git rev-list --reverse main.."$BRANCH")"
[ -n "$COMMITS" ] || die "分支 $BRANCH 没有相对 main 的新提交"

echo "[3/4] cherry-pick $(echo "$COMMITS" | wc -l | tr -d ' ') 个提交到 main ..."
for c in $COMMITS; do
  echo "      -> $(git show -s --format='%h %s' "$c")"
  git cherry-pick "$c" || die "cherry-pick $c 冲突：请手动解决后 git cherry-pick --continue"
done

echo "[4/4] push main ..."
git push origin main

echo "完成。main 现在指向 $(git rev-parse --short HEAD)。worktree $BRANCH 可按需 git worktree remove。"
