<?php

return [
    'scope' => env('SUBSCRIPTION_ROUTE_SCOPE', 'test'),
    'drain_seconds' => (int) env('SUBSCRIPTION_ROUTE_DRAIN_SECONDS', 900),
];
