<?php

namespace App\Services\WhatsApp;

use App\Models\Company;
use App\Models\WaTemplateBinding;
use App\Services\MetaWhatsAppService;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Avisos sobre el estado del servicio: por ahora, la reactivación.
 *
 * El cliente que pagó y sigue sin internet vuelve a escribir o llama, aunque el
 * sistema ya lo haya reactivado, porque nadie se lo dijo. Este aviso cierra ese
 * hueco.
 *
 * Falla en silencio a propósito: reactivar el servicio es lo importante y no
 * puede quedarse a medias porque WhatsApp no responda.
 */
class ServiceNotifier
{
    public const EVENTO = 'servicio_reactivado';

    public function __construct(
        private TemplateContext $contexto,
    ) {}

    /**
     * Avisa a cada cliente que su servicio volvió.
     *
     * @param array<int, int> $userIds
     * @return int Cuántos avisos salieron.
     */
    public function reactivados(int $companyId, array $userIds): int
    {
        if ($userIds === []) {
            return 0;
        }

        $binding = WaTemplateBinding::where('company_id', $companyId)
            ->where('event', self::EVENTO)
            ->first();

        if (!$binding || !$binding->isUsable()) {
            return 0;
        }

        $company = Company::find($companyId);

        if (!$company) {
            return 0;
        }

        $clientes = DB::table('user_data as ud')
            ->leftJoin('internet_plans as ip', 'ip.id', '=', 'ud.internet_plans_id')
            ->where('ud.company_id', $companyId)
            ->whereIn('ud.user_id', $userIds)
            ->whereRaw("CHAR_LENGTH(REGEXP_REPLACE(COALESCE(ud.phone,''), '[^0-9]', '')) >= 10")
            ->get(['ud.*', DB::raw('ip.plan_name as plan')]);

        $enviados = 0;

        foreach ($clientes as $cliente) {
            if ($this->avisar($company, $binding, $cliente)) {
                $enviados++;
            }
        }

        return $enviados;
    }

    private function avisar(Company $company, WaTemplateBinding $binding, object $cliente): bool
    {
        try {
            $valores = $this->contexto->forClient($company, $cliente, [
                'fecha' => now()->format('d/m/Y'),
            ]);

            $r = (new WhatsAppService($company->id, false, 'meta'))->sendTemplate(
                (string) $cliente->phone,
                $binding->template_name,
                $this->contexto->toParameters((array) $binding->params, $valores),
                $binding->language ?: 'es_CO'
            );

            if (!MetaWhatsAppService::accepted($r)) {
                Log::warning('[Reactivación WhatsApp] No se pudo avisar', [
                    'company_id' => $company->id,
                    'user_id'    => $cliente->user_id,
                    'error'      => $r['error'] ?? 'desconocido',
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            // El servicio ya quedó reactivado; que falle el aviso no puede
            // arrastrar ese proceso.
            Log::error('[Reactivación WhatsApp] Error enviando el aviso', [
                'company_id' => $company->id,
                'user_id'    => $cliente->user_id,
                'error'      => $e->getMessage(),
            ]);

            return false;
        }
    }
}
