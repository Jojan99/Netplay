<?php

namespace App\Services\Olt;

use App\Services\HuaweiSnmpReader;

/**
 * ONU y señal óptica de las OLT EPON que usan la MIB NSCRTV.
 *
 * Es la MIB EPON estándar de la industria china (NSCRTV-EPONEOC-EPON-MIB, bajo
 * el número de empresa 17409). La usan C-Data en su línea EPON —las
 * "EasyPath Ethernet-PON"— y otros fabricantes que comparten ese firmware, así
 * que el lector se elige por la presencia de la tabla y no por la marca.
 *
 * Todo lo de acá está verificado contra una C-Data EPON real con 64 ONU:
 *
 *   17409.2.3.4.1.1.<col>.<ifIndex>        tabla de ONU
 *       7   MAC (6 octetos): en EPON la ONU se identifica por MAC, no por serial
 *       2   nombre
 *       8   estado operativo (1 = en servicio)
 *      13   versión de firmware
 *      15   distancia en metros
 *      26   modelo
 *
 *   17409.2.3.4.2.1.<col>.<ifIndex>.0.0    tabla óptica
 *       4   potencia recibida    ×0,01 dBm
 *       5   potencia transmitida ×0,01 dBm
 *       6   corriente de bias    ×0,01 mA
 *       7   voltaje              ×0,00001 V
 *       8   temperatura          ×0,01 °C
 *
 * Las escalas son otras que las de Huawei (allá el voltaje va ×0,001 y la
 * temperatura en grados enteros), por eso no alcanza con el clasificador por
 * rangos que se usa para Huawei.
 *
 * El índice es el ifIndex de la ONU, que IF-MIB nombra "pon0/0/1:5": de ahí
 * salen el puerto y el número de ONU sin tener que decodificar el entero.
 */
class SnmpEponNscrtv
{
    private const ONU   = '1.3.6.1.4.1.17409.2.3.4.1.1';
    private const OPT   = '1.3.6.1.4.1.17409.2.3.4.2.1';
    private const IF_NOMBRE = '1.3.6.1.2.1.31.1.1.1.1';
    private const IF_ESTADO = '1.3.6.1.2.1.2.2.1.8';

    public function __construct(private HuaweiSnmpReader $snmp) {}

    /** ¿La OLT publica la tabla de ONU de esta MIB? */
    public function esCompatible(): bool
    {
        try {
            return $this->snmp->walkRaw(self::ONU . '.1') !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Las ONU, con la misma forma que devuelve el lector de Huawei para que
     * el resto de la plataforma no tenga que distinguir.
     *
     * @return list<array<string,mixed>>
     */
    public function onts(): array
    {
        $ubicacion = $this->ubicaciones();

        if ($ubicacion === []) {
            return [];
        }

        $mac       = $this->columna(self::ONU . '.7');
        $nombre    = $this->columna(self::ONU . '.2');
        $firmware  = $this->columna(self::ONU . '.13');
        $distancia = $this->columna(self::ONU . '.15');
        $modelo    = $this->columna(self::ONU . '.26');
        $estado    = $this->estadosIfMib();

        $onts = [];

        foreach ($ubicacion as $ifIndex => [$fsp, $ontId]) {
            $onts[] = [
                'fsp'         => $fsp,
                'ont_id'      => $ontId,
                // El resto de la plataforma llama "serial" a lo que identifica
                // a la ONU; en EPON eso es la MAC.
                'serial'      => self::mac($mac[$ifIndex] ?? null),
                'description' => self::texto($nombre[$ifIndex] ?? null),
                'status'      => ($estado[$ifIndex] ?? 1) === 1 ? 'online' : 'offline',
                'modelo'      => self::texto($modelo[$ifIndex] ?? null),
                'firmware'    => self::texto($firmware[$ifIndex] ?? null),
                'distancia_m' => self::entero($distancia[$ifIndex] ?? null),
            ];
        }

        usort($onts, fn ($a, $b) => strnatcmp($a['fsp'], $b['fsp']) ?: $a['ont_id'] <=> $b['ont_id']);

        return $onts;
    }

    /**
     * La medición óptica de todas las ONU, con la forma de
     * HuaweiSnmpReader::senalDeTodasLasOnts().
     *
     * @return list<array<string,mixed>>
     */
    public function senal(): array
    {
        $ubicacion = $this->ubicaciones();

        if ($ubicacion === []) {
            return [];
        }

        $rx   = $this->columna(self::OPT . '.4');
        $tx   = $this->columna(self::OPT . '.5');
        $bias = $this->columna(self::OPT . '.6');
        $volt = $this->columna(self::OPT . '.7');
        $temp = $this->columna(self::OPT . '.8');

        $filas = [];

        foreach ($ubicacion as $ifIndex => [$fsp, $ontId]) {
            $filas[] = [
                'fsp'         => $fsp,
                'ont_id'      => $ontId,
                'potencia'    => self::escalar($rx[$ifIndex] ?? null, 100),
                'tx'          => self::escalar($tx[$ifIndex] ?? null, 100),
                'corriente'   => self::escalar($bias[$ifIndex] ?? null, 100),
                'voltaje'     => self::escalar($volt[$ifIndex] ?? null, 100000),
                'temperatura' => self::escalar($temp[$ifIndex] ?? null, 100),
            ];
        }

        usort($filas, fn ($a, $b) => strnatcmp($a['fsp'], $b['fsp']) ?: $a['ont_id'] <=> $b['ont_id']);

        return $filas;
    }

    // ── Lectura ───────────────────────────────────────────────────────────

    /**
     * ifIndex de cada ONU → [fsp, número de ONU], a partir de su nombre en
     * IF-MIB ("pon0/0/1:5" → ["0/0/1", 5]).
     *
     * @return array<int, array{0:string, 1:int}>
     */
    private function ubicaciones(): array
    {
        $ubicaciones = [];

        foreach ($this->snmp->walkRaw(self::IF_NOMBRE) as $sufijo => $valor) {
            $nombre = self::texto($valor) ?? '';

            if (!preg_match('#(\d+/\d+/\d+):(\d+)\s*$#', $nombre, $m)) {
                continue;
            }

            $ubicaciones[self::indice((string) $sufijo)] = [$m[1], (int) $m[2]];
        }

        return $ubicaciones;
    }

    /** @return array<int,int> ifIndex → 1 (arriba) / 2 (abajo) */
    private function estadosIfMib(): array
    {
        $estados = [];

        foreach ($this->snmp->walkRaw(self::IF_ESTADO) as $sufijo => $valor) {
            $estados[self::indice((string) $sufijo)] = (int) filter_var((string) $valor, FILTER_SANITIZE_NUMBER_INT);
        }

        return $estados;
    }

    /**
     * Una columna como [ifIndex => valor crudo].
     *
     * La tabla óptica agrega ".0.0" después del ifIndex, así que el índice se
     * toma del primer componente y no del último: tomando el último, las 64
     * filas colapsaban en una sola.
     *
     * @return array<int,string>
     */
    private function columna(string $oid): array
    {
        $valores = [];

        foreach ($this->snmp->walkRaw($oid) as $sufijo => $valor) {
            $valores[self::indice((string) $sufijo)] = (string) $valor;
        }

        return $valores;
    }

    private static function indice(string $sufijo): int
    {
        return (int) explode('.', trim($sufijo, '.'))[0];
    }

    // ── Conversión ────────────────────────────────────────────────────────

    /** "Hex-STRING: E0 E8 E6 C8 CA 4A" → "E0:E8:E6:C8:CA:4A" */
    private static function mac(?string $crudo): ?string
    {
        if ($crudo === null) {
            return null;
        }

        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', preg_replace('/^\s*Hex-STRING:\s*/i', '', $crudo)));

        if (strlen($hex) !== 12) {
            return null;
        }

        return implode(':', str_split($hex, 2));
    }

    /** 'STRING: "V2.1.13"' o '""' → "V2.1.13" / null */
    private static function texto(?string $crudo): ?string
    {
        if ($crudo === null) {
            return null;
        }

        $texto = preg_replace('/^\s*(STRING|Hex-STRING|OID)\s*:\s*/i', '', $crudo);
        $texto = trim($texto, " \t\r\n\"");

        return $texto === '' ? null : $texto;
    }

    private static function entero(?string $crudo): ?int
    {
        if ($crudo === null || !preg_match('/-?\d+/', $crudo, $m)) {
            return null;
        }

        return (int) $m[0];
    }

    private static function escalar(?string $crudo, int $divisor): ?float
    {
        $n = self::entero($crudo);

        // 2147483647 y -32768 son los "sin dato" habituales de estas tablas.
        if ($n === null || $n === 2147483647 || $n === -32768) {
            return null;
        }

        return round($n / $divisor, $divisor >= 1000 ? 3 : 2);
    }
}
