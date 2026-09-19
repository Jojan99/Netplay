<?php

namespace App\Console\Commands;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use App\Models\OltAdmin;
use App\Models\OltOnt;
use App\Services\Red\GestionRemotaDeOnt;
use App\Services\Red\TareaDetenida;
use App\Services\Red\TareasDeGestion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Trabaja una tarea del acceso remoto en segundo plano.
 *
 * La lanza la petición web y termina sola. Lo que va pasando queda en la
 * tarea, que es lo que consulta la pantalla.
 */
class GestionTarea extends Command
{
    protected $signature = 'gestion:tarea {id}';

    protected $description = 'Trabaja en segundo plano una tarea del acceso remoto a los equipos';

    public function handle(): int
    {
        set_time_limit(0);

        $id = (string) $this->argument('id');
        $tarea = TareasDeGestion::ver($id);

        if (!$tarea) {
            $this->error("No existe la tarea {$id}");

            return self::FAILURE;
        }

        $servicio = new GestionRemotaDeOnt((int) $tarea['company_id'], app(ConectionRouterManagerInterface::class));
        $d = $tarea['datos'];

        // Pedida parar antes de arrancar (el proceso tarda un momento en
        // levantar): no se hace nada.
        if (TareasDeGestion::debeParar($id)) {
            TareasDeGestion::actualizar($id, ['estado' => 'detenida', 'detalle' => 'Detenida por el usuario antes de empezar: no se tocó nada.']);

            return self::SUCCESS;
        }

        // Lo que se ve en la ventana de tareas mientras corre.
        $paso = fn (string $texto) => TareasDeGestion::actualizar($id, ['detalle' => $texto]);

        // Dar acceso: se puede parar mientras no se le haya mandado nada a la
        // OLT (buscar en el ACS, revisar el equipo, esperar que se registre).
        // Una vez que empezó a escribirle, termina: nunca se corta una sesión
        // de la OLT a mitad de una escritura.
        $tocoLaOlt = false;
        $pasoDeAcceso = function (string $texto) use ($id, $paso, &$tocoLaOlt) {
            $esperandoRegistro = str_starts_with($texto, 'La OLT todavía la está registrando');

            if ((!$tocoLaOlt || $esperandoRegistro) && TareasDeGestion::debeParar($id)) {
                throw new TareaDetenida();
            }

            if (str_starts_with($texto, 'Configurando el acceso en la OLT')) {
                $tocoLaOlt = true;
            }

            $paso($texto);
        };

        $paso(match ($tarea['tipo']) {
            'dar_acceso'      => 'Revisando las conexiones del equipo…',
            'preparar_perfil' => 'Agregando la gestión al perfil en la OLT…',
            'reiniciar'       => 'Pidiéndole a la OLT que reinicie el equipo…',
            'al_dia'          => 'Buscando los equipos que faltan…',
            default           => 'Trabajando…',
        });

        try {
            match ($tarea['tipo']) {
                'dar_acceso'      => $this->terminar($id, $servicio->darAcceso((int) $d['olt_id'], (string) $d['fsp'], (int) $d['ont_id'], (bool) ($d['reiniciar'] ?? false), $pasoDeAcceso, (bool) ($d['limpiar'] ?? false))),
                'preparar_perfil' => $this->terminar($id, $servicio->prepararPerfil((int) $d['olt_id'], (int) $d['perfil'])),
                'reiniciar'       => $this->terminar($id, $servicio->reiniciarEquipo((int) $d['olt_id'], (string) $d['fsp'], (int) $d['ont_id'])),
                'al_dia'          => $this->alDia($id, (int) $tarea['company_id'], $servicio, isset($d['olt_id']) ? (int) $d['olt_id'] : null),
                default           => throw new \RuntimeException("Tipo de tarea desconocido: {$tarea['tipo']}"),
            };
        } catch (TareaDetenida) {
            TareasDeGestion::actualizar($id, [
                'estado'  => 'detenida',
                'detalle' => 'Detenida por el usuario antes de mandarle comandos a la OLT: no se tocó nada.',
            ]);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('[Gestión] La tarea falló', ['tarea' => $id, 'tipo' => $tarea['tipo'], 'error' => $e->getMessage()]);

            TareasDeGestion::actualizar($id, [
                'estado'  => 'error',
                'detalle' => \App\Services\Olt\EstadoDeUnaOnt::explicar($e->getMessage()),
            ]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @param array<string,mixed> $r */
    private function terminar(string $id, array $r): void
    {
        TareasDeGestion::actualizar($id, [
            // "no_aplica": el equipo no se puede configurar desde la OLT. Es un
            // final, con el motivo y qué hacer; no un error para reintentar.
            'estado'    => ($r['ok'] ?? false) ? 'listo' : (($r['no_aplica'] ?? false) ? 'no_aplica' : 'error'),
            'motivo'    => $r['motivo'] ?? null,
            'que_hacer' => $r['que_hacer'] ?? null,
            'detalle'   => $r['detalle'] ?? '',
            'resultado' => $r,
        ]);
    }

    /**
     * Les da el acceso a los equipos que no lo tienen, de a uno, hasta que no
     * quede ninguno o el operador lo pare. Cada equipo que falla se saltea
     * para no reintentarlo en bucle.
     */
    private function alDia(string $id, int $companyId, GestionRemotaDeOnt $servicio, ?int $oltId): void
    {
        $olts = OltAdmin::where('company_id', $companyId)->get(['id', 'brand'])
            ->filter(fn ($o) => GestionRemotaDeOnt::admiteGestion((string) $o->brand))
            ->pluck('id')->all();

        $saltear = [];
        $hechas = [];
        $bien = 0;
        $aMano = 0;

        while (true) {
            if (TareasDeGestion::debeParar($id)) {
                TareasDeGestion::actualizar($id, ['estado' => 'detenida', 'detalle' => "Detenida por el usuario: {$bien} equipos con acceso; el que estaba en curso se terminó."]);

                return;
            }

            $pendientes = OltOnt::whereIn('olt_id', $olts)
                ->whereNull('gestion_en')
                ->whereNotIn('id', $saltear ?: [0])
                ->when($oltId, fn ($q) => $q->where('olt_id', $oltId))
                ->orderBy('id');

            $quedan = (clone $pendientes)->count();
            $ont = $pendientes->first();

            if (!$ont) {
                TareasDeGestion::actualizar($id, [
                    'estado'  => 'listo',
                    'detalle' => $saltear
                        ? "Terminado: {$bien} con acceso"
                            . ($aMano ? ", {$aMano} hay que configurarlos en el equipo (la OLT no puede)" : '')
                            . (count($saltear) - $aMano ? ', ' . (count($saltear) - $aMano) . ' no se pudieron (se pueden reintentar)' : '') . '.'
                        : "Terminado: {$bien} equipos con acceso.",
                    'resultado' => ['hechas' => $hechas, 'quedan' => 0, 'fallaron' => count($saltear)],
                ]);

                return;
            }

            TareasDeGestion::actualizar($id, [
                'detalle'   => "Trabajando en {$ont->description} ({$ont->fsp}:{$ont->ont_id}) · quedan {$quedan}",
                'resultado' => ['hechas' => $hechas, 'quedan' => $quedan, 'fallaron' => count($saltear)],
            ]);

            $r = $servicio->darAcceso((int) $ont->olt_id, (string) $ont->fsp, (int) $ont->ont_id);

            // Apagado no es algo que se arregle reintentando equipo por equipo.
            if (!$r['ok'] && str_contains((string) $r['detalle'], 'no está activado')) {
                TareasDeGestion::actualizar($id, ['estado' => 'error', 'detalle' => $r['detalle']]);

                return;
            }

            $r['ok'] ? $bien++ : $saltear[] = $ont->id;

            if (!$r['ok'] && ($r['no_aplica'] ?? false)) {
                $aMano++;
            }

            array_unshift($hechas, [
                'ont'       => "{$ont->fsp}:{$ont->ont_id}",
                'nombre'    => $ont->description,
                'ok'        => (bool) $r['ok'],
                'no_aplica' => (bool) ($r['no_aplica'] ?? false),
                'detalle'   => $r['detalle'],
            ]);

            $hechas = array_slice($hechas, 0, 200);
        }
    }
}
