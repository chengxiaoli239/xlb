<?php

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

final class NodeDataPlaneProbeTransportException extends RuntimeException
{
    public function __construct(public readonly string $failureCode)
    {
        if (! in_array($failureCode, NodeDataPlaneProbeFailure::all(), true)) {
            throw new InvalidArgumentException('Unsupported node probe failure code.');
        }

        parent::__construct('Node data-plane probe transport failed.');
    }
}
