<?php

namespace App\Http\Controllers\Crm;

use App\Constants\ApiResponseConstants;
use App\Http\Controllers\Controller;
use App\Http\Requests\Management\SendMessageRequest;
use App\UseCases\Crm\Interfaces\CloseConversationUseCaseInterface;
use App\UseCases\Crm\Interfaces\GetConversationMessagesUseCaseInterface;
use App\UseCases\Crm\Interfaces\GetCrmAgentsUseCaseInterface;
use App\UseCases\Crm\Interfaces\GetInboxConversationsUseCaseInterface;
use App\UseCases\Crm\Interfaces\ReceiveConversationMessageUseCaseInterface;
use App\UseCases\Crm\Interfaces\SendConversationMediaUseCaseInterface;
use App\UseCases\Crm\Interfaces\SendMessageUseCaseInterface;
use App\UseCases\Crm\Interfaces\TransferConversationUseCaseInterface;
use FFMpeg\FFMpeg;
use FFMpeg\Format\Audio\Vorbis;
use Illuminate\Http\Request;
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


}
