<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * El centro de notificaciones del encabezado.
 *
 * No guarda nada nuevo: junta lo que la plataforma ya sabe —avisos de la red,
 * clientes esperando en cobranza y aprovisionamientos que se trabaron— y lo
 * muestra en un solo lugar, con su enlace a la pantalla donde se resuelve.
 * Antes esto vivía en tres pantallas distintas y la campana era un adorno.
 */
class NotificacionController extends Controller
{
    private const MAXIMO = 25;

    public function index(): JsonResponse
    {
        $user = JWTAuth::user();
        $companyId = (int) ($user->company_id ?? 0);

        if (!$user || !$companyId) {
            return standardApiReponse('Sin sesión', null, 1, JsonResponse::HTTP_UNAUTHORIZED);
        }

        $modulos = DB::table('profile_modules')->where('profile_id', $user->profile_id)->where('active', true)
            ->pluck('module')->map(fn ($m) => (string) $m)->all();

        $esAdmin = strtoupper((string) DB::table('profiles')->where('id', $user->profile_id)->value('name')) === 'ADMIN';

        $items = collect()
            ->concat($this->red($companyId))
            ->concat($esAdmin || in_array('crm', $modulos, true) ? $this->chatsEsperando($companyId) : [])
            ->concat(in_array('finanzas', $modulos, true) ? $this->cobranza($companyId) : [])
            ->concat($esAdmin || in_array('finanzas', $modulos, true) ? $this->comprobantes($companyId) : [])
            ->concat($this->aprovisionamientos($companyId))
            ->sortByDesc('cuando')->take(self::MAXIMO)->values();

        // Sin la tabla (migración pendiente) todo se ve como nuevo, pero la
        // campana igual funciona.
        $visto = Schema::hasTable('notificaciones_vistas')
            ? DB::table('notificaciones_vistas')->where('user_id', $user->id)->value('visto_hasta')
            : null;

        return standardApiReponse('OK', [
            'items'   => $items->map(fn ($i) => $i + ['nueva' => !$visto || $i['cuando'] > $visto])->all(),
            'sin_ver' => $items->filter(fn ($i) => !$visto || $i['cuando'] > $visto)->count(),
        ], 0, JsonResponse::HTTP_OK);
    }

    public function vistas(): JsonResponse
    {
        $user = JWTAuth::user();

        if ($user && Schema::hasTable('notificaciones_vistas')) {
            DB::table('notificaciones_vistas')->updateOrInsert(
                ['user_id' => $user->id],
                ['visto_hasta' => now(), 'updated_at' => now(), 'created_at' => now()]
            );
        }

        return standardApiReponse('OK', null, 0, JsonResponse::HTTP_OK);
    }

    /** Avisos de la red que siguen abiertos: señal, cortes, túneles, OLT. */
    private function red(int $companyId): array
    {
        if (!Schema::hasTable('alertas')) {
            return [];
        }

        return DB::table('alertas')->where('company_id', $companyId)->whereNull('cerrada_en')
            ->orderByRaw("FIELD(nivel, 'critico', 'aviso')")->orderByDesc('abierta_en')->limit(self::MAXIMO)
            ->get(['tipo', 'nivel', 'titulo', 'detalle', 'abierta_en'])
            ->map(fn ($a) => [
                'origen'  => 'red',
                'nivel'   => $a->nivel === 'critico' ? 'critico' : 'aviso',
                'titulo'  => $a->titulo,
                'detalle' => mb_substr((string) $a->detalle, 0, 180),
                'cuando'  => (string) $a->abierta_en,
                'ruta'    => 'olt/alertas',
            ])->all();
    }

    /**
     * Clientes que esperan respuesta en WhatsApp más del tiempo del CRM (wait_alert_minutes).
     * Antes se mandaba al grupo; se pidió verlo en la plataforma. Cada uno abre su chat.
     */
    private function chatsEsperando(int $companyId): array
    {
        $minutos = max(5, (int) (\App\Support\CrmSettings::for($companyId)['wait_alert_minutes'] ?? 15));

        $chats = DB::table('crm_conversations as c')
            ->join('crm_customers as cu', 'cu.id', '=', 'c.customer_id')
            ->join('crm_messages as m', 'm.id', '=', DB::raw('(SELECT MAX(id) FROM crm_messages WHERE conversation_id = c.id)'))
            ->leftJoin('user_data as ud', 'ud.user_id', '=', 'c.assigned_user_id')
            ->where('c.company_id', $companyId)->whereIn('c.status', ['new', 'in_progress'])
            ->where(fn ($q) => $q->whereNull('cu.is_group')->orWhere('cu.is_group', 0))
            ->where('m.sender_type', 'customer')
            ->where('m.created_at', '<', now()->subMinutes($minutos))
            ->where('m.created_at', '>', now()->subHours(72))
            ->orderByDesc('m.created_at')->limit(10)
            ->get(['c.id', 'c.provider', 'cu.name', 'cu.phone', 'm.message_type', 'm.content', 'm.created_at', 'ud.names as asesor']);

        $tipos = ['image' => '📷 Foto', 'audio' => '🎤 Nota de voz', 'video' => '🎥 Video', 'document' => '📄 Documento', 'sticker' => 'Sticker'];

        return $chats->map(function ($c) use ($minutos, $tipos) {
            $min = max(1, (int) \Illuminate\Support\Carbon::parse($c->created_at)->diffInMinutes(now()));
            $hace = $min < 60 ? "{$min} min" : ($min < 1440 ? intdiv($min, 60) . ' h' : intdiv($min, 1440) . (intdiv($min, 1440) === 1 ? ' día' : ' días'));
            $ultimo = trim((string) $c->content) !== '' ? '«' . mb_strimwidth(trim(preg_replace('/\s+/', ' ', (string) $c->content)), 0, 90, '…') . '»' : ($tipos[$c->message_type] ?? 'Mensaje');
            $nombre = trim((string) $c->name);

            return [
                'origen'  => 'chat',
                // Más de una hora esperando ya es urgente.
                'nivel'   => $min >= 60 ? 'critico' : 'aviso',
                'titulo'  => (($nombre && $nombre !== 'Cliente') ? $nombre : $c->phone) . " espera respuesta hace {$hace}",
                'detalle' => $ultimo . ($c->asesor ? " · lo tiene {$c->asesor}" : ' · sin asesor'),
                // Aparece como nueva cuando cumple el tiempo de espera, no cuando escribió.
                'cuando'  => (string) \Illuminate\Support\Carbon::parse($c->created_at)->addMinutes($minutos),
                'ruta'    => "crm/inbox?chat={$c->id}&proveedor=" . ($c->provider ?: 'netplay'),
            ];
        })->all();
    }

    /**
     * Los comprobantes de pago sin revisar, en un solo aviso. Al 23-10-2026 había 431 y uno
     * llevaba tres semanas: la clienta pagó el 1 de octubre y la suspendieron igual.
     */
    private function comprobantes(int $companyId): array
    {
        if (!Schema::hasTable('payment_proofs')) {
            return [];
        }

        $p = DB::table('payment_proofs')->where('company_id', $companyId)->where('status', 'pending')
            ->selectRaw('COUNT(*) n, MIN(created_at) primero, SUM(created_at < ?) viejos', [now()->subDays(3)])->first();

        if (!$p || !(int) $p->n) {
            return [];
        }

        $dias = max(0, (int) \Illuminate\Support\Carbon::parse($p->primero)->diffInDays(now()));

        return [[
            'origen'  => 'pago',
            'nivel'   => (int) $p->viejos > 0 ? 'critico' : 'aviso',
            'titulo'  => "{$p->n} comprobantes de pago por revisar",
            'detalle' => ((int) $p->viejos > 0 ? "{$p->viejos} llevan más de 3 días · " : '') . "el más viejo es de hace {$dias} días. Un pago sin aplicar puede dejar suspendido a quien ya pagó.",
            // Se muestra como reciente: es un pendiente de todos los días, no una novedad vieja.
            'cuando'  => (string) now()->startOfDay(),
            'ruta'    => 'payment-proof-audit',
        ]];
    }

    /** Clientes que esperan una decisión en cobranza. */
    private function cobranza(int $companyId): array
    {
        if (!Schema::hasTable('cobranza_casos')) {
            return [];
        }

        $casos = DB::table('cobranza_casos as c')->leftJoin('user_data as u', 'u.user_id', '=', 'c.user_id')
            ->where('c.company_id', $companyId)->whereIn('c.estado', ['detectado', 'escalado'])
            ->orderByDesc('c.updated_at')->limit(10)
            ->get(['c.estado', 'c.deuda', 'c.motivo', 'c.updated_at', 'u.names', 'u.lastname']);

        return $casos->map(fn ($c) => [
            'origen'  => 'cobranza',
            'nivel'   => $c->estado === 'escalado' ? 'critico' : 'aviso',
            'titulo'  => $c->estado === 'escalado'
                ? 'Cobranza: ' . trim("{$c->names} {$c->lastname}") . ' necesita una persona'
                : 'Cobranza: ' . trim("{$c->names} {$c->lastname}") . ' espera su autorización',
            'detalle' => ($c->motivo ?: 'Debe $' . number_format((float) $c->deuda, 0, ',', '.')),
            'cuando'  => (string) $c->updated_at,
            'ruta'    => 'cobranza',
        ])->all();
    }

    /** Equipos que quedaron a medio configurar: alguien tiene que mirarlos. */
    private function aprovisionamientos(int $companyId): array
    {
        if (!Schema::hasTable('aprovisionamientos')) {
            return [];
        }

        return DB::table('aprovisionamientos')->where('company_id', $companyId)
            ->whereIn('estado', ['error', 'con_errores', 'no_aplica'])
            ->where('updated_at', '>=', now()->subDays(3))
            ->orderByDesc('updated_at')->limit(10)
            ->get(['id', 'user_id', 'serial', 'estado', 'detalle', 'datos', 'updated_at'])
            ->map(function ($a) {
                $cliente = (string) (json_decode((string) $a->datos, true)['cliente'] ?? '') ?: $a->serial;

                return [
                    'origen'  => 'equipo',
                    'nivel'   => $a->estado === 'error' ? 'critico' : 'aviso',
                    'titulo'  => "Equipo de {$cliente}: quedó a medias",
                    'detalle' => mb_substr((string) $a->detalle, 0, 180),
                    'cuando'  => (string) $a->updated_at,
                    'ruta'    => $a->user_id ? "usuario?cliente={$a->user_id}" : 'olt/autorizadas',
                ];
            })->all();
    }
}
