import argparse
import json
import subprocess
import unittest
from datetime import datetime, timezone
from pathlib import Path
from unittest.mock import patch

from node_lab import TAGS, aws, check_delete_scope, check_member_ingress_cidr, check_name, create_request, inventory


class NodeLabSafetyTest(unittest.TestCase):
    def test_aws_calls_use_proxy_safe_repository_wrapper(self):
        args = argparse.Namespace(profile="xlb-ops", region="us-west-2")
        completed = subprocess.CompletedProcess([], 0, '{"Account":"289069331089"}', "")
        with patch("node_lab.subprocess.run", return_value=completed) as run:
            self.assertEqual(aws(args, "sts", "get-caller-identity"), {"Account": "289069331089"})
        command = run.call_args.args[0]
        self.assertEqual(Path(command[0]).name, "aws-cli")
        self.assertEqual(command[command.index("--cli-connect-timeout") + 1], "20")
        self.assertEqual(command[command.index("--cli-read-timeout") + 1], "60")
        self.assertEqual(run.call_args.kwargs["timeout"], 150)

    def test_inventory_aws_call_has_bounded_timeout_and_no_cli_retry(self):
        args = argparse.Namespace(profile="xlb-ops", region="us-west-2")
        completed = subprocess.CompletedProcess([], 0, "{}", "")
        with patch("node_lab.subprocess.run", return_value=completed) as run:
            aws(
                args, "ec2", "describe-instances",
                connect_timeout_seconds=8,
                read_timeout_seconds=15,
                subprocess_timeout_seconds=45,
                max_attempts=1,
            )

        command = run.call_args.args[0]
        self.assertEqual(command[command.index("--cli-connect-timeout") + 1], "8")
        self.assertEqual(command[command.index("--cli-read-timeout") + 1], "15")
        self.assertEqual(run.call_args.kwargs["timeout"], 45)
        self.assertEqual(run.call_args.kwargs["env"]["AWS_MAX_ATTEMPTS"], "1")

    def test_inventory_finds_tagged_residual_resources_when_the_stack_is_gone(self):
        args = argparse.Namespace(
            profile="xlb-ops", region="us-west-2", name="xlb-lab-us-test",
            instance_id="i-0123456789abcdef0", security_group_id="sg-0123456789abcdef0",
            volume_id="vol-0123456789abcdef0", network_interface_id="eni-0123456789abcdef0",
        )
        calls = []

        def fake_aws(_args, *command, **_options):
            calls.append(command)
            if command[:2] == ("cloudformation", "describe-stacks"):
                raise RuntimeError("ValidationError: Stack with id xlb-lab-us-test does not exist")
            if command[:2] == ("ec2", "describe-instances"):
                return {"Reservations": [{"Instances": [{"InstanceId": args.instance_id, "State": {"Name": "terminated"}, "InstanceType": "t3.small", "Tags": [{"Key": key, "Value": value} for key, value in {**TAGS, "NodeId": args.name}.items()], "NetworkInterfaces": [{"NetworkInterfaceId": args.network_interface_id, "Groups": [{"GroupId": args.security_group_id}]}]}]}]}
            if command[:2] == ("ec2", "describe-volumes"):
                return {"Volumes": [{"VolumeId": args.volume_id, "State": "available", "Attachments": [], "Tags": [{"Key": key, "Value": value} for key, value in {**TAGS, "NodeId": args.name}.items()]}]}
            if command[:2] == ("ec2", "describe-network-interfaces"):
                return {"NetworkInterfaces": [{"NetworkInterfaceId": args.network_interface_id, "Status": "available", "Groups": [{"GroupId": args.security_group_id}]}]}
            if command[:2] == ("ec2", "describe-security-groups"):
                return {"SecurityGroups": [{"GroupId": args.security_group_id, "GroupName": "xlb-lab-us-test", "Tags": [{"Key": key, "Value": value} for key, value in TAGS.items()]}]}
            if command[:2] == ("scheduler", "get-schedule"):
                self.assertEqual(command[command.index("--name") + 1], args.name+"-expiry")
                self.assertEqual(command[command.index("--group-name") + 1], "default")
                return {"Name": args.name+"-expiry", "State": "ENABLED"}
            self.fail(f"Unexpected non-inventory AWS command: {command}")

        with patch("node_lab.aws", side_effect=fake_aws):
            result = inventory(args)

        self.assertEqual(result["stack"]["status"], "not_found")
        self.assertEqual(result["instances"]["resources"][0]["state"], "terminated")
        self.assertTrue(result["instances"]["resources"][0]["ownership_tags_match"])
        self.assertEqual(result["volumes"]["resources"][0]["state"], "available")
        self.assertTrue(result["volumes"]["resources"][0]["ownership_tags_match"])
        self.assertEqual(result["network_interfaces"]["resources"][0]["id"], args.network_interface_id)
        self.assertEqual(result["security_groups"]["resources"][0]["id"], args.security_group_id)
        self.assertTrue(result["security_groups"]["resources"][0]["ownership_tags_match"])
        self.assertEqual(result["scheduler"]["resource"]["state"], "ENABLED")
        self.assertEqual(len(calls), 6)

    def test_inventory_reports_unavailable_checks_without_claiming_empty_resources(self):
        args = argparse.Namespace(
            profile="xlb-ops", region="us-west-2", name="xlb-lab-us-test",
            instance_id=None, security_group_id=None, volume_id=None, network_interface_id=None,
        )

        def fail_aws(_args, *command, **_options):
            if command[:2] == ("cloudformation", "describe-stacks"):
                raise RuntimeError("CreateOAuth2Token INVALID_REQUEST")
            if command[:2] == ("ec2", "describe-instances"):
                raise RuntimeError("Connect timeout on endpoint")
            if command[:2] == ("scheduler", "get-schedule"):
                raise RuntimeError("Connect timeout on endpoint")
            self.fail(f"Inventory should not continue dependent EC2 queries: {command}")

        with patch("node_lab.aws", side_effect=fail_aws):
            result = inventory(args)

        self.assertEqual(result["stack"]["status"], "unavailable")
        self.assertEqual(result["instances"]["status"], "unavailable")
        self.assertEqual(result["volumes"]["status"], "not_checked")
        self.assertEqual(result["network_interfaces"]["status"], "not_checked")
        self.assertEqual(result["security_groups"]["status"], "not_checked")
        self.assertEqual(result["scheduler"]["status"], "unavailable")

    def test_inventory_checks_for_interfaces_even_when_the_exact_security_group_is_gone(self):
        args = argparse.Namespace(
            profile="xlb-ops", region="us-west-2", name="xlb-lab-us-test",
            instance_id="i-0123456789abcdef0", security_group_id="sg-0123456789abcdef0",
            volume_id=None, network_interface_id=None,
        )
        calls = []

        def fake_aws(_args, *command, **_options):
            calls.append(command)
            if command[:2] == ("cloudformation", "describe-stacks"):
                raise RuntimeError("ValidationError: Stack with id xlb-lab-us-test does not exist")
            if command[:3] == ("ec2", "describe-instances", "--instance-ids"):
                raise RuntimeError("InvalidInstanceID.NotFound")
            if command[:2] == ("ec2", "describe-volumes"):
                return {"Volumes": []}
            if command[:3] == ("ec2", "describe-security-groups", "--group-ids"):
                raise RuntimeError("InvalidGroup.NotFound")
            if command[:2] == ("ec2", "describe-network-interfaces"):
                self.assertIn("Name=group-id,Values="+args.security_group_id, command)
                raise RuntimeError("InvalidGroup.NotFound")
            if command[:2] == ("scheduler", "get-schedule"):
                raise RuntimeError("ResourceNotFoundException")
            self.fail(f"Unexpected non-inventory AWS command: {command}")

        with patch("node_lab.aws", side_effect=fake_aws):
            result = inventory(args)

        self.assertEqual(result["stack"]["status"], "not_found")
        self.assertEqual(result["instances"]["resources"], [])
        self.assertEqual(result["security_groups"]["status"], "not_found")
        self.assertEqual(result["network_interfaces"]["status"], "available")
        self.assertEqual(result["network_interfaces"]["resources"], [])
        self.assertEqual(result["scheduler"]["status"], "not_found")
        self.assertEqual(len(calls), 6)

    def test_non_test_and_mixed_resource_stacks_cannot_be_deleted(self):
        stack = {"StackName": "xlb-lab-us-test", "Tags": [{"Key": k, "Value": v} for k, v in TAGS.items()]}
        resources = [{"ResourceType": "AWS::EC2::Instance"}]
        check_delete_scope(stack, resources)
        with self.assertRaises(ValueError):
            check_delete_scope({**stack, "Tags": []}, resources)
        with self.assertRaises(ValueError):
            check_delete_scope(stack, [{"ResourceType": "AWS::RDS::DBInstance"}])
        with self.assertRaises(ValueError):
            check_delete_scope(stack, [])

    def test_production_stack_names_and_cli_injection_are_rejected(self):
        for value in ("pd02", "prod", "xlb-lab-good;id", "--help", "xlb-lab-"):
            with self.subTest(name=value), self.assertRaises(ValueError):
                check_name(value)

    def test_launch_deadline_uses_utc_and_never_accepts_an_unbounded_lifetime(self):
        args = argparse.Namespace(name="xlb-lab-us-test", minutes=30, vpc="vpc-test", subnet="subnet-test", image="ami-test", instance_type="t3.micro", instance_profile="xlb-ssm", expiry_role="arn:aws:iam::123456789012:role/xlb-expiry", member_ingress_cidr="")
        request = create_request(args, datetime(2026, 11, 8, 9, 30, tzinfo=timezone.utc))
        parameters = {p["ParameterKey"]: p["ParameterValue"] for p in request["Parameters"]}
        self.assertEqual(parameters["ExpiresAtUtc"], "2026-11-08T10:00:00")
        self.assertEqual(parameters["MemberIngressCidr"], "")
        self.assertEqual(request["OnFailure"], "DELETE")
        args.member_ingress_cidr = "8.8.8.8/32"
        request = create_request(args, datetime(2026, 11, 8, 9, 30, tzinfo=timezone.utc))
        parameters = {p["ParameterKey"]: p["ParameterValue"] for p in request["Parameters"]}
        self.assertEqual(parameters["MemberIngressCidr"], "8.8.8.8/32")
        args.minutes = 0
        with self.assertRaises(ValueError):
            create_request(args, datetime.now(timezone.utc))

    def test_member_ingress_is_optional_but_limited_to_one_ipv4_host(self):
        self.assertEqual(check_member_ingress_cidr(""), "")
        self.assertEqual(check_member_ingress_cidr("8.8.8.8/32"), "8.8.8.8/32")
        for value in (
            "0.0.0.0/0", "8.8.8.0/24", "2001:db8::1/128", "8.8.8.8/24", "not-a-cidr",
            "0.0.0.0/32", "127.0.0.1/32", "224.0.0.1/32", "192.168.1.1/32",
        ):
            with self.subTest(cidr=value), self.assertRaises(ValueError):
                check_member_ingress_cidr(value)

    def test_template_keeps_inbound_closed_and_has_independent_expiry_controls(self):
        template = json.loads((Path(__file__).parent / "aws-node-lab.template.json").read_text())
        resources = template["Resources"]
        ingress = resources["NodeSecurityGroup"]["Properties"]["SecurityGroupIngress"][0]["Fn::If"]
        self.assertEqual(ingress[0], "EnableMemberIngress")
        self.assertEqual(ingress[1]["FromPort"], 443)
        self.assertEqual(ingress[1]["ToPort"], 443)
        self.assertEqual(ingress[1]["CidrIp"], {"Ref": "MemberIngressCidr"})
        self.assertEqual(ingress[2], {"Ref": "AWS::NoValue"})
        self.assertEqual(template["Parameters"]["MemberIngressCidr"]["Default"], "")
        user_data = resources["Node"]["Properties"]["UserData"]["Fn::Base64"]["Fn::Sub"]
        self.assertIn("if [ -n \"${MemberIngressCidr}\" ]", user_data)
        self.assertIn("bind 0.0.0.0:443", user_data)
        self.assertIn("server node_agent 127.0.0.1:9444", user_data)
        self.assertNotIn("haproxy", user_data.split("if [ -n", 1)[0])
        node = resources["Node"]["Properties"]
        self.assertEqual(node["InstanceInitiatedShutdownBehavior"], "terminate")
        self.assertTrue(node["PropagateTagsToVolumeOnCreation"])
        self.assertEqual(node["CreditSpecification"]["CPUCredits"], "standard")
        self.assertTrue(all(d["Ebs"]["DeleteOnTermination"] and d["Ebs"]["Encrypted"] for d in node["BlockDeviceMappings"]))
        self.assertIn("shutdown -h now", node["UserData"]["Fn::Base64"]["Fn::Sub"])
        self.assertEqual(resources["ExpirySchedule"]["Properties"]["Target"]["Arn"], "arn:aws:scheduler:::aws-sdk:ec2:terminateInstances")

    def test_access_template_limits_optional_kms_grants_to_one_lab_key(self):
        template = json.loads((Path(__file__).parent / "aws-node-lab-access.template.json").read_text())
        parameter = template["Parameters"]["NodeKmsKeyArn"]
        self.assertEqual(parameter["Default"], "")
        self.assertEqual(parameter["AllowedPattern"], "^$|^arn:aws:kms:us-west-2:[0-9]{12}:key/[0-9a-fA-F-]{36}$")
        self.assertEqual(
            template["Conditions"]["UseNodeKmsKey"]["Fn::Not"][0]["Fn::Equals"],
            [{"Ref": "NodeKmsKeyArn"}, ""],
        )

        policy = template["Resources"]["NodeKmsSigningPolicy"]
        self.assertEqual(policy["Condition"], "UseNodeKmsKey")
        self.assertEqual(policy["Properties"]["Roles"], [{"Ref": "SsmRole"}])
        statement = policy["Properties"]["PolicyDocument"]["Statement"][0]
        self.assertEqual(statement["Action"], ["kms:GetPublicKey", "kms:Sign"])
        self.assertEqual(statement["Resource"], {"Ref": "NodeKmsKeyArn"})

        expiry_statement = template["Resources"]["ExpiryRole"]["Properties"]["Policies"][0]["PolicyDocument"]["Statement"][0]
        self.assertEqual(expiry_statement["Action"], "ec2:TerminateInstances")
        self.assertEqual(expiry_statement["Condition"]["StringEquals"]["ec2:ResourceTag/ManagedBy"], "xlb-node-lab")


if __name__ == "__main__":
    unittest.main()
