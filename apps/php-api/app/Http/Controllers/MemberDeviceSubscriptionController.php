<?php

namespace App\Http\Controllers;

use App\Services\MemberDeviceSubscriptionException;
use App\Services\SubscriptionCredentialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MemberDeviceSubscriptionController extends Controller
{
    public function store(Request $request, string $id, SubscriptionCredentialService $credentials): JsonResponse
    {
        try {
            $result = $credentials->ensureForDevice($request->user()->id, $id);
        } catch (MemberDeviceSubscriptionException $exception) {
            return $this->error($exception);
        }

        return response()->json(['data' => $result['subscription']], $result['created'] ? 201 : 200)
            ->header('Cache-Control', 'no-store, private');
    }

    public function rotate(Request $request, string $id, SubscriptionCredentialService $credentials): JsonResponse
    {
        try {
            $subscription = $credentials->rotateForDevice($request->user()->id, $id);
        } catch (MemberDeviceSubscriptionException $exception) {
            return $this->error($exception);
        }

        return response()->json(['data' => $subscription])->header('Cache-Control', 'no-store, private');
    }

    public function revoke(Request $request, string $id, SubscriptionCredentialService $credentials): Response|JsonResponse
    {
        try {
            $pending = $credentials->revokeForDevice($request->user()->id, $id);
        } catch (MemberDeviceSubscriptionException $exception) {
            return $this->error($exception);
        }

        if ($pending) {
            return response()->json(['data' => ['status' => 'revocation_pending']], 202)
                ->header('Cache-Control', 'no-store, private');
        }

        return response()->noContent();
    }

    private function error(MemberDeviceSubscriptionException $exception): JsonResponse
    {
        return response()->json([
            'error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()],
        ], $exception->httpStatus)->header('Cache-Control', 'no-store, private');
    }
}
