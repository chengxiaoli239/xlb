<?php

use App\Services\NodeProviders\AwsNodeProvider;

return [
    'enabled' => (bool) env('NODE_AUTOMATION_ENABLED', false),
    'default_profile' => env('NODE_DEFAULT_PROFILE', 'aws-us-west-2-lab-v1'),
    'drivers' => [
        'aws' => [
            'class' => AwsNodeProvider::class,
        ],
    ],
    'profiles' => [
        'aws-us-west-2-lab-v1' => [
            'provider' => 'aws',
            'enabled' => true,
            'version' => 1,
            'label' => 'AWS 美国西部测试',
            'account_id' => env('NODE_AWS_ACCOUNT', ''),
            'region' => 'us-west-2',
            'region_label' => '美国西部 · 自动化测试',
            'protocol' => 'SS2022',
            'endpoint_port' => 18443,
            'instance_types' => [
                't3.micro' => 't3.micro · 2 核 / 1GB',
                't3.small' => 't3.small · 2 核 / 2GB',
            ],
            'lifetime_minutes' => [15, 30, 60],
            'credential_source' => env('NODE_AWS_CREDENTIAL_SOURCE', 'profile'),
            'profile' => env('NODE_AWS_PROFILE', env('NODE_AWS_CREDENTIAL_SOURCE', 'profile') === 'profile' ? 'xlb-ops' : ''),
            'aws_binary' => env('NODE_AWS_BINARY', 'aws'),
            'vpc' => env('NODE_AWS_VPC', ''),
            'subnet' => env('NODE_AWS_SUBNET', ''),
            'image' => env('NODE_AWS_IMAGE', ''),
            'client_cidr' => env('NODE_CLIENT_CIDR', ''),
            'instance_profile' => 'XlbNodeSsmProfile',
            'cleanup_role' => env('NODE_CLEANUP_ROLE', ''),
            'provision_role' => env('NODE_AWS_PROVISION_ROLE', ''),
        ],
    ],
    'max_nodes' => 2,
    'drain_seconds' => 60,
    'worker' => [
        'python' => env('NODE_PYTHON', '/usr/bin/python3'),
        'mihomo' => env('NODE_MIHOMO', '/usr/local/bin/mihomo'),
    ],
];
