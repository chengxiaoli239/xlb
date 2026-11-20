<?php

namespace App\Services;

interface NodePeerUsageDriver
{
    /** @return array<string, mixed> */
    public function usage(object $node, object $peer): array;
}
