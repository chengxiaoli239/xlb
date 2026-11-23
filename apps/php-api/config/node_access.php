<?php

return [
    'enabled' => (bool) env('NODE_ACCESS_REVOCATION_ENABLED', false),
    'driver' => env('NODE_ACCESS_REVOCATION_DRIVER', 'disabled'),
    'provisioning_enabled' => (bool) env('NODE_ACCESS_PROVISIONING_ENABLED', false),
    'subscription_binding_enabled' => (bool) env('NODE_ACCESS_SUBSCRIPTION_BINDING_ENABLED', false),
    'usage_reconciliation_enabled' => (bool) env('NODE_ACCESS_USAGE_RECONCILIATION_ENABLED', false),
    'agent' => [
        'client_certificate' => env('NODE_ACCESS_AGENT_CLIENT_CERT'),
        'client_key' => env('NODE_ACCESS_AGENT_CLIENT_KEY'),
        'ca_bundle' => env('NODE_ACCESS_AGENT_CA_BUNDLE'),
        'timeout_seconds' => (int) env('NODE_ACCESS_AGENT_TIMEOUT_SECONDS', 5),
    ],
    'member_bridge' => [
        'ca_bundle' => env('NODE_ACCESS_MEMBER_BRIDGE_CA_BUNDLE'),
    ],
    'lease_seconds' => 60,
    'batch_size' => 25,
];
