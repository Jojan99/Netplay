<?php

namespace App\Http\Controllers;

use App\Models\CobranzaCaso;
use App\Models\CobranzaConfig;
use App\Models\Company;
use App\Services\Cobranza\Ia;
use App\Services\Cobranza\Cobranza;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Cobranza inteligente: configuración, casos y la burbuja del panel. */
class CobranzaController extends Controller
{
    private function empresa(): int
    {
        return (int) getSessionCompanyId();
    }

    // ── Configuración ─────────────────────────────────────────────────────

    public function config(): JsonResponse
    {
        $companyId = $this->empresa();
        $cfg = CobranzaConfig::deEmpresa($companyId);
        $empresa = Company::find($companyId);

        return standardApiReponse('OK', [
            'config'        => array_merge((new CobranzaConfig())->getAttributes(), $this->valoresPorDefecto(), $cfg->exists ? $cfg->toArray() : []),
            'ia_disponible' => Ia::disponible($companyId),
            'pasarela'      => (bool) ($empresa?->pg_active) && !empty($empresa?->pg_gateway),
            // La clave nunca se devuelve: sólo si hay una.
            'ia' => [
                'propia'        => $cfg->tieneClavePropia(),
                'modelos'       => $cfg->ia_modelos,
                'limite_prueba' => Ia::LIMITE_PRUEBA,
                'usadas_hoy'    => \App\Services\Cobranza\UsoIa::conversacionesDePruebaHoy($companyId),
                'url_clave'     => Ia::URL_CLAVE,
                'url_limites'   => Ia::URL_LIMITES,
            ],
            'lineas'        => DB::table('wa_lineas')->where('company_id', $companyId)->where('activa', 1)->orderByDesc('principal')->get(['id', 'nombre', 'telefono', 'principal']),
        ], 0, JsonResponse::HTTP_OK);
    }

    public function guardarConfig(Request $request): JsonResponse
    {
        $companyId = $this->empresa();

        $datos = $request->validate([
            'activa'                    => 'required|boolean',
            'modo'                      => ['required', Rule::in(['manual', 'automatico'])],
            'min_facturas'              => 'required|integer|min:1|max:24',
            'min_dias_mora'             => 'required|integer|min:0|max:365',
            'max_dias_mora'             => 'required|integer|min:0|max:3650',
            'min_monto'                 => 'required|numeric|min:0|max:100000000',
            'descuento_max_pct'         => 'required|integer|min:0|max:50',
            'descuento_dias'            => 'required|integer|min:1|max:15',
            'cuotas_max'                => 'required|integer|min:1|max:6',
            'plazo_max_dias'            => 'required|integer|min:1|max:60',
            'compromiso_suspende'       => 'required|boolean',
            'hora_desde'                => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'hora_hasta'                => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/', 'after:hora_desde'],
            'dias'                      => ['required', 'regex:/^[1-7](,[1-7])*$/'],
            'max_contactos_dia'         => 'required|integer|min:1|max:300',
            'recordatorios'             => 'required|integer|min:0|max:3',
            'horas_entre_recordatorios' => 'required|integer|min:4|max:168',
            'nombre_asistente'          => 'required|string|max:60',
            'instrucciones'             => 'nullable|string|max:1500',
            'wa_linea_id'               => ['nullable', 'integer', Rule::exists('wa_lineas', 'id')->where('company_id', $companyId)],
        ], [
            'hora_hasta.after' => 'La hora de fin tiene que ser después de la de inicio.',
            'dias.regex'       => 'Elegí al menos un día.',
        ]);

        $cfg = CobranzaConfig::deEmpresa($companyId);
        $cfg->fill($datos + ['company_id' => $companyId]);

        // La clave de Google: vacía = se conserva; se prueba antes de guardarla.
        $clave = trim((string) $request->input('ia_clave', ''));

        if ($request->boolean('quitar_clave')) {
            $cfg->ia_clave = null;
        } elseif ($clave !== '') {
            $prueba = self::probarClave($clave, $request->input('ia_modelos') ?: $cfg->ia_modelos);

            if (!$prueba['ok']) {
                return standardApiReponse('No se guardó: ' . $prueba['mensaje'], null, 1, JsonResponse::HTTP_OK);
            }

            $cfg->ia_clave = $clave;
        }

        if ($request->has('ia_modelos')) {
            $modelos = trim((string) $request->input('ia_modelos'));
            $cfg->ia_modelos = preg_match('/^[\w.\-]+(,[\w.\-]+)*$/', $modelos) ? $modelos : null;
        }

        $cfg->save();

        return standardApiReponse('Cobranza guardada.', $cfg->fresh(), 0, JsonResponse::HTTP_OK);
    }

    /** Prueba una clave (la que escribió, o la guardada) sin guardarla. */
    public function probarIa(Request $request): JsonResponse
    {
        $cfg = CobranzaConfig::deEmpresa($this->empresa());
        $clave = trim((string) $request->input('ia_clave', '')) ?: (string) $cfg->ia_clave;

        if ($clave === '') {
            return standardApiReponse('Escribí la clave de Google para probarla.', null, 1, JsonResponse::HTTP_OK);
        }

        $r = self::probarClave($clave, $cfg->ia_modelos);

        return standardApiReponse($r['mensaje'], null, $r['ok'] ? 0 : 1, JsonResponse::HTTP_OK);
    }

    /** @return array{ok:bool, mensaje:string} */
    private static function probarClave(string $clave, ?string $modelos): array
    {
        try {
            $r = (new \App\Services\Cobranza\IaCompatible($clave, null, $modelos))
                ->mensaje('Responde solamente: OK', [['role' => 'user', 'content' => 'Prueba de conexión']], [], 20);

            return ['ok' => true, 'mensaje' => 'La clave funciona: el asistente ya puede usar tu cuenta de Google.'];
        } catch (\App\Services\Cobranza\IaOcupada) {
            // La clave es válida; sólo está en su límite ahora.
            return ['ok' => true, 'mensaje' => 'La clave es válida (ahora está en su límite de uso, se libera sola).'];
        } catch (\Throwable $e) {
            $texto = $e->getMessage();

            return ['ok' => false, 'mensaje' => preg_match('/valid API key|API key not valid|API_KEY_INVALID|respondió (400|401|403)|PERMISSION_DENIED/i', $texto)
                ? 'Google no reconoce esa clave. Copiala de nuevo desde ' . Ia::URL_CLAVE
                : 'No se pudo probar la clave: ' . mb_substr($texto, 0, 160)];
        }
    }

    private function valoresPorDefecto(): array
    {
        return [
            'activa' => false, 'modo' => 'manual', 'min_facturas' => 1, 'min_dias_mora' => 10, 'max_dias_mora' => 120, 'min_monto' => 0,
            'descuento_max_pct' => 0, 'descuento_dias' => 2, 'cuotas_max' => 1, 'plazo_max_dias' => 15, 'compromiso_suspende' => true,
            'hora_desde' => '08:00', 'hora_hasta' => '18:00', 'dias' => '1,2,3,4,5,6', 'max_contactos_dia' => 20,
            'recordatorios' => 1, 'horas_entre_recordatorios' => 24, 'nombre_asistente' => 'Asistente de cartera',
            'instrucciones' => '', 'wa_linea_id' => null,
        ];
    }

    // ── La burbuja ────────────────────────────────────────────────────────

    public function resumen(): JsonResponse
    {
        $companyId = $this->empresa();
        $cfg = CobranzaConfig::deEmpresa($companyId);
        $cuenta = CobranzaCaso::where('company_id', $companyId)->select('estado', DB::raw('COUNT(*) as n'))->groupBy('estado')->pluck('n', 'estado');

        return standardApiReponse('OK', [
            'activa'        => (bool) ($cfg->exists && $cfg->activa),
            'modo'          => $cfg->modo ?? 'manual',
            'ia_disponible' => Ia::disponible($companyId),
            'detectados'    => (int) ($cuenta['detectado'] ?? 0),
            'escalados'     => (int) ($cuenta['escalado'] ?? 0),
            'en_curso'      => (int) collect(['autorizado', 'contactado', 'negociando', 'acuerdo'])->sum(fn ($e) => $cuenta[$e] ?? 0),
            'no_vistos'     => CobranzaCaso::where('company_id', $companyId)->where('visto', false)
                ->whereIn('estado', ['detectado', 'escalado', 'acuerdo', 'pagado'])->count(),
        ], 0, JsonResponse::HTTP_OK);
    }

    public function marcarVistos(): JsonResponse
    {
        CobranzaCaso::where('company_id', $this->empresa())->where('visto', false)->update(['visto' => true]);

        return standardApiReponse('OK', null, 0, JsonResponse::HTTP_OK);
    }

    // ── Casos ─────────────────────────────────────────────────────────────

    public function casos(Request $request): JsonResponse
    {
        $grupos = [
            'atencion'   => CobranzaCaso::PENDIENTES,
            'curso'      => ['autorizado', 'contactado', 'negociando', 'acuerdo'],
            'resultados' => ['pagado', 'descartado', 'sin_respuesta', 'cerrado'],
        ];
        $estados = $grupos[$request->query('grupo', 'atencion')] ?? $grupos['atencion'];

        $casos = CobranzaCaso::from('cobranza_casos as c')
            ->leftJoin('user_data as u', 'u.user_id', '=', 'c.user_id')
            ->where('c.company_id', $this->empresa())
            ->whereIn('c.estado', $estados)
            ->when($request->query('grupo') === 'resultados', fn ($q) => $q->where('c.updated_at', '>=', now()->subDays(30)))
            ->orderByRaw("FIELD(c.estado, 'escalado', 'detectado') DESC")
            ->orderByDesc('c.deuda')
            ->limit(200)
            ->get(['c.id', 'c.user_id', 'c.estado', 'c.resultado', 'c.motivo', 'c.resumen', 'c.deuda', 'c.facturas', 'c.dias_mora',
                'c.telefono', 'c.conversation_id', 'c.contactado_en', 'c.ultimo_mensaje_en', 'c.ultima_respuesta_en', 'c.visto', 'c.updated_at',
                'u.names', 'u.lastname', 'u.dni']);

        return standardApiReponse('OK', $casos, 0, JsonResponse::HTTP_OK);
    }

    /** Un caso con la conversación del asistente (desde que la abrió). */
    public function caso(int $id): JsonResponse
    {
        $caso = $this->elCaso($id);
        $cliente = DB::table('user_data')->where('user_id', $caso->user_id)->first(['names', 'lastname', 'dni', 'phone']);

        $mensajes = $caso->conversation_id
            ? DB::table('crm_messages')->where('conversation_id', $caso->conversation_id)
                ->when($caso->contactado_en, fn ($q) => $q->where('created_at', '>=', $caso->contactado_en->copy()->subMinute()))
                ->whereNull('deleted_at')
                ->orderByDesc('id')->limit(60)
                ->get(['id', 'sender_type', 'message_type', 'content', 'agent_signature', 'created_at'])->reverse()->values()
            : collect();

        return standardApiReponse('OK', [
            'caso'     => $caso->makeHidden('historial'),
            'cliente'  => $cliente,
            'mensajes' => $mensajes,
        ], 0, JsonResponse::HTTP_OK);
    }

    public function autorizar(int $id): JsonResponse
    {
        $caso = $this->elCaso($id);

        if ($caso->estado !== 'detectado') {
            return standardApiReponse('Este caso ya no está esperando autorización.', null, 1, JsonResponse::HTTP_OK);
        }

        if (!Ia::disponible($this->empresa())) {
            return standardApiReponse('El asistente todavía no está conectado (falta la clave de la IA en el servidor).', null, 1, JsonResponse::HTTP_OK);
        }

        $caso->fill(['estado' => 'autorizado', 'autorizado_por' => getSessionUserId(), 'autorizado_en' => now(), 'visto' => true])->save();
        $cfg = CobranzaConfig::deEmpresa($this->empresa());

        if (!\App\Services\Cobranza\UsoIa::puedeEmpezar($this->empresa())) {
            return standardApiReponse('Autorizado, pero hoy ya se usaron las ' . Ia::LIMITE_PRUEBA . ' conversaciones de prueba con la IA de Netvula: '
                . 'le escribe mañana. Para no tener límite, conectá tu propia clave de Google en Cobranza inteligente.', $caso, 0, JsonResponse::HTTP_OK);
        }

        // En horario se le escribe ya (después de contestarle al panel); si
        // no, en la próxima revisión dentro del horario.
        if ($cfg->enHorario()) {
            $casoId = $caso->id;
            $companyId = $this->empresa();
            app()->terminating(function () use ($casoId, $companyId) {
                if ($c = CobranzaCaso::find($casoId)) {
                    (new Cobranza($companyId))->contactar($c);
                }
            });

            return standardApiReponse('Listo: el asistente le escribe en unos segundos.', $caso, 0, JsonResponse::HTTP_OK);
        }

        return standardApiReponse("Autorizado. Fuera del horario de cobranza ({$cfg->hora_desde}–{$cfg->hora_hasta}): le escribe apenas empiece.", $caso, 0, JsonResponse::HTTP_OK);
    }

    public function descartar(int $id): JsonResponse
    {
        $caso = $this->elCaso($id);

        if (!in_array($caso->estado, ['detectado', 'autorizado', 'escalado', 'sin_respuesta'], true)) {
            return standardApiReponse('Este caso no se puede descartar ahora.', null, 1, JsonResponse::HTTP_OK);
        }

        $caso->fill(['estado' => 'descartado', 'resultado' => 'descartado', 'visto' => true])->save();

        return standardApiReponse('Caso descartado.', $caso, 0, JsonResponse::HTTP_OK);
    }

    /** Una persona se hace cargo: el asistente deja de contestar. */
    public function tomar(int $id): JsonResponse
    {
        $caso = $this->elCaso($id);
        $caso->fill(['estado' => 'cerrado', 'resultado' => 'lo_atiende_una_persona', 'visto' => true])->save();

        return standardApiReponse('Listo: ahora lo atendés vos desde el CRM; el asistente ya no le contesta.', $caso, 0, JsonResponse::HTTP_OK);
    }

    /** Lo escalado vuelve al asistente (por ejemplo, ya se aclaró el pago). */
    public function devolver(int $id): JsonResponse
    {
        $caso = $this->elCaso($id);

        if ($caso->estado !== 'escalado' || !$caso->conversation_id) {
            return standardApiReponse('Sólo se devuelve al asistente un caso escalado con conversación.', null, 1, JsonResponse::HTTP_OK);
        }

        $caso->fill(['estado' => 'negociando', 'visto' => true])->save();

        return standardApiReponse('El asistente vuelve a atender a este cliente.', $caso, 0, JsonResponse::HTTP_OK);
    }

    public function revisarAhora(): JsonResponse
    {
        $companyId = $this->empresa();
        $cfg = CobranzaConfig::deEmpresa($companyId);

        if (!$cfg->exists || !$cfg->activa) {
            return standardApiReponse('Activá la cobranza para poder revisar.', null, 1, JsonResponse::HTTP_OK);
        }

        $n = (new Cobranza($companyId))->detectar();

        return standardApiReponse($n ? "Se encontraron {$n} cliente(s) nuevos para cobrar." : 'No hay clientes nuevos que cumplan las reglas.', ['detectados' => $n], 0, JsonResponse::HTTP_OK);
    }

    private function elCaso(int $id): CobranzaCaso
    {
        return CobranzaCaso::where('company_id', $this->empresa())->findOrFail($id);
    }
}
