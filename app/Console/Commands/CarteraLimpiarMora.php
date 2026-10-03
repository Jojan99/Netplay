<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deja a cada cliente moroso con sólo sus facturas pendientes más recientes y
 * anula las más viejas.
 *
 * A un cliente suspendido se le siguió facturando mes a mes y acumuló una
 * deuda que no es cobrable. La regla del dueño: quedan las dos más recientes
 * y lo demás se anula, dejando dicho que lo hizo el sistema, cuándo y por qué.
 *
 * Sin --aplicar sólo muestra lo que haría. Al aplicar guarda un respaldo con
 * las facturas anuladas, y --deshacer lo revierte.
 *
 * Una factura con abono no se anula nunca, aunque sea de las viejas: ahí ya
 * entró plata del cliente y anularla la dejaría en el aire. Se anulan las demás
 * y ésa queda pendiente, así que ese cliente puede quedar con más de dos.
 */
class CarteraLimpiarMora extends Command
{
    protected $signature = 'cartera:limpiar-mora
        {empresa : Id de la empresa}
        {--conservar=2 : Cuántas facturas pendientes se le dejan a cada cliente}
        {--aplicar : Anular de verdad (sin esto sólo se muestra)}
        {--deshacer= : Archivo de respaldo de una corrida anterior, para revertirla}';

    protected $description = 'Anula las facturas pendientes más viejas de los clientes morosos y les deja las más recientes';

    public function handle(): int
    {
        $empresa   = (int) $this->argument('empresa');
        $conservar = max(1, (int) $this->option('conservar'));

        if (!DB::table('companies')->where('id', $empresa)->exists()) {
            $this->error('Esa empresa no existe.');

            return self::FAILURE;
        }

        if ($this->option('deshacer')) {
            return $this->deshacer($empresa, (string) $this->option('deshacer'));
        }

        $motivo = 'Anulada por el sistema el ' . now()->format('d/m/Y') . ': limpieza de cartera en mora, se conservan las '
            . $conservar . ' facturas pendientes más recientes.';

        [$plan, $conAbono] = $this->planear($empresa, $conservar);

        if (!$plan) {
            $this->info('No hay facturas viejas sin abono que anular: nadie tiene más de ' . $conservar . ' pendientes, salvo por facturas con abono.');
            $this->listarConAbono($conAbono);

            return self::SUCCESS;
        }

        $this->table(
            ['Cédula', 'Cliente', 'Pendientes', 'Se anulan', 'Valor anulado', 'Quedan'],
            array_map(fn ($c) => [
                $c['dni'], mb_substr($c['cliente'], 0, 34), $c['pendientes'],
                implode(', ', array_column($c['anular'], 'numero')),
                '$' . number_format($c['valor'], 0, ',', '.'),
                implode(', ', $c['quedan']),
            ], $plan)
        );

        $facturas = array_sum(array_map(fn ($c) => count($c['anular']), $plan));
        $valor    = array_sum(array_column($plan, 'valor'));

        $this->line('');
        $this->info(count($plan) . ' clientes, ' . $facturas . ' facturas, $' . number_format($valor, 0, ',', '.') . ' de cartera que se anularía.');
        $this->line('Motivo que quedará en cada factura: ' . $motivo);
        $this->listarConAbono($conAbono);

        if (!$this->option('aplicar')) {
            $this->line('');
            $this->comment('Esto fue una simulación: no se anuló nada. Para anular, repita el comando con --aplicar');

            return self::SUCCESS;
        }

        if (!$this->confirm('Se van a anular ' . $facturas . ' facturas de ' . count($plan) . ' clientes. ¿Continuar?')) {
            $this->comment('Cancelado: no se anuló nada.');

            return self::SUCCESS;
        }

        $ids = array_merge(...array_map(fn ($c) => array_column($c['anular'], 'id'), $plan));

        // El respaldo se guarda antes de tocar nada: si algo falla a mitad, se sabe qué se intentó.
        $archivo = 'limpieza-cartera/empresa-' . $empresa . '-' . now()->format('Ymd-His') . '.json';
        Storage::disk('local')->put($archivo, json_encode([
            'empresa' => $empresa, 'fecha' => now()->toDateTimeString(), 'motivo' => $motivo, 'ids' => $ids, 'detalle' => $plan,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // La condición se repite en el UPDATE: una factura pagada o anulada entre la
        // vista previa y la confirmación no se toca.
        $anuladas = DB::transaction(fn () => DB::table('det_facturations')
            ->whereIn('id', $ids)->where('paid', 0)->whereNull('anulada_en')
            ->update(['anulada_en' => now(), 'anulada_por' => null, 'anulada_motivo' => mb_substr($motivo, 0, 255), 'updated_at' => now()]));

        $this->info('Anuladas: ' . $anuladas . ' de ' . count($ids) . '.');
        $this->line('Respaldo: ' . Storage::disk('local')->path($archivo));
        $this->line('Para revertir: php artisan cartera:limpiar-mora ' . $empresa . ' --deshacer=' . $archivo);

        return self::SUCCESS;
    }

    /**
     * Qué se anularía de cada cliente.
     *
     * @return array{0: list<array<string,mixed>>, 1: list<string>}  el plan y las facturas con abono que se respetan
     */
    private function planear(int $empresa, int $conservar): array
    {
        $pendientes = DB::table('det_facturations as d')
            ->join('cab_facturations as c', 'c.id', '=', 'd.cab_id')
            ->leftJoin('user_data as ud', 'ud.user_id', '=', 'c.user_id')
            ->where('c.company_id', $empresa)
            ->where('d.paid', 0)->whereNull('d.anulada_en')
            ->orderBy('d.date_facturation')->orderBy('d.id')
            ->get(['d.id', 'd.number_facture', 'd.date_facturation', 'd.price_total', 'd.price_discount', 'd.price_abone',
                'c.user_id', 'ud.dni', 'ud.names', 'ud.lastname'])
            ->groupBy('user_id');

        $plan = [];
        $conAbono = [];

        foreach ($pendientes as $facturas) {
            if ($facturas->count() <= $conservar) {
                continue;
            }

            $primera = $facturas->first();
            $cliente = trim(($primera->names ?? '') . ' ' . ($primera->lastname ?? '')) ?: ('Cliente ' . $primera->user_id);

            // De las que sobran (las más viejas), las que tienen abono se respetan.
            $sobran     = $facturas->slice(0, $facturas->count() - $conservar)->values();
            $anular     = $sobran->filter(fn ($f) => (float) ($f->price_abone ?? 0) <= 0)->values();
            $respetadas = $sobran->filter(fn ($f) => (float) ($f->price_abone ?? 0) > 0)->values();
            $quedan     = $respetadas->concat($facturas->slice($facturas->count() - $conservar))->values();

            if ($respetadas->isNotEmpty()) {
                $conAbono[] = ($primera->dni ?: '-') . ' ' . $cliente . ': se respetan '
                    . $respetadas->map(fn ($f) => $f->number_facture . ' (abono $' . number_format((float) $f->price_abone, 0, ',', '.') . ')')->implode(', ');
            }

            if ($anular->isEmpty()) {
                continue;
            }

            $plan[] = [
                'user_id'    => (int) $primera->user_id,
                'dni'        => $primera->dni,
                'cliente'    => $cliente,
                'pendientes' => $facturas->count(),
                'anular'     => $anular->map(fn ($f) => ['id' => (int) $f->id, 'numero' => $f->number_facture, 'fecha' => substr((string) $f->date_facturation, 0, 10)])->all(),
                'valor'      => (float) $anular->sum(fn ($f) => max(0, (float) $f->price_total - (float) ($f->price_discount ?? 0))),
                'quedan'     => $quedan->pluck('number_facture')->all(),
            ];
        }

        usort($plan, fn ($a, $b) => $b['pendientes'] <=> $a['pendientes'] ?: strcmp($a['cliente'], $b['cliente']));

        return [$plan, $conAbono];
    }

    private function listarConAbono(array $conAbono): void
    {
        if (!$conAbono) {
            return;
        }

        $this->line('');
        $this->warn(count($conAbono) . ' clientes tienen facturas viejas con abono: ésas no se anulan y siguen pendientes.');

        foreach ($conAbono as $c) {
            $this->line('  ' . $c);
        }
    }

    /** Devuelve a pendiente las facturas de un respaldo, sólo las que siguen anuladas con ese mismo motivo. */
    private function deshacer(int $empresa, string $archivo): int
    {
        if (!Storage::disk('local')->exists($archivo)) {
            $this->error('No existe ese respaldo: ' . $archivo);

            return self::FAILURE;
        }

        $respaldo = json_decode((string) Storage::disk('local')->get($archivo), true);

        if ((int) ($respaldo['empresa'] ?? 0) !== $empresa || empty($respaldo['ids'])) {
            $this->error('Ese respaldo no es de esta empresa o está vacío.');

            return self::FAILURE;
        }

        if (!$this->confirm('Se van a devolver a pendiente hasta ' . count($respaldo['ids']) . ' facturas anuladas el ' . $respaldo['fecha'] . '. ¿Continuar?')) {
            return self::SUCCESS;
        }

        $devueltas = DB::table('det_facturations')
            ->whereIn('id', $respaldo['ids'])
            ->where('anulada_motivo', mb_substr((string) $respaldo['motivo'], 0, 255))
            ->update(['anulada_en' => null, 'anulada_por' => null, 'anulada_motivo' => null, 'updated_at' => now()]);

        $this->info('Devueltas a pendiente: ' . $devueltas . '.');

        return self::SUCCESS;
    }
}
