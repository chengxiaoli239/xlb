<?php

namespace App\Http\Controllers;

use App\Payments\PersistentPaymentEventLedger;
use App\Payments\PersistentSandboxPaymentProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class SandboxProviderWebhookController extends Controller
{
    public function receive(Request $request, PersistentPaymentEventLedger $ledger): JsonResponse
    {
        if (! app()->environment(['local', 'testing'])) {
            return response()->json(['error' => ['code' => 'test_only']], 403)->header('Cache-Control', 'no-store');
        }
        if (strlen($request->getContent()) > 65536) {
            return response()->json(['error' => ['code' => 'payload_too_large']], 413)->header('Cache-Control', 'no-store');
        }

        try {
            $provider = new PersistentSandboxPaymentProvider((string) config('app.key'), $ledger);
            $event = $provider->verifyWebhook($request->getContent(), [
                'X-Sandbox-Timestamp' => (string) $request->header('X-Sandbox-Timestamp', ''),
                'X-Sandbox-Signature' => (string) $request->header('X-Sandbox-Signature', ''),
            ], now()->utc()->toDateTimeImmutable());
        } catch (RuntimeException) {
            return response()->json(['error' => ['code' => 'invalid_sandbox_event']], 400)->header('Cache-Control', 'no-store');
        }

        $decision = $ledger->ingest($event);

        return response()->json(['data' => ['decision' => $decision->value]])->header('Cache-Control', 'no-store');
    }
}
