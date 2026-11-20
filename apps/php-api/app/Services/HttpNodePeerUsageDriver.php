<?php

namespace App\Services;

class HttpNodePeerUsageDriver implements NodePeerUsageDriver
{
    public function __construct(private NodeAccessAgentHttpClient $client) {}

    public function usage(object $node, object $peer): array
    {
        return $this->client->get($node, rawurlencode((string) $peer->id).'/usage');
    }
}
