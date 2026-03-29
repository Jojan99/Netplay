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
    public function __construct(
        private ConversationRepository $conversationRepository,
        private WhatsAppService $whatsAppService
    ) {}

    public function execute(
        int $conversationId,
        string $type,
        UploadedFile $file,
        int $userId
    ): array {

        $conversation = $this->conversationRepository->find($conversationId);
        if (!$conversation) {
            throw new \Exception('Conversaci��n no encontrada');
        }

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

        $this->whatsAppService->sendVoice(
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

        $this->whatsAppService->sendVoice(
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

        match ($type) {
            'image' => $this->whatsAppService->sendImage(
                $conversation->phone,
                $publicUrl
            ),
            'video' => $this->whatsAppService->sendVideo(
                $conversation->phone,
                $publicUrl
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
                'content'         => null,
                'media_url'       => $finalMediaUrl,
                'mime_type'       => $finalMime,
                'extension'       => $finalExt,
                'original_name'   => $finalName,
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

    private function validateFileType(string $type, string $extension, string $mimeType): void
    {
        if ($type === 'audio') {
            return; // dejamos pasar, WhatsApp manda lo que quiera
        }
    }
}
