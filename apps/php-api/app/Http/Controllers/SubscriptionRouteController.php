<?php

namespace App\Http\Controllers;

use App\Services\SubscriptionRouteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class SubscriptionRouteController extends Controller
{
    public function index(Request $request, SubscriptionRouteService $routes): JsonResponse
    {
        $scope = (string) $request->query('scope', config('subscription_routes.scope'));

        try {
            return response()->json(['data' => ['scope' => $scope, 'items' => $routes->list($scope)]]);
        } catch (RuntimeException $exception) {
            return $this->errorResponse($exception);
        }
    }

    public function store(Request $request, SubscriptionRouteService $routes): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'scope' => ['required', 'string', 'in:test,production'],
            'node_ids' => ['required', 'array', 'min:1', 'max:20'],
            'node_ids.*' => ['required', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:190'],
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => ['code' => 'validation_error', 'message' => '请检查线路范围和节点列表']], 422);
        }

        try {
            return response()->json(['data' => $routes->create($validator->validated(), $request->user()->id)], 201);
        } catch (RuntimeException $exception) {
            return $this->errorResponse($exception);
        }
    }

    public function canary(Request $request, string $id, SubscriptionRouteService $routes): JsonResponse
    {
        $validator = Validator::make($request->all(), ['percent' => ['required', 'integer', 'between:1,100']]);
        if ($validator->fails()) {
            return response()->json(['error' => ['code' => 'validation_error', 'message' => '灰度比例必须在 1 到 100 之间']], 422);
        }

        try {
            return response()->json(['data' => $routes->canary($id, (int) $validator->validated()['percent'], $request->user()->id)]);
        } catch (RuntimeException $exception) {
            return $this->errorResponse($exception);
        }
    }

    public function publish(Request $request, string $id, SubscriptionRouteService $routes): JsonResponse
    {
        return $this->mutate($request, $id, $routes, 'publish');
    }

    public function rollback(Request $request, string $id, SubscriptionRouteService $routes): JsonResponse
    {
        return $this->mutate($request, $id, $routes, 'rollback');
    }

    private function mutate(Request $request, string $id, SubscriptionRouteService $routes, string $operation): JsonResponse
    {
        try {
            $data = $operation === 'publish'
                ? $routes->publish($id, $request->user()->id)
                : $routes->rollback($id, $request->user()->id);

            return response()->json(['data' => $data]);
        } catch (RuntimeException $exception) {
            return $this->errorResponse($exception);
        }
    }

    private function errorResponse(RuntimeException $exception): JsonResponse
    {
        $status = $exception->getCode() >= 400 && $exception->getCode() < 500 ? $exception->getCode() : 409;
        $code = $status === 422 ? 'route_nodes_not_ready' : 'route_transition_invalid';

        return response()->json(['error' => ['code' => $code, 'message' => $exception->getMessage()]], $status);
    }
}
