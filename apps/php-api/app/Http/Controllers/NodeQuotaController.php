<?php

namespace App\Http\Controllers;

use App\Services\TrafficQuotaExceeded;
use App\Services\TrafficQuotaReservationConflict;
use App\Services\TrafficQuotaReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

final class NodeQuotaController extends Controller
{
    public function reserve(Request $request, TrafficQuotaReservationService $quota): JsonResponse
    {
        $node = $request->attributes->get('authenticated_node');
        abort_unless(is_object($node), 401);

        $validator = Validator::make($request->all(), [
            'node_id' => ['required', 'string', 'max:64'],
            'entitlement_id' => ['required', 'string', 'max:64'],
            'peer_id' => ['required', 'string', 'max:64'],
            'generation' => ['required', 'integer', 'min:1'],
            'request_id' => ['required', 'string', 'regex:/\A[a-zA-Z0-9-]{1,36}\z/D'],
            'grant_bytes' => ['required', 'integer', 'min:1'],
            'lease_seconds' => ['sometimes', 'integer', 'between:5,120'],
        ]);
        if ($validator->fails()) {
            return $this->error('validation_error', 422);
        }
        $attributes = $validator->validated();
        if (! hash_equals((string) $node->id, $attributes['node_id'])) {
            return $this->error('node_identity_mismatch', 403);
        }

        try {
            $reservation = $quota->reserve(
                $attributes['entitlement_id'], (string) $node->id, $attributes['peer_id'],
                (int) $attributes['generation'], $attributes['request_id'], (int) $attributes['grant_bytes'],
                (int) ($attributes['lease_seconds'] ?? config('node_access.lease_seconds', 30)),
            );
        } catch (TrafficQuotaExceeded) {
            return $this->error('quota_exhausted', 429);
        } catch (TrafficQuotaReservationConflict) {
            return $this->error('request_conflict', 409);
        } catch (RuntimeException) {
            return $this->error('reservation_denied', 403);
        }

        return response()->json(['data' => $reservation])->header('Cache-Control', 'no-store, private');
    }

    public function settle(Request $request, string $requestId, TrafficQuotaReservationService $quota): JsonResponse
    {
        $node = $request->attributes->get('authenticated_node');
        abort_unless(is_object($node), 401);

        $validator = Validator::make($request->all(), [
            'node_id' => ['required', 'string', 'max:64'],
            'peer_id' => ['required', 'string', 'max:64'],
            'generation' => ['required', 'integer', 'min:1'],
            'request_id' => ['required', 'string', 'regex:/\A[a-zA-Z0-9-]{1,36}\z/D'],
            'acknowledgement_id' => ['required', 'string', 'max:128'],
            'upload_bytes' => ['required', 'integer', 'min:0'],
            'download_bytes' => ['required', 'integer', 'min:0'],
            'final' => ['required', 'accepted'],
        ]);
        if ($validator->fails()) {
            return $this->error('validation_error', 422);
        }
        $attributes = $validator->validated();
        if (! hash_equals((string) $node->id, $attributes['node_id'])) {
            return $this->error('node_identity_mismatch', 403);
        }
        if (! hash_equals($requestId, $attributes['request_id'])) {
            return $this->error('request_id_mismatch', 409);
        }

        $receipt = [
            'node_id' => (string) $node->id,
            'peer_id' => $attributes['peer_id'],
            'request_id' => $requestId,
            'generation' => (int) $attributes['generation'],
            'acknowledgement_id' => $attributes['acknowledgement_id'],
            'upload_bytes' => (int) $attributes['upload_bytes'],
            'download_bytes' => (int) $attributes['download_bytes'],
            'final' => true,
            'confirmation_status' => 'confirmed',
        ];

        try {
            $result = $quota->settle($receipt);
        } catch (TrafficQuotaReservationConflict) {
            return $this->error('request_conflict', 409);
        } catch (RuntimeException) {
            return $this->error('receipt_denied', 403);
        }

        if ($result['status'] !== 'settled') {
            return $this->error('settlement_pending', 503);
        }

        return response()->json(['data' => [
            'node_id' => $receipt['node_id'],
            'peer_id' => $receipt['peer_id'],
            'request_id' => $receipt['request_id'],
            'generation' => $receipt['generation'],
            'acknowledgement_id' => $receipt['acknowledgement_id'],
            'upload_bytes' => $receipt['upload_bytes'],
            'download_bytes' => $receipt['download_bytes'],
            'final' => true,
            'settled' => true,
        ]])->header('Cache-Control', 'no-store, private');
    }

    private function error(string $code, int $status): JsonResponse
    {
        return response()->json([
            'error' => ['code' => $code, 'message' => 'Quota request could not be completed.'],
        ], $status)->header('Cache-Control', 'no-store, private');
    }
}
