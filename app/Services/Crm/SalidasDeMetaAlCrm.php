<?php

namespace App\Services\Crm;

use App\Models\Company;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Todo lo que sale por el número de Meta queda en la conversación del cliente en el CRM.
 *
 * Las plantillas (avisos de suspensión, recordatorios, facturas, campañas) y los mensajes que
 * mandan los servicios por su cuenta salían sin dejar rastro: el asesor abría el chat y no
 * sabía qué se le había dicho al cliente. Se anota desde MetaWhatsAppService::sendRequest,
 * que es por donde pasa todo envío.
 *
 * El bot y el asesor ya guardan lo suyo (antes de enviar). Para no duplicarlo: si en los
 * últimos dos minutos ya hay en esa conversación un mensaje nuestro con el mismo texto (o la
 * misma imagen), sólo se le pega el id de Meta, que es lo que permite seguir si se entregó.
 *
 * La conversación es la última del cliente, abierta o cerrada, y no se le cambia el estado:
 * una campaña de 200 mensajes no puede llenar la bandeja de chats «nuevos».
 */
class SalidasDeMetaAlCrm
{
    /** Mientras sea mayor que cero, quien envía ya guarda su mensaje en el CRM. */
    private static int $callados = 0;

    public function __construct(private int $companyId) {}

    /**
     * Para lo que guarda sus propios mensajes (el bot, el asesor, los asistentes): lo que se
     * envíe dentro de $hacer no se anota otra vez.
     */
    public static function sinAnotar(callable $hacer): mixed
    {
        self::$callados++;

        try {
            return $hacer();
        } finally {
            self::$callados--;
        }
    }

    public function anotar(array $payload, ?string $wamid): void
    {
        if (self::$callados > 0) {
            return;
        }

        try {
            $this->guardar($payload, $wamid);
        } catch (\Throwable $e) {
            // Anotarlo nunca puede tumbar el envío.
            Log::warning('[CRM] No se pudo anotar un envío de Meta', ['empresa' => $this->companyId, 'error' => $e->getMessage()]);
        }
    }

    private function guardar(array $payload, ?string $wamid): void
    {
        $tipo = (string) ($payload['type'] ?? '');

        // Una reacción o una marca de leído no es un mensaje que haya que mostrar.
        if (in_array($tipo, ['reaction', ''], true) || !empty($payload['status'])) {
            return;
        }

        $telefono = $this->telefono((string) ($payload['to'] ?? ''));
        if (!$telefono) {
            return;
        }

        [$contenido, $tipoCrm, $media] = $this->contenido($payload);
        if ($contenido === '' && !$media) {
            return;
        }

        $cliente = $this->contacto($telefono);
        $conversacion = DB::table('crm_conversations')->where('company_id', $this->companyId)->where('provider', 'meta')
            ->where('customer_id', $cliente)->orderByRaw("FIELD(status, 'in_progress', 'new') DESC")->orderByDesc('id')->first(['id', 'status']);

        // ¿Ya lo guardó quien lo mandó (el bot, el asesor)?
        if ($conversacion) {
            $ya = DB::table('crm_messages')->where('conversation_id', $conversacion->id)->where('sender_type', '<>', 'customer')
                ->where('created_at', '>=', now()->subMinutes(2))
                ->where(fn ($q) => $q->where('content', $contenido)->when($media, fn ($q) => $q->orWhere('media_url', $media)))
                ->orderByDesc('id')->first(['id', 'external_id']);

            if ($ya) {
                if (!$ya->external_id && $wamid) {
                    DB::table('crm_messages')->where('id', $ya->id)->update(['external_id' => $wamid]);
                }

                return;
            }
        }

        $conversationId = $conversacion->id ?? DB::table('crm_conversations')->insertGetId([
            'company_id' => $this->companyId, 'provider' => 'meta', 'customer_id' => $cliente,
            // Nadie escribió todavía: queda como historial, no como chat por atender.
            'status' => 'closed', 'priority' => 'normal', 'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('crm_messages')->insert([
            'conversation_id' => $conversationId,
            'sender_type'     => 'system',
            'agent_signature' => $tipo === 'template' ? 'Plantilla' : 'Envío automático',
            'message_type'    => $tipoCrm,
            'content'         => $contenido,
            'media_url'       => $media,
            'external_id'     => $wamid,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        DB::table('crm_conversations')->where('id', $conversationId)->update(['last_message_at' => now(), 'updated_at' => now()]);

        try {
            broadcast(new \App\Events\InboxUpdatedEvent($conversationId, (string) ($conversacion->status ?? 'closed'), 'system', 'meta'));
        } catch (\Throwable $e) {
            // Sin tiempo real, aparece al recargar.
        }
    }

    /** Como guarda el CRM los teléfonos de Meta: con el 57 adelante. */
    private function telefono(string $to): ?string
    {
        $d = preg_replace('/\D/', '', $to);

        if (strlen($d) === 10 && $d[0] === '3') {
            $d = '57' . $d;
        }

        return strlen($d) >= 10 ? $d : null;
    }

    private function contacto(string $telefono): int
    {
        $id = DB::table('crm_customers')->where('company_id', $this->companyId)->where('phone', $telefono)->value('id');

        if ($id) {
            return (int) $id;
        }

        // Si es un cliente, con su nombre y su ficha: así el chat se abre ya identificado.
        $ficha = DB::table('user_data')->where('company_id', $this->companyId)
            ->whereRaw("RIGHT(REGEXP_REPLACE(phone, '[^0-9]', ''), 10) = ?", [substr($telefono, -10)])
            ->orderByDesc('active')->first(['user_id', 'names', 'lastname', 'dni']);

        return (int) DB::table('crm_customers')->insertGetId([
            'company_id' => $this->companyId, 'phone' => $telefono, 'is_group' => 0,
            'user_id' => $ficha->user_id ?? null, 'dni' => $ficha->dni ?? null,
            'name' => $ficha ? trim($ficha->names . ' ' . $ficha->lastname) : 'Cliente WhatsApp',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{0:string, 1:string, 2:?string} texto, tipo de mensaje en el CRM, enlace del archivo */
    private function contenido(array $p): array
    {
        return match ($p['type'] ?? '') {
            'text'        => [(string) ($p['text']['body'] ?? ''), 'text', null],
            'template'    => [$this->textoDePlantilla($p['template'] ?? []), 'text', null],
            'interactive' => [$this->textoInteractivo($p['interactive'] ?? []), 'text', null],
            'image'       => [(string) ($p['image']['caption'] ?? ''), 'image', $p['image']['link'] ?? null],
            'document'    => [(string) ($p['document']['caption'] ?? ($p['document']['filename'] ?? '')), 'document', $p['document']['link'] ?? null],
            'audio'       => ['', 'audio', $p['audio']['link'] ?? null],
            'video'       => [(string) ($p['video']['caption'] ?? ''), 'video', $p['video']['link'] ?? null],
            'location'    => [trim(($p['location']['name'] ?? '') . ' ' . ($p['location']['address'] ?? '')) ?: '[Ubicación]', 'text', null],
            default       => ['[' . ($p['type'] ?? 'mensaje') . ']', 'text', null],
        };
    }

    private function textoInteractivo(array $i): string
    {
        $texto = trim((string) ($i['body']['text'] ?? ''));
        $opciones = [];

        foreach ($i['action']['buttons'] ?? [] as $b) {
            $opciones[] = $b['reply']['title'] ?? '';
        }
        foreach ($i['action']['sections'] ?? [] as $s) {
            foreach ($s['rows'] ?? [] as $r) {
                $opciones[] = $r['title'] ?? '';
            }
        }

        $opciones = array_filter($opciones);

        return $texto . ($opciones ? "\n\n" . implode("\n", array_map(fn ($o) => "▫️ {$o}", $opciones)) : '');
    }

    /** El texto que vio el cliente: el cuerpo de la plantilla con sus variables puestas. */
    private function textoDePlantilla(array $t): string
    {
        $nombre = (string) ($t['name'] ?? '');
        $idioma = (string) ($t['language']['code'] ?? 'es');
        $valores = [];
        $botones = [];

        foreach ($t['components'] ?? [] as $c) {
            if (($c['type'] ?? '') === 'body') {
                $valores = array_map(fn ($x) => (string) ($x['text'] ?? ''), $c['parameters'] ?? []);
            }
        }

        $plantilla = $this->plantilla($nombre, $idioma);

        if (!$plantilla) {
            return "[Plantilla «{$nombre}»]" . ($valores ? ' ' . implode(' · ', $valores) : '');
        }

        $cuerpo = preg_replace_callback('/\{\{(\d+)\}\}/', fn ($m) => $valores[(int) $m[1] - 1] ?? $m[0], $plantilla['cuerpo']);

        foreach ($plantilla['botones'] as $b) {
            $botones[] = "🔘 {$b}";
        }

        return trim(($plantilla['encabezado'] ? "*{$plantilla['encabezado']}*\n\n" : '') . $cuerpo
            . ($plantilla['pie'] ? "\n\n_{$plantilla['pie']}_" : '') . ($botones ? "\n\n" . implode("\n", $botones) : ''));
    }

    /** @return array{encabezado:?string, cuerpo:string, pie:?string, botones:list<string>}|null */
    private function plantilla(string $nombre, string $idioma): ?array
    {
        return Cache::remember("meta:plantilla:texto:{$this->companyId}:{$nombre}:{$idioma}", 43200, function () use ($nombre, $idioma) {
            $empresa = Company::find($this->companyId);

            if (!$empresa?->wa_business_id || !$empresa->wa_access_token) {
                return null;
            }

            $r = Http::withToken($empresa->wa_access_token)->timeout(10)
                ->get("https://graph.facebook.com/v21.0/{$empresa->wa_business_id}/message_templates", ['name' => $nombre, 'limit' => 20, 'fields' => 'name,language,components']);

            $t = collect($r->json('data') ?? [])->first(fn ($x) => ($x['name'] ?? '') === $nombre && ($x['language'] ?? '') === $idioma)
                ?? collect($r->json('data') ?? [])->first(fn ($x) => ($x['name'] ?? '') === $nombre);

            if (!$t) {
                return null;
            }

            $partes = ['encabezado' => null, 'cuerpo' => '', 'pie' => null, 'botones' => []];

            foreach ($t['components'] ?? [] as $c) {
                match ($c['type'] ?? '') {
                    'HEADER' => $partes['encabezado'] = ($c['format'] ?? '') === 'TEXT' ? ($c['text'] ?? null) : null,
                    'BODY'   => $partes['cuerpo'] = (string) ($c['text'] ?? ''),
                    'FOOTER' => $partes['pie'] = $c['text'] ?? null,
                    'BUTTONS' => $partes['botones'] = array_map(fn ($b) => (string) ($b['text'] ?? ''), $c['buttons'] ?? []),
                    default  => null,
                };
            }

            return $partes;
        });
    }
}
