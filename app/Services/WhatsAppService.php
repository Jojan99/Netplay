<?php

namespace App\Services;

use App\Models\Company;
use App\Models\UserData;

/**
 * WhatsAppService actúa como router/proxy según el provider configurado:
 * - 'netplay'  → Usa el servicio interno (WhatsApp Web con QR, 181.48.150.43:3001)
 * - 'meta'     → Usa la API oficial de Meta (WhatsApp Business API)
 * - null/otro  → Fallback al servicio interno (compatibilidad hacia atrás)
 */
class WhatsAppService
{
    private NetplayWhatsAppService|null $netplayService = null;
    private MetaWhatsAppService|null    $metaService    = null;
    private string                      $provider       = 'netplay';

    public function __construct(?int $companyId = null, bool $ignoreEnabledFlag = false, ?string $forceProvider = null)
    {
        $id = $companyId ?? getSessionCompanyId();
        $company = $id ? Company::find($id) : null;

        // Una misma empresa puede tener ambos mecanismos activos (Meta API y Netplay WhatsApp)
        // simultáneamente. $forceProvider permite indicar explícitamente cuál usar (p.ej. según
        // el "provider" de la conversación de CRM) en lugar de asumir un único valor por empresa.
        if ($forceProvider === 'meta' || $forceProvider === 'netplay') {
            $this->provider = $forceProvider;
        } elseif ($company && $company->wa_provider) {
            $this->provider = $company->wa_provider;
        } elseif (config('services.meta_whatsapp.enabled')) {
            $this->provider = 'meta';
        }

        // Instanciar el servicio correspondiente
        if ($this->provider === 'meta') {
            $this->metaService = new MetaWhatsAppService($companyId);
        } else {
            $this->netplayService = new NetplayWhatsAppService($companyId, $ignoreEnabledFlag);
        }
    }

    // ── TEXTO ────────────────────────────────────────
    public function mensajeInformativo(string $to, string $body): array
    {
        return $this->delegate(__FUNCTION__, func_get_args());
    }

    // ── DOCUMENTO / PDF ──────────────────────────────
    public function sendDocument(string $to, string $documentUrl, string $filename, string $caption = ''): array
    {
        return $this->delegate(__FUNCTION__, func_get_args());
    }

    // ── DOCUMENTO / PDF (por contenido base64) ────────────────────────
    public function sendDocumentData(string $to, string $base64Content, string $filename, string $caption = '', string $mimetype = 'application/pdf'): array
    {
        return $this->delegate(__FUNCTION__, func_get_args());
    }

    // ── IMAGEN ───────────────────────────────────────
    public function sendImage(string $to, string $mediaUrl, string $caption = ''): array
    {
        return $this->delegate(__FUNCTION__, func_get_args());
    }

    // ── VIDEO ────────────────────────────────────────
    public function sendVideo(string $to, string $mediaUrl, string $caption = ''): array
    {
        return $this->delegate(__FUNCTION__, func_get_args());
    }

    // ── AUDIO ────────────────────────────────────────
    public function sendAudio(string $to, string $mediaUrl): array
    {
        return $this->delegate(__FUNCTION__, func_get_args());
    }

    // ── NOTA DE VOZ ──────────────────────────────────
    public function sendVoice(string $to, string $mediaUrl): array
    {
        return $this->delegate(__FUNCTION__, func_get_args());
    }

    // ── ENVÍO MASIVO / BATCH ─────────────────────────
    public function sendBulk(array $messages): array
    {
        return $this->delegate(__FUNCTION__, func_get_args());
    }

    // ── BOTONES INTERACTIVOS ────────────────────────
    public function sendInteractiveButtons(string $to, string $bodyText, array $buttons, string $headerText = ''): array
    {
        return $this->delegate(__FUNCTION__, func_get_args());
    }

    public function sendInteractiveList(string $to, string $bodyText, array $sections, string $buttonText = 'Opciones'): array
    {
        return $this->delegate(__FUNCTION__, func_get_args());
    }

    /** Botón que abre una URL dentro de WhatsApp (solo Meta). */
    public function sendCtaUrl(
        string $to,
        string $bodyText,
        string $buttonText,
        string $url,
        string $headerText = '',
        string $footerText = ''
    ): array {
        return $this->delegate(__FUNCTION__, func_get_args());
    }

    // ── HELPER ESTÁTICO ──────────────────────────────
    /**
     * Verifica si un usuario tiene WhatsApp habilitado (campo user_data.whatsapp_enabled).
     */
    public static function isEnabledForUser(int $userId): bool
    {
        $ud = UserData::where('user_id', $userId)->first();
        return $ud ? (bool) $ud->whatsapp_enabled : true;
    }

    /**
     * Obtiene el provider activo para la empresa actual.
     */
    public function getProvider(): string
    {
        return $this->provider;
    }

    // ── DELEGACIÓN INTERNA ───────────────────────────
    private function delegate(string $method, array $args): mixed
    {
        if ($this->provider === 'meta' && $this->metaService) {
            return $this->metaService->$method(...$args);
        }

        if ($this->netplayService) {
            return $this->netplayService->$method(...$args);
        }

        return ['success' => false, 'error' => 'Ningún servicio de WhatsApp está configurado.'];
    }
}
