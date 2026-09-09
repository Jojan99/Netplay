<?php

namespace App\UseCases\Crm;

use App\Events\NewMessageEvent;
use App\Repositories\ConversationRepository;
use App\Services\WhatsAppService;
use App\UseCases\Crm\Interfaces\SendConversationMediaUseCaseInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SendConversationMediaUseCase implements SendConversationMediaUseCaseInterface
{
    private WhatsAppService $whatsAppService;

    public function __construct(
        private ConversationRepository $conversationRepository
    ) {}

    public function execute(
        int $conversationId,
        string $type,
        UploadedFile $file,
        int $userId,
        ?string $caption = null
    ): array {
        $caption = trim((string)$caption) ?: null;

        $conversation = $this->conversationRepository->find($conversationId);
        if (!$conversation) {
            throw new \Exception('Conversación no encontrada');
        }

        // Misma empresa, dos mecanismos posibles (Meta API o Netplay WhatsApp): usar
        // siempre el provider real de ESTA conversación, nunca un valor global de la empresa.
        $this->whatsAppService = new WhatsAppService(
            $conversation->company_id,
            false,
            $conversation->provider ?? 'netplay'
        );

        $originalName = $file->getClientOriginalName();
        $extension    = strtolower($file->getClientOriginalExtension());
        $mimeType     = $file->getClientMimeType();

        Log::info('[ARCHIVO RECIBIDO]', [
            'name'      => $originalName,
            'extension' => $extension,
            'mime'      => $mimeType,
            'size'      => $file->getSize(),
        ]);

        $this->validateFileType($type, $extension, $mimeType);

        // ===============================
        // GUARDAR ORIGINAL (WEBM / ETC)
        // ===============================
        $path = $file->store('tmp/crm/audio', 'public');
$fullPath = storage_path('app/public/' . $path);
$publicUrl = asset('storage/' . $path);

Log::info('[ARCHIVO GUARDADO]', [
    'path'      => $path,
    'full_path' => $fullPath,
    'url'       => $publicUrl,
    'exists'    => file_exists($fullPath),
]);

try {

    if ($type === 'audio') {

    $storedFilename = pathinfo($path, PATHINFO_FILENAME);
    $storedExtension = pathinfo($path, PATHINFO_EXTENSION);

    $originalFullPath = storage_path('app/public/' . $path);

    Log::info('[AUDIO ORIGINAL]', [
        'original_path' => $originalFullPath,
        'exists' => file_exists($originalFullPath)
    ]);

    // Si ya es ogg no convertir
    if ($storedExtension === 'ogg') {

        if (!file_exists($originalFullPath)) {
            throw new \Exception('Archivo OGG original no existe');
        }

        $mediaUrl = asset('storage/' . $path);

        $sendResult = $this->whatsAppService->sendVoice(
            $conversation->phone,
            $mediaUrl
        );

        Log::info('[OGG ENVIADO SIN CONVERTIR]', [
            'url' => $mediaUrl,
            'exists_after_send' => file_exists($originalFullPath)
        ]);

        $finalMediaUrl = $mediaUrl;
        $finalMime = 'audio/ogg';
        $finalExt = 'ogg';
        $finalName = basename($path);

    } else {

        // 🔥 Convertir a OGG OPUS
        $convertedName = $storedFilename . '.ogg';
        $convertedRelativePath = 'tmp/crm/audio/' . $convertedName;
        $convertedFullPath = storage_path('app/public/' . $convertedRelativePath);

        $command = sprintf('/usr/bin/ffmpeg -y -i %s -c:a libopus -b:a 16k -vbr on -compression_level 10 -frame_duration 60 -application voip -ar 48000 -ac 1 -avoid_negative_ts make_zero -f ogg %s 2>&1',
    escapeshellarg($originalFullPath),
    escapeshellarg($convertedFullPath)
);

        exec($command, $output, $returnCode);

        Log::info('[FFMPEG OUTPUT]', [
            'command' => $command,
            'return_code' => $returnCode,
            'output' => $output
        ]);

        if ($returnCode !== 0) {
            throw new \Exception('Error en conversión FFMPEG');
        }

        if (!file_exists($convertedFullPath)) {
            throw new \Exception('El archivo convertido NO existe físicamente');
        }

        Log::info('[OGG CREADO CORRECTAMENTE]', [
            'converted_path' => $convertedFullPath,
            'exists' => file_exists($convertedFullPath),
            'size_bytes' => filesize($convertedFullPath)
        ]);

        $mediaUrl = asset('storage/' . $convertedRelativePath);

         Log::info('[>>>>>>>>>]', [
            'mediaUrl' => $mediaUrl,
        ]);

        $sendResult = $this->whatsAppService->sendVoice(
            $conversation->phone,
            $mediaUrl
        );

        // 🔥 CONFIRMAR QUE SIGUE EXISTIENDO DESPUÉS DEL ENVÍO
        Log::info('[VERIFICACION POST ENVIO]', [
            'converted_path' => $convertedFullPath,
            'exists_after_send' => file_exists($convertedFullPath)
        ]);

        $finalMediaUrl = $mediaUrl;
        $finalMime = 'audio/ogg';
        $finalExt = 'ogg';
        $finalName = $convertedName;
    }
}else {

        $sendResult = match ($type) {
            'image' => $this->whatsAppService->sendImage(
                $conversation->phone,
                $publicUrl,
                $caption ?? ''
            ),
            'video' => $this->whatsAppService->sendVideo(
                $conversation->phone,
                $publicUrl,
                $caption ?? ''
            ),
            'document' => $this->whatsAppService->sendDocument(
                $conversation->phone,
                $publicUrl,
                $originalName
            ),
            default => throw new \Exception('Tipo no soportado'),
        };

        $finalMediaUrl = $publicUrl;
        $finalMime     = $mimeType;
        $finalExt      = $extension;
        $finalName     = $originalName;
            }

            // ===============================
            // GUARDAR MENSAJE BD
            // ===============================
            $message = $this->conversationRepository->storeMessage([
                'conversation_id' => $conversationId,
                'sender_type'     => 'agent',
                'sender_user_id'  => $userId,
                'message_type'    => $type,
                'content'         => in_array($type, ['image', 'video'], true) ? $caption : null,
                'media_url'       => $finalMediaUrl,
                'mime_type'       => $finalMime,
                'extension'       => $finalExt,
                'original_name'   => $finalName,
                'external_id'     => is_array($sendResult ?? null) ? ($sendResult['messageId'] ?? ($sendResult['messages'][0]['id'] ?? null)) : null,
                'status'          => 'sent',
            ]);

            $message->refresh();

            broadcast(
                new NewMessageEvent($message, $conversationId)
            )->toOthers();

            return $message->toArray();
        } catch (\Exception $e) {

            Log::error('[ERROR ENV�0�1O MEDIA]', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /* ===============================
       VALIDACIONES
       =============================== */

    /**
     * Extensiones que nunca se guardan.
     *
     * El archivo termina servido desde storage/ bajo nuestro propio dominio, así
     * que un .html o un .svg con script se ejecutarían con las cookies de
     * netplay.com.co. No se usa una lista blanca porque por WhatsApp llega de
     * todo (planos, comprobantes, audios raros) y cerrar a una lista corta
     * rompería envíos legítimos: se bloquea lo que es peligroso y pasa el resto.
     */
    private const EXTENSIONES_BLOQUEADAS = [
        'html', 'htm', 'xhtml', 'shtml', 'svg', 'xml', 'xsl',
        'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar', 'inc',
        'js', 'mjs', 'cjs', 'jsp', 'asp', 'aspx', 'cgi', 'pl', 'py', 'rb', 'sh', 'bash',
        'exe', 'dll', 'bat', 'cmd', 'com', 'scr', 'msi', 'vbs', 'ps1', 'jar', 'apk',
        'htaccess', 'htpasswd',
    ];

    /** Mimes que el navegador interpretaría como página, venga la extensión que venga. */
    private const MIMES_BLOQUEADOS = [
        'text/html', 'application/xhtml+xml', 'image/svg+xml',
        'application/x-httpd-php', 'text/javascript', 'application/javascript',
    ];

    private function validateFileType(string $type, string $extension, string $mimeType): void
    {
        $ext  = strtolower(trim($extension));
        $mime = strtolower(trim(explode(';', $mimeType)[0] ?? ''));

        if (in_array($ext, self::EXTENSIONES_BLOQUEADAS, true) || in_array($mime, self::MIMES_BLOQUEADOS, true)) {
            Log::warning('[ARCHIVO RECHAZADO] Tipo no permitido en el CRM', [
                'extension' => $ext,
                'mime'      => $mime,
                'type'      => $type,
            ]);

            throw new \InvalidArgumentException(
                'Ese tipo de archivo no se puede enviar por seguridad. Convertilo a PDF o imagen y volvé a intentar.'
            );
        }
    }
}
