#!/usr/bin/env python3
"""Write a local manifest tying build artifacts to a clean source commit."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


def git_output(repo_root: Path, *args: str) -> str:
    result = subprocess.run(
        ["git", *args],
        cwd=repo_root,
        check=True,
        capture_output=True,
        text=True,
    )
    return result.stdout.strip()


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as artifact:
        for chunk in iter(lambda: artifact.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def resolve_repo_path(repo_root: Path, raw_path: str, *, must_exist: bool) -> Path:
    candidate = Path(raw_path)
    resolved = (candidate if candidate.is_absolute() else repo_root / candidate).resolve(
        strict=must_exist
    )
    if not resolved.is_relative_to(repo_root):
        raise ValueError(f"path must be inside the repository: {raw_path}")
    return resolved


def ignored_by_git(repo_root: Path, path: Path) -> bool:
    relative_path = path.relative_to(repo_root).as_posix()
    result = subprocess.run(
        ["git", "check-ignore", "-q", "--", relative_path],
        cwd=repo_root,
        capture_output=True,
        text=True,
        check=False,
    )
    if result.returncode not in (0, 1):
        raise ValueError("could not verify that manifest output is ignored by Git")
    return result.returncode == 0


def artifact_entry(repo_root: Path, raw_path: str) -> dict[str, Any]:
    path = resolve_repo_path(repo_root, raw_path, must_exist=True)
    if not path.is_file():
        raise ValueError(f"artifact is not a regular file: {raw_path}")
    return {
        "path": path.relative_to(repo_root).as_posix(),
        "size_bytes": path.stat().st_size,
        "sha256": sha256_file(path),
    }


def create_manifest(repo_root: Path, artifact_paths: list[str]) -> dict[str, Any]:
    dirty = git_output(repo_root, "status", "--porcelain=v1", "--untracked-files=all")
    if dirty:
        raise ValueError(
            "tracked changes and non-ignored untracked files must be cleared before manifest creation"
        )

    commit = git_output(repo_root, "rev-parse", "HEAD")
    if not re.fullmatch(r"[0-9a-f]{40,64}", commit):
        raise ValueError("HEAD is not a valid Git commit id")

    artifacts = [artifact_entry(repo_root, raw_path) for raw_path in artifact_paths]
    artifact_paths_in_manifest = [entry["path"] for entry in artifacts]
    if len(set(artifact_paths_in_manifest)) != len(artifact_paths_in_manifest):
        raise ValueError("artifact paths must be unique")

    run_id = os.environ.get("GITHUB_RUN_ID")
    run_attempt = os.environ.get("GITHUB_RUN_ATTEMPT")
    return {
        "schema_version": 1,
        "source": {
            "commit": commit,
            "ref": os.environ.get("GITHUB_REF") or None,
        },
        "build": {
            "created_at_utc": datetime.now(timezone.utc)
            .replace(microsecond=0)
            .isoformat()
            .replace("+00:00", "Z"),
            "run_id": run_id if run_id and run_id.isdecimal() else None,
            "run_attempt": (
                int(run_attempt)
                if run_attempt and run_attempt.isdecimal()
                else None
            ),
        },
        "artifacts": sorted(artifacts, key=lambda entry: entry["path"]),
    }


def main() -> int:
    default_root = Path(__file__).resolve().parents[1]
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repo-root", type=Path, default=default_root)
    parser.add_argument("--output", required=True, help="manifest path inside repo")
    parser.add_argument("artifacts", nargs="+", help="artifact paths relative to repo")
    args = parser.parse_args()

    repo_root = args.repo_root.resolve(strict=True)
    try:
        raw_output = Path(args.output)
        output_candidate = raw_output if raw_output.is_absolute() else repo_root / raw_output
        if output_candidate.exists() or output_candidate.is_symlink():
            raise ValueError("manifest output must not overwrite an existing file")
        output = resolve_repo_path(repo_root, args.output, must_exist=False)
        if not ignored_by_git(repo_root, output):
            raise ValueError("manifest output path must be ignored by Git")
        manifest = create_manifest(repo_root, args.artifacts)
        if output in {
            resolve_repo_path(repo_root, path, must_exist=True)
            for path in args.artifacts
        }:
            raise ValueError("manifest output must not overwrite an artifact")
        output.parent.mkdir(parents=True, exist_ok=True)
        with output.open("x", encoding="utf-8") as manifest_file:
            manifest_file.write(json.dumps(manifest, indent=2, sort_keys=True) + "\n")
    except (OSError, subprocess.CalledProcessError, ValueError) as exc:
        print(f"error: {exc}", file=sys.stderr)
        return 1

    print(f"wrote manifest: {output.relative_to(repo_root).as_posix()}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
