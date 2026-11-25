import json
import subprocess
import unittest
from contextlib import redirect_stderr, redirect_stdout
from io import StringIO
from unittest.mock import patch

from ops.verify_android_release_environment import (
    validate_branch_policies,
    validate_environment,
)


class VerifyAndroidReleaseEnvironmentTest(unittest.TestCase):
    def valid_environment(self) -> dict[str, object]:
        return {
            "name": "android-release-candidate",
            "protection_rules": [
                {
                    "type": "required_reviewers",
                    "reviewers": [
                        {
                            "type": "Team",
                            "reviewer": {"id": 42, "name": "release-reviewers"},
                        }
                    ],
                }
            ],
            "deployment_branch_policy": {
                "protected_branches": False,
                "custom_branch_policies": True,
            },
        }

    def test_accepts_environment_with_reviewer_and_custom_branch_policy(self) -> None:
        self.assertEqual(
            validate_environment(self.valid_environment(), "android-release-candidate"),
            [],
        )

    def test_rejects_environment_without_required_reviewer(self) -> None:
        environment = self.valid_environment()
        environment["protection_rules"] = []

        self.assertIn(
            "required reviewer is not configured",
            validate_environment(environment, "android-release-candidate"),
        )

    def test_rejects_required_reviewer_without_id(self) -> None:
        environment = self.valid_environment()
        environment["protection_rules"] = [
            {
                "type": "required_reviewers",
                "reviewers": [{"type": "Team", "reviewer": {"name": "team"}}],
            }
        ]

        self.assertIn(
            "required reviewer is not configured",
            validate_environment(environment, "android-release-candidate"),
        )

    def test_rejects_environment_name_mismatch(self) -> None:
        self.assertIn(
            "environment name does not match the requested environment",
            validate_environment(self.valid_environment(), "other-environment"),
        )

    def test_rejects_environment_that_allows_every_protected_branch(self) -> None:
        environment = self.valid_environment()
        environment["deployment_branch_policy"] = {
            "protected_branches": True,
            "custom_branch_policies": False,
        }

        self.assertIn(
            "deployment branch policy must use an explicit branch allowlist",
            validate_environment(environment, "android-release-candidate"),
        )

    def test_accepts_only_the_main_branch_policy(self) -> None:
        self.assertEqual(
            validate_branch_policies(
                {
                    "branch_policies": [
                        {
                            "id": 123,
                            "node_id": "ENV_branch_policy_123",
                            "name": "main",
                            "type": "branch",
                        }
                    ]
                }
            ),
            [],
        )

    def test_rejects_additional_branch_policies(self) -> None:
        errors = validate_branch_policies(
            {
                "branch_policies": [
                    {"name": "main", "type": "branch"},
                    {"name": "release", "type": "branch"},
                ]
            }
        )

        self.assertIn("only the main branch may be allowed", errors)

    def test_main_reads_only_environment_protection_metadata(self) -> None:
        environment = self.valid_environment()
        branch_policies = {
            "branch_policies": [{"name": "main", "type": "branch"}]
        }
        environments = {
            "environments": [{"name": "android-release-candidate"}],
        }
        responses = [
            subprocess.CompletedProcess(
                [], 0, stdout=json.dumps(environments), stderr=""
            ),
            subprocess.CompletedProcess(
                [], 0, stdout=json.dumps(environment), stderr=""
            ),
            subprocess.CompletedProcess(
                [], 0, stdout=json.dumps(branch_policies), stderr=""
            ),
        ]

        with (
            patch("sys.argv", ["verify_android_release_environment.py"]),
            patch("subprocess.run", side_effect=responses) as run,
            redirect_stdout(StringIO()),
        ):
            from ops.verify_android_release_environment import main

            self.assertEqual(main(), 0)

        self.assertEqual(
            [call.args[0] for call in run.call_args_list],
            [
                [
                    "gh",
                    "api",
                    "repos/chengxiaoli239/xlb/environments?per_page=100",
                ],
                [
                    "gh",
                    "api",
                    "repos/chengxiaoli239/xlb/environments/android-release-candidate",
                ],
                [
                    "gh",
                    "api",
                    "repos/chengxiaoli239/xlb/environments/android-release-candidate/deployment-branch-policies",
                ],
            ],
        )

    def test_main_reports_missing_environment_without_reading_other_metadata(self) -> None:
        responses = [
            subprocess.CompletedProcess(
                [], 0, stdout=json.dumps({"environments": []}), stderr=""
            )
        ]
        error_output = StringIO()

        with (
            patch("sys.argv", ["verify_android_release_environment.py"]),
            patch("subprocess.run", side_effect=responses) as run,
            redirect_stderr(error_output),
        ):
            from ops.verify_android_release_environment import main

            self.assertEqual(main(), 1)

        self.assertIn("environment is not configured", error_output.getvalue())
        self.assertEqual(run.call_count, 1)

    def test_rejects_main_tag_policy(self) -> None:
        errors = validate_branch_policies(
            {"branch_policies": [{"name": "main", "type": "tag"}]}
        )

        self.assertIn("only the main branch may be allowed", errors)


if __name__ == "__main__":
    unittest.main()
