<?php

namespace App\Http\Controllers\Crm;

use App\Constants\ApiResponseConstants;
use App\Http\Controllers\Controller;
use App\Http\Requests\Management\SendMessageRequest;
use App\Repositories\Interfaces\ConversationRepositoryInterface;
use App\UseCases\Crm\Interfaces\CloseConversationUseCaseInterface;
use App\UseCases\Crm\Interfaces\GetConversationMessagesUseCaseInterface;
use App\UseCases\Crm\Interfaces\GetCrmAgentsUseCaseInterface;
use App\UseCases\Crm\Interfaces\GetInboxConversationsUseCaseInterface;
use App\UseCases\Crm\Interfaces\ReceiveConversationMessageUseCaseInterface;
use App\UseCases\Crm\Interfaces\SendConversationMediaUseCaseInterface;
use App\UseCases\Crm\Interfaces\SendMessageUseCaseInterface;
use App\UseCases\Crm\Interfaces\TransferConversationUseCaseInterface;
use App\Events\NewMessageEvent;
use App\Events\InboxUpdatedEvent;
use App\Services\WhatsAppService;
use FFMpeg\FFMpeg;
use FFMpeg\Format\Audio\Vorbis;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\JsonResponse;
use Tymon\JWTAuth\Exceptions\JWTException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
class ConversationController extends Controller
{
public function inbox(
    Request $request,
    GetInboxConversationsUseCaseInterface $useCase
) {
    $filters = $request->only([
        'status',
        'search',
    ]);

    // 🔥 SIEMPRE usar el usuario autenticado REAL
    $filters['user_id'] = getSessionUserId();

    return response()->json([
        'ok'   => true,
        'data' => $useCase->execute($filters),
    ]);
}

/**
 * Convierte audio a formato OGG Opus para WhatsApp
 *
 * @param UploadedFile $inputFile
 * @return UploadedFile
 */
/**
 * Convierte audio a formato OGG Opus para WhatsApp
 */
private function convertToOgg(UploadedFile $inputFile): UploadedFile
{
    try {
        // Crear directorio temp si no existe
        if (!is_dir(storage_path('app/temp'))) {
            mkdir(storage_path('app/temp'), 0755, true);
        }

        $outputPath = storage_path('app/temp/voice_' . time() . '.ogg');

        // 🔥 Usar librería PHP-FFmpeg
        $ffmpeg = FFMpeg::create([
            'ffmpeg.binaries'  => '/usr/bin/ffmpeg',  // Ajusta la ruta si es necesario
            'ffprobe.binaries' => '/usr/bin/ffprobe',
            'timeout'          => 3600,
            'ffmpeg.threads'   => 12,
        ]);

        $audio = $ffmpeg->open($inputFile->getPathname());
        
        // Formato OGG con Vorbis (Opus puede no estar disponible)
        $format = new Vorbis();
        $format->setAudioChannels(1)
               ->setAudioKiloBitrate(24);

        $audio->save($format, $outputPath);

        Log::info('Audio convertido con FFMpeg', [
            'input_size' => filesize($inputFile->getPathname()),
            'output_size' => filesize($outputPath)
        ]);

        return new UploadedFile(
            $outputPath,
            'voice_' . time() . '.ogg',
            'audio/ogg',
            null,
            true
        );

    } catch (\Exception $e) {
        Log::error('Error en conversión de audio', [
            'error' => $e->getMessage()
        ]);
        
        // Fallback: devolver archivo original
        return $inputFile;
    }
}




public function getMessages(
    int $conversationId,
    GetConversationMessagesUseCaseInterface $useCase
): JsonResponse {
    try {
        $result = $useCase->execute($conversationId);

        return response()->json(
            $result,
            JsonResponse::HTTP_OK
        );

    } catch (JWTException $e) {
        return standardApiReponse(
            'Messages could not be retrieved: ' . $e->getMessage(),
            ApiResponseConstants::DATA_NULL,
            ApiResponseConstants::ERROR,
            JsonResponse::HTTP_INTERNAL_SERVER_ERROR
        );

    } catch (\Exception $e) {
        return standardApiReponse(
            'Messages could not be retrieved: ' . $e->getMessage(),
            ApiResponseConstants::DATA_NULL,
            ApiResponseConstants::ERROR,
            JsonResponse::HTTP_INTERNAL_SERVER_ERROR
        );
    }
}

  public function sendMedia(
        Request $request,
        int $conversationId,
        SendConversationMediaUseCaseInterface $useCase
    ): JsonResponse {
        $request->validate([
            'type' => 'required|in:image,video,audio,document',
            'file' => 'required|file',
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        
        // 🔥 Verificar que sea un archivo válido
        if (!$file instanceof UploadedFile) {
            return response()->json([
                'ok' => false,
                'error' => 'Archivo inválido'
            ], JsonResponse::HTTP_BAD_REQUEST);
        }
        
        // 🔥 Convertir audio a OGG Opus si es necesario
        if ($request->type === 'audio') {
            $file = $this->convertToOgg($file);
        }

        Log::info('[ARCHIVO covertido]', [
            'extension' => $file,
        ]);

        $message = $useCase->execute(
            $conversationId,
            $request->type,
            $file,
            getSessionUserId()
        );

        return response()->json([
            'ok'   => true,
            'data' => $message,
        ], JsonResponse::HTTP_OK);
    }





public function store(
    SendMessageRequest $request,
    int $conversationId,
    SendMessageUseCaseInterface $useCase
) {
    return response()->json(
        $useCase->execute(
            $conversationId,
            $request->message,
            auth()->id()
        )
    );
}

public function close(
    int $conversationId,
    CloseConversationUseCaseInterface $useCase
) {
    $useCase->execute($conversationId);

    return response()->json([
        'status' => 'ok'
    ]);
}


public function receiveMessage(
    ReceiveConversationMessageUseCaseInterface $useCase
): object {
    try {
        $payload = json_decode(
            file_get_contents('php://input'),
            true
        );

        $result = $useCase->execute($payload);

    } catch (\Throwable $e) {
        return standardApiReponse(
            'Webhook error: ' . $e->getMessage(),
            ApiResponseConstants::DATA_NULL,
            ApiResponseConstants::ERROR,
            JsonResponse::HTTP_INTERNAL_SERVER_ERROR
        );
    }

    return standardApiReponse(
        'Message received',
        $result,
        ApiResponseConstants::SUCCESS,
        JsonResponse::HTTP_OK
    );
}

public function index(
    GetCrmAgentsUseCaseInterface $useCase
) {
    return response()->json([
        'ok'   => true,
        'data' => $useCase->execute()
    ]);
}

public function transfer(
    int $conversationId,
    Request $request,
    TransferConversationUseCaseInterface $useCase
) {
    return response()->json([
        'ok' => true,
        'data' => $useCase->execute(
            $conversationId,
            (int) $request->to_user_id,
            $request->reason
        )
    ]);
}

public function agents(
    GetCrmAgentsUseCaseInterface $useCase
) {
    return response()->json([
        'ok' => true,
        'data' => $useCase->execute()
    ]);
}

/* =====================================================================
 * NOTAS INTERNAS
 * =================================================================== */
public function getNotes(int $conversationId, ConversationRepositoryInterface $repo): JsonResponse
{
    return response()->json(['ok' => true, 'data' => $repo->getNotes($conversationId)]);
}

public function addNote(int $conversationId, Request $request, ConversationRepositoryInterface $repo): JsonResponse
{
    $request->validate(['content' => 'required|string|max:2000']);

    $userId = getSessionUserId();
    $note   = $repo->addNote($conversationId, $userId, $request->content);

    return response()->json(['ok' => true, 'data' => $note], 201);
}

public function deleteNote(int $noteId, ConversationRepositoryInterface $repo): JsonResponse
{
    $repo->deleteNote($noteId);
    return response()->json(['ok' => true]);
}

/* =====================================================================
 * ETIQUETAS
 * =================================================================== */
public function getLabels(ConversationRepositoryInterface $repo): JsonResponse
{
    $companyId = getSessionCompanyId();
    return response()->json(['ok' => true, 'data' => $repo->getLabels($companyId)]);
}

public function createLabel(Request $request, ConversationRepositoryInterface $repo): JsonResponse
{
    $request->validate([
        'name'  => 'required|string|max:80',
        'color' => 'required|string|max:20',
    ]);

    $companyId = getSessionCompanyId();
    $label     = $repo->createLabel($companyId, $request->name, $request->color);

    return response()->json(['ok' => true, 'data' => $label], 201);
}

public function deleteLabel(int $labelId, ConversationRepositoryInterface $repo): JsonResponse
{
    $repo->deleteLabel($labelId);
    return response()->json(['ok' => true]);
}

public function getConversationLabels(int $conversationId, ConversationRepositoryInterface $repo): JsonResponse
{
    return response()->json(['ok' => true, 'data' => $repo->getConversationLabels($conversationId)]);
}

public function addConversationLabel(int $conversationId, Request $request, ConversationRepositoryInterface $repo): JsonResponse
{
    $request->validate(['label_id' => 'required|integer']);
    $repo->addConversationLabel($conversationId, (int)$request->label_id);
    return response()->json(['ok' => true]);
}

public function removeConversationLabel(int $conversationId, int $labelId, ConversationRepositoryInterface $repo): JsonResponse
{
    $repo->removeConversationLabel($conversationId, $labelId);
    return response()->json(['ok' => true]);
}

/* =====================================================================
 * PRIORIDAD
 * =================================================================== */
public function updatePriority(int $conversationId, Request $request, ConversationRepositoryInterface $repo): JsonResponse
{
    $request->validate(['priority' => 'required|in:low,normal,high']);
    $repo->updatePriority($conversationId, $request->priority);
    return response()->json(['ok' => true]);
}

/* =====================================================================
 * DASHBOARD MÉTRICAS
 * =================================================================== */
public function dashboard(ConversationRepositoryInterface $repo): JsonResponse
{
    $companyId = getSessionCompanyId();
    return response()->json(['ok' => true, 'data' => $repo->getDashboardMetrics($companyId)]);
}

/* =====================================================================
 * BROADCAST
 * =================================================================== */
public function broadcastCustomers(ConversationRepositoryInterface $repo): JsonResponse
{
    $companyId = getSessionCompanyId();
    return response()->json(['ok' => true, 'data' => $repo->getCustomersForBroadcast($companyId)]);
}

public function sendBroadcast(Request $request, ConversationRepositoryInterface $repo): JsonResponse
{
    $request->validate([
        'message'      => 'required|string',
        'customer_ids' => 'required|array|min:1',
        'customer_ids.*' => 'integer',
    ]);

    $companyId = getSessionCompanyId();
    $userId    = getSessionUserId();
    $message   = $request->message;
    $ids       = $request->customer_ids;

    // Obtener teléfonos
    $customers = DB::table('crm_customers')
        ->whereIn('id', $ids)
        ->where('company_id', $companyId)
        ->select(['id', 'phone', 'name'])
        ->get();

    $sent   = 0;
    $failed = 0;
    $wa     = new WhatsAppService();

    foreach ($customers as $customer) {
        try {
            $wa->mensajeInformativo($customer->phone, $message);
            $sent++;
        } catch (\Throwable $e) {
            Log::warning('[Broadcast] Fallo envío', ['phone' => $customer->phone, 'err' => $e->getMessage()]);
            $failed++;
        }
    }

    // Log broadcast
    DB::table('crm_broadcasts')->insert([
        'company_id'       => $companyId,
        'user_id'          => $userId,
        'message'          => $message,
        'recipients_count' => count($ids),
        'sent_count'       => $sent,
        'failed_count'     => $failed,
        'status'           => 'done',
        'created_at'       => now(),
        'updated_at'       => now(),
    ]);

    return response()->json(['ok' => true, 'sent' => $sent, 'failed' => $failed]);
}

/* =====================================================================
 * NUEVA CONVERSACIÓN
 * =================================================================== */
public function createConversation(Request $request, ConversationRepositoryInterface $repo): JsonResponse
{
    $request->validate([
        'phone' => 'required|string',
        'name'  => 'nullable|string|max:120',
    ]);

    $companyId      = getSessionCompanyId();
    $conversationId = $repo->createConversationFromPhone(
        $request->phone,
        $request->name ?? '',
        $companyId
    );

    return response()->json(['ok' => true, 'conversation_id' => $conversationId], 201);
}

/* =====================================================================
 * ESTADO DE SERVICIO
 * =================================================================== */
public function serviceStatus(int $conversationId, ConversationRepositoryInterface $repo): JsonResponse
{
    $phone = $repo->getPhoneByConversationId($conversationId);

    if (!$phone) {
        return response()->json(['ok' => false, 'data' => null], 404);
    }

    $status = $repo->getServiceStatusByPhone($phone);

    return response()->json(['ok' => true, 'data' => $status]);
}

/* =====================================================================
 * REENVIAR MENSAJE
 * =================================================================== */
public function forwardMessage(Request $request, ConversationRepositoryInterface $repo): JsonResponse
{
    $request->validate([
        'source_message_id'    => 'required|integer',
        'target_conversation_ids' => 'required|array|min:1',
    ]);

    $agentId = getSessionUserId();
    $source  = DB::table('crm_messages')->where('id', $request->source_message_id)->first();

    if (!$source) {
        return response()->json(['ok' => false, 'message' => 'Mensaje no encontrado'], 404);
    }

    $wa = new WhatsAppService();

    foreach ($request->target_conversation_ids as $targetConvId) {
        $phone = $repo->getPhoneByConversationId((int)$targetConvId);
        if (!$phone) continue;

        // Reenviar según tipo
        if ($source->message_type === 'text') {
            $wa->mensajeInformativo($phone, "↪ " . $source->content);
        }

        // Guardar mensaje reenviado
        $msg = $repo->storeMessage([
            'conversation_id'   => (int)$targetConvId,
            'sender_type'       => 'agent',
            'message_type'      => $source->message_type,
            'content'           => $source->content,
            'media_url'         => $source->media_url ?? null,
            'is_forwarded'      => true,
            'forwarded_from_id' => $source->id,
        ]);

        broadcast(new NewMessageEvent($msg, (int)$targetConvId));
        broadcast(new InboxUpdatedEvent((int)$targetConvId, 'in_progress'));
    }

    return response()->json(['ok' => true]);
}

/* =====================================================================
 * STICKERS
 * =================================================================== */
public function getStickers(ConversationRepositoryInterface $repo): JsonResponse
{
    $companyId = getSessionCompanyId();
    return response()->json(['ok' => true, 'data' => $repo->getStickers($companyId)]);
}

public function saveSticker(Request $request, ConversationRepositoryInterface $repo): JsonResponse
{
    $request->validate(['media_url' => 'required|string', 'name' => 'nullable|string|max:100']);

    $companyId = getSessionCompanyId();
    $sticker   = $repo->saveSticker($companyId, $request->media_url, $request->name);

    return response()->json(['ok' => true, 'data' => $sticker], 201);
}

public function deleteSticker(int $stickerId, ConversationRepositoryInterface $repo): JsonResponse
{
    $repo->deleteSticker($stickerId);
    return response()->json(['ok' => true]);
}

/* =====================================================================
 * TICKET META: tipo servicio, técnicos (para el formulario del CRM)
 * =================================================================== */
public function ticketMeta(): JsonResponse
{
    $companyId = getSessionCompanyId();

    $services = DB::table('ticket_type_services')->where('active', 1)->get(['id', 'name']);
    $priorities = DB::table('ticket_type_prioritys')->where('active', 1)->get(['id', 'name']);
    $technicians = DB::table('user_data as ud')
        ->join('users', 'users.id', '=', 'ud.user_id')
        ->join('type_role as tr', 'tr.id', '=', 'ud.role_id')
        ->where('tr.id', 1)
        ->where('users.company_id', $companyId)
        ->select('ud.user_id as id', 'ud.names', 'ud.lastname')
        ->get();

    return response()->json([
        'ok'   => true,
        'data' => compact('services', 'priorities', 'technicians'),
    ]);
}

/* =====================================================================
 * ACTUALIZAR NOMBRE CLIENTE
 * =================================================================== */
public function updateCustomerName(int $conversationId, Request $request): JsonResponse
{
    $request->validate(['name' => 'required|string|max:150']);

    $customerId = DB::table('crm_conversations')
        ->where('id', $conversationId)
        ->value('customer_id');

    if (!$customerId) {
        return response()->json(['ok' => false, 'message' => 'Conversación no encontrada'], 404);
    }

    DB::table('crm_customers')->where('id', $customerId)->update(['name' => $request->name]);

    return response()->json(['ok' => true]);
}

/* =====================================================================
 * CREAR TICKET DESDE CONVERSACIÓN
 * =================================================================== */
public function createTicketFromConversation(int $conversationId, Request $request): JsonResponse
{
    $request->validate([
        'observation'  => 'required|string',
        'type_service' => 'required|integer',
        'priority'     => 'required|integer',
        'tecnichal'    => 'required|integer',
        'address'      => 'nullable|string',
        'cedula'       => 'nullable|string',
        'phone'        => 'nullable|string',
    ]);

    // Obtener datos del cliente desde la conversación
    $customer = DB::table('crm_conversations as c')
        ->join('crm_customers as cu', 'cu.id', '=', 'c.customer_id')
        ->where('c.id', $conversationId)
        ->select('cu.phone', 'cu.name')
        ->first();

    $rawPhone = $customer->phone ?? '';
    $cleanPhone = preg_replace('/[^0-9]/', '', $rawPhone);

    // Buscar usuario por teléfono en user_data
    $userData = null;
    if ($cleanPhone) {
        $userData = DB::table('user_data')
            ->where('phone', 'like', '%' . substr($cleanPhone, -10))
            ->select('user_id', 'address', 'cedula', 'phone', 'names', 'lastname')
            ->first();
    }

    $userId    = $userData?->user_id ?? null;
    $address   = $request->address   ?? $userData?->address ?? 'Sin dirección';
    $cedula    = $request->cedula    ?? $userData?->cedula  ?? '0';
    $phone     = $request->phone     ?? $rawPhone;
    $techName  = DB::table('user_data')->where('user_id', $request->tecnichal)->value('names') ?? '';
    $clientName = ($userData ? "{$userData->names} {$userData->lastname}" : $customer->name ?? 'Cliente CRM');

    $ticketId = DB::table('tickets')->insertGetId([
        'company_id'      => getSessionCompanyId(),
        'user_id'         => $userId,
        'address'         => $address,
        'date'            => now()->toDateString(),
        'service_id'      => $request->type_service,
        'priority_id'     => $request->priority,
        'status_id'       => 1,
        'technical_id'    => $request->tecnichal,
        'observation'     => $request->observation,
        'cedula'          => $cedula,
        'phone'           => $phone,
        'user_created_id' => getSessionUserId(),
        'reopened_count'  => 0,
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    // Notificación WhatsApp (no bloqueante)
    try {
        $message =
            "🆕 *NUEVO TICKET CRM*\n\n" .
            "🆔 *ID:* {$ticketId}\n" .
            "👤 *Cliente:* {$clientName}\n" .
            "📞 *Teléfono:* {$phone}\n" .
            "📍 *Dirección:* {$address}\n" .
            "📝 *Observación:* {$request->observation}";

        \App\Services\NotificationRouterService::dispatch(getSessionCompanyId(), 'ticket_support', $message);
    } catch (\Throwable) {}

    return response()->json(['ok' => true, 'ticket_id' => $ticketId], 201);
}

}
