<?php

namespace App\Services\Red;

use App\Models\OltAdmin;
use App\OltDrivers\CdataOltDriver;
use App\Services\Olt\EstadoDeUnaOnt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * ¿La plataforma puede darle la gestión (TR-069) a esta ONT en esta OLT?
 *
 * Es el único lugar que lo decide, antes de intentar nada. Antes el panel se
 * quedaba "Esperando que el equipo aparezca en el TR-069…" 90 minutos con
 * equipos que nunca iban a aparecer: YARITZA_TERAN_REALES (0/0/1:11) es un
 * Huawei HG8145V5 en la OLT C-Data EPON, y la conexión de gestión que crea la
 * OLT C-Data (EPON: `ont wan config`, GPON: mult-srv-profile con perfiles
 * tr069/wan) va por una extensión propia de C-Data que los equipos de otras
 * marcas ignoran. Confirmado con el fabricante para Huawei en EPON, y visto en
 * la OLT Feria (GPON) con un ZTE F670L y un Mitrastar GPT-2742: toman la URL
 * del ACS pero nunca crean la conexión.
 *
 * Contesta:
 *   puede      'si' | 'no' | 'no_se_sabe'
 *   motivo     en palabras de operador
 *   que_hacer  qué hacer en vez de esperar (null si no hace falta)
 *
 * Con "no" no se intenta ni se espera. Con "no se sabe" se intenta igual pero
 * la espera es corta. Con "sí" todo sigue como siempre.
 *
 * La marca del equipo sale, en este orden, de:
 *   1. lo que la ONT le dijo a la OLT, por SNMP (EPON: vendor ID de CTC en la
 *      columna 25 de la MIB NSCRTV — "HWTC", "CDT", "TDTC"—; GPON C-Data: la
 *      columna 5 de su MIB), y el firmware (los Huawei numeran "V5R019C10S350");
 *   2. el prefijo del serial GPON (HWTC, CDTC, ZTEG, MSTC…);
 *   3. el OUI de la MAC en EPON (la ONT se identifica por MAC).
 */
final class CompatibilidadDeOnt
{
    public const SI = 'si';
    public const NO = 'no';
    public const NO_SE_SABE = 'no_se_sabe';

    // ── Lo que se sabe de cada fabricante ─────────────────────────────────
    //
    // Para sumar una marca: su prefijo GPON / vendor ID EPON en PREFIJOS, sus
    // OUI (si vienen en EPON) en OUIS y cómo se dice en NOMBRES.

    /** Vendor ID (4 letras del serial GPON o el que la ONU EPON le informa a la OLT) → marca. */
    private const PREFIJOS = [
        'HWTC' => 'huawei', 'HUAW' => 'huawei',
        'CDTC' => 'cdata',  'CDT'  => 'cdata',
        'ZTEG' => 'zte',    'ZXIC' => 'zte',
        'MSTC' => 'mitrastar',             // GPT-2741GNAC / GPT-2742GX4X5V6 (OLT Feria)
        'VSOL' => 'vsol',
        'SDMC' => 'sdmc',                  // NP2035G (Feria), YEILER_CARRILLO_GARCIA (OLT Huawei)
        'OEMT' => 'oemt',                  // ONU genérica de varios revendedores
        'SMBS' => 'sagemcom',              // Fast5670: reporta al ACS como SagemCom / OUI CC00F1
        'FHTT' => 'fiberhome',
        'SKYW' => 'skyworth',              // GN543V (Feria)
        'ASKY' => 'askey',
        'TDTC' => 'tenda',                 // ONU1FE1GE, MAC C8:3A:35 (OLT CDATA)
        'ALCL' => 'nokia',
    ];

    /**
     * OUI (3 primeros bytes de la MAC) → marca, para EPON cuando la OLT no
     * contesta por SNMP. Todos vistos en la OLT CDATA con su modelo y firmware
     * leídos por SNMP (Huawei: modelo 45V5 = HG8145V5, firmware V5R0xx…;
     * C-Data: 15BR/25AR/45AR, firmware V2.x/V3.x, vendor ID "CDT").
     */
    private const OUIS = [
        // Huawei (HG8145V5)
        'CCB182' => 'huawei', 'B85FB0' => 'huawei', '2811EC' => 'huawei', '44004D' => 'huawei',
        '485702' => 'huawei', '5C647A' => 'huawei', '60D755' => 'huawei', '70FD45' => 'huawei',
        '80E1BF' => 'huawei', '90173F' => 'huawei', '94B271' => 'huawei', 'A416E7' => 'huawei',
        'A8494D' => 'huawei', 'C0FFA8' => 'huawei', 'C4447D' => 'huawei', 'D0C65B' => 'huawei',
        'D44F67' => 'huawei', 'E43EC6' => 'huawei', 'ECC01B' => 'huawei', 'FCBCD1' => 'huawei',
        '00259E' => 'huawei',
        // C-Data (FD5xx). 80:F7:A6 es el FD511GW que anduvo de punta a punta.
        '80F7A6' => 'cdata', '70A56A' => 'cdata', 'E0E8E6' => 'cdata',
        // Tenda
        'C83A35' => 'tenda',
    ];

    private const NOMBRES = [
        'huawei' => 'Huawei', 'cdata' => 'C-Data', 'zte' => 'ZTE', 'mitrastar' => 'Mitrastar',
        'vsol' => 'V-SOL', 'sdmc' => 'SDMC', 'oemt' => 'OEM genérico', 'sagemcom' => 'Sagemcom',
        'fiberhome' => 'FiberHome', 'skyworth' => 'Skyworth', 'askey' => 'Askey', 'tenda' => 'Tenda',
        'nokia' => 'Nokia',
    ];

    /**
     * Marcas que ya se probaron en OLT C-Data y no crean la conexión de
     * gestión que manda la OLT. Las demás que no son C-Data quedan en "no se
     * sabe" hasta probarlas.
     */
    private const NO_TOMAN_LA_WAN_DE_CDATA = ['huawei', 'zte', 'mitrastar'];

    /** Los C-Data GPON también usan vendor ID "DC80", "DC90", "DF1D"… (ERICK_ZAPATA, DC90). */
    private const CDATA_OTROS_PREFIJOS = '/^D[CF][0-9A-F]{2}$/';

    // ── La decisión ───────────────────────────────────────────────────────

    /**
     * @param bool $leerOlt si se le pregunta a la OLT por SNMP (modelo, firmware,
     *                      vendor ID y si está en línea). Sin eso decide con el
     *                      serial / la MAC: es instantáneo y sirve en el alta.
     *
     * @return array{puede:string, motivo:string, que_hacer:?string, marca:?string, equipo:string, olt:string, por:string, con_olt:bool, en_linea:?bool, potencia:?float}
     */
    public static function evaluar(OltAdmin $olt, string $fsp, int $ontId, ?string $serial, bool $leerOlt = true): array
    {
        $marcaOlt = strtolower((string) $olt->brand);
        $serial = trim((string) $serial);
        $esMac = self::oui($serial) !== null;
        $tecnologia = $marcaOlt === 'cdata' ? self::tecnologiaCdata($olt, $esMac) : ($marcaOlt === 'huawei' ? 'gpon' : null);
        $nombreOlt = trim((self::NOMBRES[$marcaOlt] ?? strtoupper($marcaOlt)) . ' ' . ($tecnologia && $tecnologia !== 'desconocida' ? strtoupper($tecnologia) : ''));

        // Lo que dice la OLT de la ONT. En Huawei el serial GPON ya dice la
        // marca: no hace falta gastar una consulta.
        $vivo = null;

        if ($leerOlt && $marcaOlt === 'cdata') {
            try {
                $vivo = EstadoDeUnaOnt::de($olt, $fsp, $ontId);

                // La fila de la medición de toda la OLT no trae ni modelo ni fabricante.
                if (empty($vivo['modelo']) && empty($vivo['fabricante']) && !empty($vivo['desde_cache'])) {
                    $vivo = EstadoDeUnaOnt::de($olt, $fsp, $ontId, true);
                }
            } catch (\Throwable $e) {
                Log::info('[Compatibilidad] No se pudo leer la ONT por SNMP', ['olt' => $olt->id, 'fsp' => $fsp, 'ont' => $ontId, 'error' => $e->getMessage()]);
            }
        }

        [$marca, $por] = self::marca($serial, $vivo);
        $nombre = $marca ? self::nombreDeMarca($marca) : null;
        $modelo = $vivo['modelo'] ?? null;
        $equipo = trim(($nombre ?? 'de marca desconocida') . ($modelo ? " {$modelo}" : '')
            . (!empty($vivo['firmware']) ? " ({$vivo['firmware']})" : ''));
        $identificado = $esMac ? 'MAC ' . implode(':', str_split((string) self::oui($serial), 2)) . '…' : ($serial !== '' ? "serial {$serial}" : 'sin serial');

        $base = [
            'marca'    => $marca,
            'equipo'   => $equipo,
            'olt'      => $nombreOlt,
            'por'      => $por,
            'con_olt'  => $vivo !== null && empty($vivo['error']),
            'en_linea' => isset($vivo['status']) && empty($vivo['error']) ? $vivo['status'] === 'online' : null,
            'potencia' => $vivo['potencia'] ?? null,
        ];
        $resultado = fn (string $puede, string $motivo, ?string $queHacer) => ['puede' => $puede, 'motivo' => $motivo, 'que_hacer' => $queHacer] + $base;

        // ── OLT Huawei ──
        if ($marcaOlt === 'huawei') {
            return match ($marca) {
                'huawei' => $resultado(self::SI, 'Equipo Huawei en OLT Huawei: la OLT le manda el servidor TR-069 directo.', null),
                // servidorTr069PorOmci(): probado con C-Data (CARMEN se registró a los 2 min del reinicio).
                'cdata'  => $resultado(self::SI, 'Equipo C-Data en OLT Huawei: toma el servidor TR-069 que le manda la OLT al reiniciarse.', null),
                default  => $resultado(self::NO_SE_SABE,
                    "Equipo {$equipo} en OLT Huawei: la OLT le manda el servidor TR-069, pero con esta marca no está probado que lo tome.",
                    self::queHacerSiNoAparece()),
            };
        }

        // ── OLT de una marca en la que la plataforma no da la gestión ──
        if ($marcaOlt !== 'cdata') {
            return $resultado(self::NO,
                "La plataforma todavía no le da la gestión a los equipos en OLT {$nombreOlt}.",
                self::queHacerAMano());
        }

        // ── OLT C-Data (EPON o GPON) ──
        $sinWan = self::yaSeProboSinWan($olt, $serial, $modelo);

        if ($marca === 'cdata') {
            return $sinWan
                ? $resultado(self::NO, "Es un equipo {$equipo} en una OLT {$nombreOlt}, pero {$sinWan}.", self::queHacerAMano())
                : $resultado(self::SI, "Equipo C-Data en OLT {$nombreOlt}: la OLT le crea la conexión de gestión.", null);
        }

        if ($marca && in_array($marca, self::NO_TOMAN_LA_WAN_DE_CDATA, true)) {
            return $resultado(self::NO,
                "Es un equipo {$equipo} en una OLT {$nombreOlt}: la OLT no puede crearle la conexión de gestión (esa orden sólo la entienden los equipos C-Data).",
                self::queHacerAMano());
        }

        if ($sinWan) {
            return $resultado(self::NO, "Es un equipo {$equipo} ({$identificado}) y {$sinWan}.", self::queHacerAMano());
        }

        return $resultado(self::NO_SE_SABE,
            $marca
                ? "Equipo {$equipo} en OLT {$nombreOlt}: con esta marca no está probado que tome la conexión de gestión que crea la OLT."
                : "No se reconoce la marca del equipo ({$identificado}"
                    . (($vivo['fabricante'] ?? null) ? ", se presenta como «{$vivo['fabricante']}»" : '')
                    . ($modelo ? ", modelo {$modelo}" : '')
                    . "): no se sabe si toma la conexión de gestión que crea la OLT {$nombreOlt}.",
            self::queHacerSiNoAparece());
    }

    // ── Marca del equipo ──────────────────────────────────────────────────

    /**
     * La marca por lo que la ONT le informó a la OLT y, si no hay, por el serial.
     *
     * @return array{0:?string, 1:string} marca y de dónde salió
     */
    private static function marca(string $serial, ?array $vivo): array
    {
        $fabricante = strtoupper(trim((string) ($vivo['fabricante'] ?? '')));
        $firmware = (string) ($vivo['firmware'] ?? '');
        $firmwareHuawei = (bool) preg_match('/^V\dR\d{3}C\d{2}/i', $firmware);

        if ($fabricante !== '' && ($m = self::marcaPorPrefijo($fabricante))) {
            // Una ONU genérica con chip Realtek se presentaba como "HWTC" con
            // modelo "EPON" y firmware V1.9.0 (0/0/1:28): no es un Huawei.
            if ($m === 'huawei' && $firmware !== '' && !$firmwareHuawei) {
                return [null, 'snmp'];
            }

            return [$m, 'snmp'];
        }

        if ($firmwareHuawei) {
            return ['huawei', 'firmware'];
        }

        if ($m = self::marcaPorSerial($serial)) {
            return [$m, self::oui($serial) ? 'oui' : 'serial'];
        }

        return [null, 'desconocida'];
    }

    /** La marca por el serial GPON (prefijo) o la MAC EPON (OUI); null si no se conoce. */
    public static function marcaPorSerial(string $serial): ?string
    {
        if ($oui = self::oui($serial)) {
            return self::OUIS[$oui] ?? null;
        }

        return self::marcaPorPrefijo(self::prefijo($serial));
    }

    /** "HWTC", "CDT", "DC90"… → marca, o null si no está en la tabla. */
    public static function marcaPorPrefijo(string $vendor): ?string
    {
        $vendor = strtoupper(trim(str_replace("\0", '', $vendor)));

        if (preg_match(self::CDATA_OTROS_PREFIJOS, $vendor)) {
            return 'cdata';
        }

        return self::PREFIJOS[$vendor] ?? null;
    }

    /** Las 4 letras del fabricante de un serial GPON, esté en hexadecimal (48575443…) o no. */
    public static function prefijo(string $serial): string
    {
        $s = strtoupper(trim($serial));

        return ctype_xdigit(substr($s, 0, 8)) && strlen($s) >= 16 ? (string) @hex2bin(substr($s, 0, 8)) : substr($s, 0, 4);
    }

    /** Los 3 primeros bytes de una MAC ("CCB182"), o null si el serial no es una MAC. */
    public static function oui(?string $serial): ?string
    {
        $s = trim((string) $serial);

        if (!preg_match('/^[0-9A-Fa-f]{2}([:\-.]?[0-9A-Fa-f]{2}){5}$/', $s) || !preg_match('/[:\-.]/', $s)) {
            return null;
        }

        return strtoupper(substr((string) preg_replace('/[^0-9A-Fa-f]/', '', $s), 0, 6));
    }

    public static function nombreDeMarca(string $marca): string
    {
        return self::NOMBRES[$marca] ?? (strtoupper($marca) ?: 'de otra marca');
    }

    // ── Lo que ya se probó ────────────────────────────────────────────────

    /**
     * Si un equipo igual ya no aceptó la conexión de gestión en una OLT C-Data
     * (lo anota el driver al probar): por fabricante (OUI) en EPON, por modelo
     * en GPON.
     */
    private static function yaSeProboSinWan(OltAdmin $olt, string $serial, ?string $modelo): ?string
    {
        $oui = self::oui($serial);

        if ($oui && Cache::has(CdataOltDriver::claveSinWanRemota($olt->company_id, $oui))) {
            return 'ya se probó con otro equipo de ese fabricante en esta empresa y no aceptó la conexión de gestión';
        }

        if ($modelo && Cache::has(CdataOltDriver::claveModeloSinWan($modelo))) {
            return "el modelo {$modelo} ya se probó y no acepta la conexión de gestión de la OLT";
        }

        return null;
    }

    /**
     * EPON o GPON, sin gastar una sesión de la OLT: lo que se guardó al leer sus
     * capacidades y, si no hay, el serial (en EPON la ONT es una MAC) o el modelo.
     */
    private static function tecnologiaCdata(OltAdmin $olt, bool $esMac): string
    {
        $guardada = Cache::get("olt:{$olt->id}:capacidades:v2")['tecnologia'] ?? null;

        if (in_array($guardada, ['epon', 'gpon'], true)) {
            return $guardada;
        }

        if ($esMac || stripos((string) $olt->model, 'EPON') !== false) {
            return 'epon';
        }

        // Un serial GPON (FD16xx, "Feria") sólo puede estar en una GPON.
        return 'gpon';
    }

    // ── Qué hacer ─────────────────────────────────────────────────────────

    public static function queHacerAMano(): string
    {
        return 'Activá el TR-069 en la página del equipo (conexión de internet → TR-069, dirección del servidor '
            . config('services.genieacs.url_equipos') . ', aviso periódico cada 300 s) y el aprovisionamiento sigue solo en cuanto se reporte.';
    }

    public static function queHacerSiNoAparece(): string
    {
        return 'Se intenta igual. Si en 15 minutos no aparece en el TR-069, activá el TR-069 en la página del equipo (dirección del servidor '
            . config('services.genieacs.url_equipos') . ') y el aprovisionamiento sigue solo.';
    }
}
