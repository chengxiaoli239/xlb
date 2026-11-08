<?php

namespace App\Http\Controllers;

use App\Services\SandboxPaymentProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SandboxPaymentController extends Controller
{
    public function simulate(Request $request, string $id, SandboxPaymentProcessor $processor): JsonResponse
    {
        if (! app()->environment(['local', 'testing']) || $request->user()->role !== 'member') {
            return response()->json(['error' => ['code' => 'test_only', 'message' => '仅本地会员可模拟测试付款']], 403);
        }

        $result = $processor->simulate($id, $request->user()->id);

        return response()->json($result['body'], $result['status']);
    }
}
