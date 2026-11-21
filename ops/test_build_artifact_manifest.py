import hashlib
import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path


SCRIPT = Path(__file__).with_name("build_artifact_manifest.py")


class BuildArtifactManifestTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temp_dir = tempfile.TemporaryDirectory()
        self.repo = Path(self.temp_dir.name) / "repo"
        self.repo.mkdir()
        self.git("init", "-q", "-b", "main")
        self.git("config", "user.name", "Manifest Test")
        self.git("config", "user.email", "manifest@example.invalid")
        (self.repo / ".gitignore").write_text("build/\n", encoding="utf-8")
        (self.repo / "README.md").write_text("source\n", encoding="utf-8")
        self.git("add", ".gitignore", "README.md")
        self.git("commit", "-qm", "test: create source commit")

    def tearDown(self) -> None:
        self.temp_dir.cleanup()

    def git(self, *args: str) -> str:
        result = subprocess.run(
            ["git", *args],
            cwd=self.repo,
            check=True,
            capture_output=True,
            text=True,
        )
        return result.stdout.strip()

    def run_manifest(self, artifact: Path, output: Path) -> subprocess.CompletedProcess[str]:
        env = {
            **os.environ,
            "GITHUB_REF": "refs/heads/main",
            "GITHUB_RUN_ID": "12345",
            "GITHUB_RUN_ATTEMPT": "2",
        }
        return subprocess.run(
            [
                sys.executable,
                str(SCRIPT),
                "--repo-root",
                str(self.repo),
                "--output",
                str(output),
                str(artifact),
            ],
            cwd=self.repo,
            capture_output=True,
            text=True,
            env=env,
            check=False,
        )

    def test_manifest_records_source_and_artifact_digest(self) -> None:
        artifact = self.repo / "build" / "app-debug.apk"
        artifact.parent.mkdir()
        artifact.write_bytes(b"debug apk bytes")
        output = self.repo / "build" / "manifest.json"

        result = self.run_manifest(artifact, output)

        self.assertEqual(result.returncode, 0, result.stderr)
        manifest = json.loads(output.read_text(encoding="utf-8"))
        self.assertEqual(manifest["source"]["commit"], self.git("rev-parse", "HEAD"))
        self.assertEqual(manifest["source"]["ref"], "refs/heads/main")
        self.assertEqual(manifest["build"]["run_id"], "12345")
        self.assertEqual(manifest["build"]["run_attempt"], 2)
        self.assertEqual(
            manifest["artifacts"],
            [
                {
                    "path": "build/app-debug.apk",
                    "size_bytes": len(b"debug apk bytes"),
                    "sha256": hashlib.sha256(b"debug apk bytes").hexdigest(),
                }
            ],
        )

    def test_rejects_tracked_source_changes(self) -> None:
        artifact = self.repo / "build" / "app.apk"
        artifact.parent.mkdir()
        artifact.write_bytes(b"apk")
        (self.repo / "README.md").write_text("modified\n", encoding="utf-8")

        result = self.run_manifest(artifact, self.repo / "build" / "manifest.json")

        self.assertNotEqual(result.returncode, 0)
        self.assertIn("tracked changes", result.stderr)

    def test_rejects_artifact_outside_repository(self) -> None:
        outside = Path(self.temp_dir.name) / "outside.apk"
        outside.write_bytes(b"apk")

        result = self.run_manifest(outside, self.repo / "build" / "manifest.json")

        self.assertNotEqual(result.returncode, 0)
        self.assertIn("inside the repository", result.stderr)

    def test_rejects_nonignored_untracked_source(self) -> None:
        artifact = self.repo / "build" / "app.apk"
        artifact.parent.mkdir()
        artifact.write_bytes(b"apk")
        (self.repo / "Untracked.java").write_text("class Untracked {}\n", encoding="utf-8")

        result = self.run_manifest(artifact, self.repo / "build" / "manifest.json")

        self.assertNotEqual(result.returncode, 0)
        self.assertIn("non-ignored untracked files", result.stderr)

    def test_never_overwrites_tracked_source(self) -> None:
        artifact = self.repo / "build" / "app.apk"
        artifact.parent.mkdir()
        artifact.write_bytes(b"apk")
        original = (self.repo / "README.md").read_text(encoding="utf-8")

        result = self.run_manifest(artifact, self.repo / "README.md")

        self.assertNotEqual(result.returncode, 0)
        self.assertIn("must not overwrite", result.stderr)
        self.assertEqual((self.repo / "README.md").read_text(encoding="utf-8"), original)

    def test_rejects_nonignored_output_path(self) -> None:
        artifact = self.repo / "build" / "app.apk"
        artifact.parent.mkdir()
        artifact.write_bytes(b"apk")

        result = self.run_manifest(artifact, self.repo / "manifest.json")

        self.assertNotEqual(result.returncode, 0)
        self.assertIn("must be ignored by Git", result.stderr)


if __name__ == "__main__":
    unittest.main()
