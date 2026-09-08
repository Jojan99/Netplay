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
use App\Support\OpusAudioFormat;
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
        'provider',
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
        
        // OGG/Opus mono: el único códec de nota de voz que acepta WhatsApp
        $format = new OpusAudioFormat();

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
            getSessionUserId(),
            $request->input('caption')
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
            (string) $request->message,
            auth()->id(),
            $request->input('quoted_message_id') ? (int) $request->input('quoted_message_id') : null,
            (string) $request->input('type', 'text'),
            (array) $request->only(['latitude', 'longitude', 'name', 'address', 'contact_name', 'contact_phone', 'target_message_id', 'options', 'selectable', 'question'])
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

public function toggleBotPause(int $conversationId, Request $request): JsonResponse
{
    $conversation = DB::table('crm_conversations as c')
        ->join('crm_customers as cu', 'cu.id', '=', 'c.customer_id')
        ->where('c.id', $conversationId)
        ->where('c.company_id', getSessionCompanyId())
        ->select('c.company_id', 'c.provider', 'cu.phone')
        ->first();
    if (!$conversation) return response()->json(['ok' => false, 'error' => 'Conversación no encontrada'], 404);
    $paused   = $request->boolean('paused');
    $provider = $conversation->provider ?: 'netplay';

    if ($paused) {
        DB::table('wa_bot_pauses')->updateOrInsert(
            ['company_id' => $conversation->company_id, 'provider' => $provider, 'phone' => $conversation->phone],
            ['paused_by' => getSessionUserId(), 'paused_at' => now(), 'updated_at' => now(), 'created_at' => now()]
        );
    } else {
        DB::table('wa_bot_pauses')->where(['company_id' => $conversation->company_id, 'provider' => $provider, 'phone' => $conversation->phone])->delete();
    }

    // El bot de WhatsApp Web corre en el servicio Node: avisarle para que deje de responder a este número
    if ($provider === 'netplay') {
        try {
            $r = (new \App\Services\NetplayWhatsAppService($conversation->company_id))->setBotPaused($conversation->phone, $paused);
            if (!is_array($r) || ($r['status'] ?? null) !== 'ok') {
                Log::warning('[Bot pause] el servicio Node no confirmó', ['phone' => $conversation->phone, 'r' => $r]);
            }
        } catch (\Throwable $e) {
            Log::warning('[Bot pause] error avisando al servicio Node', ['error' => $e->getMessage()]);
        }
    }

    return response()->json(['ok' => true, 'paused' => $paused]);
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
    $wa     = new WhatsAppService($companyId);

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
    $provider       = in_array($request->input('provider'), ['meta', 'netplay'], true) ? $request->input('provider') : 'netplay';
    $conversationId = $repo->createConversationFromPhone(
        $request->phone,
        $request->name ?? '',
        $companyId,
        $provider
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

    // ─── Datos del remitente original ───
    $sourceConv = DB::table('crm_conversations as c')
        ->join('crm_customers as cu', 'cu.id', '=', 'c.customer_id')
        ->where('c.id', $source->conversation_id)
        ->select('cu.name as customer_name', 'cu.phone as customer_phone')
        ->first();

    $originName  = $sourceConv->customer_name ?? 'Cliente';
    $originPhone = $sourceConv->customer_phone ?? 'Desconocido';
    $forwardPrefix = "📎 De: {$originName} ({$originPhone})\n\n";

    foreach ($request->target_conversation_ids as $targetConvId) {
        $target = DB::table('crm_conversations as c')
            ->join('crm_customers as cu', 'cu.id', '=', 'c.customer_id')
            ->where('c.id', (int)$targetConvId)
            ->select('cu.phone', 'c.company_id', 'c.provider')
            ->first();

        if (!$target || !$target->phone) continue;
        $phone = $target->phone;

        // Cada conversación puede pertenecer a un provider distinto (Meta API o Netplay
        // WhatsApp) dentro de la misma empresa; se reenvía siempre por su provider real.
        $wa = new WhatsAppService($target->company_id, false, $target->provider ?? 'netplay');

        // Reenviar según tipo
        if ($source->message_type === 'text') {
            $wa->mensajeInformativo($phone, $forwardPrefix . "↪ " . $source->content);
        } elseif ($source->message_type === 'image' && $source->media_url) {
            $wa->sendImage($phone, $source->media_url, $forwardPrefix . ($source->content ?? ''));
        } elseif ($source->message_type === 'document' && $source->media_url) {
            $filename = basename(parse_url($source->media_url, PHP_URL_PATH)) ?: 'documento';
            $wa->sendDocument($phone, $source->media_url, $filename, $forwardPrefix . ($source->content ?? ''));
        } elseif ($source->message_type === 'video' && $source->media_url) {
            $wa->sendVideo($phone, $source->media_url, $forwardPrefix . ($source->content ?? ''));
        } elseif ($source->message_type === 'audio' && $source->media_url) {
            $wa->sendAudio($phone, $source->media_url);
        } else {
            // Fallback para cualquier otro tipo sin media
            $wa->mensajeInformativo($phone, $forwardPrefix . ($source->content ?? '[Mensaje reenviado]'));
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
        broadcast(new InboxUpdatedEvent((int)$targetConvId, 'in_progress', 'agent', DB::table('crm_conversations')->where('id', $targetConvId)->value('provider')));
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
 * AUDIO PARA SAFARI/iPHONE: OGG/Opus no reproduce ahí; se transcodifica a M4A (AAC)
 * bajo demanda y se cachea. Sólo acepta archivos servidos por este mismo host.
 * =================================================================== */
public function transcodeAudio(Request $request)
{
    $url = (string) $request->query('u', '');
    $publicRoot = realpath(storage_path('app/public'));
    $prefix = rtrim(config('app.url'), '/') . '/storage/';

    if (!str_starts_with($url, $prefix) && !str_starts_with($url, 'https://netplay.com.co/storage/')) {
        return response()->json(['ok' => false, 'error' => 'URL no permitida'], 422);
    }

    $relative = urldecode(substr($url, strpos($url, '/storage/') + 9));
    $source   = realpath($publicRoot . '/' . $relative);
    // realpath resuelve el symlink wa-media -> /var/www/whatsapp-service/uploads
    $allowedRoots = array_filter([$publicRoot, realpath('/var/www/whatsapp-service/uploads')]);
    $inside = $source && collect($allowedRoots)->contains(fn($r) => str_starts_with($source, $r . '/'));
    if (!$inside || !is_file($source)) {
        return response()->json(['ok' => false, 'error' => 'Archivo no encontrado'], 404);
    }

    $outDir = storage_path('app/public/tmp/crm/m4a');
    if (!is_dir($outDir)) mkdir($outDir, 0775, true);
    $out = $outDir . '/' . md5($source . filemtime($source)) . '.m4a';

    if (!is_file($out)) {
        $cmd = sprintf('/usr/bin/ffmpeg -y -i %s -vn -c:a aac -b:a 64k -ar 44100 -ac 1 -movflags +faststart %s 2>&1', escapeshellarg($source), escapeshellarg($out));
        exec($cmd, $output, $code);
        if ($code !== 0 || !is_file($out)) {
            Log::warning('[CRM transcode m4a] falló', ['src' => $source, 'out' => array_slice($output, -3)]);
            return response()->json(['ok' => false, 'error' => 'No se pudo convertir el audio'], 500);
        }
    }

    return redirect()->away(asset('storage/tmp/crm/m4a/' . basename($out)));
}

/* =====================================================================
 * CONFIGURACIÓN DEL CRM (asignación automática, horario, alertas)
 * =================================================================== */
public function getSettings(): JsonResponse
{
    $s = \App\Support\CrmSettings::for(getSessionCompanyId());
    $s['welcome_message_default']   = \App\Support\CrmSettings::DEFAULT_WELCOME;
    $s['off_hours_message_default'] = \App\Support\CrmSettings::DEFAULT_OFF_HOURS;
    $s['open_now'] = \App\Support\CrmSettings::isOpenNow($s);
    return response()->json(['ok' => true, 'data' => $s]);
}

public function saveSettings(Request $request): JsonResponse
{
    $data = $request->validate([
        'auto_assign'        => 'boolean',
        'off_hours_enabled'  => 'boolean',
        'business_days'      => 'array',
        'business_days.*'    => 'integer|min:1|max:7',
        'open_time'          => ['regex:/^\d{2}:\d{2}$/'],
        'close_time'         => ['regex:/^\d{2}:\d{2}$/'],
        'off_hours_message'  => 'nullable|string|max:2000',
        'welcome_message'    => 'nullable|string|max:2000',
        'wait_alert_minutes' => 'integer|min:1|max:1440',
        'timezone'           => 'nullable|string|max:40',
    ]);
    if (isset($data['business_days'])) $data['business_days'] = json_encode(array_values(array_unique(array_map('intval', $data['business_days']))));
    $data['updated_at'] = now();
    DB::table('crm_settings')->updateOrInsert(['company_id' => getSessionCompanyId()], $data + ['created_at' => now()]);
    return $this->getSettings();
}

/* =====================================================================
 * RESPUESTAS RÁPIDAS ("/atajo" desde el chat)
 * =================================================================== */
public function getQuickReplies(ConversationRepositoryInterface $repo): JsonResponse
{
    return response()->json(['ok' => true, 'data' => $repo->getQuickReplies(getSessionCompanyId())]);
}

public function saveQuickReply(Request $request, ConversationRepositoryInterface $repo): JsonResponse
{
    $request->validate([
        'id'       => 'nullable|integer',
        'shortcut' => ['required', 'string', 'max:40', 'regex:/^\/?[a-z0-9_-]+$/i'],
        'title'    => 'nullable|string|max:100',
        'content'  => 'required|string|max:4000',
    ]);

    $companyId = getSessionCompanyId();
    $shortcut  = strtolower(ltrim($request->shortcut, '/'));

    $dup = DB::table('crm_quick_replies')
        ->where('company_id', $companyId)->where('shortcut', $shortcut)
        ->when($request->id, fn($q) => $q->where('id', '!=', $request->id))
        ->exists();
    if ($dup) {
        return response()->json(['ok' => false, 'error' => "Ya existe una respuesta con el atajo /$shortcut"], 422);
    }

    $reply = $repo->saveQuickReply($companyId, $request->id, $shortcut, $request->title, $request->content, getSessionUserId());
    return response()->json(['ok' => true, 'data' => $reply], $request->id ? 200 : 201);
}

public function deleteQuickReply(int $id, ConversationRepositoryInterface $repo): JsonResponse
{
    $repo->deleteQuickReply(getSessionCompanyId(), $id);
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
        ->whereIn('users.profile_id', function ($q) use ($companyId) {
            $q->select('id')->from('profiles')
                ->where('company_id', $companyId)
                ->where('name', 'TECNICO');
        })
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
        // user_data guarda la cédula en `dni`
        $userData = DB::table('user_data')
            ->where('company_id', getSessionCompanyId())
            ->where('phone', 'like', '%' . substr($cleanPhone, -10))
            ->orderByDesc('id')
            ->select('user_id', 'address', 'dni as cedula', 'phone', 'names', 'lastname')
            ->first();
    }

    $userId    = $userData?->user_id ?? null;
    $address   = ($request->address ?: null)   ?? $userData?->address ?? 'Sin dirección';
    $cedula    = ($request->cedula ?: null)    ?? $userData?->cedula  ?? '0';
    $phone     = ($request->phone ?: null)     ?? $rawPhone;
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
