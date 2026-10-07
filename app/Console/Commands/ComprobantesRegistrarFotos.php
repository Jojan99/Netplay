<?php

namespace App\Console\Commands;

use App\Services\Comprobantes\PareceComprobante;
use App\Services\Crm\ComprobanteWhatsAppWeb;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Registra en auditoría las fotos de pago que los clientes mandaron por WhatsApp y que no
 * quedaron como comprobante (el bot no las atendió).
 *
 * Toma las filas «Foto de pago sin registrar» del cruce de la suspensión masiva y registra
 * cada foto que se lee como comprobante, a nombre de ese cliente. Quedan PENDIENTES: nunca se
 * aplica el pago solo, lo aprueba una persona en Auditoría de pagos. No se le escribe al
 * cliente ni al grupo. Un comprobante con la misma referencia o el mismo archivo no se repite.
 *
 * Sin --aplicar sólo muestra lo que haría.
 */
class ComprobantesRegistrarFotos extends Command
{
    protected $signature = 'comprobantes:registrar-fotos {empresa : Id de la empresa}
        {archivo : Cruce en storage/app (JSON)}
        {--aplicar : Registrar los comprobantes (sin esto, sólo simula)}';

    protected $description = 'Registra como comprobantes pendientes las fotos de pago que llegaron por WhatsApp sin registrarse';

    public function handle(ComprobanteWhatsAppWeb $comprobantes): int
    {
        $empresa = (int) $this->argument('empresa');
        $ruta = storage_path('app/' . ltrim((string) $this->argument('archivo'), '/'));
        $filas = is_file($ruta) ? json_decode((string) file_get_contents($ruta), true) : null;

        if (!is_array($filas)) {
            $this->error('No se pudo leer ' . $ruta);

            return self::FAILURE;
        }

        $bot = app(\App\Services\WaBotService::class);
        $leer = new \ReflectionMethod($bot, 'extractTextFromProof');
        $leer->setAccessible(true);
        $tabla = [];

        foreach ($filas as $f) {
            if (($f['estado'] ?? '') !== 'Foto de pago sin registrar') {
                continue;
            }

            $cliente = DB::table('user_data')->where('company_id', $empresa)->where('user_id', $f['user_id'])->first(['dni', 'phone']);

            foreach (array_filter(explode(' ', (string) ($f['enlaces_fotos'] ?? ''))) as $url) {
                $local = $this->local($url);
                $texto = $local ? (string) $leer->invoke($bot, $local) : '';

                if (!PareceComprobante::es($texto)) {
                    $tabla[] = [$f['cliente'], basename($url), 'no es comprobante: no se registra'];
                    continue;
                }

                if (!$this->option('aplicar')) {
                    $tabla[] = [$f['cliente'], basename($url), 'se registraría como pendiente'];
                    continue;
                }

                $r = $comprobantes->registrar([
                    'company_id' => $empresa, 'phone' => (string) ($cliente->phone ?? $f['telefono']), 'dni' => $cliente->dni ?? $f['cedula'],
                    'media_url' => $url, 'caption' => null, 'solo_revision' => true, 'sin_aviso' => true,
                ]);

                $tabla[] = [$f['cliente'], basename($url), ($r['ok'] ?? false)
                    ? (($r['motivo'] ?? '') === 'ya_registrado' ? "ya estaba (#{$r['proof_id']})" : "registrado #{$r['proof_id']} pendiente")
                    : 'no se pudo: ' . ($r['motivo'] ?? '?')];
            }
        }

        $this->line(($this->option('aplicar') ? 'APLICADO' : 'SIMULACIÓN') . ' · empresa ' . $empresa);
        $this->table(['Cliente', 'Foto', 'Resultado'], $tabla);

        if (!$this->option('aplicar')) {
            $this->line('Nada se registró. Para registrar, repita el comando con --aplicar. Después se aprueban en Auditoría de pagos.');
        }

        return self::SUCCESS;
    }

    private function local(string $url): ?string
    {
        if (preg_match('#/storage/(.+)$#', $url, $m) && is_file(storage_path('app/public/' . $m[1]))) {
            return storage_path('app/public/' . $m[1]);
        }
        if (preg_match('#/wa-media/uploads/(.+)$#', $url, $m) && is_file('/var/www/whatsapp-service/uploads/' . $m[1])) {
            return '/var/www/whatsapp-service/uploads/' . $m[1];
        }

        return null;
    }
}
