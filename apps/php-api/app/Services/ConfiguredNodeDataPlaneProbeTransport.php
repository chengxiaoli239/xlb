<?php

namespace App\Services;

use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class ConfiguredNodeDataPlaneProbeTransport implements NodeDataPlaneProbeTransport
{
    /**
     * @param  array<string, array{url?: mixed, ca_bundle?: mixed}>  $endpoints
     */
    public function __construct(
        private readonly array $endpoints,
        private readonly ?string $clientCertificate,
        private readonly ?string $clientKey,
    ) {}

    public function exchange(string $nodeId, string $challenge, int $timeoutMs): NodeDataPlaneProbeExchange
    {
        if (trim($nodeId) === '' || ! preg_match('/^[a-f0-9]{64}$/', $challenge) || $timeoutMs < 1 || $timeoutMs > 30_000) {
            throw new NodeDataPlaneProbeTransportException(NodeDataPlaneProbeFailure::INVALID_PROBE_RESPONSE);
        }

        if (! $this->isReadableFile($this->clientCertificate) || ! $this->isReadableFile($this->clientKey)) {
            throw new NodeDataPlaneProbeTransportException(NodeDataPlaneProbeFailure::CLIENT_IDENTITY_UNAVAILABLE);
        }

        $endpoint = $this->endpoints[$nodeId] ?? null;
        if (! is_array($endpoint) || ! $this->isValidHttpsOrigin($endpoint['url'] ?? null)) {
            throw new NodeDataPlaneProbeTransportException(NodeDataPlaneProbeFailure::SERVER_IDENTITY_UNVERIFIED);
        }

        $caBundle = $endpoint['ca_bundle'] ?? null;
        if (! $this->isReadableFile($caBundle)) {
            throw new NodeDataPlaneProbeTransportException(NodeDataPlaneProbeFailure::SERVER_IDENTITY_UNVERIFIED);
        }

        $timeoutSeconds = $timeoutMs / 1000;
        try {
            $response = Http::acceptJson()->asJson()->timeout($timeoutSeconds)->withOptions([
                'allow_redirects' => false,
                'cert' => $this->clientCertificate,
                'ssl_key' => $this->clientKey,
                'verify' => $caBundle,
                'connect_timeout' => $timeoutSeconds,
            ])->post(rtrim($endpoint['url'], '/').'/v1/node/probe', ['challenge' => $challenge]);
        } catch (ConnectionException $exception) {
            $failureCode = $this->hasTimeoutCause($exception)
                ? NodeDataPlaneProbeFailure::TIMEOUT
                : NodeDataPlaneProbeFailure::TRANSPORT_ERROR;
            throw new NodeDataPlaneProbeTransportException($failureCode);
        } catch (Throwable) {
            throw new NodeDataPlaneProbeTransportException(NodeDataPlaneProbeFailure::TRANSPORT_ERROR);
        }

        $payload = $response->json();
        if (! is_array($payload) || ! is_array($payload['data'] ?? null)) {
            throw new NodeDataPlaneProbeTransportException(NodeDataPlaneProbeFailure::INVALID_PROBE_RESPONSE);
        }

        $data = $payload['data'];
        $authenticatedNodeId = is_string($data['node_id'] ?? null) ? $data['node_id'] : null;
        $echoedChallenge = is_string($data['challenge'] ?? null) ? $data['challenge'] : null;

        return new NodeDataPlaneProbeExchange(
            clientIdentityPresented: true,
            mutualTlsEstablished: true,
            serverIdentityVerified: true,
            authenticatedNodeId: $authenticatedNodeId,
            dataPlaneProbeAccepted: $response->successful(),
            echoedChallenge: $echoedChallenge,
        );
    }

    private function isReadableFile(mixed $path): bool
    {
        return is_string($path) && $path !== '' && is_file($path) && is_readable($path);
    }

    private function hasTimeoutCause(Throwable $exception): bool
    {
        while ($exception instanceof Throwable) {
            if ($exception instanceof ConnectTimeoutException
                || $exception instanceof NetworkTimeoutException
                || $exception instanceof ResponseTimeoutException) {
                return true;
            }
            $exception = $exception->getPrevious();
        }

        return false;
    }

    private function isValidHttpsOrigin(mixed $url): bool
    {
        if (! is_string($url) || $url === '') {
            return false;
        }

        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')) {
            return false;
        }

        if (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535)) {
            return false;
        }

        return true;
    }
}
