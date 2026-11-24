#!/usr/bin/env python3
"""Verify build artifact files against a source-bound manifest."""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
from pathlib import Path


def resolve_repo_path(repo_root: Path, raw_path: str, *, must_exist: bool) -> Path:
    candidate = Path(raw_path)
    candidate = candidate if candidate.is_absolute() else repo_root / candidate
    resolved = candidate.resolve(strict=False)
    if not resolved.is_relative_to(repo_root):
        raise ValueError(f"path must be inside the repository: {raw_path}")
    if must_exist:
        resolved = resolved.resolve(strict=True)
    return resolved


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as artifact:
        for chunk in iter(lambda: artifact.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def verify_manifest(repo_root: Path, manifest_path: Path) -> list[str]:
    resolved_manifest = resolve_repo_path(repo_root, str(manifest_path), must_exist=True)
    if not resolved_manifest.is_file():
        raise ValueError("manifest must be a regular file")

    try:
        manifest = json.loads(resolved_manifest.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, json.JSONDecodeError) as exc:
        raise ValueError("manifest is not readable valid JSON") from exc

    if (
        not isinstance(manifest, dict)
        or type(manifest.get("schema_version")) is not int
        or manifest["schema_version"] != 1
    ):
        raise ValueError("manifest schema version is unsupported")

    source = manifest.get("source")
    if not isinstance(source, dict) or not isinstance(source.get("commit"), str):
        raise ValueError("manifest source commit is missing")
    if not re.fullmatch(r"[0-9a-f]{40,64}", source["commit"]):
        raise ValueError("manifest source commit is invalid")

    artifacts = manifest.get("artifacts")
    if not isinstance(artifacts, list) or not artifacts:
        raise ValueError("manifest must contain at least one artifact")

    seen_paths: set[str] = set()
    verified_paths: list[str] = []
    for artifact in artifacts:
        if not isinstance(artifact, dict):
            raise ValueError("manifest artifact entry must be an object")

        raw_path = artifact.get("path")
        expected_size = artifact.get("size_bytes")
        expected_digest = artifact.get("sha256")
        if not isinstance(raw_path, str) or raw_path == "":
            raise ValueError("manifest artifact path is missing")
        if Path(raw_path).is_absolute():
            raise ValueError(f"artifact paths must be relative to the repository: {raw_path}")
        if type(expected_size) is not int or expected_size < 0:
            raise ValueError(f"manifest artifact size is invalid: {raw_path}")
        if not isinstance(expected_digest, str) or not re.fullmatch(
            r"[0-9a-f]{64}", expected_digest
        ):
            raise ValueError(f"manifest artifact digest is invalid: {raw_path}")

        path = resolve_repo_path(repo_root, raw_path, must_exist=True)
        if not path.is_file():
            raise ValueError(f"artifact is not a regular file: {raw_path}")
        relative_path = path.relative_to(repo_root).as_posix()
        if relative_path in seen_paths:
            raise ValueError(f"manifest contains a duplicate artifact path: {relative_path}")
        seen_paths.add(relative_path)

        if path.stat().st_size != expected_size:
            raise ValueError(f"artifact size does not match manifest: {relative_path}")
        if sha256_file(path) != expected_digest:
            raise ValueError(f"artifact digest does not match manifest: {relative_path}")
        verified_paths.append(relative_path)

    return verified_paths


def main() -> int:
    default_root = Path(__file__).resolve().parents[1]
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repo-root", type=Path, default=default_root)
    parser.add_argument("manifest", help="source-bound artifact manifest path")
    args = parser.parse_args()

    try:
        repo_root = args.repo_root.resolve(strict=True)
        verified_paths = verify_manifest(repo_root, Path(args.manifest))
    except (OSError, ValueError) as exc:
        print(f"error: {exc}", file=sys.stderr)
        return 1

    for path in verified_paths:
        print(f"verified artifact: {path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
