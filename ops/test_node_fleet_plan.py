import copy
import unittest

from node_fleet_plan import build_plan


class NodeFleetPlanTest(unittest.TestCase):
    def manifest(self):
        return {
            "schema": "xlb-node-fleet-request/v1",
            "batch_id": "550e8400-e29b-41d4-a716-446655440000",
            "region": "us-west-2",
            "max_concurrency": 2,
            "nodes": [
                {
                    "deployment_id": "xlb-auto-" + "b" * 32,
                    "instance_type": "t3.small",
                    "ttl_minutes": 30,
                },
                {
                    "deployment_id": "xlb-auto-" + "a" * 32,
                    "instance_type": "t3.micro",
                    "ttl_minutes": 15,
                },
                {
                    "deployment_id": "xlb-auto-" + "c" * 32,
                    "instance_type": "t3.micro",
                    "ttl_minutes": 60,
                },
            ],
        }

    def test_builds_deterministic_bounded_plan_only_waves(self):
        manifest = self.manifest()

        first = build_plan(manifest)
        second = build_plan(copy.deepcopy(manifest))

        self.assertEqual(first, second)
        self.assertEqual(first["mode"], "plan_only")
        self.assertFalse(first["apply_supported"])
        self.assertTrue(first["requires_fresh_inventory_and_approval_before_apply"])
        self.assertEqual(first["node_count"], 3)
        self.assertEqual(
            [[node["deployment_id"] for node in wave] for wave in first["waves"]],
            [["xlb-auto-" + "a" * 32, "xlb-auto-" + "b" * 32], ["xlb-auto-" + "c" * 32]],
        )
        self.assertEqual(first["waves"][0][0]["idempotency_key"], "xlb-auto-" + "a" * 32)

    def test_rejects_unknown_fields_instead_of_copying_secrets_to_plan(self):
        manifest = self.manifest()
        manifest["aws_secret_access_key"] = "must-not-be-accepted"

        with self.assertRaisesRegex(ValueError, "unknown fields"):
            build_plan(manifest)

        manifest = self.manifest()
        manifest["nodes"][0]["password"] = "must-not-be-accepted"
        with self.assertRaisesRegex(ValueError, "unknown fields"):
            build_plan(manifest)

    def test_rejects_duplicate_or_out_of_scope_deployments(self):
        manifest = self.manifest()
        manifest["nodes"][1]["deployment_id"] = manifest["nodes"][0]["deployment_id"]
        with self.assertRaisesRegex(ValueError, "duplicate deployment_id"):
            build_plan(manifest)

        manifest = self.manifest()
        manifest["region"] = "ap-east-1"
        with self.assertRaisesRegex(ValueError, "region"):
            build_plan(manifest)

    def test_rejects_unbounded_or_invalid_batch_parameters(self):
        for key, value in (("max_concurrency", 0), ("max_concurrency", 4), ("max_concurrency", True)):
            manifest = self.manifest()
            manifest[key] = value
            with self.subTest(key=key, value=value), self.assertRaises(ValueError):
                build_plan(manifest)

        manifest = self.manifest()
        manifest["nodes"][0]["ttl_minutes"] = 0
        with self.assertRaisesRegex(ValueError, "ttl_minutes"):
            build_plan(manifest)

        manifest = self.manifest()
        manifest["nodes"].append(
            {"deployment_id": "xlb-auto-" + "d" * 32, "instance_type": "m7g.large", "ttl_minutes": 15}
        )
        with self.assertRaisesRegex(ValueError, "instance_type"):
            build_plan(manifest)

    def test_rejects_empty_or_oversized_batches(self):
        for nodes in ([], self.manifest()["nodes"] * 2):
            manifest = self.manifest()
            manifest["nodes"] = nodes
            with self.subTest(count=len(nodes)), self.assertRaises(ValueError):
                build_plan(manifest)


if __name__ == "__main__":
    unittest.main()
