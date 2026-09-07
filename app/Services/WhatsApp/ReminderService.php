<?php

namespace App\Services\WhatsApp;

use App\Models\Company;
use App\Models\WaTemplateBinding;
use App\Services\MetaWhatsAppService;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Los avisos que salen solos: recordatorio de pago y aviso de suspensión.
 *
 * Cuándo sale cada uno lo decide el panel, no este código. Unas empresas dan
 * cinco días desde que emiten la factura y otras cobran el día 5 del mes; con
 * la regla escrita aquí, cambiar de política obligaría a un despliegue.
 */
class ReminderService
{
    /** Eventos que este servicio sabe disparar. */
    public const EVENTOS = ['recordatorio_pago', 'suspension_mora'];

    public function __construct(
        private TemplateContext $contexto,
    ) {}

    /**
     * Recorre las empresas y manda lo que toque hoy.
     *
     * @return array<string, int> Cuántos avisos salieron por evento.
     */
    public function run(?int $soloCompanyId = null, bool $simulacion = false): array
    {
        $resumen = [];

        $companies = Company::query()
            ->when($soloCompanyId, fn ($q) => $q->where('id', $soloCompanyId))
            ->get();

        foreach ($companies as $company) {
            foreach (self::EVENTOS as $evento) {
                $binding = WaTemplateBinding::where('company_id', $company->id)
                    ->where('event', $evento)
                    ->first();

                if (!$binding || !$binding->isUsable()) {
                    continue;
                }

                $enviados = $this->procesar($company, $binding, $evento, $simulacion);

                if ($enviados > 0) {
                    $resumen["{$company->id}:{$evento}"] = $enviados;
                }
            }
        }

        return $resumen;
    }

    /**
     * A quiénes les toca hoy este aviso, sin mandar nada.
     *
     * El panel lo usa para mostrar "hoy le saldría a 34 clientes" antes de que
     * nadie active la automatización a ciegas.
     */
    public function preview(Company $company, WaTemplateBinding $binding, string $evento): array
    {
        return $this->destinatariosDeHoy($company, $binding, $evento);
    }

    // ─── Interno ─────────────────────────────────────────────────────────────

    private function procesar(Company $company, WaTemplateBinding $binding, string $evento, bool $simulacion): int
    {
        $destinatarios = $this->destinatariosDeHoy($company, $binding, $evento);
        $enviados      = 0;

        foreach ($destinatarios as $caso) {
            if ($this->yaAvisado($company->id, $evento, (int) $caso['cliente']->user_id)) {
                continue;
            }

            if ($simulacion) {
                $enviados++;
                continue;
            }

            if ($this->enviar($company, $binding, $caso)) {
                $enviados++;
            }
        }

        return $enviados;
    }

    /**
     * Los clientes a los que hoy les corresponde el aviso.
     *
     * @return array<int, array{cliente: object, extra: array}>
     */
    private function destinatariosDeHoy(Company $company, WaTemplateBinding $binding, string $evento): array
    {
        $ajustes = $binding->ajustes();

        return $ajustes['referencia'] === 'fecha_factura'
            ? $this->porFechaDeFactura($company, $ajustes)
            : $this->porDiaDeCorte($company, $ajustes);
    }

    /**
     * Cada factura lleva su propia cuenta: vence a los N días de emitida y el
     * aviso sale M días antes de eso.
     */
    private function porFechaDeFactura(Company $company, array $ajustes): array
    {
        $plazo = max(0, (int) $ajustes['dias_plazo']);
        $antes = max(0, (int) $ajustes['dias_antes']);

        // La factura que hoy cumple (plazo - antes) días de emitida.
        $emitidaEl = now()->startOfDay()->subDays(max(0, $plazo - $antes))->toDateString();

        $filas = DB::table('det_facturations as df')
            ->join('cab_facturations as cf', 'cf.id', '=', 'df.cab_id')
            ->join('user_data as ud', function ($j) use ($company) {
                $j->on('ud.user_id', '=', 'cf.user_id')->where('ud.company_id', $company->id);
            })
            ->leftJoin('internet_plans as ip', 'ip.id', '=', 'ud.internet_plans_id')
            ->where('cf.company_id', $company->id)
            ->where('df.paid', 0)
            ->whereRaw('DATE(df.date_facturation) = ?', [$emitidaEl])
            ->whereRaw('(df.price_total - COALESCE(df.price_discount,0) - COALESCE(df.price_abone,0)) > 0')
            ->whereRaw("CHAR_LENGTH(REGEXP_REPLACE(COALESCE(ud.phone,''), '[^0-9]', '')) >= 10")
            ->get([
                'ud.*',
                DB::raw('ip.plan_name as plan'),
                'df.id as invoice_id',
                'df.number_facture',
                'df.date_facturation',
                DB::raw('(df.price_total - COALESCE(df.price_discount,0) - COALESCE(df.price_abone,0)) as saldo_factura'),
            ]);

        $casos = [];

        foreach ($filas as $fila) {
            $emision = Carbon::parse($fila->date_facturation);

            $casos[] = [
                'cliente' => $fila,
                'extra'   => [
                    'factura'           => (string) $fila->number_facture,
                    'valor'             => $this->dinero((float) $fila->saldo_factura),
                    'fecha_emision'     => $emision->format('d/m/Y'),
                    'fecha_vencimiento' => $emision->copy()->addDays($plazo)->format('d/m/Y'),
                    'dias_mora'         => (string) max(0, now()->startOfDay()->diffInDays($emision->copy()->addDays($plazo), false) * -1),
                ],
            ];
        }

        return $casos;
    }

    /**
     * Un solo envío al mes: el aviso sale N días antes del día de corte, a
     * todo el que deba algo.
     */
    private function porDiaDeCorte(Company $company, array $ajustes): array
    {
        $corte = $this->diaDeCorte($company, $ajustes);
        $antes = max(0, (int) $ajustes['dias_antes']);

        $fechaCorte = $this->proximoCorte($corte);
        $avisarHoy  = $fechaCorte->copy()->subDays($antes);

        if (!now()->startOfDay()->equalTo($avisarHoy->startOfDay())) {
            return [];
        }

        $filas = DB::table('user_data as ud')
            ->leftJoin('internet_plans as ip', 'ip.id', '=', 'ud.internet_plans_id')
            ->where('ud.company_id', $company->id)
            ->where('ud.active', 1)
            ->whereRaw("CHAR_LENGTH(REGEXP_REPLACE(COALESCE(ud.phone,''), '[^0-9]', '')) >= 10")
            ->whereIn('ud.user_id', function ($q) use ($company) {
                $q->select('cf.user_id')
                    ->from('cab_facturations as cf')
                    ->join('det_facturations as df', 'df.cab_id', '=', 'cf.id')
                    ->where('cf.company_id', $company->id)
                    ->where('df.paid', 0)
                    ->whereRaw('(df.price_total - COALESCE(df.price_discount,0) - COALESCE(df.price_abone,0)) > 0');
            })
            ->get(['ud.*', DB::raw('ip.plan_name as plan')]);

        $casos = [];

        foreach ($filas as $fila) {
            $masVieja = $this->facturaMasVieja($company->id, (int) $fila->user_id);

            $casos[] = [
                'cliente' => $fila,
                'extra'   => [
                    'factura'           => (string) ($masVieja->number_facture ?? ''),
                    'fecha_emision'     => $masVieja ? Carbon::parse($masVieja->date_facturation)->format('d/m/Y') : '',
                    'fecha_vencimiento' => $fechaCorte->format('d/m/Y'),
                    'dias_mora'         => (string) ($masVieja
                        ? Carbon::parse($masVieja->date_facturation)->startOfDay()->diffInDays(now()->startOfDay())
                        : 0),
                ],
            ];
        }

        return $casos;
    }

    /** El día del mes en que se corta: el configurado, o el de la suspensión automática. */
    private function diaDeCorte(Company $company, array $ajustes): int
    {
        if (!empty($ajustes['dia_corte'])) {
            return max(1, min(28, (int) $ajustes['dia_corte']));
        }

        $configurado = DB::table('auto_suspend_configs')
            ->where('company_id', $company->id)
            ->value('suspension_day');

        return max(1, min(28, (int) ($configurado ?: 5)));
    }

    /** El corte de este mes si aún no pasó; si ya pasó, el del mes que viene. */
    private function proximoCorte(int $dia): Carbon
    {
        $esteMes = now()->startOfDay()->day($dia);

        return $esteMes->greaterThanOrEqualTo(now()->startOfDay())
            ? $esteMes
            : $esteMes->addMonthNoOverflow();
    }

    private function facturaMasVieja(int $companyId, int $userId): ?object
    {
        return DB::table('det_facturations as df')
            ->join('cab_facturations as cf', 'cf.id', '=', 'df.cab_id')
            ->where('cf.company_id', $companyId)
            ->where('cf.user_id', $userId)
            ->where('df.paid', 0)
            ->whereRaw('(df.price_total - COALESCE(df.price_discount,0) - COALESCE(df.price_abone,0)) > 0')
            ->orderBy('df.date_facturation')
            ->first(['df.number_facture', 'df.date_facturation']);
    }

    private function enviar(Company $company, WaTemplateBinding $binding, array $caso): bool
    {
        $valores = $this->contexto->forClient($company, $caso['cliente'], $caso['extra']);
        $params  = $this->contexto->toParameters((array) $binding->params, $valores);

        try {
            $wa     = new WhatsAppService($company->id, false, 'meta');
            $idioma = $binding->language ?: 'es_CO';

            // Si la plantilla trae un botón "Pagar ahora" con la URL variable,
            // se le pasa el link de este cliente. Con la URL fija el botón
            // llevaría a la página de inicio, que no le sirve de nada.
            $indiceBoton = (new MetaWhatsAppService($company->id))
                ->dynamicUrlButtonIndex($binding->template_name, $idioma);

            $token = $indiceBoton === null
                ? null
                : $this->tokenDePago($company, (int) $caso['cliente']->user_id, (string) $caso['cliente']->phone);

            $r = $wa->sendTemplate(
                (string) $caso['cliente']->phone,
                $binding->template_name,
                $params,
                $idioma,
                $token,
                $indiceBoton ?? 0
            );

            if (!MetaWhatsAppService::accepted($r)) {
                Log::warning('[Avisos WhatsApp] No se pudo enviar', [
                    'company_id' => $company->id,
                    'evento'     => $binding->event,
                    'user_id'    => $caso['cliente']->user_id,
                    'error'      => $r['error'] ?? 'desconocido',
                ]);

                return false;
            }

            $this->marcarAvisado($company->id, $binding->event, (int) $caso['cliente']->user_id);

            return true;
        } catch (\Throwable $e) {
            Log::error('[Avisos WhatsApp] Error enviando', [
                'company_id' => $company->id,
                'evento'     => $binding->event,
                'error'      => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Evita repetirle el aviso al mismo cliente el mismo día.
     *
     * El comando corre una vez al día, así que esto solo protege de una
     * ejecución manual repetida. Si el caché falla se envía igual: perder un
     * recordatorio le cuesta más a la empresa que mandar uno de más.
     */
    private function yaAvisado(int $companyId, string $evento, int $userId): bool
    {
        try {
            return (bool) Cache::get($this->claveAviso($companyId, $evento, $userId));
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function marcarAvisado(int $companyId, string $evento, int $userId): void
    {
        try {
            Cache::put($this->claveAviso($companyId, $evento, $userId), 1, now()->addHours(20));
        } catch (\Throwable $e) {
            // Sin caché el aviso ya salió; no vale la pena romper por esto.
        }
    }

    private function claveAviso(int $companyId, string $evento, int $userId): string
    {
        return "wa_aviso:{$companyId}:{$evento}:{$userId}:" . now()->toDateString();
    }

    /**
     * Link de pago para este cliente, listo para el botón de la plantilla.
     *
     * Devuelve solo el token: Meta lo pega al final de la URL que quedó
     * definida en la plantilla (…/api/pay/{{1}}).
     */
    private function tokenDePago(Company $company, int $userId, string $phone): ?string
    {
        if (!$company->pg_active || !$company->pg_gateway) {
            return null;
        }

        try {
            $link = app(\App\Services\PaymentGateways\PaymentLinkService::class)
                ->create($company, $userId, null, 'recordatorio', null, $phone);

            return $link->token;
        } catch (\Throwable $e) {
            Log::warning('[Avisos WhatsApp] No se pudo generar el link de pago', [
                'company_id' => $company->id,
                'user_id'    => $userId,
                'error'      => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function dinero(float $valor): string
    {
        return '$' . number_format($valor, 0, ',', '.');
    }
}
