<?php

namespace App\Http\Controllers;

use App\Models\GestionRemota;
use App\Models\SoporteCaso;
use App\Models\SoporteConfig;
use App\Services\Cobranza\Ia;
use App\Services\Cobranza\UsoIa;
use App\Services\Plataforma\ComplementoTr069;
use App\Services\Soporte\AgenteDeSoporte;
use App\Services\Soporte\DiagnosticoDeServicio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * El asistente de soporte por WhatsApp: cómo lo quiere la empresa y qué casos lleva.
 */
class SoporteAsistenteController extends Controller
{
    private const NOMBRES = [
        'identificar_cliente' => 'Buscó al cliente por su documento', 'diagnosticar' => 'Revisó el servicio', 'hacer_ping' => 'Hizo una prueba de ping',
        'cambiar_clave_wifi' => 'Cambio de clave del WiFi', 'reiniciar_equipo' => 'Reinicio del equipo', 'crear_ticket' => 'Creó un ticket',
        'pasar_a_asesor' => 'Pasó el caso a un asesor', 'cerrar_caso' => 'Cerró el caso',
    ];

    private function empresa(): int
    {
        return (int) getSessionCompanyId();
    }

    private function bien(string $mensaje, mixed $data = null): JsonResponse
    {
        return response()->json(['message' => $mensaje, 'data' => $data, 'error' => 0]);
    }

    private function mal(string $mensaje, int $http = 200): JsonResponse
    {
        return response()->json(['message' => $mensaje, 'data' => null, 'error' => 1], $http);
    }

    /** GET api/soporte-asistente — configuración, con qué cuenta y las cifras de hoy. */
    public function estado(): JsonResponse
    {
        $id = $this->empresa();
        $hoy = SoporteCaso::where('company_id', $id)->where('created_at', '>=', now()->startOfDay());
        $semana = SoporteCaso::where('company_id', $id)->where('created_at', '>=', now()->subDays(7));
        $propia = UsoIa::clave($id) === 'propia';

        return $this->bien('Asistente de soporte', [
            'config' => SoporteConfig::deEmpresa($id),
            'con_que_cuenta' => [
                'ia' => Ia::disponible($id),
                'clave_propia' => $propia,
                'cupo_de_prueba' => $propia ? null : Ia::LIMITE_PRUEBA,
                'usadas_hoy' => $propia ? null : UsoIa::conversacionesDePruebaHoy($id),
                'tr069' => ComplementoTr069::permitido($id),
                'gestion_remota' => GestionRemota::where('company_id', $id)->where('activa', 1)->exists(),
                'lineas_web' => (int) DB::table('wa_lineas')->where('company_id', $id)->where('activa', 1)->count(),
                'meta' => (string) DB::table('companies')->where('id', $id)->value('wa_phone_number_id') !== '',
            ],
            'hoy' => [
                'casos' => (clone $hoy)->count(),
                'abiertos' => SoporteCaso::where('company_id', $id)->whereIn('estado', SoporteCaso::ABIERTOS)->count(),
                'resueltos' => (clone $hoy)->where('estado', 'resuelto')->count(),
                'a_persona' => (clone $hoy)->whereIn('estado', ['escalado', 'humano'])->count(),
                'tickets' => (clone $hoy)->whereNotNull('ticket_id')->count(),
            ],
            'semana' => [
                'casos' => (clone $semana)->count(),
                'resueltos' => (clone $semana)->where('estado', 'resuelto')->count(),
                'por_causa' => (clone $semana)->whereNotNull('diagnostico')->get(['diagnostico'])
                    ->map(fn ($c) => $c->diagnostico['clave'] ?? null)->filter()->countBy()->sortDesc()->all(),
            ],
        ]);
    }

    /** PUT api/soporte-asistente/config */
    public function guardar(Request $request): JsonResponse
    {
        $d = $request->validate([
            'activa' => 'required|boolean',
            'canal_web' => 'required|boolean',
            'canal_meta' => 'required|boolean',
            'nombre_asistente' => 'required|string|min:2|max:60',
            'instrucciones' => 'nullable|string|max:1500',
            'permite_cambiar_clave' => 'required|boolean',
            'exige_telefono_registrado' => 'required|boolean',
            'permite_reiniciar' => 'required|boolean',
            'crea_tickets' => 'required|boolean',
            'max_casos_dia' => 'required|integer|min:1|max:1000',
            'minutos_inactividad' => 'required|integer|min:5|max:240',
            'palabras' => 'nullable|string|max:500',
        ]);

        if ($d['activa'] && !Ia::disponible($this->empresa())) {
            return $this->mal('No hay una clave de inteligencia artificial configurada: sin ella el asistente no puede conversar.');
        }

        $cfg = SoporteConfig::updateOrCreate(['company_id' => $this->empresa()], $d);

        return $this->bien($cfg->activa ? 'Asistente de soporte activado' : 'Configuración guardada', $cfg);
    }

    /** GET api/soporte-asistente/casos?estado=&q= */
    public function casos(Request $request): JsonResponse
    {
        $q = SoporteCaso::from('soporte_casos as s')->leftJoin('user_data as u', fn ($j) => $j->on('u.user_id', '=', 's.user_id')->on('u.company_id', '=', 's.company_id'))
            ->where('s.company_id', $this->empresa());

        match ((string) $request->query('estado', '')) {
            'abiertos' => $q->whereIn('s.estado', SoporteCaso::ABIERTOS),
            'resueltos' => $q->where('s.estado', 'resuelto'),
            'persona' => $q->whereIn('s.estado', ['escalado', 'humano']),
            'cerrados' => $q->where('s.estado', 'cerrado'),
            default => null,
        };

        if ($b = trim((string) $request->query('q', ''))) {
            $q->where(fn ($w) => $w->where('s.telefono', 'like', "%{$b}%")->orWhere('u.names', 'like', "%{$b}%")->orWhere('u.lastname', 'like', "%{$b}%")->orWhere('u.dni', 'like', "%{$b}%"));
        }

        $filas = $q->orderByDesc('s.id')->limit(150)->get(['s.id', 's.user_id', 's.telefono', 's.provider', 's.estado', 's.verificado', 's.resultado', 's.resumen', 's.diagnostico', 's.ticket_id', 's.conversation_id', 's.consultas_ia', 's.created_at', 's.cerrado_en', 's.ultimo_mensaje_en', 'u.names', 'u.lastname']);

        return $this->bien('Casos', $filas->map(fn ($c) => [
            'id' => $c->id, 'user_id' => $c->user_id, 'cliente' => trim("{$c->names} {$c->lastname}") ?: null, 'telefono' => $c->telefono, 'canal' => $c->provider,
            'estado' => $c->estado, 'verificado' => (bool) $c->verificado, 'resultado' => $c->resultado, 'resumen' => $c->resumen,
            'causa' => $c->diagnostico['clave'] ?? null, 'conclusion' => $c->diagnostico['conclusion'] ?? null, 'acciones' => array_values((array) ($c->diagnostico['acciones'] ?? [])),
            'ticket_id' => $c->ticket_id, 'conversation_id' => $c->conversation_id, 'creado' => $c->created_at, 'cerrado' => $c->cerrado_en, 'ultimo' => $c->ultimo_mensaje_en,
        ])->all());
    }

    /** GET api/soporte-asistente/casos/{id} — la conversación, con lo que el asistente hizo en cada paso. */
    public function caso(int $id): JsonResponse
    {
        $c = SoporteCaso::where('company_id', $this->empresa())->find($id);

        if (!$c) {
            return $this->mal('Ese caso no existe.', 404);
        }

        $pasos = [];

        foreach ((array) $c->historial as $m) {
            if (is_string($m['content'])) {
                $esCliente = str_starts_with($m['content'], '[CLIENTE] ');
                $pasos[] = ['quien' => $esCliente ? 'cliente' : 'sistema', 'texto' => $esCliente ? substr($m['content'], 10) : preg_replace('/^\[SISTEMA\] /', '', $m['content'])];

                continue;
            }

            foreach ((array) $m['content'] as $b) {
                $pasos[] = match ($b['type'] ?? '') {
                    'text' => ['quien' => 'asistente', 'texto' => (string) $b['text']],
                    // Nunca la clave que se pidió: sólo qué se intentó.
                    'tool_use' => ['quien' => 'accion', 'texto' => self::NOMBRES[$b['name'] ?? ''] ?? (string) ($b['name'] ?? '')],
                    'tool_result' => ['quien' => 'resultado', 'texto' => (string) $b['content'], 'error' => (bool) ($b['is_error'] ?? false)],
                    default => null,
                };
            }
        }

        return $this->bien('Caso', [
            'id' => $c->id, 'estado' => $c->estado, 'resultado' => $c->resultado, 'resumen' => $c->resumen, 'verificado' => $c->verificado,
            'hechos' => array_values((array) ($c->diagnostico['hechos'] ?? [])), 'conclusion' => $c->diagnostico['conclusion'] ?? null,
            'ticket_id' => $c->ticket_id, 'conversation_id' => $c->conversation_id, 'consultas_ia' => $c->consultas_ia,
            'pasos' => array_values(array_filter(array_map(fn ($p) => $p && trim($p['texto']) !== '' ? $p : null, $pasos))),
        ]);
    }

    /** POST api/soporte-asistente/casos/{id}/tomar — una persona se hace cargo y el asistente se retira. */
    public function tomar(int $id): JsonResponse
    {
        $c = SoporteCaso::where('company_id', $this->empresa())->find($id);

        if (!$c || !$c->abierto()) {
            return $this->mal('Ese caso ya no lo lleva el asistente.');
        }

        AgenteDeSoporte::cerrar($c, 'humano', 'lo_tomo_una_persona', false);

        return $this->bien('El asistente se retiró de esta conversación. Continúe usted desde el CRM.', ['conversation_id' => $c->conversation_id]);
    }

    /** POST api/soporte-asistente/probar — el mismo diagnóstico que hace el asistente, para un cliente (sólo lee). */
    public function probar(Request $request): JsonResponse
    {
        $d = $request->validate(['user_id' => 'required|integer']);

        if (!DB::table('user_data')->where('company_id', $this->empresa())->where('user_id', $d['user_id'])->exists()) {
            return $this->mal('Ese cliente no es de su empresa.', 404);
        }

        try {
            $r = (new DiagnosticoDeServicio($this->empresa(), (int) $d['user_id']))->completo();
        } catch (\Throwable $e) {
            Log::warning('[Soporte] Falló el diagnóstico de prueba', ['error' => $e->getMessage()]);

            return $this->mal('No se pudo hacer el diagnóstico en este momento.');
        }

        return $this->bien('Diagnóstico', ['clave' => $r['clave'], 'conclusion' => $r['conclusion'], 'para_el_cliente' => $r['para_el_cliente'], 'hechos' => $r['hechos']]);
    }
}
