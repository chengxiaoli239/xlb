#!/usr/bin/env python3
"""Plan/create/delete an XLB-only temporary CloudFormation stack. Default: plan."""

import argparse
import json
import re
import subprocess
from datetime import datetime, timedelta, timezone
from pathlib import Path


TAGS = {"Project": "xlb", "Environment": "test", "ManagedBy": "xlb-node-lab"}
ALLOWED_RESOURCES = {
    "AWS::EC2::Instance",
    "AWS::EC2::SecurityGroup",
    "AWS::Scheduler::Schedule",
}


def check_name(value):
    if not re.fullmatch(r"xlb-lab-[a-z0-9-]{1,30}", value):
        raise ValueError("Stack name must start xlb-lab- and contain only lowercase letters/digits/hyphens")
    return value


def aws(args, *command):
    result = subprocess.run(
        ["aws", "--profile", args.profile, "--region", args.region, "--no-cli-pager",
         "--cli-connect-timeout", "8", "--cli-read-timeout", "20", *command],
        text=True, capture_output=True, timeout=45,
    )
    if result.returncode:
        raise RuntimeError(result.stderr.strip())
    return json.loads(result.stdout or "{}")


def create_request(args, now):
    check_name(args.name)
    if args.minutes not in (15, 30, 60):
        raise ValueError("Test lifetime must be 15, 30 or 60 minutes")
    expires = (now + timedelta(minutes=args.minutes)).strftime("%Y-%m-%dT%H:%M:%S")
    parameters = {
        "VpcId": args.vpc, "SubnetId": args.subnet, "ImageId": args.image,
        "InstanceType": args.instance_type, "InstanceProfile": args.instance_profile,
        "ExpiryRoleArn": args.expiry_role, "ExpiresAtUtc": expires,
        "LifetimeMinutes": str(args.minutes), "NodeId": args.name,
    }
    return {
        "StackName": args.name,
        "Parameters": [{"ParameterKey": key, "ParameterValue": value} for key, value in parameters.items()],
        "Tags": [{"Key": key, "Value": value} for key, value in TAGS.items()],
        "OnFailure": "DELETE", "TimeoutInMinutes": 10,
    }


def check_delete_scope(stack, resources):
    check_name(stack["StackName"])
    tags = {tag["Key"]: tag["Value"] for tag in stack.get("Tags", [])}
    if any(tags.get(key) != value for key, value in TAGS.items()):
        raise ValueError("Refusing to delete a stack without all XLB test ownership tags")
    if not resources or any(resource["ResourceType"] not in ALLOWED_RESOURCES for resource in resources):
        raise ValueError("Stack has resources outside the XLB test-host template; manual review required")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--profile", required=True)
    parser.add_argument("--region", required=True)
    commands = parser.add_subparsers(dest="action", required=True)
    create = commands.add_parser("create")
    create.add_argument("--name", required=True, type=check_name)
    create.add_argument("--vpc", required=True)
    create.add_argument("--subnet", required=True)
    create.add_argument("--image", required=True)
    create.add_argument("--instance-profile", required=True)
    create.add_argument("--expiry-role", required=True)
    create.add_argument("--instance-type", choices=("t3.micro", "t3.small"), default="t3.micro")
    create.add_argument("--minutes", type=int, choices=(15, 30, 60), default=30)
    create.add_argument("--apply", action="store_true")
    for action in ("status", "delete"):
        command = commands.add_parser(action)
        command.add_argument("--name", required=True, type=check_name)
        if action == "delete":
            command.add_argument("--apply", action="store_true")
    args = parser.parse_args()
    if args.action == "create":
        request = create_request(args, datetime.now(timezone.utc))
        template = (Path(__file__).parent / "aws-node-lab.template.json").read_text()
        print(json.dumps({
            "mode": "apply" if args.apply else "plan", "region": args.region, "request": request,
        }, indent=2))
        if args.apply:
            aws(args, "cloudformation", "validate-template", "--template-body", template)
            result = aws(args, "cloudformation", "create-stack", "--cli-input-json",
                         json.dumps({**request, "TemplateBody": template}))
            print(json.dumps(result, indent=2))
        return
    stack = aws(args, "cloudformation", "describe-stacks", "--stack-name", args.name)["Stacks"][0]
    if args.action == "status":
        keys = ("StackName", "StackId", "StackStatus", "Outputs", "Tags")
        print(json.dumps({key: stack.get(key) for key in keys}, indent=2))
        return
    response = aws(args, "cloudformation", "list-stack-resources", "--stack-name", args.name)
    resources = response["StackResourceSummaries"]
    check_delete_scope(stack, resources)
    print(json.dumps({
        "mode": "apply" if args.apply else "plan", "stack": args.name, "resources": resources,
    }, indent=2))
    if args.apply:
        aws(args, "cloudformation", "delete-stack", "--stack-name", stack["StackId"])
        print("Delete requested. Wait for stack deletion; then verify EC2/EBS/ENI/SG/scheduler remnants.")


if __name__ == "__main__":
    main()
