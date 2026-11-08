from datetime import datetime, timedelta, timezone
import json
import os
from pathlib import Path
import subprocess
import unittest
from unittest.mock import patch

from node_manager import Manager, CloudError, MANAGER


class ManagerTest(unittest.TestCase):
    def setUp(self):
        self.name = 'xlb-auto-' + 'a' * 32
        self.environment = patch.dict(os.environ, {'XLB_NODE_ACCOUNT': '123456789012', 'XLB_NODE_REGION': 'us-west-2', 'XLB_NODE_PROFILE': 'test', 'XLB_NODE_CREDENTIAL_SOURCE': 'profile'})
        self.environment.start()
        self.addCleanup(self.environment.stop)

    def manager(self, **overrides):
        with patch.object(Manager, 'aws', return_value={'Account': '123456789012'}):
            return Manager({'name': self.name, 'resources': {}, **overrides})

    def test_rejects_shared_hosts_before_cloud_access(self):
        with patch.object(Manager, 'aws') as aws:
            for identity in ['i-088064ceb5bc65858', 'i-08eb42c0d337acd8e']:
                with self.assertRaisesRegex(CloudError, 'protected_instance'):
                    Manager({'name': self.name, 'resources': {'InstanceId': identity}})
            aws.assert_not_called()

    def test_rejects_unrelated_stack_names_regions_and_accounts(self):
        with self.assertRaises(CloudError):
            self.manager(name='msll-next-alb')
        with patch.dict(os.environ, {'XLB_NODE_REGION': 'ap-east-1'}):
            with self.assertRaises(CloudError):
                self.manager()
        with patch.object(Manager, 'aws', return_value={'Account': '000000000000'}):
            with self.assertRaisesRegex(CloudError, 'account_mismatch'):
                Manager({'name': self.name})

    def test_wrong_ownership_never_deletes(self):
        manager = self.manager()
        with patch.object(manager, 'aws', return_value={'Stacks': [{'Tags': [], 'StackId': 'other'}]}) as aws:
            with self.assertRaisesRegex(CloudError, 'ownership_mismatch'):
                manager.release()
            self.assertEqual(aws.call_count, 1)
            self.assertNotIn('delete-stack', aws.call_args.args)

    def test_deleted_stack_with_residual_resources_is_not_released(self):
        manager = self.manager()
        with patch.object(manager, 'stack', return_value=None), patch.object(manager, 'absent', return_value=False):
            self.assertEqual(manager.release()['state'], 'deleting')

    def test_resource_identity_change_or_unsupported_resource_prevents_deletion(self):
        manager = self.manager(resources={'InstanceId': 'i-' + '1' * 17})
        for resource in [
            {'LogicalResourceId': 'Node', 'ResourceType': 'AWS::EC2::Instance', 'PhysicalResourceId': 'i-' + '2' * 17},
            {'LogicalResourceId': 'Other', 'ResourceType': 'AWS::RDS::DBInstance', 'PhysicalResourceId': 'production'},
        ]:
            with patch.object(manager, 'aws', return_value={'StackResourceSummaries': [resource]}):
                with self.assertRaises(CloudError):
                    manager.inventory({'StackId': 'owned-stack'})

    def test_profile_action_is_callable_and_has_no_direct_fallback(self):
        manager = self.manager()
        with patch.object(manager, 'credentials', return_value={'name': 'test', 'type': 'ss', 'password': 'test-only'}):
            result = getattr(manager, 'profile')()
            profile = json.loads(result['profile'])
            self.assertEqual(profile['proxy-groups'][0]['proxies'], ['test'])
            self.assertNotIn('DIRECT', json.dumps(profile))

    def test_workload_role_ignores_static_profile_secrets_and_endpoint_overrides(self):
        poisoned = {
            'XLB_NODE_ACCOUNT': '123456789012', 'XLB_NODE_REGION': 'us-west-2',
            'XLB_NODE_PROFILE': '', 'XLB_NODE_CREDENTIAL_SOURCE': 'workload_role',
            'XLB_NODE_PROVISION_ROLE': 'arn:aws:iam::123456789012:role/XlbNodeProvisionRole',
            'AWS_PROFILE': 'developer', 'AWS_ACCESS_KEY_ID': 'not-used',
            'AWS_SECRET_ACCESS_KEY': 'not-used', 'AWS_ENDPOINT_URL': 'http://127.0.0.1:9999',
        }
        with patch.dict(os.environ, poisoned), patch('node_manager.subprocess.run', return_value=subprocess.CompletedProcess([], 0, '{"Account":"123456789012"}', '')) as run:
            manager = Manager({'name': self.name})
        command = run.call_args.args[0]
        child_environment = run.call_args.kwargs['env']
        self.assertNotIn('--profile', command)
        self.assertNotIn('AWS_PROFILE', child_environment)
        self.assertNotIn('AWS_ACCESS_KEY_ID', child_environment)
        self.assertNotIn('AWS_SECRET_ACCESS_KEY', child_environment)
        self.assertNotIn('AWS_ENDPOINT_URL', child_environment)
        self.assertEqual(manager.credential_source, 'workload_role')

    def test_control_plane_role_tag_is_always_protected(self):
        manager = self.manager(resources={'InstanceId': 'i-' + '1' * 17})
        stack = {'StackId': 'owned-stack'}
        with patch.object(manager, 'aws', return_value={'StackResourceSummaries': [{'LogicalResourceId': 'Node', 'ResourceType': 'AWS::EC2::Instance', 'PhysicalResourceId': 'i-' + '1' * 17}]}), patch.object(manager, 'instance', return_value={'State': {'Name': 'running'}, 'Tags': [{'Key': 'XlbRole', 'Value': 'control-plane'}]}):
            with self.assertRaisesRegex(CloudError, 'protected_control_plane'):
                manager.inventory(stack)

    def test_control_plane_tag_is_rejected_even_after_instance_termination(self):
        manager = self.manager(resources={'InstanceId': 'i-' + '1' * 17})
        with patch.object(manager, 'aws', return_value={'Reservations': [{'Instances': [{'State': {'Name': 'terminated'}, 'Tags': [{'Key': 'SystemRole', 'Value': 'control-plane'}]}]}]}):
            with self.assertRaisesRegex(CloudError, 'protected_control_plane'):
                manager.instance()

    def test_release_is_idempotent_and_does_not_duplicate_deletion(self):
        manager = self.manager()
        with patch.object(manager, 'stack', return_value={'StackId': 'owned', 'StackStatus': 'DELETE_IN_PROGRESS'}), patch.object(manager, 'inventory'), patch.object(manager, 'aws') as aws:
            self.assertEqual(manager.release()['state'], 'deleting')
            aws.assert_not_called()

    def test_deleted_stack_is_queried_by_immutable_arn_and_still_inventoried(self):
        identity = f'arn:aws:cloudformation:us-west-2:123456789012:stack/{self.name}/abc'
        manager = self.manager(resources={'StackId': identity})
        tags = [{'Key': key, 'Value': value} for key, value in {'Project': 'xlb', 'Environment': 'test', 'ManagedBy': MANAGER, 'DeploymentId': self.name, 'XlbRole': 'dedicated-test-node'}.items()]
        stack = {'StackId': identity, 'StackStatus': 'DELETE_COMPLETE', 'Tags': tags}
        with patch.object(manager, 'aws', return_value={'Stacks': [stack]}) as aws, patch.object(manager, 'inventory') as inventory, patch.object(manager, 'absent', return_value=True):
            self.assertEqual(manager.inspect()['state'], 'released')
            self.assertEqual(aws.call_args.kwargs['missing'], ['does not exist'])
            self.assertIn(identity, aws.call_args.args)
            inventory.assert_called_once_with(stack)

    def test_exact_security_group_residue_blocks_released_even_after_tag_change(self):
        manager = self.manager(resources={'SecurityGroupId': 'sg-' + '1' * 17})
        def response(*args, **kwargs):
            if '--group-ids' in args:
                return {'SecurityGroups': [{'GroupId': 'sg-' + '1' * 17}]}
            return {}
        with patch.object(manager, 'aws', side_effect=response):
            self.assertFalse(manager.absent())

    def test_cloud_expiry_precedes_instance_and_deletes_entire_stack(self):
        template = json.loads((Path(__file__).parent / 'aws-node-manager.template.json').read_text())
        resources = template['Resources']
        self.assertEqual(resources['Node']['DependsOn'], 'ExpirySchedule')
        self.assertTrue(resources['Node']['Properties']['BlockDeviceMappings'][0]['Ebs']['DeleteOnTermination'])
        self.assertEqual(resources['ExpirySchedule']['Properties']['Target']['Arn'], 'arn:aws:scheduler:::aws-sdk:cloudformation:deleteStack')
        self.assertEqual(resources['NodeSecurityGroup']['Properties']['SecurityGroupIngress'][0]['CidrIp'], {'Ref': 'ClientCidr'})
        for resource in resources.values():
            tags = resource.get('Properties', {}).get('Tags', [])
            if tags:
                self.assertIn({'Key': 'XlbRole', 'Value': 'dedicated-test-node'}, tags)
        self.assertNotIn('AWS::EC2::EIP', json.dumps(template))

    def test_cleanup_write_permissions_are_scoped(self):
        template = json.loads((Path(__file__).parent / 'aws-node-manager-access.template.json').read_text())
        statements = template['Resources']['CleanupRole']['Properties']['Policies'][0]['PolicyDocument']['Statement']
        actions = [
            action
            for statement in statements
            for action in (statement['Action'] if isinstance(statement['Action'], list) else [statement['Action']])
        ]
        self.assertEqual(actions, ['cloudformation:DeleteStack'])
        for statement in statements:
            actions = statement['Action'] if isinstance(statement['Action'], list) else [statement['Action']]
            if statement['Resource'] == '*':
                self.assertTrue(all(action.startswith('ec2:Describe') for action in actions))
            if 'ec2:TerminateInstances' in actions:
                self.assertEqual(statement['Condition']['StringEquals']['ec2:ResourceTag/ManagedBy'], MANAGER)

    def test_control_plane_workload_role_uses_a_scoped_cloudformation_execution_role(self):
        template = json.loads((Path(__file__).parent / 'aws-node-manager-access.template.json').read_text())
        resources = template['Resources']
        manager = resources['ManagerRole']['Properties']
        self.assertEqual(manager['AssumeRolePolicyDocument']['Statement'][0]['Principal']['Service'], 'ec2.amazonaws.com')
        self.assertEqual(resources['ManagerProfile']['Properties']['Roles'], [{'Ref': 'ManagerRole'}])

        manager_statements = manager['Policies'][0]['PolicyDocument']['Statement']
        manager_actions = [action for statement in manager_statements for action in (statement['Action'] if isinstance(statement['Action'], list) else [statement['Action']])]
        self.assertIn('cloudformation:CreateStack', manager_actions)
        self.assertIn('iam:PassRole', manager_actions)
        self.assertNotIn('ec2:RunInstances', manager_actions)
        self.assertNotIn('ec2:TerminateInstances', manager_actions)
        self.assertNotIn('ec2:DeleteSecurityGroup', manager_actions)
        self.assertIn('stack/xlb-auto-*/*', json.dumps(manager_statements))

        provision = resources['ProvisionRole']['Properties']
        trust = provision['AssumeRolePolicyDocument']['Statement'][0]
        self.assertEqual(trust['Principal']['Service'], 'cloudformation.amazonaws.com')
        self.assertEqual(trust['Condition']['StringEquals']['aws:SourceAccount'], {'Ref': 'AWS::AccountId'})
        self.assertIn('stack/xlb-auto-*/*', json.dumps(trust['Condition']['ArnLike']['aws:SourceArn']))
        provision_statements = provision['Policies'][0]['PolicyDocument']['Statement']
        self.assertIn('ec2:TerminateInstances', json.dumps(provision_statements))
        self.assertIn('aws:RequestTag/XlbRole', json.dumps(provision_statements))

        pass_role_statements = [statement for statement in provision_statements if statement['Action'] == 'iam:PassRole']
        self.assertEqual(len(pass_role_statements), 2)
        passed_services = {
            statement['Resource']['Fn::Sub'].rsplit('/', 1)[-1]: statement['Condition']['StringEquals']['iam:PassedToService']
            for statement in pass_role_statements
        }
        self.assertEqual(passed_services, {
            'XlbNodeSsmRole': 'ec2.amazonaws.com',
            'XlbNodeCleanupRole': 'scheduler.amazonaws.com',
        })

        create_secret = next(statement for statement in provision_statements if statement['Action'] == 'secretsmanager:CreateSecret')
        self.assertIn('aws:RequestTag/Project', json.dumps(create_secret['Condition']))
        self.assertNotIn('aws:ResourceTag', json.dumps(create_secret['Condition']))
        schedule = next(statement for statement in provision_statements if 'scheduler:CreateSchedule' in statement['Action'])
        self.assertNotIn('aws:RequestTag', json.dumps(schedule))
        self.assertNotIn('scheduler:TagResource', json.dumps(schedule))
        self.assertIn('schedule/default/xlb-auto-*-expiry', schedule['Resource']['Fn::Sub'])

        cleanup_statements = resources['CleanupRole']['Properties']['Policies'][0]['PolicyDocument']['Statement']
        delete_stack = next(statement for statement in cleanup_statements if 'cloudformation:DeleteStack' in statement['Action'])
        self.assertIn('aws:ResourceTag/XlbRole', delete_stack['Condition']['StringEquals'])
        self.assertEqual(delete_stack['Condition']['StringLike']['aws:ResourceTag/DeploymentId'], 'xlb-auto-*')
        self.assertTrue(all(key in template['Outputs'] for key in [
            'ManagerProfileArn', 'ManagerRoleArn', 'ProvisionRoleArn', 'NodeProfileArn', 'CleanupRoleArn',
        ]))

    def test_create_passes_the_fixed_execution_role_to_cloudformation(self):
        expires_at = (datetime.now(timezone.utc) + timedelta(minutes=10)).isoformat()
        environment = {
            'XLB_NODE_VPC': 'vpc-' + '1' * 8,
            'XLB_NODE_SUBNET': 'subnet-' + '1' * 8,
            'XLB_NODE_IMAGE': 'ami-' + '1' * 17,
            'XLB_NODE_INSTANCE_PROFILE': 'XlbNodeSsmProfile',
            'XLB_NODE_CLEANUP_ROLE': 'arn:aws:iam::123456789012:role/XlbNodeCleanupRole',
            'XLB_NODE_CLIENT_CIDR': '8.8.8.8/32',
            'XLB_NODE_PROVISION_ROLE': 'arn:aws:iam::123456789012:role/XlbNodeProvisionRole',
        }
        with patch.dict(os.environ, environment):
            manager = self.manager(expires_at=expires_at, instance_type='t3.micro')
            with patch.object(manager, 'aws', side_effect=[None, {'StackId': 'test-stack'}]) as aws:
                manager.create()
        command = aws.call_args.args
        self.assertEqual(command[0], 'cloudformation')
        self.assertIn('--role-arn', command)
        self.assertEqual(command[command.index('--role-arn') + 1], environment['XLB_NODE_PROVISION_ROLE'])


if __name__ == '__main__':
    unittest.main()
