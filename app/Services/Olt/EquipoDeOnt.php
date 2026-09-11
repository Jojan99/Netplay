<?php

namespace App\Services\Olt;

use App\Models\OltAdmin;
use App\Services\OltTelnetDispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * El equipo del cliente: qué ONT es, su WiFi y su foto.
 *
 * Fabricante, modelo y WiFi salen de la consola de la OLT (en EPON la OLT
 * gestiona la ONU por OAM y conoce su configuración). Es una consulta Telnet
 * de unos segundos, por eso se guarda diez minutos.
 *
 * La OLT informa el código interno del modelo ("25AR", "45V5"), no el nombre
 * comercial, y ningún fabricante publica un catálogo de fotos por ese código.
 * La foto la sube el operador una vez por modelo y sirve para todas las ONT
 * de ese modelo en su empresa; sin foto, la pantalla dibuja el equipo.
 */
class EquipoDeOnt
{
    private const VIGENCIA = 600;

    /** Vendor-ID de CTC → nombre del fabricante. */
    private const FABRICANTES = [
        'CDT'  => 'C-Data',  'CDTC' => 'C-Data',
        'HWTC' => 'Huawei',  'HUAW' => 'Huawei',
        'ZTEG' => 'ZTE',     'ZTE'  => 'ZTE',
        'VSOL' => 'V-SOL',
        'FHTT' => 'FiberHome',
        'ALCL' => 'Nokia',
        'TPLG' => 'TP-Link',
    ];

    /**
     * Nombres comerciales que se conocen con certeza, por fabricante y código.
     * El código de Huawei son las últimas cuatro letras del modelo.
     */
    private const COMERCIALES = [
        'HWTC:45V5' => 'EchoLife 8145V5',
    ];

    /** @return array<string,mixed> */
    public static function de(OltAdmin $olt, string $fsp, int $ontId, int $companyId, bool $refrescar = false): array
    {
        $clave = "olt:{$olt->id}:ont:{$fsp}:{$ontId}:equipo";

        $datos = $refrescar ? null : Cache::get($clave);

        if (!is_array($datos)) {
            try {
                $datos = app(OltTelnetDispatcher::class)->dispatch($olt->id, 'equipoDeOnt', [
                    'fsp' => $fsp, 'ont_id' => $ontId,
                ]) ?: [];

                $datos['leido_en'] = now()->toIso8601String();

                if (!empty($datos['version'])) {
                    Cache::put($clave, $datos, now()->addSeconds(self::VIGENCIA));
                }
            } catch (\Throwable $e) {
                Log::warning('[OLT] No se pudo leer el equipo de la ONT', [
                    'olt' => $olt->id, 'fsp' => $fsp, 'ont' => $ontId, 'error' => $e->getMessage(),
                ]);

                return ['error' => 'La OLT no respondió: ' . $e->getMessage()];
            }
        }

        if (empty($datos['version'])) {
            return ['error' => 'Esta OLT no informa el modelo ni el WiFi de sus ONT desde la consola.'];
        }

        $v = $datos['version'];
        $vendor = strtoupper((string) ($v['fabricante_id'] ?? ''));

        $v['fabricante'] = self::FABRICANTES[$vendor] ?? ($vendor ?: null);
        $v['comercial']  = self::COMERCIALES[$vendor . ':' . strtoupper((string) ($v['modelo'] ?? ''))] ?? null;

        return [
            'error'          => null,
            'version'        => $v,
            'wifi'           => $datos['wifi'] ?? null,
            'wifi_soportado' => (bool) ($datos['wifi_soportado'] ?? !empty($datos['wifi'])),
            'foto'           => self::foto($companyId, $vendor, (string) ($v['modelo'] ?? '')),
            'leido_en'       => $datos['leido_en'] ?? null,
        ];
    }

    // ── Foto por modelo ───────────────────────────────────────────────────

    public static function foto(int $companyId, string $fabricante, string $modelo): ?string
    {
        $ruta = self::rutaFoto($companyId, $fabricante, $modelo);

        return $ruta ? url('/storage/' . $ruta) . '?v=' . Storage::disk('public')->lastModified($ruta) : null;
    }

    public static function guardarFoto(int $companyId, string $fabricante, string $modelo, UploadedFile $archivo): string
    {
        self::borrarFoto($companyId, $fabricante, $modelo);

        $nombre = self::nombre($fabricante, $modelo) . '.' . strtolower($archivo->extension() ?: 'jpg');
        $ruta   = $archivo->storeAs("onts/{$companyId}", $nombre, 'public');

        return url('/storage/' . $ruta) . '?v=' . time();
    }

    public static function borrarFoto(int $companyId, string $fabricante, string $modelo): void
    {
        while ($ruta = self::rutaFoto($companyId, $fabricante, $modelo)) {
            Storage::disk('public')->delete($ruta);
        }
    }

    private static function rutaFoto(int $companyId, string $fabricante, string $modelo): ?string
    {
        $base = "onts/{$companyId}/" . self::nombre($fabricante, $modelo);

        foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
            if (Storage::disk('public')->exists("{$base}.{$ext}")) {
                return "{$base}.{$ext}";
            }
        }

        return null;
    }

    /** Sólo letras, números y guiones: es nombre de archivo. */
    private static function nombre(string $fabricante, string $modelo): string
    {
        return trim(strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', trim($fabricante) . '-' . trim($modelo))), '-') ?: 'ont';
    }
}
