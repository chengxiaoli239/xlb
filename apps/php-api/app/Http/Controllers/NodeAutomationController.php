<?php

namespace App\Http\Controllers;

use App\Services\NodeAutomation;
use App\Services\NodeProviderRegistry;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class NodeAutomationController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = DB::table('node_deployments as d')->join('nodes as n', 'n.id', '=', 'd.node_id')
            ->orderByDesc('d.created_at')->limit(50)->get(['d.*', 'n.name']);

        return response()->json(['data' => ['enabled' => (bool) config('node_automation.enabled'), 'max_nodes' => config('node_automation.max_nodes'), 'items' => $rows]])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, NodeAutomation $automation): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'request_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'min:2', 'max:60', 'regex:/\S/u'],
            'instance_type' => ['required', Rule::in(['t3.micro', 't3.small'])],
            'lifetime_minutes' => ['required', 'integer', Rule::in([15, 30, 60])],
        ]);
        if ($validator->fails() || mb_strlen(trim((string) $request->input('name'))) < 2 || array_diff(array_keys($request->all()), ['request_id', 'name', 'instance_type', 'lifetime_minutes'])) {
            return response()->json(['error' => ['message' => '仅支持美国西部专用测试节点、micro/small 规格及 15/30/60 分钟有效期']], 422);
        }
        try {
            $row = $automation->create($validator->validated(), $request->user()->id);
        } catch (LockTimeoutException) {
            return response()->json(['error' => ['message' => '节点任务正在推进，请稍后重试；重复提交不会重复建机']], 423);
        }

        return response()->json(['data' => $row], 202);
    }

    public function show(string $id, NodeAutomation $automation): JsonResponse
    {
        $row = $automation->find($id);

        return response()->json(['data' => [...(array) $row, 'events' => DB::table('node_deployment_events')->where('deployment_id', $id)->orderBy('created_at')->get()]])->header('Cache-Control', 'no-store');
    }

    public function retire(Request $request, string $id, NodeAutomation $automation): JsonResponse
    {
        if ($request->input('confirmation') !== '永久下架并释放') {
            return response()->json(['error' => ['message' => '请输入“永久下架并释放”确认本测试节点资源删除']], 422);
        }

        try {
            return response()->json(['data' => $automation->retire($id, $request->user()->id)], 202);
        } catch (LockTimeoutException) {
            return response()->json(['error' => ['message' => '节点任务正在推进，请稍后再次下架']], 423);
        }
    }

    public function profile(string $id, NodeAutomation $automation, NodeProviderRegistry $providers): Response
    {
        $row = $automation->find($id);
        abort_unless($row->phase === 'available' && ! $row->release_requested_at && now()->lessThan($row->expires_at), 409, '测试节点未就绪或已下架');
        $result = $providers->resolve($row->provider)->profile($row);

        return response($result['profile'], 200, [
            'Content-Type' => 'application/json', 'Content-Disposition' => 'attachment; filename="xlb-admin-test.json"',
            'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
