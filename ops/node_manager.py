#!/usr/bin/env python3
"""Restricted bridge for isolated XLB test nodes. JSON stdin/stdout, no provider logs."""
import base64
import ipaddress
import json
import os
from pathlib import Path
import re
import socket
import subprocess
import sys
import tempfile
from datetime import datetime, timezone

from node_probe import free_port, http_probe, wait_port

REGION = 'us-west-2'
MANAGER = 'xlb-node-manager'
NODE_ROLE = 'dedicated-test-node'
CONTROL_PLANE_ROLES = {'control-plane', 'command-center', 'shared-host'}
PROTECTED = {'i-088064ceb5bc65858', 'i-08eb42c0d337acd8e'}
TYPES = {'AWS::EC2::Instance', 'AWS::EC2::SecurityGroup', 'AWS::SecretsManager::Secret', 'AWS::Scheduler::Schedule'}
ROOT = Path(__file__).resolve().parent


class CloudError(RuntimeError):
    pass


class Manager:
    def __init__(self, request):
        self.request = request
        self.name = request.get('name', '')
        if not re.fullmatch(r'xlb-auto-[a-f0-9]{32}', self.name):
            raise CloudError('invalid_identity')
        self.account = os.environ.get('XLB_NODE_ACCOUNT', '')
        if not re.fullmatch(r'[0-9]{12}', self.account) or os.environ.get('XLB_NODE_REGION') != REGION:
            raise CloudError('invalid_scope')
        self.credential_source = os.environ.get('XLB_NODE_CREDENTIAL_SOURCE', 'profile')
        self.aws_profile = os.environ.get('XLB_NODE_PROFILE', '')
        self.provision_role = os.environ.get('XLB_NODE_PROVISION_ROLE', '')
        if self.credential_source == 'profile':
            if not re.fullmatch(r'[A-Za-z0-9_-]{1,64}', self.aws_profile):
                raise CloudError('invalid_profile')
        elif self.credential_source == 'workload_role':
            if self.aws_profile:
                raise CloudError('profile_forbidden_with_workload_role')
            if self.provision_role != f'arn:aws:iam::{self.account}:role/XlbNodeProvisionRole':
                raise CloudError('invalid_roles')
        else:
            raise CloudError('invalid_credential_source')
        self.resources = request.get('resources') or {}
        if not isinstance(self.resources, dict):
            raise CloudError('invalid_resources')
        if self.resources.get('InstanceId') in PROTECTED:
            raise CloudError('protected_instance')
        if self.aws('sts', 'get-caller-identity')['Account'] != self.account:
            raise CloudError('account_mismatch')

    def aws(self, *args, missing=None):
        command = [os.environ.get('XLB_NODE_AWS_BINARY', 'aws')]
        child_environment = os.environ.copy()
        if self.credential_source == 'profile':
            command.extend(['--profile', self.aws_profile])
        else:
            for key in ['AWS_PROFILE', 'AWS_DEFAULT_PROFILE', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_SESSION_TOKEN', 'AWS_SHARED_CREDENTIALS_FILE', 'AWS_CONFIG_FILE', 'AWS_WEB_IDENTITY_TOKEN_FILE', 'AWS_ROLE_ARN']:
                child_environment.pop(key, None)
            for key in list(child_environment):
                if key == 'AWS_ENDPOINT_URL' or key.startswith('AWS_ENDPOINT_URL_'):
                    child_environment.pop(key, None)
            child_environment['AWS_EC2_METADATA_DISABLED'] = 'false'
        command.extend(['--region', REGION, '--no-cli-pager', '--output', 'json', *args])
        process = subprocess.run(command, capture_output=True, text=True, timeout=45, env=child_environment)
        if process.returncode:
            if missing and any(code in process.stderr for code in missing):
                return None
            raise CloudError('aws_request_failed')
        return json.loads(process.stdout) if process.stdout.strip() else {}

    def stack(self):
        identity = self.resources.get('StackId', self.name)
        if identity != self.name and not identity.startswith(f'arn:aws:cloudformation:{REGION}:{self.account}:stack/{self.name}/'):
            raise CloudError('stack_scope_mismatch')
        result = self.aws('cloudformation', 'describe-stacks', '--stack-name', identity, missing=['does not exist'])
        if result is None:
            return None
        stack = result['Stacks'][0]
        expected = {'Project': 'xlb', 'Environment': 'test', 'ManagedBy': MANAGER, 'DeploymentId': self.name, 'XlbRole': NODE_ROLE}
        tags = {tag['Key']: tag['Value'] for tag in stack.get('Tags', [])}
        if any(tags.get(key) != value for key, value in expected.items()):
            raise CloudError('ownership_mismatch')
        if not stack['StackId'].startswith(f'arn:aws:cloudformation:{REGION}:{self.account}:stack/{self.name}/'):
            raise CloudError('stack_scope_mismatch')
        return stack

    def inventory(self, stack):
        result = self.aws('cloudformation', 'list-stack-resources', '--stack-name', stack['StackId'])
        resources = dict(self.resources)
        resources['StackId'] = stack['StackId']
        mapping = {'Node': 'InstanceId', 'NodeSecurityGroup': 'SecurityGroupId', 'Credential': 'SecretArn', 'ExpirySchedule': 'ScheduleId'}
        for item in result.get('StackResourceSummaries', []):
            if item['ResourceType'] not in TYPES or item['LogicalResourceId'] not in mapping:
                raise CloudError('unsupported_resource')
            physical = item.get('PhysicalResourceId')
            if not physical:
                continue
            key = mapping[item['LogicalResourceId']]
            if key == 'InstanceId' and physical in PROTECTED:
                raise CloudError('protected_instance')
            if resources.get(key) and resources[key] != physical:
                raise CloudError('resource_identity_changed')
            resources[key] = physical
        self.resources = resources
        instance = self.instance()
        if instance and instance['State']['Name'] != 'terminated':
            self.require_tags(instance.get('Tags', []))
            resources['PublicIp'] = instance.get('PublicIpAddress', '')
            resources['VolumeIds'] = sorted(item['Ebs']['VolumeId'] for item in instance.get('BlockDeviceMappings', []) if 'Ebs' in item)
            resources['NetworkInterfaceIds'] = sorted(item['NetworkInterfaceId'] for item in instance.get('NetworkInterfaces', []))
        return resources

    def require_tags(self, tags):
        self.reject_control_plane_tags(tags)
        values = {tag['Key']: tag['Value'] for tag in tags}
        if any(values.get(key) != val for key, val in {'Project': 'xlb', 'Environment': 'test', 'ManagedBy': MANAGER, 'DeploymentId': self.name, 'XlbRole': NODE_ROLE}.items()):
            raise CloudError('resource_ownership_mismatch')

    @staticmethod
    def reject_control_plane_tags(tags):
        values = {tag['Key']: str(tag['Value']).strip().lower() for tag in tags}
        if any(values.get(key) in CONTROL_PLANE_ROLES for key in ['XlbRole', 'SystemRole', 'Purpose']):
            raise CloudError('protected_control_plane')

    def instance(self):
        instance_id = self.resources.get('InstanceId')
        if not instance_id:
            return None
        if not re.fullmatch(r'i-[a-f0-9]{17}', instance_id) or instance_id in PROTECTED:
            raise CloudError('invalid_instance')
        result = self.aws('ec2', 'describe-instances', '--instance-ids', instance_id, missing=['InvalidInstanceID.NotFound'])
        if not result or not result['Reservations']:
            return None
        instance = result['Reservations'][0]['Instances'][0]
        self.reject_control_plane_tags(instance.get('Tags', []))

        return instance

    def create(self):
        if self.stack():
            return self.inspect()
        size = self.request.get('instance_type')
        if size not in {'t3.micro', 't3.small'}:
            raise CloudError('invalid_size')
        expires = datetime.fromisoformat(self.request['expires_at'].replace('Z', '+00:00'))
        remaining = (expires - datetime.now(timezone.utc)).total_seconds()
        if not 60 < remaining <= 3600:
            raise CloudError('invalid_expiry')
        cidr = ipaddress.ip_network(os.environ['XLB_NODE_CLIENT_CIDR'])
        if cidr.version != 4 or cidr.prefixlen != 32 or not cidr.network_address.is_global:
            raise CloudError('invalid_test_source')
        params = {
            'VpcId': os.environ['XLB_NODE_VPC'], 'SubnetId': os.environ['XLB_NODE_SUBNET'], 'ImageId': os.environ['XLB_NODE_IMAGE'],
            'InstanceType': size, 'InstanceProfile': os.environ['XLB_NODE_INSTANCE_PROFILE'], 'CleanupRoleArn': os.environ['XLB_NODE_CLEANUP_ROLE'],
            'ExpiresAtUtc': expires.astimezone(timezone.utc).strftime('%Y-%m-%dT%H:%M:%S'), 'NodeId': self.name, 'ClientCidr': str(cidr),
        }
        if params['InstanceProfile'] != 'XlbNodeSsmProfile' or params['CleanupRoleArn'] != f'arn:aws:iam::{self.account}:role/XlbNodeCleanupRole':
            raise CloudError('invalid_roles')
        arguments = [
            'cloudformation', 'create-stack', '--stack-name', self.name,
            '--template-body', 'file://' + str(ROOT / 'aws-node-manager.template.json'),
            '--parameters', json.dumps([{'ParameterKey': k, 'ParameterValue': v} for k, v in params.items()]),
            '--tags', json.dumps([{'Key': k, 'Value': v} for k, v in {'Project': 'xlb', 'Environment': 'test', 'ManagedBy': MANAGER, 'DeploymentId': self.name, 'XlbRole': NODE_ROLE}.items()]),
            '--timeout-in-minutes', '10', '--on-failure', 'DELETE', '--client-request-token', self.name,
        ]
        if self.provision_role:
            arguments.extend(['--role-arn', self.provision_role])
        result = self.aws(*arguments)
        return {'state': 'building', 'resources': {'StackId': result['StackId']}}

    def inspect(self):
        stack = self.stack()
        if not stack or stack['StackStatus'] == 'DELETE_COMPLETE':
            if stack:
                self.inventory(stack)
            return {'state': 'released' if self.absent() else 'deleting', 'resources': self.resources}
        resources = self.inventory(stack)
        status = stack['StackStatus']
        if status.startswith('DELETE_'):
            state = 'deleting'
        elif 'FAILED' in status or 'ROLLBACK' in status:
            state = 'failed'
        elif status == 'CREATE_COMPLETE':
            state = 'bootstrapping'
            host = resources.get('PublicIp')
            if host:
                try:
                    with socket.create_connection((host, 18443), timeout=3):
                        state = 'ready'
                except OSError:
                    pass
        else:
            state = 'building'
        return {'state': state, 'resources': resources}

    def absent(self):
        """Do not infer cleanup from a missing DB field or a deleted stack alone."""
        tagged = self.aws('ec2', 'describe-instances', '--filters', f'Name=tag:DeploymentId,Values={self.name}', 'Name=instance-state-name,Values=pending,running,shutting-down,stopping,stopped')
        if any(r['Instances'] for r in tagged.get('Reservations', [])):
            return False
        instance = self.instance()
        if instance and instance['State']['Name'] != 'terminated':
            raise CloudError('unexpected_live_resource')
        filters = ['Name=tag:ManagedBy,Values=' + MANAGER, 'Name=tag:DeploymentId,Values=' + self.name]
        for operation, key in [('describe-volumes', 'Volumes'), ('describe-security-groups', 'SecurityGroups')]:
            if self.aws('ec2', operation, '--filters', *filters).get(key):
                return False
        for volume in self.resources.get('VolumeIds', []):
            if not re.fullmatch(r'vol-[a-f0-9]{17}', volume):
                raise CloudError('invalid_volume')
            if self.aws('ec2', 'describe-volumes', '--volume-ids', volume, missing=['InvalidVolume.NotFound']):
                return False
        for interface in self.resources.get('NetworkInterfaceIds', []):
            if not re.fullmatch(r'eni-[a-f0-9]{17}', interface):
                raise CloudError('invalid_interface')
            if self.aws('ec2', 'describe-network-interfaces', '--network-interface-ids', interface, missing=['InvalidNetworkInterfaceID.NotFound']):
                return False
        security_group = self.resources.get('SecurityGroupId')
        if security_group:
            if not re.fullmatch(r'sg-[a-f0-9]{17}', security_group):
                raise CloudError('invalid_security_group')
            if self.aws('ec2', 'describe-security-groups', '--group-ids', security_group, missing=['InvalidGroup.NotFound']):
                return False
        groups = self.aws('ec2', 'describe-network-interfaces', '--filters', f'Name=group-id,Values={self.resources["SecurityGroupId"]}') if self.resources.get('SecurityGroupId') else {}
        if groups.get('NetworkInterfaces'):
            return False
        secret = self.aws('secretsmanager', 'describe-secret', '--secret-id', self.resources.get('SecretArn', 'xlb/auto/' + self.name), missing=['ResourceNotFoundException'])
        if secret:
            return False
        schedule = self.aws('scheduler', 'get-schedule', '--name', self.name + '-expiry', missing=['ResourceNotFoundException'])
        return schedule is None

    def release(self):
        stack = self.stack()
        if stack and stack['StackStatus'] != 'DELETE_COMPLETE':
            self.inventory(stack)
            if stack['StackStatus'] != 'DELETE_IN_PROGRESS':
                self.aws('cloudformation', 'delete-stack', '--stack-name', stack['StackId'], '--client-request-token', self.name + '-release')
            return {'state': 'deleting', 'resources': self.resources}
        return self.inspect()

    def credentials(self):
        if datetime.now(timezone.utc) >= datetime.fromisoformat(self.request['expires_at'].replace('Z', '+00:00')):
            raise CloudError('expired')
        state = self.inspect()
        if state['state'] != 'ready':
            raise CloudError('not_ready')
        secret = self.resources.get('SecretArn', '')
        if not secret.startswith(f'arn:aws:secretsmanager:{REGION}:{self.account}:secret:xlb/auto/{self.name}-'):
            raise CloudError('secret_identity_mismatch')
        self.require_tags(self.aws('secretsmanager', 'describe-secret', '--secret-id', secret).get('Tags', []))
        password = base64.b64encode(self.aws('secretsmanager', 'get-secret-value', '--secret-id', secret)['SecretString'].encode()).decode()
        host = str(ipaddress.IPv4Address(self.resources['PublicIp']))
        return {'name': 'XLB 管理员测试', 'type': 'ss', 'server': host, 'port': 18443, 'cipher': '2022-blake3-aes-256-gcm', 'password': password, 'udp': False}

    def profile(self):
        proxy = self.credentials()
        return {'profile': json.dumps({'mixed-port': 7890, 'allow-lan': False, 'mode': 'rule', 'log-level': 'error', 'proxies': [proxy], 'proxy-groups': [{'name': 'XLB-TEST', 'type': 'select', 'proxies': [proxy['name']]}], 'rules': ['MATCH,XLB-TEST']}, ensure_ascii=False)}

    def probe(self):
        proxy = self.credentials()
        binary = Path(os.environ['XLB_NODE_MIHOMO']).resolve(strict=True)
        with tempfile.TemporaryDirectory(prefix='xlb-node-') as directory:
            path = Path(directory)
            port = free_port()
            config = {'mixed-port': port, 'allow-lan': False, 'bind-address': '127.0.0.1', 'mode': 'rule', 'log-level': 'silent', 'proxies': [proxy], 'proxy-groups': [{'name': 'XLB-TEST', 'type': 'select', 'proxies': [proxy['name']]}], 'rules': ['MATCH,XLB-TEST']}
            file = path / 'config.json'
            file.write_text(json.dumps(config))
            file.chmod(0o600)
            process = subprocess.Popen([str(binary), '-d', directory, '-f', str(file)], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            try:
                wait_port(port, process)
                ip_probe = http_probe(port, 'https://checkip.amazonaws.com', path / 'ip')
                if (path / 'ip').read_text().strip() != proxy['server']:
                    raise CloudError('egress_mismatch')
                web_probe = http_probe(port, 'https://example.com', path / 'page')
                if 'Example Domain' not in (path / 'page').read_text():
                    raise CloudError('content_mismatch')
                return {'verified': True, 'public_ip': proxy['server'], 'checks': [ip_probe, web_probe]}
            finally:
                process.terminate()
                try:
                    process.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait(timeout=5)


def main():
    try:
        request = json.load(sys.stdin)
        action = request.get('action')
        if action not in {'create', 'inspect', 'probe', 'release', 'profile'}:
            raise CloudError('invalid_action')
        result = getattr(Manager(request), action)()
        print(json.dumps(result))
    except Exception as error:
        code = str(error) if isinstance(error, CloudError) else {
            FileNotFoundError: 'dependency_not_found', subprocess.TimeoutExpired: 'process_timeout',
            KeyError: 'configuration_missing', PermissionError: 'dependency_permission_denied',
        }.get(type(error), 'node_step_failed')
        print(json.dumps({'error': code}))
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
