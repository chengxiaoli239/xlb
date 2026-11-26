<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class NodeAccessAgentHttpClient
{
    private const MAX_CREDENTIAL_FILE_BYTES = 1_048_576;

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function post(object $node, string $path, array $payload): array
    {
        $endpoint = $this->endpoint($node);
        $certificate = $this->requiredFile('client_certificate');
        $privateKey = $this->requiredFile('client_key');
        $caBundle = $this->requiredFile('ca_bundle');
        $timeout = max(1, min(15, (int) config('node_access.agent.timeout_seconds', 5)));

        $response = Http::acceptJson()->asJson()->timeout($timeout)->withOptions([
            'allow_redirects' => false,
            'cert' => $certificate,
            'ssl_key' => $privateKey,
            'verify' => $caBundle,
        ])->post($endpoint.'/v1/member-peers/'.$path, $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Node agent request failed.');
        }

        $result = $response->json();
        if (! is_array($result) || ! is_array($result['data'] ?? null)) {
            throw new RuntimeException('Node agent response is invalid.');
        }

        return $result['data'];
    }

    /** @return array<string, mixed> */
    public function get(object $node, string $path): array
    {
        if (! preg_match('/^[A-Za-z0-9_-]+\/usage$/', $path)) {
            throw new RuntimeException('Node agent read path is invalid.');
        }
        $endpoint = $this->endpoint($node);
        $certificate = $this->requiredFile('client_certificate');
        $privateKey = $this->requiredFile('client_key');
        $caBundle = $this->requiredFile('ca_bundle');
        $timeout = max(1, min(15, (int) config('node_access.agent.timeout_seconds', 5)));

        $response = Http::acceptJson()->timeout($timeout)->withOptions([
            'allow_redirects' => false,
            'cert' => $certificate,
            'ssl_key' => $privateKey,
            'verify' => $caBundle,
        ])->get($endpoint.'/v1/member-peers/'.$path);

        if (! $response->successful()) {
            throw new RuntimeException('Node agent usage request failed.');
        }
        $result = $response->json();
        if (! is_array($result) || ! is_array($result['data'] ?? null)) {
            throw new RuntimeException('Node agent usage response is invalid.');
        }

        return $result['data'];
    }

    private function endpoint(object $node): string
    {
        $endpoint = $node->agent_endpoint ?? null;
        if (! is_string($endpoint) || $endpoint === '') {
            throw new RuntimeException('Node agent endpoint is unavailable.');
        }
        $parts = parse_url($endpoint);
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('Node agent endpoint must be a private HTTPS URL.');
        }

        return rtrim($endpoint, '/');
    }

    private function requiredFile(string $name): string
    {
        $path = config('node_access.agent.'.$name);
        if (! is_string($path) || $path === '' || is_link($path) || ! is_readable($path)) {
            throw new RuntimeException('Node agent mutual TLS credentials are unavailable.');
        }

        $privateKey = $name === 'client_key';
        $unsafePermissions = $privateKey ? 0o077 : 0o022;
        $fileInfo = @lstat($path);
        if (! is_array($fileInfo) || (($fileInfo['mode'] & 0o170000) !== 0o100000)
            || ($fileInfo['mode'] & $unsafePermissions) !== 0 || $fileInfo['size'] < 1
            || $fileInfo['size'] > self::MAX_CREDENTIAL_FILE_BYTES) {
            throw new RuntimeException('Node agent mutual TLS credentials are unavailable.');
        }

        $file = @fopen($path, 'rb');
        if (! is_resource($file)) {
            throw new RuntimeException('Node agent mutual TLS credentials are unavailable.');
        }
        try {
            $openedInfo = fstat($file);
        } finally {
            fclose($file);
        }
        if (! is_array($openedInfo) || $openedInfo['dev'] !== $fileInfo['dev'] || $openedInfo['ino'] !== $fileInfo['ino']
            || (($openedInfo['mode'] & 0o170000) !== 0o100000) || ($openedInfo['mode'] & $unsafePermissions) !== 0
            || $openedInfo['size'] < 1 || $openedInfo['size'] > self::MAX_CREDENTIAL_FILE_BYTES) {
            throw new RuntimeException('Node agent mutual TLS credentials are unavailable.');
        }

        return $path;
    }
}
