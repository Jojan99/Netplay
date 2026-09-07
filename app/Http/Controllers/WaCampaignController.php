<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\WaCampaign;
use App\Models\WaCampaignRecipient;
use App\Models\WaTemplateBinding;
use App\Services\WhatsApp\CampaignService;
use App\Services\MetaWhatsAppService;
use App\Services\WhatsApp\ClientAudience;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Envíos de una plantilla a muchos clientes.
 *
 * El envío real nunca ocurre aquí: esta clase prepara, prueba y da la orden.
 * Quien manda los mensajes es el comando programado, porque novecientos
 * mensajes no caben en una respuesta HTTP.
 */
class WaCampaignController extends Controller
{
    public function __construct(
        private CampaignService $campañas,
        private ClientAudience $audiencia,
    ) {}

    /** Lo que el panel necesita para armar la pantalla. */
    public function options(): JsonResponse
    {
        return response()->json([
            'ok'        => true,
            'filtros'   => ClientAudience::FILTROS,
            'variables' => collect(WaTemplateBinding::VARIABLES)
                ->map(fn (array $v, string $k) => $v + ['key' => $k])
                ->values(),
        ]);
    }

    public function index(): JsonResponse
    {
        $companyId = getSessionCompanyId();

        if (!$companyId) {
            return $this->sinEmpresa();
        }

        return response()->json([
            'ok'        => true,
            'campaigns' => WaCampaign::where('company_id', $companyId)
                ->orderByDesc('id')
                ->limit(50)
                ->get(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $campaign = $this->buscar($id);

        if (!$campaign) {
            return $this->noEncontrada();
        }

        return response()->json([
            'ok'       => true,
            'campaign' => $campaign,
            'progreso' => [
                'total'      => (int) $campaign->recipients_count,
                'enviados'   => $campaign->recipients()->where('status', WaCampaignRecipient::ENVIADO)->count(),
                'fallidos'   => $campaign->recipients()->where('status', WaCampaignRecipient::FALLIDO)->count(),
                'pendientes' => $campaign->recipients()->where('status', WaCampaignRecipient::PENDIENTE)->count(),
            ],
            // Solo los que fallaron: los enviados son novecientos y no aportan.
            'fallidos' => $campaign->recipients()
                ->where('status', WaCampaignRecipient::FALLIDO)
                ->limit(50)
                ->get(['user_id', 'name', 'phone', 'error']),
        ]);
    }

    /** Cuántos recibirían con estos filtros, antes de gastar un solo mensaje. */
    public function audience(Request $request): JsonResponse
    {
        $companyId = getSessionCompanyId();

        if (!$companyId) {
            return $this->sinEmpresa();
        }

        return response()->json([
            'ok' => true,
        ] + $this->campañas->preview(
            $companyId,
            $this->filtros($request),
            $this->excluidos($request)
        ));
    }

    /** Buscador de clientes, para ir armando la lista de excluidos. */
    public function clients(Request $request): JsonResponse
    {
        $companyId = getSessionCompanyId();

        if (!$companyId) {
            return $this->sinEmpresa();
        }

        $busqueda = trim((string) $request->query('q', ''));

        $clientes = $this->audiencia->resolve($companyId, $this->filtros($request))
            ->when($busqueda !== '', fn ($c) => $c->filter(function ($x) use ($busqueda) {
                $texto = mb_strtolower(($x->names ?? '') . ' ' . ($x->lastname ?? '') . ' ' . ($x->dni ?? '') . ' ' . ($x->phone ?? ''));
                return str_contains($texto, mb_strtolower($busqueda));
            }))
            ->take(100)
            ->map(fn ($c) => [
                'user_id' => (int) $c->user_id,
                'nombre'  => trim(($c->names ?? '') . ' ' . ($c->lastname ?? '')),
                'dni'     => $c->dni,
                'phone'   => $c->phone,
                'plan'    => $c->plan,
            ])
            ->values();

        return response()->json(['ok' => true, 'clients' => $clientes]);
    }

    /** Guarda el borrador. Guardar nunca envía nada. */
    public function save(Request $request): JsonResponse
    {
        $companyId = getSessionCompanyId();

        if (!$companyId) {
            return $this->sinEmpresa();
        }

        $validator = Validator::make($request->all(), [
            'id'                => ['nullable', 'integer'],
            'name'              => ['nullable', 'string', 'max:120'],
            'template_name'     => ['required', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/'],
            'language'          => ['nullable', 'string', 'max:10'],
            'params'            => ['array', 'max:20'],
            'params.*.tipo'     => ['required', 'in:variable,fijo'],
            'params.*.variable' => ['required', 'string', 'in:' . implode(',', array_keys(WaTemplateBinding::VARIABLES))],
            'params.*.valor'    => ['nullable', 'string', 'max:900'],
            'audience'          => ['array'],
            'excluded_user_ids' => ['array', 'max:5000'],
            'excluded_user_ids.*' => ['integer'],
        ], [
            'template_name.regex' => 'El nombre de la plantilla solo admite minúsculas, números y guiones bajos.',
        ]);

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'error' => $validator->errors()->first()], 422);
        }

        $datos = $validator->validated();

        $campaign = !empty($datos['id'])
            ? WaCampaign::where('company_id', $companyId)->find($datos['id'])
            : null;

        // Una campaña que ya salió no se edita: cambiarla dejaría el registro
        // sin correspondencia con lo que de verdad recibieron los clientes.
        if ($campaign && $campaign->status === WaCampaign::ENVIANDO) {
            return response()->json(['ok' => false, 'error' => 'Este envío ya está en curso y no se puede modificar.'], 422);
        }

        $atributos = [
            'company_id'        => $companyId,
            'created_by'        => auth()->id(),
            'name'              => $datos['name'] ?? null,
            'template_name'     => $datos['template_name'],
            'language'          => $datos['language'] ?: 'es_CO',
            'params'            => array_values($datos['params'] ?? []),
            'audience'          => $datos['audience'] ?? [],
            'excluded_user_ids' => array_values(array_unique($datos['excluded_user_ids'] ?? [])),
        ];

        if ($campaign) {
            // Cambiar el contenido invalida la prueba anterior: lo que se vio
            // ya no es lo que se enviaría.
            if ($this->cambióElContenido($campaign, $atributos)) {
                $atributos['test_sent_at'] = null;
                $atributos['status']       = WaCampaign::BORRADOR;
            }

            $campaign->update($atributos);
        } else {
            $campaign = WaCampaign::create($atributos + ['status' => WaCampaign::BORRADOR]);
        }

        return response()->json(['ok' => true, 'campaign' => $campaign->fresh()]);
    }

    /** Manda la plantilla a un número de prueba. Es obligatorio antes de enviar. */
    public function test(Request $request, int $id): JsonResponse
    {
        $campaign = $this->buscar($id);

        if (!$campaign) {
            return $this->noEncontrada();
        }

        $validator = Validator::make($request->all(), [
            'phone' => ['required', 'string', 'regex:/^[0-9+\s-]{10,20}$/'],
        ], [
            'phone.regex' => 'Escribe un número de celular válido.',
        ]);

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'error' => $validator->errors()->first()], 422);
        }

        $resultado = $this->campañas->sendTest($campaign, (string) $request->input('phone'));

        $aceptado = MetaWhatsAppService::accepted($resultado);

        return response()->json([
            'ok'       => $aceptado,
            'error'    => $aceptado ? null : ($resultado['error'] ?? 'Meta no aceptó el mensaje.'),
            'campaign' => $campaign->fresh(),
        ], $aceptado ? 200 : 422);
    }

    /** Da la orden de envío. A partir de aquí manda el comando programado. */
    public function send(int $id): JsonResponse
    {
        $campaign = $this->buscar($id);

        if (!$campaign) {
            return $this->noEncontrada();
        }

        try {
            $total = $this->campañas->start($campaign);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok'       => true,
            'total'    => $total,
            'campaign' => $campaign->fresh(),
            'mensaje'  => "El envío quedó en marcha para {$total} cliente(s). Puedes seguir el avance en esta pantalla.",
        ]);
    }

    /** Detiene un envío en curso. Lo ya enviado no se puede deshacer. */
    public function cancel(int $id): JsonResponse
    {
        $campaign = $this->buscar($id);

        if (!$campaign) {
            return $this->noEncontrada();
        }

        if (!$campaign->enCurso()) {
            return response()->json(['ok' => false, 'error' => 'Este envío no está en curso.'], 422);
        }

        DB::transaction(function () use ($campaign) {
            $campaign->recipients()
                ->where('status', WaCampaignRecipient::PENDIENTE)
                ->update(['status' => 'skipped', 'updated_at' => now()]);

            $campaign->update(['status' => WaCampaign::CANCELADO, 'finished_at' => now()]);
        });

        return response()->json([
            'ok'      => true,
            'mensaje' => 'Envío detenido. Los mensajes que ya salieron no se pueden retirar.',
        ]);
    }

    // ─── Interno ─────────────────────────────────────────────────────────────

    private function buscar(int $id): ?WaCampaign
    {
        $companyId = getSessionCompanyId();

        return $companyId ? WaCampaign::where('company_id', $companyId)->find($id) : null;
    }

    private function filtros(Request $request): array
    {
        return [
            'solo_vigentes' => filter_var($request->query('solo_vigentes', 'true'), FILTER_VALIDATE_BOOLEAN),
            'servicio'      => (string) $request->query('servicio', 'todos'),
            'deuda'         => (string) $request->query('deuda', 'todos'),
        ];
    }

    private function excluidos(Request $request): array
    {
        $crudo = $request->query('excluded', '');

        if (is_array($crudo)) {
            return array_map('intval', $crudo);
        }

        return array_values(array_filter(array_map('intval', explode(',', (string) $crudo))));
    }

    /** ¿Cambió lo que verían los clientes? */
    private function cambióElContenido(WaCampaign $campaign, array $nuevos): bool
    {
        return $campaign->template_name !== $nuevos['template_name']
            || $campaign->language !== $nuevos['language']
            || json_encode($campaign->params) !== json_encode($nuevos['params']);
    }

    private function sinEmpresa(): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => 'Sin empresa en sesión.'], 400);
    }

    private function noEncontrada(): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => 'No encontramos ese envío.'], 404);
    }
}
