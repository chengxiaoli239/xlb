import os
import re
import subprocess
import unittest
from pathlib import Path


REPOSITORY_ROOT = Path(__file__).resolve().parents[1]
WORKFLOWS = (
    Path(".github/workflows/ci.yml"),
    Path(".github/workflows/windows-client.yml"),
    Path(".github/workflows/android-release-candidate.yml"),
)
PINNED_NODE_24_ACTIONS = {
    "actions/checkout": "3d3c42e5aac5ba805825da76410c181273ba90b1",
    "actions/setup-node": "949feb2413d6458794dcd2491c4babbbce0c15c1",
    "pnpm/action-setup": "73e46992e05b19abd31f2f3c20e0e7370d762115",
    "actions/setup-go": "d8cbb8045d7f43706b9177cceb36c1f91a6644d5",
    "actions/setup-java": "d1374f8ac74474200d16ef8ecee22c7ae7a51880",
    "actions/setup-python": "6d4fd22b20465ccc0f7354476588e8df7c9ca0e0",
    "actions/upload-artifact": "cf430e030ddbb5b0abf93d22962f4752f3646cd9",
    "actions/download-artifact": "9000827ccba6bdab643e8b6fd33ac0654aef8333",
    "android-actions/setup-android": "be39fa834029ff78f1a44aa3bb0819b8fc2bd8fd",
    "gradle/actions/setup-gradle": "8da46b539cd8fe6ab27ca75128919a18d757604c",
    "actions/attest": "508db95dd578ae2727ebd6217d5ba78e4fbda05d",
}
USES_PATTERN = re.compile(r"^\s*uses:\s*([^@\s]+)@([^\s#]+)", re.MULTILINE)


class WorkflowActionPinsTest(unittest.TestCase):
    def _release_input_preflight_script(self) -> str:
        workflow_lines = (
            REPOSITORY_ROOT / ".github/workflows/android-release-candidate.yml"
        ).read_text(encoding="utf-8").splitlines()
        step_start = workflow_lines.index("      - name: Preflight protected release inputs")
        run_line = workflow_lines.index("        run: |", step_start)
        script_lines = []
        for line in workflow_lines[run_line + 1 :]:
            if line and not line.startswith("          "):
                break
            script_lines.append(line[10:] if line.startswith("          ") else "")
        return "\n".join(script_lines)

    def test_release_input_preflight_rejects_missing_values_without_printing_values(self) -> None:
        environment_names = (
            "RELEASE_KEYSTORE_BASE64",
            "RELEASE_STORE_PASSWORD",
            "RELEASE_KEY_ALIAS",
            "RELEASE_KEY_PASSWORD",
            "RELEASE_API_BASE_URL",
        )
        sentinel = "synthetic-non-secret-release-input"
        missing_environment = {name: sentinel for name in environment_names}
        missing_environment["RELEASE_KEY_ALIAS"] = ""
        missing = subprocess.run(
            ["bash", "-euo", "pipefail", "-c", self._release_input_preflight_script()],
            env={"PATH": os.defpath, **missing_environment},
            check=False,
            capture_output=True,
            text=True,
        )
        self.assertEqual(missing.returncode, 1)
        self.assertIn("XLB_ANDROID_RELEASE_KEY_ALIAS", missing.stdout)
        self.assertNotIn(sentinel, missing.stdout + missing.stderr)

        complete = subprocess.run(
            ["bash", "-euo", "pipefail", "-c", self._release_input_preflight_script()],
            env={"PATH": os.defpath, **{name: sentinel for name in environment_names}},
            check=False,
            capture_output=True,
            text=True,
        )
        self.assertEqual(complete.returncode, 0, complete.stderr)
        self.assertNotIn(sentinel, complete.stdout + complete.stderr)

    def test_runtime_migration_actions_use_reviewed_commit_shas_everywhere(self) -> None:
        occurrences = {name: 0 for name in PINNED_NODE_24_ACTIONS}

        for relative_path in WORKFLOWS:
            workflow = (REPOSITORY_ROOT / relative_path).read_text(encoding="utf-8")
            for action, reference in USES_PATTERN.findall(workflow):
                if action not in PINNED_NODE_24_ACTIONS:
                    continue
                occurrences[action] += 1
                self.assertEqual(
                    reference,
                    PINNED_NODE_24_ACTIONS[action],
                    f"{relative_path} must pin {action} to its reviewed commit",
                )

        self.assertEqual(
            {
                "actions/checkout": 8,
                "actions/setup-node": 3,
                "pnpm/action-setup": 3,
                "actions/setup-go": 5,
                "actions/setup-java": 2,
                "actions/setup-python": 1,
                "actions/upload-artifact": 3,
                "android-actions/setup-android": 2,
                "gradle/actions/setup-gradle": 2,
                "actions/attest": 6,
                "actions/download-artifact": 2,
            },
            occurrences,
        )

    def test_android_release_candidate_is_manual_main_only_and_review_only(self) -> None:
        workflow = (
            REPOSITORY_ROOT / ".github/workflows/android-release-candidate.yml"
        ).read_text(encoding="utf-8")
        candidate_job_match = re.search(
            r"(?ms)^  signed-candidate:\n(?P<body>.*?)(?=^  [A-Za-z0-9_-]+:|\Z)",
            workflow,
        )

        self.assertIn("on:\n  workflow_dispatch:", workflow)
        self.assertNotIn("  push:\n", workflow)
        self.assertNotIn("  pull_request:\n", workflow)
        self.assertIsNotNone(candidate_job_match)
        candidate_job = candidate_job_match.group("body")
        self.assertIn("if: github.ref == 'refs/heads/main'", candidate_job)
        self.assertIn("environment: android-release-candidate", candidate_job)
        self.assertIn("      contents: read", candidate_job)
        self.assertIn("      attestations: write", candidate_job)
        self.assertIn("      id-token: write", candidate_job)
        self.assertIn("XLB_ANDROID_RELEASE_KEYSTORE_BASE64", workflow)
        self.assertIn("XLB_ANDROID_RELEASE_STORE_PASSWORD", workflow)
        self.assertIn("XLB_ANDROID_RELEASE_KEY_ALIAS", workflow)
        self.assertIn("XLB_ANDROID_RELEASE_KEY_PASSWORD", workflow)
        self.assertIn("XLB_ANDROID_RELEASE_API_BASE_URL", workflow)
        preflight_step = re.search(
            r"(?ms)^      - name: Preflight protected release inputs\n(?P<body>.*?)(?=^      - name: |\Z)",
            candidate_job,
        )
        self.assertIsNotNone(preflight_step)
        release_inputs = {
            "RELEASE_KEYSTORE_BASE64": "secrets.XLB_ANDROID_RELEASE_KEYSTORE_BASE64",
            "RELEASE_STORE_PASSWORD": "secrets.XLB_ANDROID_RELEASE_STORE_PASSWORD",
            "RELEASE_KEY_ALIAS": "secrets.XLB_ANDROID_RELEASE_KEY_ALIAS",
            "RELEASE_KEY_PASSWORD": "secrets.XLB_ANDROID_RELEASE_KEY_PASSWORD",
            "RELEASE_API_BASE_URL": "vars.XLB_ANDROID_RELEASE_API_BASE_URL",
        }
        for environment_name, source_expression in release_inputs.items():
            input_name = source_expression.split(".", maxsplit=1)[1]
            self.assertIn(f"Missing protected release input: {input_name}", preflight_step.group("body"))
            self.assertIn(f"{environment_name}: ${{{{ {source_expression} }}}}", preflight_step.group("body"))
            self.assertIn(f'[[ -z "${environment_name}" ]]', preflight_step.group("body"))
        self.assertIn("missing=1", preflight_step.group("body"))
        self.assertIn('exit "$missing"', preflight_step.group("body"))
        self.assertNotIn("echo \"$RELEASE_", preflight_step.group("body"))
        self.assertIn("push-to-registry: false", workflow)
        self.assertIn("create-storage-record: false", workflow)
        self.assertIn("retention-days: 14", workflow)
        self.assertEqual(
            [
                "Check out source",
                "Preflight protected release inputs",
                "Set up Java",
                "Set up Android SDK",
                "Install pinned Android SDK and NDK",
                "Set up Go for Mihomo JNI",
                "Set up Gradle",
                "Restore release signing keystore",
                "Build signed release candidate",
                "Verify package identity and APK signature",
                "Generate release runtime SBOM",
                "Create source-bound APK manifest",
                "Verify candidate artifact manifest",
                "Attest signed candidate APK",
                "Attest release runtime SBOM",
                "Upload signed candidate for review",
            ],
            re.findall(r"^      - name: (.+)$", candidate_job, re.MULTILINE),
        )
        self.assertIn(":app:cyclonedxDirectBom", candidate_job)
        manifest_step = re.search(
            r"(?ms)^      - name: Create source-bound APK manifest\n(?P<body>.*?)(?=^      - name: |\Z)",
            candidate_job,
        )
        self.assertIsNotNone(manifest_step)
        self.assertIn("apps/android/app/build/outputs/apk/release/app-release.apk", manifest_step.group("body"))
        self.assertIn("apps/android/app/build/reports/cyclonedx-direct/bom.json", manifest_step.group("body"))

        sbom_attestation_step = re.search(
            r"(?ms)^      - name: Attest release runtime SBOM\n(?P<body>.*?)(?=^      - name: |\Z)",
            candidate_job,
        )
        self.assertIsNotNone(sbom_attestation_step)
        self.assertIn(
            "subject-path: apps/android/app/build/reports/cyclonedx-direct/bom.json",
            sbom_attestation_step.group("body"),
        )

        upload_step = re.search(
            r"(?ms)^      - name: Upload signed candidate for review\n(?P<body>.*?)(?=^      - name: |\Z)",
            candidate_job,
        )
        self.assertIsNotNone(upload_step)
        for artifact_path in [
            "apps/android/app/build/outputs/apk/release/app-release.apk",
            "apps/android/app/build/outputs/apk/release/build-manifest.json",
            "apps/android/app/build/reports/cyclonedx-direct/bom.json",
        ]:
            self.assertIn(artifact_path, upload_step.group("body"))
        manifest_verification_step = re.search(
            r"(?ms)^      - name: Verify candidate artifact manifest\n(?P<body>.*?)(?=^      - name: |\Z)",
            candidate_job,
        )
        self.assertIsNotNone(manifest_verification_step)
        manifest_verification_body = manifest_verification_step.group("body")
        self.assertIn("python3 ops/verify_artifact_manifest.py", manifest_verification_body)
        self.assertIn('--expected-commit "$GITHUB_SHA"', manifest_verification_body)
        self.assertIn(
            "apps/android/app/build/outputs/apk/release/build-manifest.json",
            manifest_verification_body,
        )
        self.assertNotIn("gh release create", workflow)
        self.assertNotIn("softprops/action-gh-release@", workflow)
        self.assertNotIn("r0adkll/upload-google-play@", workflow)

    def test_sbom_attestations_are_main_push_only_and_job_scoped(self) -> None:
        workflow = (REPOSITORY_ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        main_push_gate = "if: github.event_name == 'push' && github.ref == 'refs/heads/main'"

        self.assertEqual(workflow.count(main_push_gate), 1)
        self.assertEqual(workflow.count("attestations: write"), 1)
        self.assertEqual(workflow.count("artifact-metadata: write"), 1)
        self.assertEqual(workflow.count("id-token: write"), 1)
        self.assertEqual(workflow.count("push-to-registry: false"), 4)
        self.assertEqual(workflow.count("create-storage-record: false"), 4)
        self.assertIn("upload-artifact: false", workflow)
        self.assertIn("needs: [checks, tests, node-agent, operations, android]", workflow)
        self.assertEqual(workflow.count("continue-on-error: true"), 3)
        self.assertEqual(workflow.count("run: sleep 30"), 2)
        self.assertIn("steps.attest_source_sbom.outcome == 'failure'", workflow)
        self.assertIn("steps.attest_android_sbom.outcome == 'failure'", workflow)
        self.assertIn(
            "subject-path: ${{ runner.temp }}/sboms/source/xlb-vpn-source.cdx.json",
            workflow,
        )
        self.assertIn("subject-path: ${{ runner.temp }}/sboms/android/bom.json", workflow)


if __name__ == "__main__":
    unittest.main()
