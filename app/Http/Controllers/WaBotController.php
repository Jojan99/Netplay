<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\WaBotConfig;
use App\Services\WaBot\CanalSimulado;
use App\Services\WaBot\MotorDeFlujos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\JsonResponse;

class WaBotController extends Controller
{
    /**
     * GET /api/company/whatsapp/bot-config
     */
    public function getConfig(): JsonResponse
    {
        $companyId = getSessionCompanyId();
        $config = WaBotConfig::where('company_id', $companyId)->first();

        if (!$config) {
            return response()->json([
                'ok' => true,
                'data' => [
                    'enabled' => false,
                    'trigger_word' => 'hola',
                    'welcome_message' => null,
                    'menu_type' => 'text',
                    'menu_title' => '¿En qué puedo ayudarte?',
                    'options' => [
                        ['key' => '1', 'label' => 'Consultar factura', 'flow_id' => 'consultar_factura'],
                        ['key' => '2', 'label' => 'Consultar revisión', 'flow_id' => 'consultar_revision'],
                    ],
                    'flows' => [],
                    'variables' => [],
                    'settings' => [],
                ],
            ]);
        }

        return response()->json(['ok' => true, 'data' => $config]);
    }

    /**
     * PUT /api/company/whatsapp/bot-config
     */
    public function updateConfig(Request $request): JsonResponse
    {
        $companyId = getSessionCompanyId();

        $request->validate([
            'enabled' => 'boolean',
            'trigger_word' => 'nullable|string|max:50',
            'welcome_message' => 'nullable|string|max:1000',
            'menu_type' => 'nullable|in:text,buttons,list',
            'menu_title' => 'nullable|string|max:200',
            'options' => 'nullable|array',
            'options.*.key' => 'nullable|string|max:10',
            'options.*.label' => 'required|string|max:100',
            'options.*.flow' => 'nullable|string|max:100',
            'options.*.flow_id' => 'nullable|string|max:100',
            'flows' => 'nullable|array',
            'variables' => 'nullable|array',
            'settings' => 'nullable|array',
        ]);

        $config = WaBotConfig::firstOrNew(['company_id' => $companyId]);
        $config->enabled = $request->boolean('enabled', false);
        $config->trigger_word = $request->input('trigger_word', 'hola');
        $config->welcome_message = $request->input('welcome_message');
        $config->menu_type = $request->input('menu_type', $config->menu_type ?? 'text');
        $config->menu_title = $request->input('menu_title', $config->menu_title ?? '¿En qué puedo ayudarte?');
        $config->options = $request->input('options', $config->options ?? []);
        $config->flows = $request->input('flows', $config->flows ?? []);
        $config->variables = $request->input('variables', $config->variables ?? []);
        $config->settings = $request->input('settings', $config->settings ?? []);
        $config->save();

        return response()->json(['ok' => true, 'data' => $config]);
    }

    /**
     * POST /api/company/whatsapp/bot-config/probar
     *
     * Corre un flujo sin mandar nada por WhatsApp y devuelve lo que el cliente
     * habría recibido. Es lo que hace comprobable el constructor: antes, para
     * saber si un flujo servía había que publicarlo y escribirle desde un
     * teléfono de verdad.
     *
     * No guarda sesión: el estado (en qué bloque quedó y qué variables lleva)
     * va y vuelve en la petición, así probar no le toca nada a nadie.
     */
    public function probar(Request $request): JsonResponse
    {
        $request->validate([
            'flow_id' => 'required|string|max:100',
            'flows'   => 'required|array',
            'paso'    => 'nullable|string|max:100',
            'datos'   => 'nullable|array',
            'mensaje' => 'nullable|string|max:500',
        ]);

        $empresa = Company::find(getSessionCompanyId());

        if (!$empresa) {
            return response()->json(['ok' => false, 'error' => 'No se encontró la empresa de la sesión.'], 422);
        }

        $canal = new CanalSimulado();
        $motor = new MotorDeFlujos($empresa, $request->input('flows', []), $canal);
        $flujo = (string) $request->input('flow_id');

        if (!$motor->tiene($flujo)) {
            return response()->json([
                'ok'    => false,
                'error' => 'Ese flujo no tiene bloques o está desactivado.',
            ], 422);
        }

        $datos = (array) $request->input('datos', []);
        $datos['telefono'] ??= '573000000000';
        $paso = $request->input('paso');

        $parada = $paso
            ? $motor->seguir($flujo, $paso, $datos, (string) $request->input('mensaje', ''))
            : $motor->arrancar($flujo, $datos);

        return response()->json([
            'ok' => true,
            'data' => [
                'mensajes'   => $canal->mensajes(),
                'paso'       => $parada->bloque,
                // Puede haber saltado a otro flujo por el camino.
                'flujo'      => $parada->flujo ?: $flujo,
                'datos'      => $parada->datos,
                'terminado'  => $parada->termino,
                'transferir' => $parada->transferirA,
            ],
        ]);
    }

    /**
     * DELETE /api/company/whatsapp/bot-config
     */
    public function deleteConfig(): JsonResponse
    {
        $companyId = getSessionCompanyId();
        WaBotConfig::where('company_id', $companyId)->delete();

        return response()->json(['ok' => true]);
    }
}
