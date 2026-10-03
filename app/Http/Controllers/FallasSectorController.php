<?php

namespace App\Http\Controllers;

use App\Services\Red\FallasDeSector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Módulo «Fallas de sector»: configuración, nombres de los sectores y fallas detectadas.
 */
class FallasSectorController extends Controller
{
    private function empresa(): int
    {
        return (int) getSessionCompanyId();
    }

    /** GET /api/fallas-sector */
    public function index(): JsonResponse
    {
        $empresa = $this->empresa();
        $cfg = FallasDeSector::config($empresa);

        $nombres = DB::table('fallas_sector_nombres')->where('company_id', $empresa)->get()->keyBy(fn ($n) => $n->olt_id . '|' . $n->fsp);
        $puertos = DB::table('olt_onts as o')->join('olt_admins as a', 'a.id', '=', 'o.olt_id')
            ->leftJoin('user_data as ud', 'ud.id', '=', 'o.user_data_id')
            ->where('a.company_id', $empresa)
            ->groupBy('o.olt_id', 'a.name', 'a.brand', 'o.fsp')
            ->selectRaw('o.olt_id, a.name olt, a.brand marca, o.fsp, COUNT(*) onts, SUM(ud.active = 1 AND ud.status_internet_id = 1) clientes')
            ->orderBy('a.name')->get()
            ->sortBy(fn ($p) => [$p->olt, array_map('intval', explode('/', $p->fsp))])->values()
            ->map(fn ($p) => [
                'olt_id' => (int) $p->olt_id, 'olt' => $p->olt, 'marca' => $p->marca, 'fsp' => $p->fsp,
                'onts' => (int) $p->onts, 'clientes' => (int) $p->clientes,
                'nombre' => $nombres[$p->olt_id . '|' . $p->fsp]->nombre ?? null,
            ]);

        $servicio = new FallasDeSector($empresa);
        $ejemplo = (object) ['olt_id' => $puertos[0]['olt_id'] ?? 0, 'fsp' => $puertos[0]['fsp'] ?? ''];

        return response()->json(['error' => 0, 'data' => [
            'config'   => $cfg,
            'defecto'  => ['mensaje_inicio' => FallasDeSector::MENSAJE_INICIO, 'mensaje_fin' => FallasDeSector::MENSAJE_FIN],
            'ejemplo'  => [
                'inicio' => $servicio->rellenar($cfg->mensaje_inicio ?: FallasDeSector::MENSAJE_INICIO, 'MARÍA', $ejemplo),
                'fin'    => $servicio->rellenar($cfg->mensaje_fin ?: FallasDeSector::MENSAJE_FIN, 'MARÍA', $ejemplo),
            ],
            'lineas'   => DB::table('wa_lineas')->where('company_id', $empresa)->where('activa', 1)->orderByDesc('principal')->get(['id', 'nombre', 'telefono', 'principal']),
            'puertos'  => $puertos,
            'fallas'   => $this->fallas($empresa),
        ]]);
    }

    private function fallas(int $empresa)
    {
        $servicio = new FallasDeSector($empresa);

        return DB::table('fallas_sector as f')->where('f.company_id', $empresa)
            ->where(fn ($q) => $q->whereIn('f.estado', FallasDeSector::ABIERTAS)->orWhere('f.empezo_en', '>=', now()->subDays(30)))
            // Los parpadeos que se descartaron solos no le interesan a nadie.
            ->where(fn ($q) => $q->where('f.estado', '<>', 'descartada')->orWhere('f.mantenimiento', 1))
            ->orderByRaw("FIELD(f.estado, 'por_aprobar', 'activa', 'detectada') DESC")->orderByDesc('f.empezo_en')->limit(60)->get()
            ->map(function ($f) use ($servicio) {
                $c = DB::table('fallas_sector_clientes')->where('falla_id', $f->id)
                    ->selectRaw('COUNT(*) total, SUM(avisado_inicio_en IS NOT NULL) inicio, SUM(avisado_fin_en IS NOT NULL) fin, SUM(error IS NOT NULL) errores')->first();

                return (array) $f + [
                    'sector'   => $servicio->nombreDelSector($f, true),
                    'afectados' => (int) $c->total, 'avisados_inicio' => (int) $c->inicio, 'avisados_fin' => (int) $c->fin, 'errores' => (int) $c->errores,
                ];
            });
    }

    /** PUT /api/fallas-sector/config */
    public function guardar(Request $request): JsonResponse
    {
        $hora = ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'];
        $datos = $request->validate([
            'activo' => 'required|boolean', 'modo' => 'required|in:automatico,aprobar',
            'minimo_onts' => 'required|integer|between:1,500', 'porcentaje' => 'required|integer|between:1,100',
            'minutos_revision' => 'required|integer|between:1,60', 'minutos_confirmacion' => 'required|integer|between:0,120',
            'minutos_resolucion' => 'required|integer|between:0,120', 'contar_cortes_de_luz' => 'required|boolean',
            'avisar_inicio' => 'required|boolean', 'avisar_fin' => 'required|boolean', 'avisar_grupo' => 'required|boolean',
            'incluir_suspendidos' => 'required|boolean',
            'wa_linea_id' => ['nullable', 'integer', fn ($a, $v, $fail) => $v && !DB::table('wa_lineas')->where('id', $v)->where('company_id', $this->empresa())->exists() ? $fail('Esa línea no es de la empresa.') : null],
            'segundos_entre_mensajes' => 'required|integer|between:0,30',
            'silencio_desde' => $hora, 'silencio_hasta' => $hora,
            'mensaje_inicio' => 'nullable|string|max:1000', 'mensaje_fin' => 'nullable|string|max:1000',
        ]);

        return response()->json(['error' => 0, 'message' => 'Configuración guardada.', 'data' => FallasDeSector::guardarConfig($this->empresa(), $datos)]);
    }

    /** PUT /api/fallas-sector/sectores — [{olt_id, fsp, nombre}] */
    public function sectores(Request $request): JsonResponse
    {
        $request->validate(['sectores' => 'required|array|max:500', 'sectores.*.olt_id' => 'required|integer', 'sectores.*.fsp' => 'required|string|max:20', 'sectores.*.nombre' => 'nullable|string|max:120']);
        $empresa = $this->empresa();
        $olts = DB::table('olt_admins')->where('company_id', $empresa)->pluck('id')->map(fn ($i) => (int) $i)->all();

        foreach ($request->input('sectores') as $s) {
            if (!in_array((int) $s['olt_id'], $olts, true)) {
                continue;
            }

            $llave = ['olt_id' => (int) $s['olt_id'], 'fsp' => $s['fsp']];
            $nombre = trim((string) ($s['nombre'] ?? ''));

            if ($nombre === '') {
                DB::table('fallas_sector_nombres')->where($llave)->delete();
                continue;
            }

            DB::table('fallas_sector_nombres')->updateOrInsert($llave, ['company_id' => $empresa, 'nombre' => $nombre, 'updated_at' => now(), 'created_at' => now()]);
        }

        return response()->json(['error' => 0, 'message' => 'Nombres de los sectores guardados.']);
    }

    /** GET /api/fallas-sector/fallas/{id} */
    public function falla(int $id): JsonResponse
    {
        $f = DB::table('fallas_sector')->where('company_id', $this->empresa())->where('id', $id)->first();

        if (!$f) {
            return response()->json(['error' => 1, 'message' => 'No existe esa falla.'], 404);
        }

        $clientes = DB::table('fallas_sector_clientes as c')->join('user_data as ud', 'ud.user_id', '=', 'c.user_id')
            ->where('c.falla_id', $id)->orderBy('ud.names')
            ->get(['c.user_id', 'ud.names', 'ud.lastname', 'c.telefono', 'c.ont', 'c.avisado_inicio_en', 'c.avisado_fin_en', 'c.error']);

        return response()->json(['error' => 0, 'data' => ['falla' => $f, 'sector' => (new FallasDeSector($this->empresa()))->nombreDelSector($f, true), 'clientes' => $clientes]]);
    }

    /** POST /api/fallas-sector/fallas/{id}/{accion} — aprobar, descartar, resolver */
    public function accion(Request $request, int $id, string $accion): JsonResponse
    {
        $servicio = new FallasDeSector($this->empresa());
        $usuario = (int) getSessionUserId();

        $hecho = match ($accion) {
            'aprobar'   => $servicio->aprobar($id, $usuario),
            'descartar' => $servicio->descartar($id, $usuario, $request->input('nota')),
            'resolver'  => $servicio->resolverAMano($id),
            default     => false,
        };

        if (!$hecho) {
            return response()->json(['error' => 1, 'message' => 'La falla ya cambió de estado. Recargue la lista.']);
        }

        // Aprobada o resuelta: los avisos salen ya, sin esperar la próxima revisión.
        if (in_array($accion, ['aprobar', 'resolver'], true)) {
            $this->lanzarRevision(false);
        }

        return response()->json(['error' => 0, 'message' => match ($accion) {
            'aprobar' => 'Aprobada: se les está avisando a los clientes.', 'descartar' => 'Descartada: no se le escribe a nadie.', default => 'Cerrada.',
        }]);
    }

    /** POST /api/fallas-sector/revisar — leer la OLT ahora. */
    public function revisar(): JsonResponse
    {
        $this->lanzarRevision(true);

        return response()->json(['error' => 0, 'message' => 'Revisando la red. Actualice en unos segundos.']);
    }

    /** POST /api/fallas-sector/probar — un mensaje de muestra al número que se indique. */
    public function probar(Request $request): JsonResponse
    {
        $request->validate(['telefono' => 'required|string|max:20', 'cual' => 'required|in:inicio,fin']);
        $empresa = $this->empresa();
        $cfg = FallasDeSector::config($empresa);
        $servicio = new FallasDeSector($empresa);
        $falla = DB::table('fallas_sector_nombres')->where('company_id', $empresa)->first() ?? (object) ['olt_id' => 0, 'fsp' => ''];
        $texto = $servicio->rellenar($request->input('cual') === 'inicio' ? ($cfg->mensaje_inicio ?: FallasDeSector::MENSAJE_INICIO) : ($cfg->mensaje_fin ?: FallasDeSector::MENSAJE_FIN), 'Prueba', $falla);
        $instancia = $cfg->wa_linea_id ? DB::table('wa_lineas')->where('id', $cfg->wa_linea_id)->value('instance_id') : null;

        try {
            $r = (new \App\Services\WhatsAppService($empresa, false, 'netplay', $instancia))->mensajeInformativo(preg_replace('/\D/', '', $request->input('telefono')), $texto);
        } catch (\Throwable $e) {
            return response()->json(['error' => 1, 'message' => 'No se pudo enviar: ' . $e->getMessage()]);
        }

        return response()->json(($r['status'] ?? null) === 'error'
            ? ['error' => 1, 'message' => 'No se pudo enviar: ' . ($r['message'] ?? '')]
            : ['error' => 0, 'message' => 'Mensaje de prueba enviado.']);
    }

    private function lanzarRevision(bool $forzar): void
    {
        $php = (new PhpExecutableFinder())->find() ?: 'php';

        exec(sprintf('nohup %s %s red:fallas-sector --empresa=%d%s >> %s 2>&1 &',
            escapeshellarg($php), escapeshellarg(base_path('artisan')), $this->empresa(), $forzar ? ' --forzar' : '',
            escapeshellarg(storage_path('logs/fallas-sector.log'))));
    }
}
