#!/usr/bin/env python3
"""Validate a bounded node-fleet request and print a plan without cloud access."""

import json
import re
import sys
import uuid


REQUEST_SCHEMA = "xlb-node-fleet-request/v1"
PLAN_SCHEMA = "xlb-node-fleet-plan/v1"
REGION = "us-west-2"
MAX_INPUT_BYTES = 16 * 1024
MAX_NODES = 5
MAX_CONCURRENCY = 3
ALLOWED_INSTANCE_TYPES = {"t3.micro", "t3.small"}
ALLOWED_TTLS = {15, 30, 60}
ROOT_FIELDS = {"schema", "batch_id", "region", "max_concurrency", "nodes"}
NODE_FIELDS = {"deployment_id", "instance_type", "ttl_minutes"}


def require_exact_fields(value, fields, name):
    if not isinstance(value, dict):
        raise ValueError(f"{name} must be an object")
    missing = fields - value.keys()
    unknown = value.keys() - fields
    if missing:
        raise ValueError(f"{name} is missing required fields: {', '.join(sorted(missing))}")
    if unknown:
        raise ValueError(f"{name} contains unknown fields: {', '.join(sorted(unknown))}")


def validate_batch_id(value):
    if not isinstance(value, str):
        raise ValueError("batch_id must be a canonical UUID v4")
    try:
        parsed = uuid.UUID(value)
    except ValueError:
        raise ValueError("batch_id must be a canonical UUID v4") from None
    if str(parsed) != value or parsed.version != 4:
        raise ValueError("batch_id must be a canonical UUID v4")


def validate_node(value):
    require_exact_fields(value, NODE_FIELDS, "node")
    deployment_id = value["deployment_id"]
    if not isinstance(deployment_id, str) or not re.fullmatch(r"xlb-auto-[a-f0-9]{32}", deployment_id):
        raise ValueError("deployment_id must match the isolated xlb-auto identity format")
    instance_type = value["instance_type"]
    if not isinstance(instance_type, str) or instance_type not in ALLOWED_INSTANCE_TYPES:
        raise ValueError("instance_type is outside the isolated test-node sizes")
    ttl_minutes = value["ttl_minutes"]
    if type(ttl_minutes) is not int or ttl_minutes not in ALLOWED_TTLS:
        raise ValueError("ttl_minutes must be one of 15, 30 or 60")
    return {
        "deployment_id": deployment_id,
        "idempotency_key": deployment_id,
        "instance_type": instance_type,
        "ttl_minutes": ttl_minutes,
    }


def build_plan(manifest):
    require_exact_fields(manifest, ROOT_FIELDS, "request")
    if manifest["schema"] != REQUEST_SCHEMA:
        raise ValueError("schema is unsupported")
    validate_batch_id(manifest["batch_id"])
    if manifest["region"] != REGION:
        raise ValueError(f"region must be {REGION}")

    max_concurrency = manifest["max_concurrency"]
    if type(max_concurrency) is not int or not 1 <= max_concurrency <= MAX_CONCURRENCY:
        raise ValueError(f"max_concurrency must be between 1 and {MAX_CONCURRENCY}")

    nodes = manifest["nodes"]
    if not isinstance(nodes, list) or not 1 <= len(nodes) <= MAX_NODES:
        raise ValueError(f"nodes must contain between 1 and {MAX_NODES} entries")
    normalized_nodes = sorted((validate_node(node) for node in nodes), key=lambda node: node["deployment_id"])
    deployment_ids = [node["deployment_id"] for node in normalized_nodes]
    if len(deployment_ids) != len(set(deployment_ids)):
        raise ValueError("duplicate deployment_id")

    return {
        "schema": PLAN_SCHEMA,
        "mode": "plan_only",
        "apply_supported": False,
        "requires_fresh_inventory_and_approval_before_apply": True,
        "batch_id": manifest["batch_id"],
        "region": REGION,
        "max_concurrency": max_concurrency,
        "node_count": len(normalized_nodes),
        "waves": [
            normalized_nodes[offset:offset + max_concurrency]
            for offset in range(0, len(normalized_nodes), max_concurrency)
        ],
    }


def main():
    raw = sys.stdin.buffer.read(MAX_INPUT_BYTES + 1)
    if len(raw) > MAX_INPUT_BYTES:
        print(json.dumps({"error": "request_too_large"}), file=sys.stderr)
        return 2
    try:
        plan = build_plan(json.loads(raw))
    except (json.JSONDecodeError, ValueError) as error:
        print(json.dumps({"error": "invalid_plan", "detail": str(error)}), file=sys.stderr)
        return 2
    print(json.dumps(plan, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    sys.exit(main())
