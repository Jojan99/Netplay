<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\WaTemplateBinding;
use App\Services\WhatsApp\ReminderService;
use App\Services\WhatsApp\TemplateContext;
use App\Services\MetaWhatsAppService;
use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Automatizaciones: qué plantilla de Meta sale ante cada hecho del negocio.
 *
 * El panel decide el vínculo y el orden de las variables; el código que envía
 * solo obedece. Así, si mañana cambia el texto de una plantilla, no hay que
 * tocar PHP.
 */
class WaTemplateBindingController extends Controller
{
    public function index(): JsonResponse
    {
        $companyId = getSessionCompanyId();

        if (!$companyId) {
            return response()->json(['ok' => false, 'error' => 'Sin empresa en sesión.'], 400);
        }

        $saved = WaTemplateBinding::where('company_id', $companyId)->get()->keyBy('event');

        $bindings = [];
        foreach (WaTemplateBinding::EVENTS as $event => $meta) {
            $row = $saved->get($event);

            $bindings[] = [
                'event'         => $event,
                'label'         => $meta['label'],
                'description'   => $meta['description'],
                'suggested'     => $meta['suggested'],
                // Los avisos programados salen solos a una hora; los demás
                // reaccionan a algo que acaba de pasar. El panel los pinta
                // distinto porque se configuran distinto.
                'programado'    => (bool) ($meta['programado'] ?? false),
                'template_name' => $row->template_name ?? null,
                'language'      => $row->language ?? 'es_CO',
                'enabled'       => (bool) ($row->enabled ?? false),
                'params'        => $row->params ?? [],
                'config'        => $row ? $row->ajustes() : WaTemplateBinding::CONFIG_POR_DEFECTO,
            ];
        }

        return response()->json([
            'ok'          => true,
            'bindings'    => $bindings,
            'referencias' => collect(WaTemplateBinding::REFERENCIAS)
                ->map(fn (array $v, string $key) => $v + ['key' => $key])
                ->values(),
            'variables'   => collect(WaTemplateBinding::VARIABLES)
                ->map(fn (array $v, string $key) => $v + ['key' => $key])
                ->values(),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $companyId = getSessionCompanyId();

        if (!$companyId) {
            return response()->json(['ok' => false, 'error' => 'Sin empresa en sesión.'], 400);
        }

        $validator = Validator::make($request->all(), [
            'event'         => ['required', 'string', 'in:' . implode(',', array_keys(WaTemplateBinding::EVENTS))],
            'template_name' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/'],
            'language'      => ['nullable', 'string', 'max:10'],
            'enabled'       => ['required', 'boolean'],
            'params'        => ['array', 'max:20'],
            'params.*'      => ['string', 'in:' . implode(',', array_keys(WaTemplateBinding::VARIABLES))],
            'config'                => ['array'],
            'config.referencia'     => ['nullable', 'string', 'in:' . implode(',', array_keys(WaTemplateBinding::REFERENCIAS))],
            'config.dias_antes'     => ['nullable', 'integer', 'min:0', 'max:60'],
            'config.dias_plazo'     => ['nullable', 'integer', 'min:0', 'max:120'],
            'config.dia_corte'      => ['nullable', 'integer', 'min:1', 'max:28'],
        ], [
            'template_name.regex' => 'El nombre de la plantilla solo admite minúsculas, números y guiones bajos.',
        ]);

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'error' => $validator->errors()->first()], 422);
        }

        $data = $validator->validated();

        // Activarla sin plantilla dejaría el aviso en silencio sin que se note.
        if (($data['enabled'] ?? false) && empty($data['template_name'])) {
            return response()->json([
                'ok'    => false,
                'error' => 'Elige una plantilla antes de activar el aviso.',
            ], 422);
        }

        $binding = WaTemplateBinding::updateOrCreate(
            ['company_id' => $companyId, 'event' => $data['event']],
            [
                'template_name' => $data['template_name'] ?? null,
                'language'      => $data['language'] ?: 'es_CO',
                'enabled'       => $data['enabled'],
                'params'        => array_values($data['params'] ?? []),
                'config'        => $data['config'] ?? null,
            ]
        );

        return response()->json(['ok' => true, 'binding' => $binding]);
    }

    /**
     * A cuántos clientes les saldría hoy este aviso.
     *
     * Sirve para no activar una automatización a ciegas: si el número no
     * cuadra, los días están mal puestos y se ve antes de que salga.
     */
    public function preview(string $event, ReminderService $avisos): JsonResponse
    {
        $companyId = getSessionCompanyId();

        if (!$companyId) {
            return response()->json(['ok' => false, 'error' => 'Sin empresa en sesión.'], 400);
        }

        if (!in_array($event, ReminderService::EVENTOS, true)) {
            return response()->json(['ok' => false, 'error' => 'Ese aviso no es programado.'], 422);
        }

        $binding = WaTemplateBinding::where('company_id', $companyId)->where('event', $event)->first();

        if (!$binding) {
            return response()->json(['ok' => true, 'total' => 0, 'muestra' => []]);
        }

        $company = Company::findOrFail($companyId);
        $casos   = $avisos->preview($company, $binding, $event);

        return response()->json([
            'ok'      => true,
            'total'   => count($casos),
            'muestra' => collect($casos)->take(10)->map(fn (array $c) => [
                'nombre' => trim(($c['cliente']->names ?? '') . ' ' . ($c['cliente']->lastname ?? '')),
                'phone'  => $c['cliente']->phone,
            ] + $c['extra'])->values(),
        ]);
    }

    /**
     * Manda la plantilla de un aviso a un número de prueba, con datos de
     * ejemplo, para ver cómo le llega al cliente antes de activarla.
     */
    public function test(Request $request, TemplateContext $contexto): JsonResponse
    {
        $companyId = getSessionCompanyId();

        if (!$companyId) {
            return response()->json(['ok' => false, 'error' => 'Sin empresa en sesión.'], 400);
        }

        $validator = Validator::make($request->all(), [
            'phone'         => ['required', 'string', 'regex:/^[0-9+\s-]{10,20}$/'],
            'template_name' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/'],
            'language'      => ['nullable', 'string', 'max:10'],
            'params'        => ['array', 'max:20'],
            'params.*'      => ['string', 'in:' . implode(',', array_keys(WaTemplateBinding::VARIABLES))],
        ], [
            'phone.regex' => 'Escribe un número de celular válido.',
        ]);

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'error' => $validator->errors()->first()], 422);
        }

        $data    = $validator->validated();
        $company = Company::findOrFail($companyId);

        try {
            $wa = new WhatsAppService($companyId, false, 'meta');
            $r  = $wa->sendTemplate(
                (string) $data['phone'],
                $data['template_name'],
                $contexto->toParameters($data['params'] ?? [], $contexto->sample($company)),
                $data['language'] ?: 'es_CO'
            );
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        $aceptado = MetaWhatsAppService::accepted($r);

        return response()->json([
            'ok'    => $aceptado,
            'error' => $aceptado ? null : ($r['error'] ?? 'Meta no aceptó el mensaje.'),
        ], $aceptado ? 200 : 422);
    }
}
