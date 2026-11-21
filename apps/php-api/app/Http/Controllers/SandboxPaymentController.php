<?php

namespace App\Http\Controllers;

use App\Payments\MerchantOrder;
use App\Payments\PersistentPaymentEventLedger;
use App\Payments\PersistentSandboxPaymentProvider;
use App\Services\SandboxPaymentProcessor;
use App\Services\SubscriptionCredentialService;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SandboxPaymentController extends Controller
{
    public function createProviderSession(Request $request, string $id, PersistentPaymentEventLedger $ledger): JsonResponse
    {
        if (! app()->environment(['local', 'testing']) || $request->user()->role !== 'member') {
            return response()->json(['error' => ['code' => 'test_only']], 403);
        }
        $order = DB::table('orders')->where('id', $id)
            ->where('user_id', $request->user()->id)->where('is_test', true)->first();
        if (! $order) {
            return response()->json(['error' => ['code' => 'not_found']], 404);
        }

        $provider = new PersistentSandboxPaymentProvider((string) config('app.key'), $ledger);
        $session = $provider->createPayment(new MerchantOrder(
            $order->id,
            (int) $order->total_cents,
            'CNY',
            new DateTimeImmutable($order->expires_at),
        ));

        return response()->json(['data' => $session])->header('Cache-Control', 'no-store, private');
    }

    public function simulate(Request $request, string $id, SandboxPaymentProcessor $processor, SubscriptionCredentialService $credentials): JsonResponse
    {
        if (! app()->environment(['local', 'testing']) || $request->user()->role !== 'member') {
            return response()->json(['error' => ['code' => 'test_only', 'message' => '仅本地会员可模拟测试付款']], 403);
        }

        $result = $processor->simulate($id, $request->user()->id, $credentials);

        return response()->json($result['body'], $result['status'])->header('Cache-Control', 'no-store, private');
    }
}
