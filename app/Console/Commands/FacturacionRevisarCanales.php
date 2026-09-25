<?php

namespace App\Console\Commands;

use App\Models\Alerta;
use App\Models\Company;
use App\Models\CompanyBillingSchedule;
use App\Services\Facturacion\CanalDeFacturacion;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Avisa antes de facturar si las facturas no van a poder salir.
 *
 * Waonet facturó 134 clientes y no le llegó nada a nadie: no tenía por dónde
 * mandarlas. El problema no era que no se pudiera —eso puede pasar—, era que
 * se descubrió **después**, cuando ya estaban emitidas y la gente esperaba un
 * aviso que nunca llegó.
 *
 * Esto mira los días previos. Si una empresa factura pronto y hoy no tiene
 * ningún canal listo, abre un aviso; cuando lo arregla, lo cierra solo.
 */
class FacturacionRevisarCanales extends Command
{
    protected $signature = 'facturacion:revisar-canales {--dias=3 : Cuántos días antes avisar}';

    protected $description = 'Avisa si una empresa va a facturar y no tiene por dónde entregar las facturas';

    /** La clave del aviso, para no abrir uno nuevo cada vez que se revisa. */
    private const CLAVE = 'facturacion:sin-canal';

    public function handle(): int
    {
        $dias = max(0, (int) $this->option('dias'));
        $hoy = Carbon::now('America/Bogota');
        $avisados = 0;
        $cerrados = 0;

        $porEmpresa = CompanyBillingSchedule::with('company')
            ->where('active', true)
            ->get()
            ->groupBy('company_id');

        foreach ($porEmpresa as $companyId => $programaciones) {
            $empresa = $programaciones->first()->company;

            if (!$empresa || !$empresa->active) {
                continue;
            }

            $proxima = $this->proximaFecha($programaciones->all(), $hoy);
            $faltan = $proxima ? $hoy->copy()->startOfDay()->diffInDays($proxima->startOfDay(), false) : null;

            // Fuera de la ventana no se toca nada: ni se avisa ni se cierra un
            // aviso que sigue siendo cierto.
            if ($faltan === null || $faltan > $dias) {
                continue;
            }

            if (CanalDeFacturacion::puedeEntregar($empresa)) {
                $cerrados += $this->cerrar((int) $companyId);
                continue;
            }

            $this->avisar($empresa, $proxima, (int) $faltan);
            $avisados++;
        }

        $this->info("Revisado. {$avisados} empresa(s) sin canal para su próxima facturación, {$cerrados} aviso(s) cerrado(s).");

        return self::SUCCESS;
    }

    /**
     * El próximo momento en que le toca facturar.
     *
     * Cuenta la hora, no sólo el día: la de hoy a la 01:00 ya pasó y avisar de
     * ella sería avisar tarde —que es justamente lo que esto viene a evitar—.
     *
     * Un día 31 en un mes de 30 se factura el último día, igual que hace el
     * proceso automático; si no, esa empresa se saltaría el mes.
     *
     * @param  list<\App\Models\CompanyBillingSchedule>  $programaciones
     */
    private function proximaFecha(array $programaciones, Carbon $ahora): ?Carbon
    {
        $fechas = [];

        foreach ($programaciones as $p) {
            foreach ([$ahora, $ahora->copy()->addMonthNoOverflow()] as $mes) {
                $fecha = $mes->copy()
                    ->day(min((int) $p->billing_day, $mes->daysInMonth))
                    ->setTime((int) $p->billing_hour, 0);

                if ($fecha->gt($ahora)) {
                    $fechas[] = $fecha;
                }
            }
        }

        if (!$fechas) {
            return null;
        }

        usort($fechas, fn (Carbon $a, Carbon $b) => $a <=> $b);

        return $fechas[0];
    }

    private function avisar(Company $empresa, Carbon $cuando, int $faltan): void
    {
        $estado = CanalDeFacturacion::estado($empresa);

        $detalle = $faltan === 0
            ? 'Hoy le toca facturar y las facturas no van a poder salir.'
            : "Factura en {$faltan} día(s) (el {$cuando->format('d/m')}) y las facturas no van a poder salir.";

        $detalle .= "\n\n• WhatsApp: " . ($estado['whatsapp'] ?? 'listo');
        $detalle .= "\n• Correo: " . ($estado['correo'] ?? 'listo');
        $detalle .= "\n\nLas facturas se van a generar igual; lo que no va a haber es aviso al cliente.";

        $alerta = Alerta::firstOrNew(['company_id' => $empresa->id, 'clave' => self::CLAVE]);

        // Si estaba cerrado y vuelve, es un problema nuevo: fecha nueva y se
        // vuelve a avisar.
        if ($alerta->exists && $alerta->cerrada_en !== null) {
            $alerta->abierta_en = null;
            $alerta->avisada_en = null;
            $alerta->cierre_avisado_en = null;
        }

        $alerta->fill([
            'tipo'    => 'facturacion',
            'nivel'   => $faltan <= 1 ? 'critico' : 'aviso',
            'titulo'  => 'Las facturas no van a poder salir',
            'detalle' => $detalle,
            'datos'   => [
                'factura_el' => $cuando->toDateString(),
                'faltan'     => $faltan,
                'whatsapp'   => $estado['whatsapp'],
                'correo'     => $estado['correo'],
            ],
            'cerrada_en' => null,
        ]);

        $alerta->abierta_en ??= now();
        $alerta->save();

        $this->warn("{$empresa->name}: factura el {$cuando->format('d/m')} y no tiene canal.");
    }

    private function cerrar(int $companyId): int
    {
        return Alerta::where('company_id', $companyId)
            ->where('clave', self::CLAVE)
            ->whereNull('cerrada_en')
            ->update(['cerrada_en' => now()]);
    }
}
