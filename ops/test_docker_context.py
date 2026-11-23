import subprocess
import tempfile
import unittest
from pathlib import Path


REPOSITORY_ROOT = Path(__file__).resolve().parents[1]
SENSITIVE_CONTEXT_PATHS = (
    "apps/php-api/.env",
    "apps/php-api/auth.json",
    "apps/php-api/.npmrc",
    "apps/php-api/.netrc",
    "apps/php-api/.aws/credentials",
    "apps/php-api/.ssh/id_rsa",
    "apps/php-api/storage/app/private/fixture.key",
    "apps/php-api/storage/app/private/fixture.pem",
    "apps/php-api/storage/framework/sessions/session-fixture",
    "apps/php-api/bootstrap/cache/config.php",
)


class DockerContextTest(unittest.TestCase):
    def test_root_dockerignore_excludes_local_credentials_and_runtime_files(self) -> None:
        with tempfile.TemporaryDirectory(prefix="xlb-docker-context-") as temporary_directory:
            context = Path(temporary_directory)
            (context / ".dockerignore").write_text(
                (REPOSITORY_ROOT / ".dockerignore").read_text(encoding="utf-8"),
                encoding="utf-8",
            )

            for index, relative_path in enumerate(SENSITIVE_CONTEXT_PATHS):
                with self.subTest(path=relative_path):
                    sensitive_file = context / relative_path
                    sensitive_file.parent.mkdir(parents=True, exist_ok=True)
                    sensitive_file.write_text("synthetic non-secret test data\n", encoding="utf-8")
                    (context / "Dockerfile").write_text(
                        f"FROM scratch\nCOPY {relative_path} /sentinel\n",
                        encoding="utf-8",
                    )

                    result = subprocess.run(
                        [
                            "docker",
                            "build",
                            "--no-cache",
                            "--progress=plain",
                            "--file",
                            "Dockerfile",
                            "--tag",
                            f"xlb-docker-context-test:{index}",
                            ".",
                        ],
                        cwd=context,
                        capture_output=True,
                        text=True,
                        check=False,
                        timeout=30,
                    )
                    output = result.stdout + result.stderr

                    self.assertNotEqual(
                        0,
                        result.returncode,
                        f"Docker copied excluded path {relative_path}: {output}",
                    )
                    self.assertIn(
                        "not found",
                        output.lower(),
                        f"Docker failed for an unrelated reason on {relative_path}: {output}",
                    )
                    sensitive_file.unlink()


if __name__ == "__main__":
    unittest.main()
