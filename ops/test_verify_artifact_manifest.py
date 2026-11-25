import hashlib
import json
import tempfile
import unittest
from pathlib import Path
from typing import Any

from ops.verify_artifact_manifest import verify_manifest


class VerifyArtifactManifestTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temp_dir = tempfile.TemporaryDirectory()
        self.repo = Path(self.temp_dir.name).resolve()
        self.artifact_dir = self.repo / "candidate"
        self.artifact_dir.mkdir()
        self.apk = self.artifact_dir / "app-release.apk"
        self.apk.write_bytes(b"signed candidate bytes")
        self.sbom = self.artifact_dir / "bom.json"
        self.sbom.write_bytes(b"release runtime BOM bytes")
        self.manifest_path = self.artifact_dir / "build-manifest.json"
        self.manifest_path.write_text(json.dumps(self.valid_manifest()), encoding="utf-8")

    def tearDown(self) -> None:
        self.temp_dir.cleanup()

    def artifact_entry(self, path: Path) -> dict[str, object]:
        contents = path.read_bytes()
        return {
            "path": path.relative_to(self.repo).as_posix(),
            "size_bytes": len(contents),
            "sha256": hashlib.sha256(contents).hexdigest(),
        }

    def valid_manifest(self) -> dict[str, Any]:
        return {
            "schema_version": 1,
            "source": {"commit": "a" * 40, "ref": "refs/heads/main"},
            "build": {"created_at_utc": "2026-11-24T00:00:00Z"},
            "artifacts": [self.artifact_entry(self.apk), self.artifact_entry(self.sbom)],
        }

    def write_manifest(self, manifest: dict[str, Any]) -> None:
        self.manifest_path.write_text(json.dumps(manifest), encoding="utf-8")

    def test_verifies_all_listed_artifacts(self) -> None:
        verified = verify_manifest(self.repo, self.manifest_path)

        self.assertEqual(verified, ["candidate/app-release.apk", "candidate/bom.json"])

    def test_verifies_manifest_source_commit_matches_expected_commit(self) -> None:
        verified = verify_manifest(self.repo, self.manifest_path, expected_commit="a" * 40)

        self.assertEqual(verified, ["candidate/app-release.apk", "candidate/bom.json"])

    def test_rejects_manifest_source_commit_that_differs_from_expected_commit(self) -> None:
        with self.assertRaisesRegex(ValueError, "does not match expected commit"):
            verify_manifest(self.repo, self.manifest_path, expected_commit="b" * 40)

    def test_rejects_invalid_expected_commit(self) -> None:
        with self.assertRaisesRegex(ValueError, "expected commit is invalid"):
            verify_manifest(self.repo, self.manifest_path, expected_commit="not-a-commit")

    def test_rejects_modified_artifact(self) -> None:
        self.apk.write_bytes(b"different candidate bytes")

        with self.assertRaisesRegex(ValueError, "does not match manifest"):
            verify_manifest(self.repo, self.manifest_path)

    def test_rejects_artifact_path_outside_repository(self) -> None:
        manifest = self.valid_manifest()
        manifest["artifacts"][0]["path"] = "../outside.apk"
        self.write_manifest(manifest)

        with self.assertRaisesRegex(ValueError, "inside the repository"):
            verify_manifest(self.repo, self.manifest_path)

    def test_rejects_absolute_artifact_path(self) -> None:
        manifest = self.valid_manifest()
        manifest["artifacts"][0]["path"] = str(self.apk)
        self.write_manifest(manifest)

        with self.assertRaisesRegex(ValueError, "must be relative"):
            verify_manifest(self.repo, self.manifest_path)

    def test_rejects_symlink_artifact_that_resolves_outside_repository(self) -> None:
        outside = self.repo.parent / f"{self.repo.name}-outside.apk"
        outside.write_bytes(b"outside artifact")
        link = self.repo / "candidate" / "outside.apk"
        link.symlink_to(outside)
        manifest = self.valid_manifest()
        manifest["artifacts"][0] = self.artifact_entry(self.apk)
        manifest["artifacts"].append({
            "path": "candidate/outside.apk",
            "size_bytes": len(b"outside artifact"),
            "sha256": hashlib.sha256(b"outside artifact").hexdigest(),
        })
        self.write_manifest(manifest)

        with self.assertRaisesRegex(ValueError, "inside the repository"):
            verify_manifest(self.repo, self.manifest_path)

    def test_rejects_duplicate_artifact_entries(self) -> None:
        manifest = self.valid_manifest()
        manifest["artifacts"].append(manifest["artifacts"][0])
        self.write_manifest(manifest)

        with self.assertRaisesRegex(ValueError, "duplicate artifact path"):
            verify_manifest(self.repo, self.manifest_path)

    def test_rejects_unsupported_schema_version(self) -> None:
        manifest = self.valid_manifest()
        manifest["schema_version"] = 2
        self.write_manifest(manifest)

        with self.assertRaisesRegex(ValueError, "schema version"):
            verify_manifest(self.repo, self.manifest_path)


if __name__ == "__main__":
    unittest.main()
