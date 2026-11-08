<?php

namespace App\Http\Controllers;

use App\Services\SandboxPaymentProcessor;
use App\Services\SubscriptionCredentialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SandboxPaymentController extends Controller
{
    public function simulate(Request $request, string $id, SandboxPaymentProcessor $processor, SubscriptionCredentialService $credentials): JsonResponse
    {
        if (! app()->environment(['local', 'testing']) || $request->user()->role !== 'member') {
            return response()->json(['error' => ['code' => 'test_only', 'message' => '仅本地会员可模拟测试付款']], 403);
        }

        $result = $processor->simulate($id, $request->user()->id, $credentials);

        return response()->json($result['body'], $result['status'])->header('Cache-Control', 'no-store, private');
    }
}
