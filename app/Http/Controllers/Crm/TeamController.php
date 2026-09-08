<?php

namespace App\Http\Controllers\Crm;

use App\Events\TeamCallSignalEvent;
use App\Events\TeamMessageEvent;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Chat interno y llamadas entre agentes de la misma empresa. */
class TeamController extends Controller
{
    private function me(): array
    {
        return ['id' => (int) getSessionUserId(), 'company_id' => (int) getSessionCompanyId()];
    }

    private function memberRow(int $userId): ?array
    {
        $u = DB::table('users')->leftJoin('user_data as ud', 'ud.user_id', '=', 'users.id')->leftJoin('profiles as p', 'p.id', '=', 'users.profile_id')
            ->where('users.id', $userId)->first(['users.id', 'users.email', 'ud.names', 'ud.lastname', 'p.name as profile']);
        return $u ? ['id' => (int)$u->id, 'name' => trim(($u->names ?? '') . ' ' . ($u->lastname ?? '')) ?: ($u->email ?? 'Usuario'), 'profile' => $u->profile] : null;
    }

    /* GET team/members: compañeros de la empresa (sin clientes del portal) */
    public function members(): JsonResponse
    {
        $me = $this->me();
        $rows = DB::table('users')
            ->leftJoin('user_data as ud', 'ud.user_id', '=', 'users.id')
            ->leftJoin('profiles as p', 'p.id', '=', 'users.profile_id')
            ->where('users.company_id', $me['company_id'])
            ->where('users.id', '!=', $me['id'])
            ->whereNotNull('users.profile_id')
            // El perfil USER son los clientes del ISP; el equipo es todo lo demás (ADMIN, TECNICO, agentes…)
            ->whereNotIn(DB::raw('UPPER(COALESCE(p.name, \'\'))'), ['USER', 'CLIENTE', 'CLIENT', 'USUARIO'])
            ->orderBy('ud.names')
            ->get(['users.id', 'users.email', 'ud.names', 'ud.lastname', 'p.name as profile']);

        $unread = DB::table('crm_team_messages')->where('company_id', $me['company_id'])->where('to_user_id', $me['id'])->whereNull('read_at')
            ->groupBy('from_user_id')->selectRaw('from_user_id, COUNT(*) as n')->pluck('n', 'from_user_id');
        $lastRead = DB::table('crm_team_reads')->where('user_id', $me['id'])->where('thread', 'general')->value('read_at');
        $generalUnread = DB::table('crm_team_messages')->where('company_id', $me['company_id'])->whereNull('to_user_id')->where('from_user_id', '!=', $me['id'])
            ->when($lastRead, fn($q) => $q->where('created_at', '>', $lastRead))->count();

        return response()->json(['ok' => true, 'data' => [
            'me' => $this->memberRow($me['id']),
            'members' => $rows->map(fn($u) => [
                'id' => (int)$u->id,
                'name' => trim(($u->names ?? '') . ' ' . ($u->lastname ?? '')) ?: ($u->email ?? 'Usuario'),
                'profile' => $u->profile,
                'unread' => (int)($unread[$u->id] ?? 0),
            ])->values(),
            'general_unread' => $generalUnread,
        ]]);
    }

    /* GET team/messages?with=general|{userId}&before={id} */
    public function messages(Request $request): JsonResponse
    {
        $me = $this->me();
        $with = $request->query('with', 'general');
        $q = DB::table('crm_team_messages as m')->leftJoin('user_data as ud', 'ud.user_id', '=', 'm.from_user_id')
            ->where('m.company_id', $me['company_id']);
        if ($with === 'general') {
            $q->whereNull('m.to_user_id');
        } else {
            $other = (int)$with;
            $q->where(fn($w) => $w->where(fn($a) => $a->where('m.from_user_id', $me['id'])->where('m.to_user_id', $other))
                                 ->orWhere(fn($b) => $b->where('m.from_user_id', $other)->where('m.to_user_id', $me['id'])));
        }
        if ($request->filled('before')) $q->where('m.id', '<', (int)$request->before);
        $rows = $q->orderByDesc('m.id')->limit(50)->get(['m.id', 'm.from_user_id', 'm.to_user_id', 'm.content', 'm.read_at', 'm.created_at', DB::raw("TRIM(CONCAT(COALESCE(ud.names,''),' ',COALESCE(ud.lastname,''))) as from_name")]);

        return response()->json(['ok' => true, 'data' => $rows->reverse()->values()->map(fn($m) => [
            'id' => (int)$m->id, 'from_user_id' => (int)$m->from_user_id, 'to_user_id' => $m->to_user_id ? (int)$m->to_user_id : null,
            'from_name' => $m->from_name ?: 'Agente', 'content' => $m->content, 'read_at' => $m->read_at, 'created_at' => $m->created_at,
            'mine' => (int)$m->from_user_id === $me['id'],
        ])]);
    }

    /* POST team/messages { to: userId|null, content } */
    public function send(Request $request): JsonResponse
    {
        $request->validate(['content' => 'required|string|max:4000', 'to' => 'nullable|integer']);
        $me = $this->me();
        $to = $request->filled('to') ? (int)$request->to : null;
        if ($to && !DB::table('users')->where('id', $to)->where('company_id', $me['company_id'])->exists()) {
            return response()->json(['ok' => false, 'error' => 'Destinatario inválido'], 422);
        }
        $id = DB::table('crm_team_messages')->insertGetId([
            'company_id' => $me['company_id'], 'from_user_id' => $me['id'], 'to_user_id' => $to,
            'content' => trim($request->content), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $from = $this->memberRow($me['id']);
        $message = ['id' => $id, 'company_id' => $me['company_id'], 'from_user_id' => $me['id'], 'to_user_id' => $to, 'from_name' => $from['name'] ?? 'Agente',
            'content' => trim($request->content), 'read_at' => null, 'created_at' => now()->toDateTimeString()];
        broadcast(new TeamMessageEvent($message));
        return response()->json(['ok' => true, 'data' => $message + ['mine' => true]], 201);
    }

    /* POST team/read { with } */
    public function read(Request $request): JsonResponse
    {
        $me = $this->me();
        $with = $request->input('with', 'general');
        if ($with === 'general') {
            DB::table('crm_team_reads')->updateOrInsert(['user_id' => $me['id'], 'thread' => 'general'], ['read_at' => now()]);
        } else {
            DB::table('crm_team_messages')->where('company_id', $me['company_id'])->where('to_user_id', $me['id'])->where('from_user_id', (int)$with)->whereNull('read_at')->update(['read_at' => now()]);
        }
        return response()->json(['ok' => true]);
    }

    /* POST team/call/signal { to, type, payload } — relé de señalización WebRTC */
    public function callSignal(Request $request): JsonResponse
    {
        $request->validate(['to' => 'required|integer', 'type' => 'required|string|in:ring,accept,reject,busy,hangup,offer,answer,ice', 'payload' => 'nullable']);
        $me = $this->me();
        $to = (int)$request->to;
        if (!DB::table('users')->where('id', $to)->where('company_id', $me['company_id'])->exists()) {
            return response()->json(['ok' => false, 'error' => 'Destinatario inválido'], 422);
        }
        $from = $this->memberRow($me['id']);
        broadcast(new TeamCallSignalEvent($to, [
            'type' => $request->type, 'payload' => $request->input('payload'), 'call_id' => (string)$request->input('call_id', ''),
            'from' => ['id' => $me['id'], 'name' => $from['name'] ?? 'Agente'], 'at' => now()->toIso8601String(),
        ]));
        return response()->json(['ok' => true]);
    }
}
