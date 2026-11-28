#!/usr/bin/env python3
"""Plan/create/delete an XLB-only temporary CloudFormation stack. Default: plan."""

import argparse
import ipaddress
import json
import os
import re
import subprocess
from datetime import datetime, timedelta, timezone
from pathlib import Path


TAGS = {"Project": "xlb", "Environment": "test", "ManagedBy": "xlb-node-lab"}
AWS_CONNECT_TIMEOUT_SECONDS = 20
AWS_READ_TIMEOUT_SECONDS = 60
AWS_SUBPROCESS_TIMEOUT_SECONDS = 150
AWS_INVENTORY_CONNECT_TIMEOUT_SECONDS = 8
AWS_INVENTORY_READ_TIMEOUT_SECONDS = 15
AWS_INVENTORY_SUBPROCESS_TIMEOUT_SECONDS = 45
ALLOWED_RESOURCES = {
    "AWS::EC2::Instance",
    "AWS::EC2::SecurityGroup",
    "AWS::Scheduler::Schedule",
}


def check_name(value):
    if not re.fullmatch(r"xlb-lab-[a-z0-9-]{1,30}", value):
        raise ValueError("Stack name must start xlb-lab- and contain only lowercase letters/digits/hyphens")
    return value


def check_resource_id(value, prefix):
    if not re.fullmatch(prefix+r"-[a-f0-9]{17}", value):
        raise ValueError(f"Resource ID must be a lowercase {prefix}- ID")
    return value


def check_member_ingress_cidr(value):
    if value == "":
        return ""
    try:
        network = ipaddress.ip_network(value, strict=True)
    except ValueError as exc:
        raise ValueError("Member ingress must be one globally routable IPv4 /32 host address") from exc
    if not isinstance(network, ipaddress.IPv4Network) or network.prefixlen != 32:
        raise ValueError("Member ingress must be one globally routable IPv4 /32 host address")
    address = network.network_address
    if not address.is_global or address.is_multicast or address.is_unspecified or address.is_reserved:
        raise ValueError("Member ingress must be one globally routable IPv4 /32 host address")
    return str(network)


def aws(
    args,
    *command,
    connect_timeout_seconds=AWS_CONNECT_TIMEOUT_SECONDS,
    read_timeout_seconds=AWS_READ_TIMEOUT_SECONDS,
    subprocess_timeout_seconds=AWS_SUBPROCESS_TIMEOUT_SECONDS,
    max_attempts=None,
):
    options = {
        "text": True,
        "capture_output": True,
        "timeout": subprocess_timeout_seconds,
    }
    if max_attempts is not None:
        environment = os.environ.copy()
        environment["AWS_MAX_ATTEMPTS"] = str(max_attempts)
        options["env"] = environment

    result = subprocess.run(
        [str(Path(__file__).with_name("aws-cli")), "--profile", args.profile, "--region", args.region, "--no-cli-pager",
         "--cli-connect-timeout", str(connect_timeout_seconds),
         "--cli-read-timeout", str(read_timeout_seconds), *command],
        **options,
    )
    if result.returncode:
        raise RuntimeError(result.stderr.strip())
    return json.loads(result.stdout or "{}")


def inventory(args):
    """Read-only inventory of one lab name and any independently discoverable remnants."""

    def ownership_tags_match(tags, expected):
        values = {tag.get("Key"): tag.get("Value") for tag in tags}
        return all(values.get(key) == value for key, value in expected.items())

    def query(label, command, not_found_markers=()):
        try:
            return {"status": "available", "data": aws(
                args, *command,
                connect_timeout_seconds=AWS_INVENTORY_CONNECT_TIMEOUT_SECONDS,
                read_timeout_seconds=AWS_INVENTORY_READ_TIMEOUT_SECONDS,
                subprocess_timeout_seconds=AWS_INVENTORY_SUBPROCESS_TIMEOUT_SECONDS,
                max_attempts=1,
            )}
        except (RuntimeError, OSError, subprocess.TimeoutExpired, json.JSONDecodeError) as error:
            if isinstance(error, subprocess.TimeoutExpired):
                message = f"AWS CLI inventory request exceeded {error.timeout} seconds"
            elif isinstance(error, OSError):
                message = f"AWS CLI wrapper launch failed: {type(error).__name__}"
            elif isinstance(error, json.JSONDecodeError):
                message = "AWS CLI inventory response was invalid JSON"
            else:
                message = str(error)
            if not_found_markers and all(marker in message for marker in not_found_markers):
                return {"status": "not_found", "resources": []}
            return {"status": "unavailable", "error": f"{label}: {message}"}

    def not_checked(reason):
        return {"status": "not_checked", "resources": [], "reason": reason}

    def list_response(result, key, label):
        if result["status"] != "available":
            return result, []
        data = result.get("data")
        resources = data.get(key) if isinstance(data, dict) else None
        if not isinstance(resources, list):
            return {"status": "unavailable", "error": f"{label}: malformed AWS response (missing {key})"}, []
        return result, resources

    name = args.name
    stack_result = query(
        "stack",
        ("cloudformation", "describe-stacks", "--stack-name", name),
        ("ValidationError", "does not exist"),
    )
    stack_result, stacks = list_response(stack_result, "Stacks", "CloudFormation stack")
    if stack_result["status"] == "available":
        if stacks:
            stack = stacks[0]
            if stack.get("StackName") != name:
                stack_result = {"status": "unavailable", "error": "CloudFormation returned an unexpected stack identity"}
            else:
                stack_result = {
                    "status": "found",
                    "resource": {
                        "name": stack.get("StackName"),
                        "status": stack.get("StackStatus"),
                        "tags": stack.get("Tags", []),
                        "ownership_tags_match": ownership_tags_match(stack.get("Tags", []), TAGS),
                    },
                }
        else:
            stack_result = {"status": "not_found", "resources": []}

    instance_command = (
        ("ec2", "describe-instances", "--instance-ids", args.instance_id)
        if args.instance_id
        else (
            "ec2", "describe-instances", "--filters",
            f"Name=tag:NodeId,Values={name}",
            "Name=tag:Project,Values=xlb",
            "Name=tag:Environment,Values=test",
            "Name=tag:ManagedBy,Values=xlb-node-lab",
        )
    )
    instances_result = query("instances", instance_command, ("InvalidInstanceID.NotFound",))
    instances = []
    instances_result, reservations = list_response(instances_result, "Reservations", "EC2 instances")
    if instances_result["status"] == "available":
        if any(not isinstance(reservation.get("Instances"), list) for reservation in reservations):
            instances_result = {"status": "unavailable", "error": "EC2 instances: malformed reservation response"}
        else:
            for reservation in reservations:
                for instance in reservation["Instances"]:
                    instance_tags = instance.get("Tags", [])
                    instances.append({
                        "id": instance.get("InstanceId"),
                        "state": instance.get("State", {}).get("Name"),
                        "type": instance.get("InstanceType"),
                        "tags": instance_tags,
                        "ownership_tags_match": ownership_tags_match(
                            instance_tags, {**TAGS, "NodeId": name},
                        ),
                        "network_interface_ids": [
                            interface.get("NetworkInterfaceId")
                            for interface in instance.get("NetworkInterfaces", [])
                            if interface.get("NetworkInterfaceId")
                        ],
                        "security_group_ids": sorted({
                            group.get("GroupId")
                            for interface in instance.get("NetworkInterfaces", [])
                            for group in interface.get("Groups", [])
                            if group.get("GroupId")
                        }),
                    })
            if instances_result["status"] == "available":
                instances_result = {"status": "available", "resources": instances}
    elif instances_result["status"] == "not_found":
        instances_result = {"status": "available", "resources": []}

    ec2_block_reason = "instance query failed; remaining EC2 inventory was not attempted"
    if instances_result["status"] == "unavailable":
        volumes_result = not_checked(ec2_block_reason)
        groups_result = not_checked(ec2_block_reason)
        interfaces_result = not_checked(ec2_block_reason)
    else:
        volume_command = (
            ("ec2", "describe-volumes", "--volume-ids", args.volume_id)
            if args.volume_id
            else (
                "ec2", "describe-volumes", "--filters",
                f"Name=tag:NodeId,Values={name}",
                "Name=tag:Project,Values=xlb",
                "Name=tag:Environment,Values=test",
                "Name=tag:ManagedBy,Values=xlb-node-lab",
            )
        )
        volumes_result = query("volumes", volume_command, ("InvalidVolume.NotFound",))
        volumes_result, volumes = list_response(volumes_result, "Volumes", "EC2 volumes")
        if volumes_result["status"] == "available":
            volumes_result = {
                "status": "available",
                "resources": [{
                    "id": volume.get("VolumeId"),
                    "state": volume.get("State"),
                    "attachments": volume.get("Attachments", []),
                    "tags": volume.get("Tags", []),
                    "ownership_tags_match": ownership_tags_match(
                        volume.get("Tags", []), {**TAGS, "NodeId": name},
                    ),
                } for volume in volumes],
            }
        elif volumes_result["status"] == "not_found":
            volumes_result = {"status": "available", "resources": []}

        if volumes_result["status"] == "unavailable":
            groups_result = not_checked("volume query failed; remaining EC2 inventory was not attempted")
            interfaces_result = not_checked("volume query failed; remaining EC2 inventory was not attempted")
        else:
            group_command = (
                ("ec2", "describe-security-groups", "--group-ids", args.security_group_id)
                if args.security_group_id
                else (
                    "ec2", "describe-security-groups", "--filters",
                    "Name=tag:Project,Values=xlb",
                    "Name=tag:Environment,Values=test",
                    "Name=tag:ManagedBy,Values=xlb-node-lab",
                )
            )
            groups_result = query("security groups", group_command, ("InvalidGroup.NotFound",))
            groups_result, groups = list_response(groups_result, "SecurityGroups", "EC2 security groups")
            if groups_result["status"] == "available":
                groups_result = {
                    "status": "available",
                    "resources": [{
                        "id": group.get("GroupId"),
                        "name": group.get("GroupName"),
                        "description": group.get("Description"),
                        "tags": group.get("Tags", []),
                        "ownership_tags_match": ownership_tags_match(group.get("Tags", []), TAGS),
                        "node_name_in_group_name": name in (group.get("GroupName") or ""),
                    } for group in groups],
                }

            if groups_result["status"] == "unavailable":
                interfaces_result = not_checked("security group query failed; interface correlation was not attempted")
            else:
                security_group_ids = sorted({
                    group["id"] for group in groups_result.get("resources", []) if group.get("id")
                })
                if args.security_group_id and args.security_group_id not in security_group_ids:
                    security_group_ids.append(args.security_group_id)
                security_group_ids.extend(
                    group_id for instance in instances
                    for group_id in instance["security_group_ids"]
                    if group_id not in security_group_ids
                )
                interface_ids = sorted({
                    interface_id for instance in instances
                    for interface_id in instance["network_interface_ids"]
                })
                if args.network_interface_id:
                    interface_command = (
                        "ec2", "describe-network-interfaces", "--network-interface-ids", args.network_interface_id,
                    )
                elif security_group_ids:
                    interface_command = (
                        "ec2", "describe-network-interfaces", "--filters",
                        "Name=group-id,Values="+",".join(security_group_ids),
                    )
                elif interface_ids:
                    interface_command = (
                        "ec2", "describe-network-interfaces", "--network-interface-ids", *interface_ids,
                    )
                else:
                    interface_command = (
                        "ec2", "describe-network-interfaces", "--filters",
                        f"Name=tag:NodeId,Values={name}",
                        "Name=tag:Project,Values=xlb",
                        "Name=tag:Environment,Values=test",
                        "Name=tag:ManagedBy,Values=xlb-node-lab",
                    )

                if interface_command is not None:
                    missing_interface_markers = (
                        ("InvalidNetworkInterfaceID.NotFound",)
                        if args.network_interface_id else ("InvalidGroup.NotFound",)
                    )
                    interfaces_result = query(
                        "network interfaces", interface_command, missing_interface_markers,
                    )
                    interfaces_result, interfaces = list_response(
                        interfaces_result, "NetworkInterfaces", "EC2 network interfaces",
                    )
                    if interfaces_result["status"] == "available":
                        interfaces_result = {
                            "status": "available",
                            "resources": [{
                                "id": interface.get("NetworkInterfaceId"),
                                "status": interface.get("Status"),
                                "attachment": interface.get("Attachment"),
                                "security_group_ids": sorted({
                                    group.get("GroupId") for group in interface.get("Groups", [])
                                    if group.get("GroupId")
                                }),
                                "tags": interface.get("TagSet", []),
                            } for interface in interfaces],
                        }
                    elif interfaces_result["status"] == "not_found":
                        interfaces_result = {"status": "available", "resources": []}

    schedule_name = name+"-expiry"
    schedule_result = query(
        "scheduler",
        ("scheduler", "get-schedule", "--name", schedule_name, "--group-name", "default"),
        ("ResourceNotFoundException",),
    )
    if schedule_result["status"] == "available":
        schedule = schedule_result["data"]
        if not isinstance(schedule, dict) or schedule.get("Name") != schedule_name:
            schedule_result = {"status": "unavailable", "error": "Scheduler returned an unexpected schedule identity"}
        else:
            schedule_result = {
                "status": "found",
                "resource": {"name": schedule.get("Name"), "state": schedule.get("State")},
            }
    elif schedule_result["status"] == "not_found":
        schedule_result = {"status": "not_found", "resources": []}

    if stack_result["status"] == "found":
        stack_resources_result = query(
            "stack resources",
            ("cloudformation", "list-stack-resources", "--stack-name", name),
        )
        stack_resources_result, stack_resources = list_response(
            stack_resources_result, "StackResourceSummaries", "CloudFormation stack resources",
        )
        if stack_resources_result["status"] == "available":
            stack_resources_result = {"status": "available", "resources": stack_resources}
    elif stack_result["status"] == "not_found":
        stack_resources_result = {"status": "not_applicable", "resources": []}
    else:
        stack_resources_result = not_checked("stack identity could not be verified")

    return {
        "scope": {"name": name, "region": args.region, "ownership_tags": TAGS},
        "stack": stack_result,
        "stack_resources": stack_resources_result,
        "instances": instances_result,
        "volumes": volumes_result,
        "network_interfaces": interfaces_result,
        "security_groups": groups_result,
        "scheduler": schedule_result,
    }


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
        "MemberIngressCidr": check_member_ingress_cidr(getattr(args, "member_ingress_cidr", "")),
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
    create.add_argument(
        "--member-ingress-cidr", type=check_member_ingress_cidr, default="",
        help="optional globally routable IPv4 /32 allowed to reach the TLS-passthrough member endpoint on TCP 443",
    )
    create.add_argument("--apply", action="store_true")
    for action in ("status", "delete"):
        command = commands.add_parser(action)
        command.add_argument("--name", required=True, type=check_name)
        if action == "delete":
            command.add_argument("--apply", action="store_true")
    audit = commands.add_parser("inventory", help="read-only scan for a lab stack and independently discoverable remnants")
    audit.add_argument("--name", required=True, type=check_name)
    audit.add_argument("--instance-id", type=lambda value: check_resource_id(value, "i"))
    audit.add_argument("--volume-id", type=lambda value: check_resource_id(value, "vol"))
    audit.add_argument("--security-group-id", type=lambda value: check_resource_id(value, "sg"))
    audit.add_argument("--network-interface-id", type=lambda value: check_resource_id(value, "eni"))
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
    if args.action == "inventory":
        result = inventory(args)
        print(json.dumps(result, indent=2))
        checks = [value for value in result.values() if isinstance(value, dict) and "status" in value]
        return 2 if any(check["status"] in ("unavailable", "not_checked") for check in checks) else 0
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
    raise SystemExit(main() or 0)
