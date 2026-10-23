<?php

namespace App\Services\Crm;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pasa a texto las notas de voz de los clientes.
 *
 * Kloe y cobranza leían un audio como «(envió un audio que no puedes escuchar)» y el cliente que
 * explicaba su falla hablando se quedaba sin respuesta útil; el asesor tenía que escucharlos
 * uno por uno. Se transcriben con la misma IA de la empresa (Gemini entiende audio) y el texto
 * queda en crm_messages.transcripcion: lo usan los asistentes y se ve bajo el audio en el chat.
 *
 * Nunca frena nada: si no se puede, el audio sigue siendo un audio. Tres intentos y no más.
 */
class TranscriptorDeAudios
{
    private const INTENTOS = 3;
    /** Un audio más largo que esto se corta: lo importante va al principio y la IA cobra por segundo. */
    private const SEGUNDOS_MAX = 180;

    public function __construct(private int $companyId) {}

    /** ¿La IA de la empresa entiende audio? (Gemini sí; Claude no). */
    public function disponible(): bool
    {
        return $this->clave() !== '' && str_contains($this->url(), 'generativelanguage.googleapis.com')
            && \App\Services\Cobranza\Ia::proveedor() === 'compatible';
    }

    /** Transcribe las notas de voz del cliente en el chat que todavía no lo están (desde ese mensaje). */
    public function pendientesDe(int $conversationId, int $desdeId = 0): void
    {
        if (!$this->disponible()) {
            return;
        }

        $ids = DB::table('crm_messages')->where('conversation_id', $conversationId)->where('id', '>', $desdeId)
            ->where('sender_type', 'customer')->where('message_type', 'audio')->whereNull('transcripcion')
            ->where('transcripcion_intentos', '<', self::INTENTOS)->orderBy('id')->limit(5)->pluck('id');

        foreach ($ids as $id) {
            $this->transcribir((int) $id);
        }
    }

    public function transcribir(int $mensajeId): ?string
    {
        $m = DB::table('crm_messages')->where('id', $mensajeId)->first(['id', 'media_url', 'transcripcion', 'transcripcion_intentos']);

        if (!$m || $m->transcripcion !== null || (int) $m->transcripcion_intentos >= self::INTENTOS || !$this->disponible()) {
            return $m->transcripcion ?? null;
        }

        DB::table('crm_messages')->where('id', $m->id)->increment('transcripcion_intentos');

        $origen = $this->archivo((string) $m->media_url);
        if (!$origen) {
            return null;
        }

        $mp3 = tempnam(sys_get_temp_dir(), 'voz') . '.mp3';

        try {
            // Mono, 16 kHz y 32 kbps: es lo que necesita una voz, y el envío pesa diez veces menos.
            exec(sprintf('ffmpeg -nostdin -loglevel error -y -t %d -i %s -ac 1 -ar 16000 -b:a 32k %s 2>&1',
                self::SEGUNDOS_MAX, escapeshellarg($origen), escapeshellarg($mp3)), $salida, $codigo);

            if ($codigo !== 0 || !is_file($mp3) || filesize($mp3) < 200) {
                Log::info('[Transcripción] No se pudo convertir el audio', ['mensaje' => $m->id, 'error' => implode(' ', $salida)]);

                return null;
            }

            $texto = $this->pedir(base64_encode((string) file_get_contents($mp3)));
        } finally {
            @unlink($mp3);
            if (str_starts_with($origen, sys_get_temp_dir())) {
                @unlink($origen);
            }
        }

        if ($texto === null) {
            return null;
        }

        DB::table('crm_messages')->where('id', $m->id)->update(['transcripcion' => $texto, 'updated_at' => now()]);

        return $texto;
    }

    private function pedir(string $audio): ?string
    {
        $instruccion = 'Transcribe esta nota de voz de un cliente de un proveedor de internet en Colombia. '
            . 'Escribe exactamente lo que dice, en español, con puntuación, sin resumir ni agregar nada. '
            . 'Si no se entiende nada, responde sólo: [inaudible]';

        foreach (array_filter(array_map('trim', explode(',', $this->modelos()))) as $modelo) {
            try {
                $r = Http::timeout(40)->withToken($this->clave())->acceptJson()->post($this->url(), [
                    'model' => $modelo,
                    'messages' => [['role' => 'user', 'content' => [
                        ['type' => 'text', 'text' => $instruccion],
                        ['type' => 'input_audio', 'input_audio' => ['data' => $audio, 'format' => 'mp3']],
                    ]]],
                    'max_tokens' => 2500,
                ]);
            } catch (\Throwable $e) {
                Log::info('[Transcripción] La IA no respondió', ['modelo' => $modelo, 'error' => $e->getMessage()]);
                continue;
            }

            // Cupo agotado u ocupado: se prueba con el siguiente modelo de la lista.
            if ($r->status() === 429 || $r->status() >= 500) {
                continue;
            }

            if (!$r->successful()) {
                Log::info('[Transcripción] La IA rechazó el audio', ['modelo' => $modelo, 'estado' => $r->status(), 'error' => mb_substr($r->body(), 0, 300)]);
                continue;
            }

            $texto = trim((string) data_get($r->json(), 'choices.0.message.content', ''));

            if ($texto !== '') {
                \App\Services\Cobranza\UsoIa::consulta($this->companyId);

                return mb_substr($texto, 0, 4000);
            }
        }

        return null;
    }

    /** El archivo en el disco (los audios de WhatsApp Web están en public/storage); si no, se descarga. */
    private function archivo(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        $ruta = parse_url($url, PHP_URL_PATH) ?: '';
        if (str_starts_with($ruta, '/storage/')) {
            $local = realpath(public_path(ltrim(rawurldecode($ruta), '/')));
            if ($local && is_file($local)) {
                return $local;
            }
        }

        try {
            $r = Http::timeout(20)->get($url);
            if (!$r->successful() || strlen($r->body()) < 200) {
                return null;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'vozorig');
            file_put_contents($tmp, $r->body());

            return $tmp;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function config(): \App\Models\CobranzaConfig
    {
        return \App\Models\CobranzaConfig::deEmpresa($this->companyId);
    }

    private function clave(): string
    {
        $cfg = $this->config();

        return $cfg->tieneClavePropia() ? (string) $cfg->ia_clave : (string) config('services.cobranza_ia.key');
    }

    private function modelos(): string
    {
        $cfg = $this->config();

        return (string) (($cfg->tieneClavePropia() && $cfg->ia_modelos) ? $cfg->ia_modelos : config('services.cobranza_ia.modelo'));
    }

    private function url(): string
    {
        return (string) config('services.cobranza_ia.url');
    }
}
