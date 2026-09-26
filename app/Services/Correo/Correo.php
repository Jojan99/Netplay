<?php

namespace App\Services\Correo;

use App\Models\Company;
use Illuminate\Support\Facades\Log;
use Mailjet\Client;
use Mailjet\Resources;

/**
 * Único punto de salida de correos (Mailjet).
 *
 * - Correo::deEmpresa($empresa): la cuenta de Mailjet de esa empresa. Si no la
 *   tiene cargada, no envía: la cuenta de la plataforma no se presta para que
 *   un ISP le mande facturas a sus clientes desde el correo de Netvula.
 * - Correo::plataforma(): siempre la cuenta de la plataforma, a nombre de
 *   Netvula (confirmación de empresas, verificación, avisos de la plataforma).
 *
 * Nunca se registran llaves ni secretos en los logs.
 */
class Correo
{
    public const PROPIA = 'propia';
    public const PLATAFORMA = 'plataforma';

    /** La empresa todavía no cargó su cuenta de Mailjet: no puede enviar. */
    public const SIN_CUENTA = 'sin_cuenta';

    public const FALTA_CUENTA = 'La empresa todavía no tiene su cuenta de correo (Mailjet). '
        . 'Cargala en Configuración → Correo (Mailjet) para poder enviarle correos a los clientes.';

    private function __construct(
        private string $apiKey,
        private string $apiSecret,
        private string $desdeEmail,
        private string $desdeNombre,
        private ?string $responderA,
        private ?string $responderANombre,
        private string $origen,
        private ?int $empresaId = null,
    ) {}

    /** Cuenta y remitente con que salen los correos de una empresa a sus clientes. */
    public static function deEmpresa(Company|int $empresa): self
    {
        $company = $empresa instanceof Company ? $empresa : Company::find($empresa);
        if (!$company) {
            return self::plataforma();
        }

        $nombre = trim((string) $company->invoice_business_name) ?: trim((string) $company->name);
        $correoEmpresa = self::emailValido($company->email);

        if (self::tieneCuentaPropia($company)) {
            $desde = trim((string) $company->mailjet_from_email);
            return new self(
                trim((string) $company->mailjet_api_key),
                (string) self::secretoDe($company),
                $desde,
                trim((string) $company->mailjet_from_name) ?: ($nombre ?: $desde),
                $correoEmpresa && strcasecmp($correoEmpresa, $desde) !== 0 ? $correoEmpresa : null,
                $nombre ?: null,
                self::PROPIA,
                (int) $company->id,
            );
        }

        // Sin cuenta propia la empresa no envía. La cuenta de la plataforma es
        // sólo para los correos de Netvula (confirmar una empresa nueva, avisos
        // del producto), no para que cada ISP le mande facturas a sus clientes
        // desde el correo de Netvula.
        return new self('', '', '', $nombre, $correoEmpresa, $nombre ?: null, self::SIN_CUENTA, (int) $company->id);
    }

    /** Cuenta de la plataforma, a nombre de Netvula. */
    public static function plataforma(): self
    {
        return new self(
            trim((string) config('services.mailjet.api_key_public', '')),
            trim((string) config('services.mailjet.api_key_private', '')),
            self::remitentePlataforma(),
            trim((string) config('services.mailjet.from_name', '')) ?: 'Netvula',
            null,
            null,
            self::PLATAFORMA,
        );
    }

    /** Dirección no-reply de la plataforma. */
    public static function remitentePlataforma(): string
    {
        return trim((string) config('services.mailjet.from_email', '')) ?: 'no-reply@netvula.com';
    }

    /** La empresa tiene su cuenta de Mailjet activa y completa. */
    public static function tieneCuentaPropia(Company $company): bool
    {
        return (bool) $company->mailjet_activo
            && trim((string) $company->mailjet_api_key) !== ''
            && self::secretoDe($company) !== null
            && self::emailValido($company->mailjet_from_email) !== null;
    }

    public function origen(): string
    {
        return $this->origen;
    }

    public function configurado(): bool
    {
        return $this->apiKey !== '' && $this->apiSecret !== '' && $this->desdeEmail !== '';
    }

    /** Lo que verá el destinatario: From y Reply-To. */
    public function remitente(): array
    {
        return [
            'origen'       => $this->origen,
            'email'        => $this->desdeEmail,
            'nombre'       => $this->desdeNombre,
            'responder_a'  => $this->responderA,
        ];
    }

    /**
     * Envía un correo.
     *
     * @param string|array $para      correo, o ['email' => ..., 'nombre' => ...], o lista de esos
     * @param array        $adjuntos  formato Mailjet: ContentType, Filename, Base64Content.
     *                                Si trae ContentID va como imagen incrustada (cid:).
     * @param string|null  $responderA si se da, reemplaza el Reply-To por defecto
     * @return array{ok: bool, detalle: string, message_id: ?string}
     */
    public function enviar(
        string|array $para,
        string $asunto,
        string $html,
        ?string $texto = null,
        array $adjuntos = [],
        ?string $responderA = null,
    ): array {
        if (!$this->configurado()) {
            Log::warning('[Correo] servicio no configurado', ['origen' => $this->origen, 'company_id' => $this->empresaId]);

            return [
                'ok'      => false,
                'detalle' => $this->origen === self::SIN_CUENTA ? self::FALTA_CUENTA : 'Servicio de correo no configurado',
                'message_id' => null,
            ];
        }

        $destinos = $this->destinos($para);
        if (!$destinos) {
            return ['ok' => false, 'detalle' => 'Correo de destino inválido', 'message_id' => null];
        }

        $mensaje = [
            'From'     => ['Email' => $this->desdeEmail, 'Name' => $this->desdeNombre],
            'To'       => $destinos,
            'Subject'  => $asunto,
            'HTMLPart' => $html,
            'TextPart' => $texto ?? trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')),
        ];

        $replyTo = self::emailValido($responderA) ?? $this->responderA;
        if ($replyTo) {
            $mensaje['ReplyTo'] = array_filter(['Email' => $replyTo, 'Name' => $this->responderANombre]);
        }

        foreach ($adjuntos as $a) {
            if (!empty($a['ContentID'])) {
                $mensaje['InlinedAttachments'][] = $a;
            } else {
                $mensaje['Attachments'][] = $a;
            }
        }

        $correos = array_column($destinos, 'Email');
        try {
            $mj = new Client($this->apiKey, $this->apiSecret, true, ['version' => 'v3.1']);
            $mj->setConnectionTimeout(5);
            $mj->setTimeout(30);
            $respuesta = $mj->post(Resources::$Email, ['body' => ['Messages' => [$mensaje]]]);
            $data = $respuesta->getData();

            $m = $data['Messages'][0] ?? [];
            if (($m['Status'] ?? null) === 'success') {
                $id = $m['To'][0]['MessageID'] ?? null;
                Log::info('[Correo] enviado', ['origen' => $this->origen, 'company_id' => $this->empresaId, 'para' => $correos, 'message_id' => $id]);
                return ['ok' => true, 'detalle' => 'Correo enviado', 'message_id' => $id !== null ? (string) $id : null];
            }

            $detalle = $m['Errors'][0]['ErrorMessage']
                ?? $data['ErrorMessage']
                ?? self::errorHttp($respuesta->getStatus());
            Log::error('[Correo] Mailjet rechazó el envío', [
                'origen' => $this->origen, 'company_id' => $this->empresaId, 'para' => $correos,
                'http' => $respuesta->getStatus(), 'detalle' => $detalle,
            ]);
            return ['ok' => false, 'detalle' => $detalle, 'message_id' => null];
        } catch (\Throwable $e) {
            Log::error('[Correo] excepción enviando', [
                'origen' => $this->origen, 'company_id' => $this->empresaId, 'para' => $correos, 'error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'detalle' => 'No se pudo conectar con Mailjet', 'message_id' => null];
        }
    }

    /**
     * Revisa que las llaves funcionen y que el remitente (o su dominio) esté
     * validado en esa cuenta de Mailjet. No envía nada.
     *
     * @return array{ok: bool, llaves_validas: bool, remitente_activo: bool, detalle: string}
     */
    public static function probarCredenciales(string $apiKey, string $apiSecret, string $desdeEmail): array
    {
        $apiKey = trim($apiKey);
        $apiSecret = trim($apiSecret);
        $desdeEmail = strtolower(trim($desdeEmail));
        $r = fn (bool $ok, bool $llaves, bool $activo, string $detalle) =>
            ['ok' => $ok, 'llaves_validas' => $llaves, 'remitente_activo' => $activo, 'detalle' => $detalle];

        if ($apiKey === '' || $apiSecret === '') {
            return $r(false, false, false, 'Faltan la API Key o la Secret Key.');
        }
        if (!filter_var($desdeEmail, FILTER_VALIDATE_EMAIL)) {
            return $r(false, false, false, 'El correo remitente no es válido.');
        }

        try {
            $mj = new Client($apiKey, $apiSecret, true, ['version' => 'v3']);
            $mj->setConnectionTimeout(5);
            $mj->setTimeout(20);
            $respuesta = $mj->get(Resources::$Sender, ['filters' => ['Limit' => 1000]]);
        } catch (\Throwable $e) {
            Log::warning('[Correo] no se pudo consultar Mailjet al probar llaves', ['error' => $e->getMessage()]);
            return $r(false, false, false, 'No pudimos conectarnos con Mailjet. Intente de nuevo en unos minutos.');
        }

        $http = (int) $respuesta->getStatus();
        if ($http === 401 || $http === 403) {
            return $r(false, false, false, 'Mailjet rechazó las llaves: revise que la API Key y la Secret Key estén bien copiadas.');
        }
        if (!$respuesta->success()) {
            return $r(false, false, false, 'Mailjet respondió con un error (' . self::errorHttp($http) . ').');
        }

        $dominio = substr($desdeEmail, strpos($desdeEmail, '@') + 1);
        $estadoCorreo = null;
        $estadoDominio = null;
        foreach ($respuesta->getData() as $s) {
            $email = strtolower((string) ($s['Email'] ?? ''));
            $estado = (string) ($s['Status'] ?? '');
            if ($email === $desdeEmail) {
                $estadoCorreo = $estado;
            } elseif ($email === '*@' . $dominio) {
                $estadoDominio = $estado;
            }
        }

        if ($estadoCorreo === 'Active' || $estadoDominio === 'Active') {
            $como = $estadoCorreo === 'Active' ? "el remitente {$desdeEmail}" : "el dominio {$dominio}";
            return $r(true, true, true, "Llaves correctas y {$como} está validado en Mailjet.");
        }
        if ($estadoCorreo !== null || $estadoDominio !== null) {
            return $r(false, true, false, "Las llaves funcionan, pero {$desdeEmail} todavía no está validado en Mailjet. Abra el correo de confirmación que le mandó Mailjet o valide el dominio en Senders & Domains.");
        }
        return $r(false, true, false, "Las llaves funcionan, pero {$desdeEmail} no está agregado como remitente en Mailjet. Agregalo en Account settings → Senders & Domains y validalo.");
    }

    // ─── Internos ─────────────────────────────────────────────────────────────

    private function destinos(string|array $para): array
    {
        $lista = is_string($para) || isset($para['email']) ? [$para] : $para;
        $salida = [];
        foreach ($lista as $d) {
            $email = self::emailValido(is_array($d) ? ($d['email'] ?? null) : $d);
            if (!$email) {
                continue;
            }
            $nombre = is_array($d) ? trim((string) ($d['nombre'] ?? '')) : '';
            $salida[] = $nombre !== '' ? ['Email' => $email, 'Name' => $nombre] : ['Email' => $email];
        }
        return $salida;
    }

    private static function emailValido(mixed $email): ?string
    {
        $email = trim((string) $email);
        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /** El secreto se descifra al leerlo; si APP_KEY cambió no se puede usar. */
    private static function secretoDe(Company $company): ?string
    {
        try {
            $s = trim((string) $company->mailjet_api_secret);
            return $s !== '' ? $s : null;
        } catch (\Throwable $e) {
            Log::warning('[Correo] no se pudo descifrar el secreto de Mailjet', ['company_id' => $company->id]);
            return null;
        }
    }

    private static function errorHttp(?int $http): string
    {
        return match ((int) $http) {
            401, 403 => 'llaves de Mailjet inválidas',
            429      => 'Mailjet limitó los envíos por exceso de solicitudes',
            0        => 'sin respuesta de Mailjet',
            default  => 'HTTP ' . $http,
        };
    }
}
