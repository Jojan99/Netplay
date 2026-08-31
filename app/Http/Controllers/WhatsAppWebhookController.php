<?php

namespace App\Http\Controllers;

use App\UseCases\Crm\Interfaces\ReceiveConversationMessageUseCaseInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class WhatsAppWebhookController extends Controller
{
    private const VERIFY_TOKEN = 'netplay_verify_token_2026';

    public function __construct(
        private ReceiveConversationMessageUseCaseInterface $useCase
    ) {}

    /**
     * GET /api/webhooks/whatsapp-meta
     *
     * Verificación del webhook por parte de Meta.
     * Meta envía: hub_mode=subscribe, hub_verify_token=XXX, hub_challenge=YYY
     */
    public function verify(Request $request): Response
    {
        $mode      = $request->query('hub_mode');
        $token     = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        Log::info('[Meta Webhook] Verificación recibida', [
            'hub_mode'          => $mode,
            'hub_verify_token'  => $token,
            'hub_challenge'     => $challenge,
        ]);

        if ($mode === 'subscribe' && $token === self::VERIFY_TOKEN) {
            Log::info('[Meta Webhook] Verificación exitosa');
            return response($challenge, 200);
        }

        Log::warning('[Meta Webhook] Verificación fallida', [
            'expected_mode'  => 'subscribe',
            'received_mode'  => $mode,
            'expected_token' => self::VERIFY_TOKEN,
            'received_token' => $token,
        ]);

        return response('Verificación fallida', 403);
    }

    /**
     * POST /api/webhooks/whatsapp-meta
     *
     * Recibe los mensajes y eventos de la API oficial de WhatsApp Business (Meta).
     * Transforma el payload de Meta al formato interno y lo procesa.
     */
    public function receive(Request $request): JsonResponse
    {
        $payload = $request->all();

        Log::info('[Meta Webhook] Payload recibido', $payload);

        // Validar estructura básica de Meta
        if (($payload['object'] ?? null) !== 'whatsapp_business_account') {
            Log::warning('[Meta Webhook] Objeto no reconocido', ['object' => $payload['object'] ?? null]);
            return response()->json(['status' => 'ignored', 'reason' => 'not_whatsapp_business_account']);
        }

        $results = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? null) !== 'messages') {
                    continue;
                }

                $value    = $change['value'] ?? [];
                $messages = $value['messages'] ?? [];
                $contacts = $value['contacts'] ?? [];

                // Mapear contactos por wa_id
                $contactMap = [];
                foreach ($contacts as $contact) {
                    $waId = $contact['wa_id'] ?? null;
                    if ($waId) {
                        $contactMap[$waId] = $contact['profile']['name'] ?? 'Cliente';
                    }
                }

                foreach ($messages as $message) {
                    try {
                        $internalPayload = $this->transformMetaToInternal($message, $contactMap);
                        $result = $this->useCase->execute($internalPayload);
                        $results[] = $result;
                    } catch (\Throwable $e) {
                        Log::error('[Meta Webhook] Error procesando mensaje', [
                            'message_id' => $message['id'] ?? null,
                            'error'      => $e->getMessage(),
                            'trace'      => $e->getTraceAsString(),
                        ]);
                        $results[] = ['status' => 'error', 'message_id' => $message['id'] ?? null, 'error' => $e->getMessage()];
                    }
                }

                // Procesar status updates (mensajes entregados/leídos) — opcional
                $statuses = $value['statuses'] ?? [];
                foreach ($statuses as $status) {
                    Log::info('[Meta Webhook] Status update', $status);
                    // Aquí puedes agregar lógica para actualizar el estado de mensajes enviados
                }
            }
        }

        // Meta espera siempre 200 OK, incluso si hubo errores internos
        return response()->json([
            'status'  => 'processed',
            'results' => $results,
        ]);
    }

    /**
     * Transforma un mensaje del formato de Meta al formato interno del sistema.
     */
    private function transformMetaToInternal(array $message, array $contactMap): array
    {
        $phone   = $message['from'] ?? null;
        $name    = $contactMap[$phone] ?? 'Cliente';
        $msgType = $message['type'] ?? 'text';

        // Mapear tipos de Meta a tipos internos
        $typeMap = [
            'text'     => 'text',
            'image'    => 'image',
            'video'    => 'video',
            'audio'    => 'audio',
            'voice'    => 'audio',
            'document' => 'document',
            'sticker'  => 'sticker',
            'location' => 'location',
            'contacts' => 'contact',
            'reaction' => 'reaction',
        ];

        $internalType = $typeMap[$msgType] ?? 'text';

        // Extraer contenido según el tipo
        $content  = null;
        $mediaUrl = null;

        switch ($msgType) {
            case 'text':
                $content = $message['text']['body'] ?? null;
                break;

            case 'image':
                $mediaUrl = $message['image']['link'] ?? null;
                $content  = $message['image']['caption'] ?? null;
                break;

            case 'video':
                $mediaUrl = $message['video']['link'] ?? null;
                $content  = $message['video']['caption'] ?? null;
                break;

            case 'audio':
            case 'voice':
                $mediaUrl = $message[$msgType]['link'] ?? null;
                break;

            case 'document':
                $mediaUrl = $message['document']['link'] ?? null;
                $content  = $message['document']['caption'] ?? $message['document']['filename'] ?? null;
                break;

            case 'sticker':
                $mediaUrl = $message['sticker']['link'] ?? null;
                break;

            case 'location':
                $lat = $message['location']['latitude'] ?? '?';
                $lng = $message['location']['longitude'] ?? '?';
                $content = "📍 Ubicación: lat {$lat}, lng {$lng}";
                break;

            case 'contacts':
                $contactName = $message['contacts'][0]['name']['formatted_name'] ?? 'Desconocido';
                $content = "👤 Contacto: {$contactName}";
                break;

            case 'reaction':
                $content = "👍 Reacción: " . ($message['reaction']['emoji'] ?? '');
                break;
        }

        // Fallback: si no hay contenido ni media, poner un placeholder
        if (!$content && !$mediaUrl) {
            $content = "[Mensaje {$msgType} recibido]";
        }

        return [
            'event'      => 'message.received',
            'instanceId' => 'meta_official_api',
            'data'       => [
                'type'      => $internalType,
                'phone'     => $phone,
                'from'      => $phone . '@s.whatsapp.net',
                'fromName'  => $name,
                'messageId' => $message['id'] ?? null,
                'content'   => $content,
                'body'      => $content, // alias para compatibilidad
                'url'       => $mediaUrl,
                'mediaUrl'  => $mediaUrl,
                'isGroup'   => false,
                'timestamp' => $message['timestamp'] ?? time(),
            ],
        ];
    }
}
