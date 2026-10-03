<?php

namespace App\Services\WhatsApp;

use App\Models\Company;
use App\Models\WaCampaign;
use App\Models\WaCampaignRecipient;
use App\Services\MetaWhatsAppService;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Manda una plantilla a muchos clientes.
 *
 * El envío no ocurre en la petición web: novecientos mensajes no caben en el
 * tiempo de una respuesta HTTP, y esta instalación no tiene cola de trabajos.
 * Por eso la campaña se prepara, se marca "enviando" y un comando programado
 * la va despachando por tandas. Cada destinatario queda registrado antes de
 * escribirle, así que una interrupción se retoma sin cobrarle dos veces a
 * nadie.
 */
class CampaignService
{
    /**
     * Cuántos mensajes por tanda.
     *
     * Meta acepta bastante más, pero el comando corre cada minuto y una tanda
     * chica mantiene cada ejecución muy por debajo de cualquier tiempo límite.
     */
    public const POR_TANDA = 50;

    public function __construct(
        private ClientAudience $audiencia,
        private TemplateContext $contexto,
    ) {}

    /**
     * Cuántos recibirían y una muestra de quiénes, para confirmar antes de
     * gastar mensajes.
     */
    public function preview(int $companyId, array $filtros, array $excluidos = []): array
    {
        $clientes = $this->audiencia->resolve($companyId, $filtros, $excluidos);
        $limite = $this->limiteDiario($companyId);

        return [
            'total'   => $clientes->count(),
            'limite'  => $limite,
            'excede'  => $limite['maximo'] !== null && $clientes->count() > $limite['maximo'],
            'muestra' => $clientes->take(10)->map(fn ($c) => [
                'user_id' => (int) $c->user_id,
                'nombre'  => trim(($c->names ?? '') . ' ' . ($c->lastname ?? '')),
                'dni'     => $c->dni,
                'phone'   => $c->phone,
                'plan'    => $c->plan,
            ])->values(),
        ];
    }

    /**
     * Manda la plantilla a un solo número, con datos de muestra.
     *
     * Es el paso que habilita el envío real: nadie descubre una variable
     * cambiada de puesto después de haberle escrito a toda la base.
     */
    public function sendTest(WaCampaign $campaign, string $phone): array
    {
        $company = Company::findOrFail($campaign->company_id);

        $valores = $this->contexto->sample($company);

        // El texto que escribió el operador se ve tal cual va a salir; lo demás
        // son ejemplos, porque en la prueba no hay un cliente real detrás.
        foreach ((array) $campaign->params as $entrada) {
            if (($entrada['tipo'] ?? '') === 'fijo') {
                $valores[$entrada['variable'] ?? 'texto_libre'] = $this->contexto->rellenar((string) ($entrada['valor'] ?? ''), $valores);
            }
        }

        if ($problema = $this->botonQueNoSePuedeLlenar($company, $campaign)) {
            return ['success' => false, 'error' => $problema];
        }

        $resultado = $this->enviar(
            $company,
            $phone,
            $campaign->template_name,
            $this->parametros($campaign, $valores),
            $campaign->language
        );

        if (MetaWhatsAppService::accepted($resultado)) {
            $campaign->update([
                'test_phone'   => $phone,
                'test_sent_at' => now(),
                'status'       => $campaign->status === WaCampaign::BORRADOR
                    ? WaCampaign::PROBADO
                    : $campaign->status,
            ]);
        }

        return $resultado;
    }

    /**
     * Deja la lista de destinatarios grabada y pone la campaña en marcha.
     *
     * Se congela aquí a propósito: si se recalculara en cada tanda, un cliente
     * dado de alta a mitad del envío recibiría un comunicado que no le tocaba,
     * y uno retirado dejaría un hueco imposible de auditar.
     */
    public function start(WaCampaign $campaign): int
    {
        if (!$campaign->listoParaEnviar()) {
            throw new \RuntimeException('Envía primero una prueba y revísala antes de mandarlo a los clientes.');
        }

        $clientes = $this->audiencia->resolve(
            $campaign->company_id,
            (array) $campaign->audience,
            (array) $campaign->excluded_user_ids
        );

        if ($clientes->isEmpty()) {
            throw new \RuntimeException('Con esos filtros no queda ningún cliente al que escribirle.');
        }

        $company = Company::findOrFail($campaign->company_id);

        if ($problema = $this->botonQueNoSePuedeLlenar($company, $campaign)) {
            throw new \RuntimeException($problema);
        }

        // El tope de Meta es por número: pasarse no «encola» el resto, lo rechaza. Mejor no
        // empezar que dejar a la mitad de los clientes sin el aviso y sin saber a cuáles.
        $limite = $this->limiteDiario((int) $campaign->company_id);

        if ($limite['maximo'] !== null && $clientes->count() > $limite['maximo']) {
            throw new \RuntimeException("Este envío es para {$clientes->count()} clientes y su número de WhatsApp puede iniciar conversación con {$limite['maximo']} clientes distintos cada 24 horas (nivel {$limite['nivel']} de Meta). Meta rechazaría los que pasen de ahí. Reduzca la lista con los filtros —por ejemplo, un solo grupo de corte— y envíe el resto mañana.");
        }

        DB::transaction(function () use ($campaign, $clientes) {
            $ahora = now();

            foreach ($clientes->chunk(500) as $tanda) {
                WaCampaignRecipient::insertOrIgnore($tanda->map(fn ($c) => [
                    'campaign_id' => $campaign->id,
                    'user_id'     => (int) $c->user_id,
                    'phone'       => $this->normalizar((string) $c->phone),
                    'name'        => trim(($c->names ?? '') . ' ' . ($c->lastname ?? '')),
                    'status'      => WaCampaignRecipient::PENDIENTE,
                    'created_at'  => $ahora,
                    'updated_at'  => $ahora,
                ])->all());
            }

            $campaign->update([
                'recipients_count' => $clientes->count(),
                'status'           => WaCampaign::ENVIANDO,
                'started_at'       => $ahora,
            ]);
        });

        return $clientes->count();
    }

    /**
     * Despacha una tanda. Lo llama el comando programado.
     *
     * @return int Cuántos se intentaron en esta pasada.
     */
    public function dispatchBatch(WaCampaign $campaign, int $limite = self::POR_TANDA): int
    {
        $company = Company::find($campaign->company_id);

        if (!$company) {
            $campaign->update(['status' => WaCampaign::CANCELADO, 'finished_at' => now()]);
            return 0;
        }

        $pendientes = $campaign->recipients()
            ->where('status', WaCampaignRecipient::PENDIENTE)
            ->orderBy('id')
            ->limit($limite)
            ->get();

        if ($pendientes->isEmpty()) {
            $campaign->update(['status' => WaCampaign::TERMINADO, 'finished_at' => now()]);
            return 0;
        }

        foreach ($pendientes as $destinatario) {
            $this->enviarA($company, $campaign, $destinatario);
        }

        $campaign->update([
            'sent_count'   => $campaign->recipients()->where('status', WaCampaignRecipient::ENVIADO)->count(),
            'failed_count' => $campaign->recipients()->where('status', WaCampaignRecipient::FALLIDO)->count(),
        ]);

        return $pendientes->count();
    }

    // ─── Interno ─────────────────────────────────────────────────────────────

    private function enviarA(Company $company, WaCampaign $campaign, WaCampaignRecipient $destinatario): void
    {
        $cliente = DB::table('user_data as ud')
            ->leftJoin('internet_plans as ip', 'ip.id', '=', 'ud.internet_plans_id')
            ->where('ud.user_id', $destinatario->user_id)
            ->where('ud.company_id', $company->id)
            ->first(['ud.*', DB::raw('ip.plan_name as plan')]);

        if (!$cliente) {
            $destinatario->update([
                'status' => WaCampaignRecipient::FALLIDO,
                'error'  => 'El cliente ya no existe.',
            ]);
            return;
        }

        $fijos = [];
        foreach ((array) $campaign->params as $entrada) {
            if (($entrada['tipo'] ?? '') === 'fijo') {
                $fijos[$entrada['variable'] ?? 'texto_libre'] = (string) ($entrada['valor'] ?? '');
            }
        }

        $valores = $this->contexto->forClient($company, $cliente);

        // El texto del operador puede llevar datos del cliente entre llaves: {total_pendiente}, {dias_mora}…
        foreach ($fijos as $variable => $texto) {
            $valores[$variable] = $this->contexto->rellenar($texto, $valores);
        }

        $resultado = $this->enviar(
            $company,
            $destinatario->phone,
            $campaign->template_name,
            $this->parametros($campaign, $valores),
            $campaign->language
        );

        $destinatario->update(MetaWhatsAppService::accepted($resultado)
            ? [
                'status'     => WaCampaignRecipient::ENVIADO,
                'message_id' => MetaWhatsAppService::messageIdOf($resultado),
                'sent_at'    => now(),
                'error'      => null,
            ]
            : [
                'status' => WaCampaignRecipient::FALLIDO,
                'error'  => mb_substr((string) ($resultado['error'] ?? 'Error desconocido'), 0, 250),
            ]);
    }

    /**
     * Cuántos clientes distintos puede contactar hoy el número, según Meta.
     *
     * @return array{nivel:?string, maximo:?int, calidad:?string}
     */
    public function limiteDiario(int $companyId): array
    {
        return \Illuminate\Support\Facades\Cache::store('redis')->remember("meta:limite:{$companyId}", 600, function () use ($companyId) {
            $nada = ['nivel' => null, 'maximo' => null, 'calidad' => null];
            $c = Company::find($companyId);

            if (!$c || !$c->wa_phone_number_id || !$c->wa_access_token) {
                return $nada;
            }

            try {
                $r = \Illuminate\Support\Facades\Http::withToken($c->wa_access_token)->timeout(12)
                    ->get('https://graph.facebook.com/v21.0/' . $c->wa_phone_number_id, ['fields' => 'messaging_limit_tier,quality_rating']);
            } catch (\Throwable) {
                return $nada;
            }

            $nivel = (string) $r->json('messaging_limit_tier');
            $topes = ['TIER_50' => 50, 'TIER_250' => 250, 'TIER_1K' => 1000, 'TIER_10K' => 10000, 'TIER_100K' => 100000];

            return ['nivel' => $nivel ?: null, 'maximo' => $topes[$nivel] ?? null, 'calidad' => $r->json('quality_rating')];
        });
    }

    /**
     * Una plantilla con un botón de enlace variable («Pagar ahora → …/pay/{{1}}») exige ese dato
     * en cada mensaje: sin él, Meta rechaza el envío entero. Los envíos masivos no arman ese
     * enlace, así que se avisa antes de empezar en vez de fallarle a toda la lista.
     */
    private function botonQueNoSePuedeLlenar(Company $company, WaCampaign $campaign): ?string
    {
        try {
            $botones = (new MetaWhatsAppService($company->id))->dynamicUrlButtons((string) $campaign->template_name, $campaign->language ?: 'es_CO');
        } catch (\Throwable) {
            return null;
        }

        return $botones === [] ? null
            : 'La plantilla «' . $campaign->template_name . '» tiene un botón con enlace propio de cada cliente («' . implode('», «', $botones) . '») y un envío masivo no puede armarlo: Meta rechazaría todos los mensajes. Use una plantilla sin ese botón.';
    }

    /** Los {{n}} en el orden que definió el panel. */
    private function parametros(WaCampaign $campaign, array $valores): array
    {
        $orden = [];

        foreach ((array) $campaign->params as $entrada) {
            $orden[] = $entrada['variable'] ?? 'texto_libre';
        }

        return $this->contexto->toParameters($orden, $valores);
    }

    private function enviar(Company $company, string $phone, string $plantilla, array $params, ?string $idioma): array
    {
        try {
            $wa = new WhatsAppService($company->id, false, 'meta');

            return $wa->sendTemplate($phone, $plantilla, $params, $idioma ?: 'es_CO');
        } catch (\Throwable $e) {
            Log::error('[Campaña WhatsApp] Falló el envío', [
                'company_id' => $company->id,
                'plantilla'  => $plantilla,
                'error'      => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function normalizar(string $phone): string
    {
        $digitos = preg_replace('/\D+/', '', $phone);

        return strlen($digitos) === 10 ? '57' . $digitos : $digitos;
    }
}
