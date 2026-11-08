<?php

use App\Services\NodeProviders\AwsNodeProvider;

return [
    'enabled' => (bool) env('NODE_AUTOMATION_ENABLED', false),
    'default_provider' => env('NODE_DEFAULT_PROVIDER', 'aws'),
    'drivers' => [
        'aws' => [
            'class' => AwsNodeProvider::class,
            'credential_source' => env('NODE_AWS_CREDENTIAL_SOURCE', 'profile'),
            'profile' => env('NODE_AWS_PROFILE', env('NODE_AWS_CREDENTIAL_SOURCE', 'profile') === 'profile' ? 'xlb-ops' : ''),
            'aws_binary' => env('NODE_AWS_BINARY', 'aws'),
            'account' => env('NODE_AWS_ACCOUNT', ''),
            'region' => 'us-west-2',
            'region_label' => '美国西部 · 自动化测试',
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
