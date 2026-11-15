<?php

return [
    'scope' => env('SUBSCRIPTION_ROUTE_SCOPE', 'test'),
    'drain_seconds' => (int) env('SUBSCRIPTION_ROUTE_DRAIN_SECONDS', 900),
    'health_check_ttl_seconds' => (int) env('SUBSCRIPTION_ROUTE_HEALTH_CHECK_TTL_SECONDS', 300),
];
