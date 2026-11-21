<?php

namespace App\Services;

final class NodeDataPlaneProbeFailure
{
    public const TIMEOUT = 'probe_timeout';

    public const CLIENT_IDENTITY_UNAVAILABLE = 'client_identity_unavailable';

    public const MTLS_HANDSHAKE_FAILED = 'mtls_handshake_failed';

    public const SERVER_IDENTITY_UNVERIFIED = 'server_identity_unverified';

    public const NODE_IDENTITY_MISMATCH = 'node_identity_mismatch';

    public const DATA_PLANE_PROBE_REJECTED = 'data_plane_probe_rejected';

    public const PROBE_ECHO_MISMATCH = 'probe_echo_mismatch';

    public const INVALID_PROBE_RESPONSE = 'invalid_probe_response';

    public const TRANSPORT_ERROR = 'transport_error';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::TIMEOUT,
            self::CLIENT_IDENTITY_UNAVAILABLE,
            self::MTLS_HANDSHAKE_FAILED,
            self::SERVER_IDENTITY_UNVERIFIED,
            self::NODE_IDENTITY_MISMATCH,
            self::DATA_PLANE_PROBE_REJECTED,
            self::PROBE_ECHO_MISMATCH,
            self::INVALID_PROBE_RESPONSE,
            self::TRANSPORT_ERROR,
        ];
    }
}
