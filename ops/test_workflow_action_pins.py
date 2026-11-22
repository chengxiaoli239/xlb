import re
import unittest
from pathlib import Path


REPOSITORY_ROOT = Path(__file__).resolve().parents[1]
WORKFLOWS = (
    Path(".github/workflows/ci.yml"),
    Path(".github/workflows/windows-client.yml"),
)
PINNED_NODE_24_ACTIONS = {
    "actions/checkout": "3d3c42e5aac5ba805825da76410c181273ba90b1",
    "actions/setup-node": "949feb2413d6458794dcd2491c4babbbce0c15c1",
    "pnpm/action-setup": "73e46992e05b19abd31f2f3c20e0e7370d762115",
    "actions/setup-go": "d8cbb8045d7f43706b9177cceb36c1f91a6644d5",
    "actions/setup-java": "d1374f8ac74474200d16ef8ecee22c7ae7a51880",
    "actions/setup-python": "6d4fd22b20465ccc0f7354476588e8df7c9ca0e0",
    "actions/upload-artifact": "cf430e030ddbb5b0abf93d22962f4752f3646cd9",
    "android-actions/setup-android": "be39fa834029ff78f1a44aa3bb0819b8fc2bd8fd",
    "gradle/actions/setup-gradle": "8da46b539cd8fe6ab27ca75128919a18d757604c",
    "actions/attest": "508db95dd578ae2727ebd6217d5ba78e4fbda05d",
}
USES_PATTERN = re.compile(r"^\s*uses:\s*([^@\s]+)@([^\s#]+)", re.MULTILINE)


class WorkflowActionPinsTest(unittest.TestCase):
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
                "actions/checkout": 6,
                "actions/setup-node": 3,
                "pnpm/action-setup": 3,
                "actions/setup-go": 3,
                "actions/setup-java": 1,
                "actions/setup-python": 1,
                "actions/upload-artifact": 2,
                "android-actions/setup-android": 1,
                "gradle/actions/setup-gradle": 1,
                "actions/attest": 4,
            },
            occurrences,
        )

    def test_sbom_attestations_are_main_push_only_and_job_scoped(self) -> None:
        workflow = (REPOSITORY_ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        main_push_gate = "if: github.event_name == 'push' && github.ref == 'refs/heads/main'"

        self.assertEqual(workflow.count(main_push_gate), 6)
        self.assertEqual(workflow.count("attestations: write"), 2)
        self.assertEqual(workflow.count("artifact-metadata: write"), 2)
        self.assertEqual(workflow.count("id-token: write"), 2)
        self.assertEqual(workflow.count("push-to-registry: false"), 4)
        self.assertEqual(workflow.count("create-storage-record: false"), 4)
        self.assertIn("upload-artifact: false", workflow)
        self.assertEqual(workflow.count("continue-on-error: true"), 2)
        self.assertEqual(workflow.count("run: sleep 30"), 2)
        self.assertIn("steps.attest_source_sbom.outcome == 'failure'", workflow)
        self.assertIn("steps.attest_android_sbom.outcome == 'failure'", workflow)
        self.assertIn(
            "name: xlb-vpn-source-sbom\n          path: ${{ runner.temp }}/xlb-vpn-source.cdx.json",
            workflow,
        )
        self.assertIn("subject-path: ${{ runner.temp }}/xlb-vpn-source.cdx.json", workflow)
        self.assertIn("subject-path: apps/android/app/build/reports/cyclonedx-direct/bom.json", workflow)


if __name__ == "__main__":
    unittest.main()
