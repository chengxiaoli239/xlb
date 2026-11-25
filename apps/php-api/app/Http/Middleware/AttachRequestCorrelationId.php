<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AttachRequestCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/*') && ! $request->is('v1/node-quota/*')) {
            return $next($request);
        }

        $requestId = (string) Str::uuid();
        $request->attributes->set('request_id', $requestId);
        Log::shareContext(['request_id' => $requestId]);

        try {
            $response = $next($request);
            $response->headers->set('X-Request-Id', $requestId);

            return $response;
        } finally {
            Log::withoutContext(['request_id']);
        }
    }
}
