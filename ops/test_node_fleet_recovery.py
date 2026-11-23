import copy
from datetime import datetime, timezone
import json
from pathlib import Path
import subprocess
import sys
import unittest

from node_fleet_recovery import _build_recovery_preview, build_recovery_preview


class NodeFleetRecoveryPreviewTest(unittest.TestCase):
    def trusted_now(self):
        return datetime(2026, 11, 23, 10, 30, tzinfo=timezone.utc)

    def entry(self, suffix='a'):
        deployment_id = 'xlb-auto-' + suffix * 32
        return {
            'deployment_id': deployment_id,
            'generation': 3,
            'operation_state': 'probe_failed',
            'release_intent': {
                'request_id': '550e8400-e29b-41d4-a716-' + f'{ord(suffix):012x}',
                'batch_id': '550e8400-e29b-41d4-a716-446655440000',
                'generation': 3,
                'status': 'approved',
                'revoked': False,
                'approved_by': 'operator-1',
                'approved_at': '2026-11-23T10:00:00Z',
                'not_before': '2026-11-23T10:05:00Z',
                'deadline': '2026-11-23T11:00:00Z',
            },
            'resource': {
                'resource_id': deployment_id,
                'resource_type': 'AWS::CloudFormation::Stack',
                'account_id': '123456789012',
                'region': 'us-west-2',
                'observed_at': '2026-11-23T10:29:00Z',
                'stack_status': 'CREATE_COMPLETE',
                'deployment_scope': 'dedicated_instance',
                'shared_dependencies': False,
                'tags': {
                    'Project': 'xlb',
                    'Environment': 'test',
                    'ManagedBy': 'xlb-node-manager',
                    'DeploymentId': deployment_id,
                    'XlbRole': 'dedicated-test-node',
                },
            },
        }

    def request(self):
        return {
            'schema': 'xlb-node-fleet-recovery/v1',
            'batch_id': '550e8400-e29b-41d4-a716-446655440000',
            'account_id': '123456789012',
            'region': 'us-west-2',
            'nodes': [self.entry()],
        }

    def test_builds_sorted_review_only_preview_for_exact_approved_stack(self):
        request = self.request()
        request['nodes'].append(self.entry('b'))
        request['nodes'].reverse()

        preview = _build_recovery_preview(request, self.trusted_now())

        self.assertEqual(preview['mode'], 'review_only')
        self.assertFalse(preview['cloud_access'])
        self.assertFalse(preview['apply_supported'])
        self.assertTrue(preview['requires_fresh_inventory_and_approval_before_apply'])
        self.assertEqual(
            [entry['deployment_id'] for entry in preview['entries']],
            ['xlb-auto-' + 'a' * 32, 'xlb-auto-' + 'b' * 32],
        )
        self.assertEqual(preview['entries'][0]['decision'], 'eligible_for_human_review')
        self.assertEqual(preview['entries'][0]['resource_id'], 'xlb-auto-' + 'a' * 32)

    def test_holds_revoked_stale_or_generation_mismatched_intents(self):
        cases = (
            ('revoked', lambda node: node['release_intent'].__setitem__('revoked', True), 'release_intent_not_active'),
            ('expired', lambda node: node['release_intent'].__setitem__('deadline', '2026-11-23T10:20:00Z'), 'release_intent_outside_window'),
            ('generation', lambda node: node['release_intent'].__setitem__('generation', 2), 'generation_mismatch'),
            ('wrong_batch', lambda node: node['release_intent'].__setitem__('batch_id', '550e8400-e29b-41d4-a716-446655440001'), 'release_intent_batch_mismatch'),
        )
        for name, mutate, expected_reason in cases:
            with self.subTest(name=name):
                request = self.request()
                mutate(request['nodes'][0])
                entry = _build_recovery_preview(request, self.trusted_now())['entries'][0]
                self.assertEqual(entry['decision'], 'hold')
                self.assertIn(expected_reason, entry['reason_codes'])

    def test_holds_shared_control_plane_scope_and_resource_mismatches(self):
        cases = (
            ('shared', lambda resource: resource.__setitem__('deployment_scope', 'shared_host'), 'shared_or_unknown_scope'),
            ('control_plane', lambda resource: resource['tags'].__setitem__('XlbRole', 'control-plane'), 'protected_control_plane_role'),
            ('account', lambda resource: resource.__setitem__('account_id', '000000000000'), 'resource_scope_mismatch'),
            ('wrong_stack', lambda resource: resource.__setitem__('resource_id', 'xlb-auto-' + 'c' * 32), 'resource_identity_mismatch'),
            ('stale_inventory', lambda resource: resource.__setitem__('observed_at', '2026-11-23T10:20:00Z'), 'inventory_not_fresh'),
        )
        for name, mutate, expected_reason in cases:
            with self.subTest(name=name):
                request = self.request()
                mutate(request['nodes'][0]['resource'])
                entry = _build_recovery_preview(request, self.trusted_now())['entries'][0]
                self.assertEqual(entry['decision'], 'hold')
                self.assertIn(expected_reason, entry['reason_codes'])
                if name == 'wrong_stack':
                    self.assertIsNone(entry['resource_id'])

    def test_holds_non_failed_operations_and_unrecoverable_stack_states(self):
        cases = (
            ('ready', lambda node: node.__setitem__('operation_state', 'ready'), 'operation_not_failed'),
            ('deleting', lambda node: node['resource'].__setitem__('stack_status', 'DELETE_IN_PROGRESS'), 'stack_state_not_recoverable'),
            ('unknown_resource', lambda node: node['resource'].__setitem__('resource_type', 'AWS::EC2::Instance'), 'unsupported_resource_type'),
        )
        for name, mutate, expected_reason in cases:
            with self.subTest(name=name):
                request = self.request()
                mutate(request['nodes'][0])
                entry = _build_recovery_preview(request, self.trusted_now())['entries'][0]
                self.assertEqual(entry['decision'], 'hold')
                self.assertIn(expected_reason, entry['reason_codes'])

    def test_rejects_unknown_fields_duplicate_nodes_and_bad_timestamp(self):
        request = self.request()
        request['aws_secret_access_key'] = 'never echo this value'
        with self.assertRaisesRegex(ValueError, 'unknown fields'):
            _build_recovery_preview(request, self.trusted_now())

        request = self.request()
        request['nodes'].append(copy.deepcopy(request['nodes'][0]))
        with self.assertRaisesRegex(ValueError, 'duplicate deployment_id'):
            _build_recovery_preview(request, self.trusted_now())

        request = self.request()
        duplicate = copy.deepcopy(request['nodes'][0])
        duplicate['deployment_id'] = 'xlb-auto-' + 'b' * 32
        duplicate['resource']['resource_id'] = duplicate['deployment_id']
        duplicate['resource']['tags']['DeploymentId'] = duplicate['deployment_id']
        request['nodes'].append(duplicate)
        with self.assertRaisesRegex(ValueError, 'duplicate release_request_id'):
            build_recovery_preview(request)

        request = self.request()
        request['nodes'][0]['release_intent']['deadline'] = '2026-11-23T10:20:00Z'
        entry = _build_recovery_preview(request, self.trusted_now())['entries'][0]
        self.assertIn('release_intent_outside_window', entry['reason_codes'])

    def test_expired_intent_cannot_be_revived_by_request_fields(self):
        request = self.request()
        request['evaluated_at'] = '2026-11-23T10:30:00Z'
        with self.assertRaisesRegex(ValueError, 'unknown fields'):
            build_recovery_preview(request)

    def test_cli_rejects_invalid_and_oversized_requests_without_plan_output(self):
        script = Path(__file__).with_name('node_fleet_recovery.py')
        for request, error_code in (
            ('{bad json', 'invalid_recovery_request'),
            (' ' * (64 * 1024 + 1), 'request_too_large'),
        ):
            with self.subTest(error_code=error_code):
                result = subprocess.run(
                    [sys.executable, str(script)],
                    input=request,
                    text=True,
                    capture_output=True,
                    timeout=5,
                    check=False,
                )
                self.assertEqual(result.returncode, 2)
                self.assertEqual(result.stdout, '')
                self.assertIn(error_code, result.stderr)

    def test_cli_outputs_review_only_and_never_an_apply_action(self):
        script = Path(__file__).with_name('node_fleet_recovery.py')
        result = subprocess.run(
            [sys.executable, str(script)],
            input=json.dumps(self.request()),
            text=True,
            capture_output=True,
            timeout=5,
            check=False,
        )

        self.assertEqual(result.returncode, 0)
        self.assertEqual(result.stderr, '')
        preview = json.loads(result.stdout)
        self.assertEqual(preview['mode'], 'review_only')
        self.assertFalse(preview['cloud_access'])
        self.assertFalse(preview['apply_supported'])


if __name__ == '__main__':
    unittest.main()
