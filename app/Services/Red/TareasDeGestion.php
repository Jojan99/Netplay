<?php

namespace App\Services\Red;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Los trabajos largos del acceso remoto, fuera de la petición web.
 *
 * Darle acceso a un equipo son una veintena de comandos contra la OLT y, con
 * el túnel cortándose, pasaban de los 120 s que espera nginx: el operador veía
 * un 504 aunque el trabajo siguiera. Ahora la petición crea la tarea, la lanza
 * en un proceso aparte y contesta enseguida; la pantalla pregunta cómo va.
 *
 * No hay cola de trabajos en el servidor, así que se lanza igual que los
 * workers de OLT: un proceso de consola en segundo plano.
 */
class TareasDeGestion
{
    /**
     * En Redis y no en el caché de archivos: la tarea la escribe la petición
     * web y la trabaja otro proceso, y los archivos del caché quedaban con
     * permisos que un proceso lanzado por otro usuario no podía leer.
     */
    private static function cache(): \Illuminate\Contracts\Cache\Repository
    {
        return Cache::store('redis');
    }

    /** Lo que dura guardada una tarea, terminada o no. */
    private const VIGENCIA_HORAS = 24;

    /** @param array<string,mixed> $datos */
    public static function crear(int $companyId, string $tipo, array $datos = []): string
    {
        $id = (string) Str::uuid();

        self::cache()->put(self::clave($id), [
            'id'         => $id,
            'company_id' => $companyId,
            'tipo'       => $tipo,
            'datos'      => $datos,
            'estado'     => 'en_curso',
            'detalle'    => 'En cola',
            'resultado'  => null,
            'creada_en'  => now()->toIso8601String(),
            'actualizada_en' => now()->toIso8601String(),
        ], now()->addHours(self::VIGENCIA_HORAS));

        return $id;
    }

    /** Arranca el proceso que la trabaja y vuelve sin esperarlo. */
    public static function lanzar(string $id): void
    {
        $php = (new PhpExecutableFinder())->find() ?: 'php';

        exec(sprintf(
            'nohup %s %s gestion:tarea %s >> %s 2>&1 &',
            escapeshellarg($php),
            escapeshellarg(base_path('artisan')),
            escapeshellarg($id),
            escapeshellarg(storage_path('logs/gestion-tareas.log'))
        ));
    }

    /** @return array<string,mixed>|null */
    public static function ver(string $id): ?array
    {
        $t = self::cache()->get(self::clave($id));

        return is_array($t) ? $t : null;
    }

    /** @param array<string,mixed> $cambios */
    public static function actualizar(string $id, array $cambios): void
    {
        $t = self::ver($id);

        if (!$t) {
            return;
        }

        self::cache()->put(self::clave($id), array_merge($t, $cambios, ['actualizada_en' => now()->toIso8601String()]), now()->addHours(self::VIGENCIA_HORAS));
    }

    public static function pedirParar(string $id): void
    {
        self::cache()->put(self::clave($id) . ':parar', true, now()->addHours(self::VIGENCIA_HORAS));
    }

    public static function debeParar(string $id): bool
    {
        return (bool) self::cache()->get(self::clave($id) . ':parar');
    }

    /**
     * La tarea de ese tipo que ya está corriendo para la empresa, si hay.
     * Dos puestas al día a la vez se pisarían los mismos equipos.
     */
    public static function enCurso(int $companyId, string $tipo): ?string
    {
        $id = self::cache()->get("gestion:tarea-activa:{$companyId}:{$tipo}");
        $t  = $id ? self::ver($id) : null;

        return $t && $t['estado'] === 'en_curso' ? $id : null;
    }

    public static function marcarActiva(int $companyId, string $tipo, string $id): void
    {
        self::cache()->put("gestion:tarea-activa:{$companyId}:{$tipo}", $id, now()->addHours(self::VIGENCIA_HORAS));
    }

    private static function clave(string $id): string
    {
        return "gestion:tarea:{$id}";
    }
}
