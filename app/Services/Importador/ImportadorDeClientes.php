<?php

namespace App\Services\Importador;

use App\Models\Importacion;
use App\Models\ImportacionFila;
use App\Services\Importador\Fuentes\FuenteApi;
use App\Services\Importador\Fuentes\Mikrowisp;
use App\Services\Importador\Fuentes\WispHub;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * El recorrido de una importación: leer (API o archivo) → vista previa →
 * ejecutar en segundo plano.
 */
class ImportadorDeClientes
{
    public const ORIGENES = ['wisphub' => 'WispHub', 'mikrowisp' => 'Mikrowisp'];

    public static function fuente(string $origen, string $url, string $token, ?callable $transporte = null): FuenteApi
    {
        return match ($origen) {
            'wisphub'   => new WispHub($url, $token, $transporte),
            'mikrowisp' => new Mikrowisp($url, $token, $transporte),
            default     => throw new \InvalidArgumentException('Origen desconocido.'),
        };
    }

    /* ── API ─────────────────────────────────────────────────────────────── */

    /** Lee todos los clientes de la API y deja la vista previa lista. Corre en segundo plano. */
    public static function leerApi(Importacion $imp, ?callable $transporte = null): void
    {
        $fuente = self::fuente($imp->origen, (string) $imp->api_url, (string) $imp->api_token, $transporte);

        $clientes = $fuente->clientes(
            fn (string $texto) => $imp->update(['detalle' => mb_substr($texto, 0, 255)]),
            fn () => Importacion::where('id', $imp->id)->value('estado') === 'cancelando',
        );

        if (!$clientes) {
            throw new \RuntimeException('La plataforma no devolvió clientes.');
        }

        $imp->update(['detalle' => 'Revisando ' . count($clientes) . ' clientes contra los de la empresa…']);

        self::analizarYGuardar($imp, $clientes);

        // El token no queda en la importación: si la empresa quiso guardarlo,
        // está aparte y cifrado.
        $imp->update(['api_token' => null]);
    }

    /* ── Archivo ─────────────────────────────────────────────────────────── */

    public static function crearDesdeArchivo(int $companyId, ?int $userId, string $origen, UploadedFile $archivo): Importacion
    {
        $nombre = $archivo->getClientOriginalName();
        $tabla = LectorDeArchivo::leer($archivo->getRealPath(), $nombre);

        if (!$tabla['filas']) {
            throw new \RuntimeException('El archivo no tiene clientes debajo del encabezado.');
        }

        $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
        $ruta = $archivo->storeAs("importador/{$companyId}", Str::uuid() . '.' . $ext, 'local');

        return Importacion::create([
            'company_id'     => $companyId,
            'user_id'        => $userId,
            'origen'         => $origen,
            'metodo'         => 'archivo',
            'estado'         => 'mapeo',
            'archivo'        => $ruta,
            'nombre_archivo' => mb_substr($nombre, 0, 255),
            'columnas'       => $tabla['columnas'],
            'mapeo'          => Normalizador::sugerirMapeo($tabla['columnas'], $origen),
            'total'          => count($tabla['filas']),
            'detalle'        => 'Revise qué columna corresponde a cada dato.',
        ]);
    }

    /** Las primeras filas del archivo, para que el administrador reconozca las columnas. */
    public static function muestra(Importacion $imp, int $cuantas = 5): array
    {
        if (!$imp->archivo || !Storage::disk('local')->exists($imp->archivo)) {
            return [];
        }

        $tabla = LectorDeArchivo::leer(Storage::disk('local')->path($imp->archivo), (string) $imp->nombre_archivo);

        return array_slice($tabla['filas'], 0, $cuantas);
    }

    /** Lee el archivo con las columnas confirmadas y arma la vista previa. */
    public static function aplicarMapeo(Importacion $imp, array $mapeo): void
    {
        if (!$imp->archivo || !Storage::disk('local')->exists($imp->archivo)) {
            throw new \RuntimeException('El archivo ya no está en el servidor. Subilo de nuevo.');
        }

        $limpio = [];
        foreach (array_keys(Normalizador::CAMPOS) as $campo) {
            $i = $mapeo[$campo] ?? null;
            $limpio[$campo] = is_numeric($i) && (int) $i >= 0 && (int) $i < count($imp->columnas ?? []) ? (int) $i : null;
        }

        if ($limpio['dni'] === null || $limpio['nombre'] === null) {
            throw new \RuntimeException('Hace falta indicar al menos la columna del documento y la del nombre.');
        }

        // Dos datos distintos no pueden salir de la misma columna. Pasó de
        // verdad: apellidos apuntando a "Nombre" dejaba el nombre repetido, y
        // el precio apuntando a "Plan Internet" leía "80 Mb" como $80.
        if ($limpio['apellidos'] !== null && $limpio['apellidos'] === $limpio['nombre']) {
            $limpio['apellidos'] = null;
        }
        if ($limpio['plan_precio'] !== null && $limpio['plan_precio'] === $limpio['plan']) {
            $limpio['plan_precio'] = null;
        }

        $tabla = LectorDeArchivo::leer(Storage::disk('local')->path($imp->archivo), (string) $imp->nombre_archivo);
        $clientes = array_map(fn ($fila) => Normalizador::desdeFila($fila, $limpio), $tabla['filas']);

        $imp->update(['mapeo' => $limpio]);

        self::analizarYGuardar($imp, $clientes);
    }

    /* ── Vista previa ────────────────────────────────────────────────────── */

    /** @param array<int, array<string,mixed>> $clientes */
    public static function analizarYGuardar(Importacion $imp, array $clientes): void
    {
        $analisis = (new AnalisisDeImportacion((int) $imp->company_id, $imp->origen))->analizar($clientes);

        DB::transaction(function () use ($imp, $analisis) {
            ImportacionFila::where('importacion_id', $imp->id)->delete();

            $ahora = now();
            foreach (array_chunk($analisis['filas'], 200) as $lote) {
                ImportacionFila::insert(array_map(fn ($f) => [
                    'importacion_id'    => $imp->id,
                    'company_id'        => $imp->company_id,
                    'fila'              => $f['fila'],
                    'external_id'       => $f['datos']['external_id'] ?: null,
                    'dni'               => mb_substr($f['datos']['dni'], 0, 60) ?: null,
                    'nombre'            => mb_substr(trim($f['datos']['nombres'] . ' ' . $f['datos']['apellidos']), 0, 255) ?: null,
                    // Mismo formato que el cast encrypted:array del modelo.
                    'datos'             => Crypt::encryptString(json_encode($f['datos'], JSON_UNESCAPED_UNICODE)),
                    'avisos'            => json_encode(['errores' => $f['errores'], 'avisos' => $f['avisos']], JSON_UNESCAPED_UNICODE),
                    'previo'            => $f['previo'],
                    'existente_user_id' => $f['existente_user_id'],
                    'created_at'        => $ahora,
                    'updated_at'        => $ahora,
                ], $lote));
            }

            $imp->update([
                'estado'     => 'analizado',
                'analisis'   => $analisis['resumen'],
                'total'      => $analisis['resumen']['total'],
                'procesadas' => 0,
                'creados'    => 0, 'actualizados' => 0, 'omitidos' => 0, 'errores' => 0,
                'detalle'    => "Vista previa lista: {$analisis['resumen']['total']} clientes.",
            ]);
        });
    }

    /* ── Ejecución ───────────────────────────────────────────────────────── */

    public static function ejecutar(Importacion $imp): void
    {
        (new EjecutarImportacion($imp))->ejecutar();
    }

    /** Arranca el proceso que la trabaja y vuelve sin esperarlo (no hay cola de trabajos). */
    public static function lanzar(Importacion $imp): void
    {
        $php = (new PhpExecutableFinder())->find() ?: 'php';

        exec(sprintf(
            'nohup %s %s importador:trabajar %d >> %s 2>&1 &',
            escapeshellarg($php),
            escapeshellarg(base_path('artisan')),
            (int) $imp->id,
            escapeshellarg(storage_path('logs/importador.log'))
        ));
    }

    /** Un cliente de la vista previa para mostrar: sin la contraseña PPPoE. */
    public static function filaParaMostrar(ImportacionFila $f, string $regla = 'auto'): array
    {
        $d = Normalizador::conRegla($f->datos ?? [], $regla);
        $d['pppoe_clave'] = ($d['pppoe_clave'] ?? '') !== '' ? '••••••' : '';
        unset($d['avisos_origen']);

        return [
            'id'        => $f->id,
            'fila'      => $f->fila,
            'previo'    => $f->previo,
            'resultado' => $f->resultado,
            'mensaje'   => $f->mensaje,
            'user_id'   => $f->user_id ?? $f->existente_user_id,
            'factura_id' => $f->factura_id,
            'grupo_elegido'  => $f->grupo_elegido,
            'router_elegido' => $f->router_elegido,
            'errores'   => $f->avisos['errores'] ?? [],
            'avisos'    => $f->avisos['avisos'] ?? [],
            'datos'     => $d,
        ];
    }

    /**
     * Unos nombres del archivo partidos con la regla indicada, para que se vea
     * el efecto antes de importar.
     *
     * @return array<int, array<string,string>>
     */
    public static function ejemplosDeNombres(Importacion $imp, string $regla, int $cuantos = 5): array
    {
        $completos = ImportacionFila::where('importacion_id', $imp->id)
            ->whereNotNull('nombre')
            ->orderBy('id')
            ->limit(120)
            ->get()
            ->map(fn ($f) => (string) (($f->datos['nombre_completo'] ?? '') ?: $f->nombre))
            // Los más largos primero: son los que muestran mejor la diferencia.
            ->sortByDesc(fn ($n) => str_word_count($n))
            ->values()
            ->all();

        return SeparadorDeNombres::ejemplos($completos, $regla, $cuantos);
    }
}
