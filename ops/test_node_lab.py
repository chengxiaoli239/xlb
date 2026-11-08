import argparse
import json
import unittest
from datetime import datetime, timezone
from pathlib import Path

from node_lab import TAGS, check_delete_scope, check_name, create_request


class NodeLabSafetyTest(unittest.TestCase):
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
        args = argparse.Namespace(name="xlb-lab-us-test", minutes=30, vpc="vpc-test", subnet="subnet-test", image="ami-test", instance_type="t3.micro", instance_profile="xlb-ssm", expiry_role="arn:aws:iam::123456789012:role/xlb-expiry")
        request = create_request(args, datetime(2026, 11, 8, 9, 30, tzinfo=timezone.utc))
        parameters = {p["ParameterKey"]: p["ParameterValue"] for p in request["Parameters"]}
        self.assertEqual(parameters["ExpiresAtUtc"], "2026-11-08T10:00:00")
        self.assertEqual(request["OnFailure"], "DELETE")
        args.minutes = 0
        with self.assertRaises(ValueError):
            create_request(args, datetime.now(timezone.utc))

    def test_template_keeps_inbound_closed_and_has_independent_expiry_controls(self):
        template = json.loads((Path(__file__).parent / "aws-node-lab.template.json").read_text())
        resources = template["Resources"]
        self.assertNotIn("SecurityGroupIngress", resources["NodeSecurityGroup"]["Properties"])
        node = resources["Node"]["Properties"]
        self.assertEqual(node["InstanceInitiatedShutdownBehavior"], "terminate")
        self.assertTrue(node["PropagateTagsToVolumeOnCreation"])
        self.assertEqual(node["CreditSpecification"]["CPUCredits"], "standard")
        self.assertTrue(all(d["Ebs"]["DeleteOnTermination"] and d["Ebs"]["Encrypted"] for d in node["BlockDeviceMappings"]))
        self.assertIn("shutdown -h now", node["UserData"]["Fn::Base64"]["Fn::Sub"])
        self.assertEqual(resources["ExpirySchedule"]["Properties"]["Target"]["Arn"], "arn:aws:scheduler:::aws-sdk:ec2:terminateInstances")


if __name__ == "__main__":
    unittest.main()
