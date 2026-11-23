#!/usr/bin/env python3
"""Build a local-only review preview for failed isolated fleet deployments."""

from datetime import datetime, timezone
import json
import re
import sys
import uuid


REQUEST_SCHEMA = 'xlb-node-fleet-recovery/v1'
PREVIEW_SCHEMA = 'xlb-node-fleet-recovery-preview/v1'
REGION = 'us-west-2'
MAX_INVENTORY_AGE_SECONDS = 300
MANAGER = 'xlb-node-manager'
NODE_ROLE = 'dedicated-test-node'
CONTROL_PLANE_ROLES = {'control-plane', 'command-center', 'shared-host'}
DEPLOYMENT_ID_PATTERN = re.compile(r'^xlb-auto-[a-f0-9]{32}$')
ACCOUNT_ID_PATTERN = re.compile(r'^[0-9]{12}$')
STACK_ARN_PATTERN = re.compile(
    r'^arn:aws:cloudformation:us-west-2:([0-9]{12}):stack/(xlb-auto-[a-f0-9]{32})/[A-Za-z0-9-]+$'
)
ROOT_FIELDS = {'schema', 'batch_id', 'account_id', 'region', 'nodes'}
NODE_FIELDS = {'deployment_id', 'generation', 'operation_state', 'release_intent', 'resource'}
INTENT_FIELDS = {'request_id', 'batch_id', 'generation', 'status', 'revoked', 'approved_by', 'approved_at', 'not_before', 'deadline'}
RESOURCE_FIELDS = {'resource_id', 'resource_type', 'account_id', 'region', 'observed_at', 'stack_status', 'deployment_scope', 'shared_dependencies', 'tags'}
FAILED_OPERATION_STATES = {'provisioning_failed', 'configuration_failed', 'probe_failed'}
RECOVERABLE_STACK_STATES = {'CREATE_COMPLETE', 'CREATE_FAILED', 'ROLLBACK_COMPLETE', 'ROLLBACK_FAILED', 'UPDATE_ROLLBACK_FAILED'}
REQUIRED_TAGS = {
    'Project': 'xlb',
    'Environment': 'test',
    'ManagedBy': MANAGER,
    'XlbRole': NODE_ROLE,
}


def require_exact_fields(value, fields, name):
    if not isinstance(value, dict):
        raise ValueError(f'{name} must be an object')
    missing = fields - value.keys()
    unknown = value.keys() - fields
    if missing:
        raise ValueError(f'{name} is missing required fields: {", ".join(sorted(missing))}')
    if unknown:
        raise ValueError(f'{name} contains unknown fields: {", ".join(sorted(unknown))}')


def require_uuid_v4(value, name):
    if not isinstance(value, str):
        raise ValueError(f'{name} must be a canonical UUID v4')
    try:
        parsed = uuid.UUID(value)
    except ValueError:
        raise ValueError(f'{name} must be a canonical UUID v4') from None
    if str(parsed) != value or parsed.version != 4:
        raise ValueError(f'{name} must be a canonical UUID v4')


def parse_timestamp(value, name):
    if not isinstance(value, str):
        raise ValueError(f'{name} must be an RFC 3339 timestamp')
    try:
        parsed = datetime.fromisoformat(value.replace('Z', '+00:00'))
    except ValueError:
        raise ValueError(f'{name} must be an RFC 3339 timestamp') from None
    if parsed.tzinfo is None or parsed.utcoffset() is None:
        raise ValueError(f'{name} must include a timezone')
    return parsed


def resource_reasons(node, batch_id, account_id, region, evaluated_at):
    resource = node['resource']
    intent = node['release_intent']
    tags = resource['tags']
    reasons = []

    if intent['status'] != 'approved' or intent['revoked'] is not False:
        reasons.append('release_intent_not_active')
    if node['generation'] != intent['generation']:
        reasons.append('generation_mismatch')
    approved_at = parse_timestamp(intent['approved_at'], 'approved_at')
    not_before = parse_timestamp(intent['not_before'], 'not_before')
    deadline = parse_timestamp(intent['deadline'], 'deadline')
    if not approved_at <= not_before <= evaluated_at <= deadline:
        reasons.append('release_intent_outside_window')
    if intent['batch_id'] != batch_id:
        reasons.append('release_intent_batch_mismatch')

    inventory_at = parse_timestamp(resource['observed_at'], 'resource.observed_at')
    inventory_age = (evaluated_at - inventory_at).total_seconds()
    if inventory_age < 0 or inventory_age > MAX_INVENTORY_AGE_SECONDS:
        reasons.append('inventory_not_fresh')

    operation_state = node['operation_state']
    if not isinstance(operation_state, str) or operation_state not in FAILED_OPERATION_STATES:
        reasons.append('operation_not_failed')
    if resource['resource_type'] != 'AWS::CloudFormation::Stack':
        reasons.append('unsupported_resource_type')
    if resource['account_id'] != account_id or resource['region'] != region:
        reasons.append('resource_scope_mismatch')
    if resource['deployment_scope'] != 'dedicated_instance' or resource['shared_dependencies'] is not False:
        reasons.append('shared_or_unknown_scope')
    stack_status = resource['stack_status']
    if not isinstance(stack_status, str) or stack_status not in RECOVERABLE_STACK_STATES:
        reasons.append('stack_state_not_recoverable')
    if not isinstance(tags, dict):
        raise ValueError('resource.tags must be an object')
    if any(tags.get(key) != value for key, value in REQUIRED_TAGS.items()):
        reasons.append('ownership_tags_mismatch')
    if tags.get('DeploymentId') != node['deployment_id']:
        reasons.append('deployment_id_mismatch')
    if any(str(tags.get(key, '')).strip().lower() in CONTROL_PLANE_ROLES
           for key in ('XlbRole', 'SystemRole', 'Purpose')):
        reasons.append('protected_control_plane_role')

    resource_id = resource['resource_id']
    identity_verified = False
    if not isinstance(resource_id, str):
        reasons.append('resource_identity_mismatch')
    elif resource_id != node['deployment_id']:
        match = STACK_ARN_PATTERN.fullmatch(resource_id)
        identity_verified = bool(match and match.group(1) == account_id and match.group(2) == node['deployment_id'])
        if not identity_verified:
            reasons.append('resource_identity_mismatch')
    else:
        identity_verified = True

    return sorted(set(reasons)), identity_verified


def build_recovery_preview(request):
    return _build_recovery_preview(request, datetime.now(timezone.utc))


def _build_recovery_preview(request, trusted_now):
    require_exact_fields(request, ROOT_FIELDS, 'request')
    if request['schema'] != REQUEST_SCHEMA:
        raise ValueError('schema is unsupported')
    require_uuid_v4(request['batch_id'], 'batch_id')
    account_id = request['account_id']
    if not isinstance(account_id, str) or not ACCOUNT_ID_PATTERN.fullmatch(account_id):
        raise ValueError('account_id must contain 12 decimal digits')
    region = request['region']
    if region != REGION:
        raise ValueError(f'region must be {REGION}')
    if not isinstance(trusted_now, datetime) or trusted_now.tzinfo is None or trusted_now.utcoffset() is None:
        raise ValueError('trusted evaluation time must include a timezone')
    nodes = request['nodes']
    if not isinstance(nodes, list) or not 1 <= len(nodes) <= 5:
        raise ValueError('nodes must contain between 1 and 5 entries')

    seen = set()
    seen_release_requests = set()
    entries = []
    for node in nodes:
        require_exact_fields(node, NODE_FIELDS, 'node')
        deployment_id = node['deployment_id']
        if not isinstance(deployment_id, str) or not DEPLOYMENT_ID_PATTERN.fullmatch(deployment_id):
            raise ValueError('deployment_id is invalid')
        if deployment_id in seen:
            raise ValueError('duplicate deployment_id')
        seen.add(deployment_id)

        generation = node['generation']
        if type(generation) is not int or generation < 1:
            raise ValueError('generation must be a positive integer')
        require_exact_fields(node['release_intent'], INTENT_FIELDS, 'release_intent')
        intent = node['release_intent']
        require_uuid_v4(intent['request_id'], 'release_intent.request_id')
        require_uuid_v4(intent['batch_id'], 'release_intent.batch_id')
        if intent['request_id'] in seen_release_requests:
            raise ValueError('duplicate release_request_id')
        seen_release_requests.add(intent['request_id'])
        intent_generation = intent['generation']
        if type(intent_generation) is not int or intent_generation < 1:
            raise ValueError('release_intent.generation must be a positive integer')
        if not isinstance(intent['status'], str) or type(intent['revoked']) is not bool:
            raise ValueError('release intent status or revoked flag is invalid')
        if not isinstance(intent['approved_by'], str) or not intent['approved_by'].strip():
            raise ValueError('release_intent.approved_by is required')
        require_exact_fields(node['resource'], RESOURCE_FIELDS, 'resource')
        entry_reasons, identity_verified = resource_reasons(node, request['batch_id'], account_id, region, trusted_now)
        entries.append({
            'deployment_id': deployment_id,
            'decision': 'eligible_for_human_review' if not entry_reasons else 'hold',
            'reason_codes': entry_reasons,
            'release_request_id': intent['request_id'],
            'resource_id': node['resource']['resource_id'] if identity_verified else None,
        })

    entries.sort(key=lambda entry: entry['deployment_id'])
    return {
        'schema': PREVIEW_SCHEMA,
        'mode': 'review_only',
        'cloud_access': False,
        'apply_supported': False,
        'requires_fresh_inventory_and_approval_before_apply': True,
        'batch_id': request['batch_id'],
        'region': region,
        'entries': entries,
    }


def main():
    maximum_input_bytes = 64 * 1024
    raw = sys.stdin.buffer.read(maximum_input_bytes + 1)
    if len(raw) > maximum_input_bytes:
        print(json.dumps({'error': 'request_too_large'}), file=sys.stderr)
        return 2
    try:
        preview = build_recovery_preview(json.loads(raw))
    except (json.JSONDecodeError, ValueError) as error:
        print(json.dumps({'error': 'invalid_recovery_request', 'detail': str(error)}), file=sys.stderr)
        return 2
    print(json.dumps(preview, ensure_ascii=False, indent=2))
    return 0


if __name__ == '__main__':
    sys.exit(main())
