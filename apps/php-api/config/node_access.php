<?php

return [
    'enabled' => (bool) env('NODE_ACCESS_REVOCATION_ENABLED', false),
    'driver' => env('NODE_ACCESS_REVOCATION_DRIVER', 'disabled'),
    'lease_seconds' => 60,
    'batch_size' => 25,
];
