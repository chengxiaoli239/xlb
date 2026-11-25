#!/usr/bin/env python3
"""Check Android release environment protection without reading secrets."""

from __future__ import annotations

import argparse
import json
import subprocess
import sys
from typing import Any
from urllib.parse import quote


def validate_environment(environment: Any, expected_name: str) -> list[str]:
    errors: list[str] = []
    if not isinstance(environment, dict):
        return ["environment response is not an object"]
    if environment.get("name") != expected_name:
        errors.append("environment name does not match the requested environment")

    protection_rules = environment.get("protection_rules")
    has_required_reviewer = isinstance(protection_rules, list) and any(
        isinstance(rule, dict)
        and rule.get("type") == "required_reviewers"
        and isinstance(rule.get("reviewers"), list)
        and any(
            isinstance(reviewer, dict)
            and reviewer.get("type") in {"User", "Team"}
            and isinstance(reviewer.get("reviewer"), dict)
            and type(reviewer["reviewer"].get("id")) is int
            and reviewer["reviewer"]["id"] > 0
            for reviewer in rule["reviewers"]
        )
        for rule in protection_rules
    )
    if not has_required_reviewer:
        errors.append("required reviewer is not configured")

    branch_policy = environment.get("deployment_branch_policy")
    if (
        not isinstance(branch_policy, dict)
        or branch_policy.get("protected_branches") is not False
        or branch_policy.get("custom_branch_policies") is not True
    ):
        errors.append("deployment branch policy must use an explicit branch allowlist")

    return errors


def validate_branch_policies(policies_response: Any) -> list[str]:
    if not isinstance(policies_response, dict):
        return ["deployment branch policy response is not an object"]

    policies = policies_response.get("branch_policies")
    if (
        not isinstance(policies, list)
        or len(policies) != 1
        or not isinstance(policies[0], dict)
        or policies[0].get("name") != "main"
        or policies[0].get("type") != "branch"
    ):
        return ["only the main branch may be allowed"]

    return []


def github_api_json(endpoint: str) -> Any:
    try:
        response = subprocess.run(
            ["gh", "api", endpoint],
            check=False,
            capture_output=True,
            text=True,
        )
    except OSError as exc:
        raise RuntimeError("could not run GitHub CLI") from exc

    if response.returncode != 0:
        raise RuntimeError(
            "could not read release environment metadata; check GitHub CLI authentication and read access"
        )

    try:
        return json.loads(response.stdout)
    except json.JSONDecodeError as exc:
        raise RuntimeError("GitHub API returned invalid JSON") from exc


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repo", default="chengxiaoli239/xlb")
    parser.add_argument("--environment", default="android-release-candidate")
    args = parser.parse_args()

    repository_path = f"repos/{args.repo}"
    try:
        environments_response = github_api_json(
            f"{repository_path}/environments?per_page=100"
        )
    except RuntimeError as exc:
        print(f"error: {exc}", file=sys.stderr)
        return 2

    if not isinstance(environments_response, dict) or not isinstance(
        environments_response.get("environments"), list
    ):
        print("error: GitHub API returned an invalid environment list", file=sys.stderr)
        return 2

    if not any(
        isinstance(environment, dict)
        and environment.get("name") == args.environment
        for environment in environments_response["environments"]
    ):
        print(
            f"not ready: environment is not configured: {args.environment}",
            file=sys.stderr,
        )
        return 1

    environment_path = (
        f"{repository_path}/environments/{quote(args.environment, safe='')}"
    )
    try:
        environment = github_api_json(environment_path)
        branch_policies = github_api_json(
            f"{environment_path}/deployment-branch-policies"
        )
    except RuntimeError as exc:
        print(f"error: {exc}", file=sys.stderr)
        return 2

    errors = validate_environment(environment, args.environment)
    errors.extend(validate_branch_policies(branch_policies))
    if errors:
        for error in errors:
            print(f"not ready: {error}", file=sys.stderr)
        return 1

    print(f"release environment preflight passed: {args.repo}/{args.environment}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
