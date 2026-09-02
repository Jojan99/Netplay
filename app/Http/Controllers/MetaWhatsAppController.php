<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\JsonResponse;

class MetaWhatsAppController extends Controller
{
    private function getMetaConfig(int $companyId): ?array
    {
        $company = Company::find($companyId);
        if (!$company || $company->wa_provider !== 'meta') {
            return null;
        }
        return [
            'phone_number_id' => $company->wa_phone_number_id,
            'business_id'     => $company->wa_business_id,
            'access_token'    => $company->wa_access_token,
            'api_version'     => config('services.meta_whatsapp.api_version', 'v18.0'),
        ];
    }

    /**
     * GET /api/company/whatsapp/meta-info
     * Información del número de teléfono en Meta.
     */
    public function getPhoneInfo(): JsonResponse
    {
        $companyId = getSessionCompanyId();
        $config = $this->getMetaConfig($companyId);

        if (!$config) {
            return response()->json(['ok' => false, 'error' => 'Meta no configurado'], 400);
        }

        try {
            $url = "https://graph.facebook.com/{$config['api_version']}/{$config['phone_number_id']}";
            $response = Http::withToken($config['access_token'])
                ->get($url, ['fields' => 'id,display_phone_number,verified_name,quality_rating,account_mode,code_verification_status,certificate,status']);

            if ($response->failed()) {
                return response()->json([
                    'ok'    => false,
                    'error' => $response->json('error.message') ?? 'Error de Meta',
                ], 400);
            }

            return response()->json(['ok' => true, 'data' => $response->json()]);
        } catch (\Throwable $e) {
            Log::error('[MetaWhatsAppController] Error phone info', ['error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/company/whatsapp/templates
     * Listar plantillas de Meta.
     */
    public function getTemplates(): JsonResponse
    {
        $companyId = getSessionCompanyId();
        $config = $this->getMetaConfig($companyId);

        if (!$config) {
            return response()->json(['ok' => false, 'error' => 'Meta no configurado'], 400);
        }

        try {
            $wabaId = $this->getWabaId($config);
            if (!$wabaId) {
                return response()->json(['ok' => false, 'error' => 'No se pudo obtener WABA ID'], 400);
            }

            $url = "https://graph.facebook.com/{$config['api_version']}/{$wabaId}/message_templates";
            $response = Http::withToken($config['access_token'])
                ->get($url, ['limit' => 100]);

            if ($response->failed()) {
                return response()->json([
                    'ok'    => false,
                    'error' => $response->json('error.message') ?? 'Error de Meta',
                ], 400);
            }

            return response()->json(['ok' => true, 'data' => $response->json('data') ?? []]);
        } catch (\Throwable $e) {
            Log::error('[MetaWhatsAppController] Error templates', ['error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/company/whatsapp/templates
     * Crear plantilla en Meta.
     */
    public function createTemplate(Request $request): JsonResponse
    {
        $companyId = getSessionCompanyId();
        $config = $this->getMetaConfig($companyId);

        if (!$config) {
            return response()->json(['ok' => false, 'error' => 'Meta no configurado'], 400);
        }

        $request->validate([
            'name'       => 'required|string|max:512',
            'parameter_format' => 'nullable|in:POSITIONAL,NAMED',
            'category'   => 'required|in:UTILITY,MARKETING,AUTHENTICATION',
            'language'   => 'required|string|size:5',
            'components' => 'required|array',
        ]);

        try {
            $wabaId = $this->getWabaId($config);
            if (!$wabaId) {
                return response()->json(['ok' => false, 'error' => 'No se pudo obtener WABA ID'], 400);
            }

            $url = "https://graph.facebook.com/{$config['api_version']}/{$wabaId}/message_templates";
            $response = Http::withToken($config['access_token'])
                ->post($url, [
                    'name'       => $request->name,
                    'parameter_format' => $request->input('parameter_format', 'POSITIONAL'),
                    'category'   => $request->category,
                    'language'   => $request->language,
                    'components' => $request->components,
                ]);

            if ($response->failed()) {
                return response()->json([
                    'ok'    => false,
                    'error' => $response->json('error.message') ?? 'Error de Meta',
                ], 400);
            }

            return response()->json(['ok' => true, 'data' => $response->json()]);
        } catch (\Throwable $e) {
            Log::error('[MetaWhatsAppController] Error create template', ['error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /api/company/whatsapp/templates/{name}
     * Eliminar plantilla en Meta.
     */
    public function deleteTemplate(string $name): JsonResponse
    {
        $companyId = getSessionCompanyId();
        $config = $this->getMetaConfig($companyId);

        if (!$config) {
            return response()->json(['ok' => false, 'error' => 'Meta no configurado'], 400);
        }

        try {
            $wabaId = $this->getWabaId($config);
            if (!$wabaId) {
                return response()->json(['ok' => false, 'error' => 'No se pudo obtener WABA ID'], 400);
            }

            $url = "https://graph.facebook.com/{$config['api_version']}/{$wabaId}/message_templates";
            $response = Http::withToken($config['access_token'])
                ->delete($url, ['name' => $name]);

            if ($response->failed()) {
                return response()->json([
                    'ok'    => false,
                    'error' => $response->json('error.message') ?? 'Error de Meta',
                ], 400);
            }

            return response()->json(['ok' => true, 'data' => $response->json()]);
        } catch (\Throwable $e) {
            Log::error('[MetaWhatsAppController] Error delete template', ['error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/company/whatsapp/conversation-window/{phone}
     * Verificar si hay una ventana de conversación abierta (24h).
     * Consulta la base de datos local en lugar de la API de Meta.
     */
    public function checkConversationWindow(string $phone): JsonResponse
    {
        $companyId = getSessionCompanyId();
        $config = $this->getMetaConfig($companyId);

        if (!$config) {
            return response()->json(['ok' => false, 'error' => 'Meta no configurado'], 400);
        }

        try {
            $normalizedPhone = $this->normalizePhone($phone);
            
            // Buscar mensajes recibidos del cliente en los últimos 24 horas
            $lastMessage = \DB::table('wa_meta_logs')
                ->where('company_id', $companyId)
                ->where('phone', $normalizedPhone)
                ->where('direction', 'inbound')
                ->where('created_at', '>=', now()->subHours(24))
                ->orderByDesc('created_at')
                ->first();

            $hasWindow = false;
            $expiresAt = null;

            if ($lastMessage) {
                $hasWindow = true;
                $expiresAt = date('Y-m-d H:i:s', strtotime($lastMessage->created_at . ' +24 hours'));
            }

            return response()->json([
                'ok'         => true,
                'has_window' => $hasWindow,
                'expires_at' => $expiresAt,
                'last_message_at' => $lastMessage?->created_at,
            ]);
        } catch (\Throwable $e) {
            Log::error('[MetaWhatsAppController] Error conversation window', ['error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/company/whatsapp/send-test
     * Enviar mensaje de prueba por Meta.
     */
    public function sendTest(Request $request): JsonResponse
    {
        $companyId = getSessionCompanyId();
        $config = $this->getMetaConfig($companyId);

        if (!$config) {
            return response()->json(['ok' => false, 'error' => 'Meta no configurado'], 400);
        }

        $request->validate([
            'to'          => 'required|string',
            'type'        => 'required|in:text,template,image,document,audio,video',
            'message'     => 'nullable|string',
            'template_name'     => 'nullable|string',
            'template_language' => 'nullable|string',
            'media_url'   => 'nullable|string',
            'caption'     => 'nullable|string',
        ]);

        try {
            $phone = $this->normalizePhone($request->to);
            $type  = $request->type;
            $payload = [
                'messaging_product' => 'whatsapp',
                'recipient_type'    => 'individual',
                'to'                => $phone,
                'type'              => $type,
            ];

            switch ($type) {
                case 'text':
                    $payload['text'] = ['body' => $request->message ?? ''];
                    break;
                case 'template':
                    $payload['template'] = [
                        'name'     => $request->template_name,
                        'language' => ['code' => $request->template_language ?? 'es'],
                    ];
                    break;
                case 'image':
                    $payload['image'] = ['link' => $request->media_url];
                    if ($request->caption) $payload['image']['caption'] = $request->caption;
                    break;
                case 'document':
                    $payload['document'] = ['link' => $request->media_url];
                    if ($request->caption) $payload['document']['caption'] = $request->caption;
                    break;
                case 'audio':
                    $payload['audio'] = ['link' => $request->media_url];
                    break;
                case 'video':
                    $payload['video'] = ['link' => $request->media_url];
                    if ($request->caption) $payload['video']['caption'] = $request->caption;
                    break;
            }

            $url = "https://graph.facebook.com/{$config['api_version']}/{$config['phone_number_id']}/messages";
            $response = Http::withToken($config['access_token'])
                ->post($url, $payload);

            if ($response->failed()) {
                $errorMsg = $response->json('error.message') ?? $response->body();
                return response()->json([
                    'ok'    => false,
                    'error' => $errorMsg,
                ], 400);
            }

            // Log del mensaje
            \DB::table('wa_meta_logs')->insert([
                'company_id'   => $companyId,
                'phone'        => $phone,
                'type'         => $type,
                'direction'    => 'outbound',
                'status'       => 'sent',
                'meta_msg_id'  => $response->json('messages.0.id') ?? null,
                'payload'      => json_encode($payload),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            return response()->json(['ok' => true, 'data' => $response->json()]);
        } catch (\Throwable $e) {
            Log::error('[MetaWhatsAppController] Error send test', ['error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/company/whatsapp/meta-logs
     * Logs de mensajes enviados/recibidos por Meta.
     */
    public function getLogs(): JsonResponse
    {
        $companyId = getSessionCompanyId();
        $logs = \DB::table('wa_meta_logs')
            ->where('company_id', $companyId)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return response()->json(['ok' => true, 'data' => $logs]);
    }

    /**
     * POST /api/company/whatsapp/validate-phone
     * Validar que un número no esté configurado en ambas APIs.
     */
    public function validatePhone(Request $request): JsonResponse
    {
        $request->validate(['phone' => 'required|string']);
        $phone = $this->normalizePhone($request->phone);
        $companyId = getSessionCompanyId();

        // Verificar si hay otra empresa con este número en Netplay
        $netplayExists = Company::where('wa_provider', 'netplay')
            ->where('id', '!=', $companyId)
            ->whereRaw("wa_instance_id IS NOT NULL")
            ->exists();

        // Verificar si hay otra empresa con este número en Meta
        $metaExists = Company::where('wa_provider', 'meta')
            ->where('id', '!=', $companyId)
            ->where('wa_phone_number_id', 'like', "%{$phone}%")
            ->exists();

        return response()->json([
            'ok'             => true,
            'phone'          => $phone,
            'available'      => !$metaExists,
            'meta_exists'    => $metaExists,
            'netplay_exists' => $netplayExists,
        ]);
    }

    // ── HELPERS ──────────────────────────────────────────────────

    private function getWabaId(array $config): ?string
    {
        // Si ya está configurado en la DB, usarlo directamente
        if (!empty($config['business_id'])) {
            return $config['business_id'];
        }

        try {
            $url = "https://graph.facebook.com/{$config['api_version']}/{$config['phone_number_id']}";
            $response = Http::withToken($config['access_token'])
                ->get($url, ['fields' => 'id,whatsapp_business_account']);

            if ($response->successful()) {
                $data = $response->json();
                $wabaId = $data['whatsapp_business_account']['id'] ?? null;
                if ($wabaId) {
                    return $wabaId;
                }
                Log::warning('[MetaWhatsAppController] WABA ID not found in response', ['response' => $data]);
            } else {
                Log::error('[MetaWhatsAppController] Failed to get WABA ID', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('[MetaWhatsAppController] Exception getting WABA ID', ['error' => $e->getMessage()]);
        }

        return null;
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone);
    }
}
