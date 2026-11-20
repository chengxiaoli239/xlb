<?php

namespace App\Services;

interface NodePeerProvisioningDriver
{
    /**
     * Provision a device peer idempotently. The request ID and generation stay stable across retries.
     *
     * @return array{
     *     node_id:string,
     *     peer_id:string,
     *     request_id:string,
     *     generation:int,
     *     acknowledgement_id:string,
     *     profile?:string,
     *     profile_artifact?:array{
     *         node_id:string,peer_id:string,entitlement_id:string,request_id:string,device_id:string,
     *         generation:int,device_key_fingerprint:string,
     *         device_binding_generation:int,format:'mihomo-yaml'|'xlb-client-manifest-v1',renderer_id:string,
     *         renderer_version:string,sha256:string,bridge_protocol:'xlb-proof-v1',connectable:bool,content:string
     *     },
     *     confirmation_status:'confirmed'|'confirmed_test'
     * }
     */
    public function provision(object $node, object $peer, string $requestId, int $generation, string $credential): array;
}
