<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\MetaWhatsAppService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Manda la pantalla de pago a un celular para verla en el teléfono.
 *
 * Mientras Meta no destrabe la publicación, el Flow se prueba en borrador:
 * le llega sólo a quien tenga un rol en la app o en la cuenta de WhatsApp
 * Business. Y en cualquier caso hace falta que esa persona le haya escrito
 * a la empresa en las últimas 24 horas: si no, WhatsApp no deja mandar nada
 * que no sea una plantilla.
 */
class ProbarFlowDePago extends Command
{
    protected $signature = 'pago:probar-flow
        {celular : A qué número, con indicativo (573...)}
        {--empresa= : Id de la empresa; por defecto la primera con Meta}
        {--cliente= : Id del cliente cuya deuda se muestra; por defecto el dueño del celular}
        {--flow= : Id del Flow; por defecto el que se llame «Pago» en la cuenta}
        {--publicado : Mandarlo como publicado en vez de borrador}';

    protected $description = 'Manda la pantalla de pago por WhatsApp para probarla';

    public function handle(): int
    {
        $celular = preg_replace('/\D/', '', $this->argument('celular'));

        $empresa = $this->option('empresa')
            ? Company::find($this->option('empresa'))
            : Company::where('wa_provider', 'meta')->whereNotNull('wa_business_id')->first();

        if (!$empresa) {
            $this->error('No encontré una empresa con WhatsApp de Meta configurado.');

            return self::FAILURE;
        }

        $flowId = (string) ($this->option('flow') ?: $this->flowDePago($empresa));

        if (!$flowId) {
            $this->error("No encontré el Flow de pago en la cuenta de {$empresa->name}. Pase --flow=<id>.");

            return self::FAILURE;
        }

        $clienteId = (int) ($this->option('cliente') ?: $this->duenoDelCelular($empresa->id, $celular));

        if (!$clienteId) {
            $this->error("No encontré un cliente de {$empresa->name} con el celular {$celular}. Pase --cliente=<id>.");

            return self::FAILURE;
        }

        $nombre = DB::table('user_data')->where('user_id', $clienteId)
            ->selectRaw("TRIM(CONCAT(COALESCE(names,''),' ',COALESCE(lastname,''))) AS nombre")
            ->value('nombre');
        $this->info("Empresa: {$empresa->name} · Cliente: {$nombre} (#{$clienteId}) · " . ($this->option('publicado') ? 'publicado' : 'borrador'));

        $r = (new MetaWhatsAppService((int) $empresa->id))->enviarFlowDePago(
            to:        $celular,
            flowId:    $flowId,
            companyId: (int) $empresa->id,
            userId:    $clienteId,
            titulo:    'Paga su factura',
            cuerpo:    'Mira lo que tienes pendiente y págalo aquí mismo, sin salir de WhatsApp.',
            borrador:  !$this->option('publicado'),
        );

        if (!($r['success'] ?? false)) {
            $this->error('No se pudo enviar: ' . json_encode($r['error'] ?? $r, JSON_UNESCAPED_UNICODE));

            return self::FAILURE;
        }

        $this->info('Enviado. Revise el WhatsApp de ese número.');

        return self::SUCCESS;
    }

    /** El Flow de pago de la cuenta, buscado por nombre. */
    private function flowDePago(Company $empresa): ?string
    {
        if (!$empresa->wa_business_id || !$empresa->wa_access_token) {
            return null;
        }

        try {
            $flows = \Illuminate\Support\Facades\Http::withToken($empresa->wa_access_token)
                ->get("https://graph.facebook.com/v21.0/{$empresa->wa_business_id}/flows", ['fields' => 'id,name,status'])
                ->json('data') ?? [];
        } catch (\Throwable $e) {
            return null;
        }

        foreach ($flows as $f) {
            if (stripos((string) ($f['name'] ?? ''), 'pago') !== false) {
                $this->line("Flow: {$f['name']} (#{$f['id']}, {$f['status']})");

                return (string) $f['id'];
            }
        }

        return null;
    }

    /** El cliente al que le pertenece ese celular, si lo hay. */
    private function duenoDelCelular(int $empresaId, string $celular): ?int
    {
        $ultimos = substr($celular, -10);

        return DB::table('user_data')
            ->join('users', 'users.id', '=', 'user_data.user_id')
            ->where('users.company_id', $empresaId)
            ->whereRaw("RIGHT(REGEXP_REPLACE(user_data.phone, '[^0-9]', ''), 10) = ?", [$ultimos])
            ->value('users.id');
    }
}
