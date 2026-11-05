<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use stdClass;

class TicketController extends Controller
{
    public function listMember(Request $request): JsonResponse
    {
        return $this->listTickets($request, false);
    }

    public function listAdmin(Request $request): JsonResponse
    {
        return $this->listTickets($request, true);
    }

    public function detail(Request $request, string $id): JsonResponse
    {
        $ticket = $this->findTicket($request, $id);
        if (! $ticket) {
            return $this->error('not_found', '工单不存在', 404);
        }

        $events = DB::table('support_ticket_events as e')->join('users as u', 'u.id', '=', 'e.actor_user_id')
            ->where('e.ticket_id', $id)->orderBy('e.sequence')
            ->get(['e.id', 'e.kind', 'e.body', 'e.from_status', 'e.to_status', 'e.created_at', 'u.name as actor_name', 'u.role as actor_role']);

        return response()->json(['data' => [...(array) $ticket, 'events' => $events]]);
    }

    public function create(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'category' => ['required', 'in:connection,subscription,payment,account,other'],
            'subject' => ['required', 'string', 'min:3', 'max:100'],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        if ($validator->fails()) {
            return $this->error('validation_error', '请检查工单类别、标题和描述', 400);
        }

        $subject = trim($request->input('subject'));
        $description = trim($request->input('description'));
        if ($this->containsCredential($subject.' '.$description)) {
            return $this->error('credential_not_allowed', '工单中不要填写订阅地址或会话凭证', 400);
        }

        $id = (string) Str::uuid();
        $now = now()->toIso8601String();
        DB::transaction(function () use ($request, $id, $now, $subject, $description): void {
            DB::table('support_tickets')->insert(['id' => $id, 'user_id' => $request->user()->id, 'category' => $request->input('category'), 'subject' => $subject, 'status' => 'open', 'created_at' => $now, 'updated_at' => $now]);
            $this->event($id, $request->user()->id, 'message', $description, null, null, $now);
            $this->audit($request, 'ticket.created', $id);
        });

        return response()->json(['data' => ['id' => $id, 'status' => 'open', 'created_at' => $now]], 201)->header('Location', '/api/v1/me/tickets/'.$id);
    }

    public function reply(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), ['body' => ['required', 'string', 'min:2', 'max:2000']]);
        if ($validator->fails()) {
            return $this->error('validation_error', '回复需为 2–2000 字', 400);
        }

        $body = trim($request->input('body'));
        if ($this->containsCredential($body)) {
            return $this->error('credential_not_allowed', '回复中不要填写订阅地址或会话凭证', 400);
        }

        $ticket = $this->findTicket($request, $id);
        if (! $ticket) {
            return $this->error('not_found', '工单不存在', 404);
        }

        $role = $request->user()->role;
        $nextStatus = match (true) {
            $role === 'member' && $ticket->status === 'resolved' => 'open',
            $role === 'admin' && $ticket->status === 'open' => 'in_progress',
            default => $ticket->status,
        };
        $now = now()->toIso8601String();
        $eventId = (string) Str::uuid();
        DB::transaction(function () use ($request, $ticket, $id, $nextStatus, $now, $eventId, $body): void {
            DB::table('support_tickets')->where('id', $id)->update(['status' => $nextStatus, 'updated_at' => $now, 'resolved_at' => $nextStatus === 'resolved' ? $ticket->resolved_at : null]);
            if ($nextStatus !== $ticket->status) {
                $this->event($id, $request->user()->id, 'status_changed', null, $ticket->status, $nextStatus, $now);
                $this->audit($request, 'ticket.status_changed', $id);
            }
            $this->event($id, $request->user()->id, 'message', $body, null, null, $now, $eventId);
            $this->audit($request, 'ticket.replied', $id);
        });

        $owner = $role === 'admin' ? 'admin' : 'me';

        return response()->json(['data' => ['id' => $eventId, 'ticket_id' => $id, 'status' => $nextStatus]], 201)->header('Location', '/api/v1/'.$owner.'/tickets/'.$id);
    }

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), ['status' => ['required', 'in:open,in_progress,resolved']]);
        if ($validator->fails()) {
            return $this->error('validation_error', '工单状态无效', 400);
        }

        $ticket = $this->findTicket($request, $id);
        if (! $ticket) {
            return $this->error('not_found', '工单不存在', 404);
        }

        $status = $request->input('status');
        if ($ticket->status === $status) {
            return response()->json(['data' => ['id' => $id, 'status' => $status]]);
        }

        $transitions = ['open' => ['in_progress', 'resolved'], 'in_progress' => ['open', 'resolved'], 'resolved' => ['in_progress']];
        if (! in_array($status, $transitions[$ticket->status], true)) {
            return $this->error('invalid_transition', '当前状态不允许该操作', 409);
        }

        $now = now()->toIso8601String();
        DB::transaction(function () use ($request, $id, $ticket, $status, $now): void {
            DB::table('support_tickets')->where('id', $id)->update(['status' => $status, 'updated_at' => $now, 'resolved_at' => $status === 'resolved' ? $now : null]);
            $this->event($id, $request->user()->id, 'status_changed', null, $ticket->status, $status, $now);
            $this->audit($request, 'ticket.status_changed', $id);
        });

        return response()->json(['data' => ['id' => $id, 'status' => $status, 'updated_at' => $now]]);
    }

    private function listTickets(Request $request, bool $admin): JsonResponse
    {
        $validator = Validator::make($request->query(), ['page' => ['sometimes', 'integer', 'between:1,100000'], 'per_page' => ['sometimes', 'integer', 'between:1,50']]);
        if ($validator->fails()) {
            return $this->error('validation_error', '分页参数无效', 400);
        }

        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 20);
        $query = DB::table('support_tickets as t')->join('users as u', 'u.id', '=', 't.user_id');
        if (! $admin) {
            $query->where('t.user_id', $request->user()->id);
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('t.updated_at')->orderByDesc('t.id')->limit($perPage)->offset(($page - 1) * $perPage)
            ->get(['t.id', 't.category', 't.subject', 't.status', 't.created_at', 't.updated_at', 't.resolved_at', 'u.name as user_name']);

        return response()->json(['data' => $rows, 'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'total_pages' => (int) ceil($total / $perPage)]]);
    }

    private function findTicket(Request $request, string $id): ?stdClass
    {
        $query = DB::table('support_tickets as t')->join('users as u', 'u.id', '=', 't.user_id')->where('t.id', $id);
        if ($request->user()->role !== 'admin') {
            $query->where('t.user_id', $request->user()->id);
        }

        return $query->first(['t.id', 't.user_id', 't.category', 't.subject', 't.status', 't.created_at', 't.updated_at', 't.resolved_at', 'u.name as user_name', 'u.email as user_email']);
    }

    private function containsCredential(string $body): bool
    {
        return preg_match('~\\/subscribe\\/[A-Za-z0-9_-]{8,}|xlb_session\\s*=~i', $body) === 1;
    }

    private function event(string $ticketId, string $actorId, string $kind, ?string $body, ?string $from, ?string $to, string $created, ?string $id = null): void
    {
        DB::table('support_ticket_events')->insert(['id' => $id ?? (string) Str::uuid(), 'ticket_id' => $ticketId, 'actor_user_id' => $actorId, 'kind' => $kind, 'body' => $body, 'from_status' => $from, 'to_status' => $to, 'created_at' => $created]);
    }

    private function audit(Request $request, string $action, string $target): void
    {
        DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'actor_user_id' => $request->user()->id, 'action' => $action, 'target_type' => 'support_ticket', 'target_id' => $target, 'created_at' => now()->toIso8601String()]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
