<?php

namespace App\Http\Controllers;

use App\Models\WaTemplateBinding;
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
                'template_name' => $row->template_name ?? null,
                'language'      => $row->language ?? 'es_CO',
                'enabled'       => (bool) ($row->enabled ?? false),
                'params'        => $row->params ?? [],
            ];
        }

        return response()->json([
            'ok'        => true,
            'bindings'  => $bindings,
            'variables' => collect(WaTemplateBinding::VARIABLES)
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
            ]
        );

        return response()->json(['ok' => true, 'binding' => $binding]);
    }
}
