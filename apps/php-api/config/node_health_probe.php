<?php

return [
    'enabled' => (bool) env('NODE_HEALTH_PROBE_ENABLED', false),
    'batch_size' => (int) env('NODE_HEALTH_PROBE_BATCH_SIZE', 25),
    'route_filter_enabled' => (bool) env('NODE_HEALTH_PROBE_ROUTE_FILTER_ENABLED', false),
    'route_state_ttl_seconds' => (int) env('NODE_HEALTH_PROBE_ROUTE_STATE_TTL_SECONDS', 30),
];
