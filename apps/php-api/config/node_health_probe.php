<?php

return [
    'enabled' => (bool) env('NODE_HEALTH_PROBE_ENABLED', false),
    'transport' => env('NODE_HEALTH_PROBE_TRANSPORT', 'disabled'),
    'timeout_ms' => (int) env('NODE_HEALTH_PROBE_TIMEOUT_MS', 5000),
    'client_certificate' => env('NODE_HEALTH_PROBE_CLIENT_CERT'),
    'client_key' => env('NODE_HEALTH_PROBE_CLIENT_KEY'),
    'endpoints' => json_decode((string) env('NODE_HEALTH_PROBE_ENDPOINTS', '[]'), true) ?: [],
    'batch_size' => (int) env('NODE_HEALTH_PROBE_BATCH_SIZE', 25),
    'route_filter_enabled' => (bool) env('NODE_HEALTH_PROBE_ROUTE_FILTER_ENABLED', false),
    'route_state_ttl_seconds' => (int) env('NODE_HEALTH_PROBE_ROUTE_STATE_TTL_SECONDS', 30),
];
