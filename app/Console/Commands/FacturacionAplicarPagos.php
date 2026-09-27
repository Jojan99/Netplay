<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\AutoSuspendService;
use App\Services\Facturacion\AplicarPagoALasFacturas;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Aplica una lista de pagos (cédula + valor) a las facturas de cada cliente.
 *
 *   php artisan facturacion:aplicar-pagos archivo.json --empresa=1              (simula)
 *   php artisan facturacion:aplicar-pagos archivo.json --empresa=1 --aplicar \
 *        --por=212 --metodo=4 --lote=cruce-20260926                              (aplica)
 *
 * El archivo es una lista JSON de { ref, cedula, nombre, valor }. `ref` es lo que
 * identifica cada pago: con él y el lote se arma la marca que impide aplicarlo dos
 * veces. Por eso el comando se puede volver a correr sin miedo.
 *
 * Siempre se corre con netvula-artisan (como www-data): artisan como root deja el
 * caché con dueño root y PHP-FPM falla lejos de la causa.
 */
class FacturacionAplicarPagos extends Command
{
    protected $signature = 'facturacion:aplicar-pagos
        {archivo : JSON con la lista de pagos, dentro de storage/app}
        {--empresa=1 : id de la empresa}
        {--aplicar : escribe de verdad; sin esto sólo simula}
        {--por= : id del usuario que queda como quien registró los pagos (obligatorio al aplicar)}
        {--metodo= : id del método de pago (obligatorio al aplicar)}
        {--fecha= : fecha en que se recibió el pago, Y-m-d; por defecto ahora}
        {--lote= : nombre del lote, forma parte de la marca de cada pago}
        {--titulo= : cómo se ve el lote en el historial de la pantalla}
        {--exacta-primero : si una factura debe justo lo que se pagó, se paga esa antes que las más viejas}
        {--reactivar : después de aplicar, reactiva a los clientes que quedaron al día}';

    protected $description = 'Aplica pagos (cédula + valor) a las facturas de cada cliente: paga las que alcanza y abona la que no.';

    public function handle(): int
    {
        $empresa = (int) $this->option('empresa');
        $aplicar = (bool) $this->option('aplicar');
        $ruta = storage_path('app/' . ltrim($this->argument('archivo'), '/'));

        if (!is_file($ruta)) return $this->fallar("No existe {$ruta}");
        if (!Company::where('id', $empresa)->exists()) return $this->fallar("La empresa {$empresa} no existe.");

        $pagos = json_decode((string) file_get_contents($ruta), true);
        if (!is_array($pagos) || !$pagos) return $this->fallar('El archivo no trae pagos.');

        $por = $this->option('por') ? (int) $this->option('por') : null;
        $metodo = $this->option('metodo') ? (int) $this->option('metodo') : null;
        $fecha = $this->option('fecha') ? Carbon::parse($this->option('fecha'))->setTime(12, 0) : null;
        $lote = $this->option('lote') ?: 'cruce-' . now()->format('Ymd');

        if ($por && !DB::table('users')->where('id', $por)->where('company_id', $empresa)->exists()) {
            return $this->fallar("El usuario {$por} no es de la empresa {$empresa}.");
        }
        if ($metodo && !DB::table('payment_methods')->where('id', $metodo)->where('company_id', $empresa)->exists()) {
            return $this->fallar("El método de pago {$metodo} no es de la empresa {$empresa}.");
        }
        if ($aplicar && (!$por || !$metodo)) {
            return $this->fallar('Para aplicar hay que decir quién registra (--por) y con qué método (--metodo).');
        }

        $motor = new AplicarPagoALasFacturas($empresa, $por, $metodo, $fecha, $aplicar, (bool) $this->option('exacta-primero'));

        $this->line($aplicar ? '<fg=red;options=bold>APLICANDO pagos de verdad.</>' : '<fg=yellow;options=bold>SIMULACIÓN: no se escribe nada.</>');
        $this->line("Empresa {$empresa} · lote {$lote} · orden: " . ($this->option('exacta-primero') ? 'la factura exacta primero, luego la más vieja' : 'de la más vieja a la más nueva') . ' · ' . count($pagos) . ' pagos' . ($por ? " · registra el usuario {$por}" : '') . ($metodo ? " · método {$metodo}" : ''));

        // Un lote aplicado por consola también queda registrado, con cómo estaban las facturas: así aparece en el
        // historial de la pantalla y se puede deshacer igual que uno de la web.
        if ($aplicar) {
            $simulador = new AplicarPagoALasFacturas($empresa, null, $metodo, $fecha, false, (bool) $this->option('exacta-primero'));
            $ids = [];
            foreach ($pagos as $p) {
                try {
                    $x = $simulador->pagar((string) $p['cedula'], (float) $p['valor'], "Lote {$lote} · {$p['ref']} · cruce por cédula");
                    foreach ($x['movimientos'] as $m) $ids[] = $m['det_id'];
                } catch (\Throwable $e) { /* el que falle se reporta al aplicarlo */ }
            }
            \App\Services\Facturacion\LoteDePagos::abrir($empresa, $lote, $por, $metodo, $this->option('exacta-primero') ? 'exacta' : 'antigua', $fecha?->toDateString(), $this->option('titulo') ?: "Consola · {$lote}", array_values(array_unique($ids)), 'consola');
        }

        $reporte = [];
        $resumen = [];
        $usuarios = [];

        foreach ($pagos as $p) {
            $marca = "Lote {$lote} · {$p['ref']} · cruce por cédula";

            try {
                $r = $motor->pagar((string) $p['cedula'], (float) $p['valor'], $marca);
            } catch (\Throwable $e) {
                $r = ['estado' => 'error', 'user_id' => null, 'cliente' => null, 'movimientos' => [], 'sobrante' => 0.0, 'detalle' => $e->getMessage()];
            }

            $reporte[] = ['ref' => $p['ref'], 'cedula' => $p['cedula'], 'nombre' => $p['nombre'] ?? '', 'valor' => $p['valor']] + $r;
            $resumen[$r['estado']] = ($resumen[$r['estado']] ?? ['n' => 0, 'valor' => 0]);
            $resumen[$r['estado']]['n']++;
            $resumen[$r['estado']]['valor'] += (float) $p['valor'];

            if ($r['user_id'] && in_array($r['estado'], ['aplicado', 'simulado'], true)) $usuarios[$r['user_id']] = true;
        }

        // ── Lo que hay que mirar, antes del resumen ─────────────────────────
        $this->newLine();
        $atencion = array_filter($reporte, fn ($x) => !in_array($x['estado'], ['aplicado', 'simulado'], true) || $x['sobrante'] > 0.004
            || count($x['movimientos']) > 1 || collect($x['movimientos'])->contains(fn ($m) => $m['tipo'] === 'abono'));

        foreach ($atencion as $x) {
            $movs = collect($x['movimientos'])->map(fn ($m) => "{$m['factura']} {$m['tipo']} \${$m['monto']}")->implode('; ');
            $this->line(sprintf(' %-13s %-8s %-36s $%-9s %s%s', strtoupper($x['estado']), $x['ref'], mb_substr($x['nombre'], 0, 36), number_format((float) $x['valor'], 0, ',', '.'),
                $movs ?: ($x['detalle'] ?? ''), $x['sobrante'] > 0.004 ? "  | SOBRA \${$x['sobrante']}" : ''));
        }

        $this->newLine();
        $this->table(['Estado', 'Pagos', 'Valor'], collect($resumen)->map(fn ($v, $k) => [$k, $v['n'], '$' . number_format($v['valor'], 0, ',', '.')])->values()->all());

        $aplicado = collect($reporte)->whereIn('estado', ['aplicado', 'simulado'])->sum(fn ($x) => collect($x['movimientos'])->sum('monto'));
        $sobrante = collect($reporte)->sum('sobrante');
        $alDia = collect($reporte)->whereIn('estado', ['aplicado', 'simulado'])->where('quedan', 0)->count();
        $debiendo = collect($reporte)->whereIn('estado', ['aplicado', 'simulado'])->where('quedan', '>', 0)->count();
        $this->line("Clientes que quedan sin facturas pendientes: <options=bold>{$alDia}</> · que todavía deben algo: <options=bold>{$debiendo}</>");
        $this->line('Se ' . ($aplicar ? 'aplicó' : 'aplicaría') . ' en facturas: <options=bold>$' . number_format($aplicado, 0, ',', '.') . '</>'
            . ' · sin aplicar (sobrante o sin facturas): <options=bold>$' . number_format($sobrante, 0, ',', '.') . '</>');

        if ($aplicar) {
            \App\Services\Facturacion\LoteDePagos::cerrar($empresa, $lote, [
                'pagos' => count($pagos),
                'pagos_aplicados' => collect($reporte)->where('estado', 'aplicado')->count(),
                'aplicado' => round($aplicado, 2), 'sin_aplicar' => round($sobrante, 2),
                'por_estado' => collect($reporte)->groupBy('estado')->map(fn ($g) => ['pagos' => $g->count(), 'valor' => round($g->sum('valor'), 2)])->all(),
            ]);
        }

        $salida = 'reporte_pagos_' . $lote . ($aplicar ? '' : '_simulacion') . '.json';
        file_put_contents(storage_path('app/' . $salida), json_encode($reporte, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->line("Detalle completo: storage/app/{$salida}");

        // ── Reactivar a los que quedaron al día ─────────────────────────────
        if ($aplicar && $this->option('reactivar')) {
            $servicio = app(AutoSuspendService::class);
            $n = 0;
            foreach (array_keys($usuarios) as $uid) if ($servicio->reactivateIfClear((int) $uid, $empresa)) $n++;
            $this->line("Clientes reactivados: {$n}");
        }

        return self::SUCCESS;
    }

    private function fallar(string $mensaje): int
    {
        $this->error($mensaje);

        return self::FAILURE;
    }
}
