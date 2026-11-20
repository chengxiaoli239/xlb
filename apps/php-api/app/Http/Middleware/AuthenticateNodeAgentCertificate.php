<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateNodeAgentCertificate
{
    public function handle(Request $request, Closure $next): Response
    {
        $certificatePem = $request->server('SSL_CLIENT_CERT');
        if ($request->server('SSL_CLIENT_VERIFY') !== 'SUCCESS' || ! is_string($certificatePem) || $certificatePem === ''
            || ! function_exists('openssl_x509_read') || ! function_exists('openssl_x509_fingerprint')) {
            return $this->unauthorized();
        }

        $certificate = openssl_x509_read($certificatePem);
        if ($certificate === false) {
            return $this->unauthorized();
        }

        $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');
        if (! is_string($fingerprint) || ! preg_match('/\A[a-fA-F0-9]{64}\z/D', $fingerprint)) {
            return $this->unauthorized();
        }

        $node = DB::table('nodes')->where('agent_client_cert_sha256', strtolower($fingerprint))
            ->where('is_test', false)->where('status', 'available')->where('health_status', 'healthy')->first();
        if (! $node) {
            return $this->unauthorized();
        }

        $request->attributes->set('authenticated_node', $node);

        return $next($request);
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json([
            'error' => ['code' => 'node_certificate_invalid', 'message' => 'Node certificate is not authorized.'],
        ], 401)->header('Cache-Control', 'no-store, private');
    }
}
