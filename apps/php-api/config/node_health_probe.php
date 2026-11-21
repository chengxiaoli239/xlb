<?php

return [
    'enabled' => (bool) env('NODE_HEALTH_PROBE_ENABLED', false),
    'batch_size' => (int) env('NODE_HEALTH_PROBE_BATCH_SIZE', 25),
];
