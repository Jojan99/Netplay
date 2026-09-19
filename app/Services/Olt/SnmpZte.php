<?php

namespace App\Services\Olt;

use App\Services\HuaweiSnmpReader;

/**
 * ONT y señal de las OLT ZTE (C320 / C300 V2.x), por SNMP.
 *
 * Las ZTE no publican la MIB de Huawei: la lista salía vacía y la señal "Sin
 * medir". Publican la suya (enterprise 3902); los OID y el índice son los del
 * proyecto snmp-olt-zte (Cepat-Kilat-Teknologi), verificados contra la C320
 * «Rebolo» de skartelecon: 620 ONT, y la potencia de 1/1/1:1 da -21,14 dBm
 * igual que «show pon power onu-rx» por consola.
 *
 *   1.3.6.1.4.1.3902.1082.500.10.2.3.3.1.2.<pon>.<onu>     nombre
 *   1.3.6.1.4.1.3902.1082.500.10.2.3.3.1.18.<pon>.<onu>    serial ("1,ZTEG…")
 *   1.3.6.1.4.1.3902.1082.500.10.2.3.8.1.4.<pon>.<onu>     estado: 1 logging,
 *        2 LOS, 3 sincronizando, 4 en línea, 5 dying gasp, 6 auth failed, 7 offline
 *   1.3.6.1.4.1.3902.1082.500.20.2.2.2.1.10.<pon>.<onu>.1  potencia recibida:
 *        dBm = valor × 0,002 − 30
 *
 * <pon> = 0x11010000 + tarjeta × 0x100 + puerto   (1/1/1 → 285278465)
 */
class SnmpZte
{
    private const BASE = '1.3.6.1.4.1.3902.1082';
    private const NOMBRE = self::BASE . '.500.10.2.3.3.1.2';
    private const SERIAL = self::BASE . '.500.10.2.3.3.1.18';
    private const ESTADO = self::BASE . '.500.10.2.3.8.1.4';
    private const RX     = self::BASE . '.500.20.2.2.2.1.10';

    private const PON_BASE = 0x11010000;

    public function __construct(private HuaweiSnmpReader $snmp) {}

    public function esCompatible(): bool
    {
        try {
            return $this->columna(self::ESTADO) !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    /** Con la misma forma que el lector de Huawei. @return list<array<string,mixed>> */
    public function onts(): array
    {
        $estado = $this->columna(self::ESTADO);
        $serial = $this->columna(self::SERIAL);
        $nombre = $this->columna(self::NOMBRE);
        $onts = [];

        foreach ($estado as $clave => $valor) {
            [$fsp, $ontId] = self::ubicacion($clave);

            if ($fsp === null) {
                continue;
            }

            $e = self::entero($valor);

            $onts[] = [
                'fsp'         => $fsp,
                'ont_id'      => $ontId,
                'serial'      => self::serial($serial[$clave] ?? null),
                'description' => self::texto($nombre[$clave] ?? null),
                'status'      => $e === 4 ? 'online' : 'offline',
                'fase'        => self::fase($e),
            ];
        }

        usort($onts, fn ($a, $b) => strnatcmp($a['fsp'], $b['fsp']) ?: $a['ont_id'] <=> $b['ont_id']);

        return $onts;
    }

    /** La potencia de todas las ONT. @return list<array<string,mixed>> */
    public function senal(): array
    {
        $filas = [];

        foreach ($this->columna(self::RX) as $clave => $valor) {
            [$fsp, $ontId] = self::ubicacion($clave);

            if ($fsp === null) {
                continue;
            }

            $filas[] = [
                'fsp'         => $fsp,
                'ont_id'      => $ontId,
                'potencia'    => self::dbm(self::entero($valor)),
                'tx'          => null,
                'corriente'   => null,
                'voltaje'     => null,
                'temperatura' => null,
            ];
        }

        usort($filas, fn ($a, $b) => strnatcmp($a['fsp'], $b['fsp']) ?: $a['ont_id'] <=> $b['ont_id']);

        return $filas;
    }

    /** Una sola ONT, para la ficha. */
    public function una(string $fsp, int $ontId): ?array
    {
        $pon = self::ponDe($fsp);

        if ($pon === null) {
            return null;
        }

        $estado = self::entero($this->valor(self::ESTADO . ".{$pon}.{$ontId}"));

        if ($estado === null) {
            return null;
        }

        return [
            'fsp'         => $fsp,
            'ont_id'      => $ontId,
            'serial'      => self::serial($this->valor(self::SERIAL . ".{$pon}.{$ontId}")),
            'description' => self::texto($this->valor(self::NOMBRE . ".{$pon}.{$ontId}")),
            'status'      => $estado === 4 ? 'online' : 'offline',
            'fase'        => self::fase($estado),
            'potencia'    => self::dbm(self::entero($this->valor(self::RX . ".{$pon}.{$ontId}.1"))),
        ];
    }

    // ── Índice ────────────────────────────────────────────────────────────

    /** "285278465.3" o "285278465.3.1" → ["1/1/1", 3] */
    private static function ubicacion(string $clave): array
    {
        $partes = explode('.', trim($clave, '.'));

        if (count($partes) < 2) {
            return [null, 0];
        }

        $pon = (int) $partes[0] - self::PON_BASE;
        $tarjeta = $pon >> 8;
        $puerto = $pon & 0xFF;

        return $tarjeta >= 1 && $puerto >= 1 ? ["1/{$tarjeta}/{$puerto}", (int) $partes[1]] : [null, 0];
    }

    private static function ponDe(string $fsp): ?int
    {
        return preg_match('#\d+/(\d+)/(\d+)#', $fsp, $m) ? self::PON_BASE + (int) $m[1] * 0x100 + (int) $m[2] : null;
    }

    // ── Valores ───────────────────────────────────────────────────────────

    /** @return array<string,string> por "pon.onu[.1]" */
    private function columna(string $oid): array
    {
        $valores = [];

        foreach ($this->snmp->walkRaw($oid) as $sufijo => $valor) {
            $valores[trim((string) $sufijo, '.')] = (string) $valor;
        }

        return $valores;
    }

    private function valor(string $oid): ?string
    {
        try {
            $crudo = $this->snmp->getRaw($oid);
        } catch (\Throwable) {
            return null;
        }

        return $crudo === '' || stripos($crudo, 'No Such') !== false ? null : $crudo;
    }

    private static function texto(?string $crudo): ?string
    {
        if ($crudo === null) {
            return null;
        }

        $t = trim(preg_replace('/^\s*(STRING|Hex-STRING)\s*:\s*/i', '', $crudo), " \t\r\n\"");

        return $t === '' ? null : $t;
    }

    /** Viene como "1,ZTEGC8D703D0": el número de adelante es el tipo de autenticación. */
    private static function serial(?string $crudo): ?string
    {
        $t = self::texto($crudo);

        if ($t === null) {
            return null;
        }

        $t = str_contains($t, ',') ? substr($t, strrpos($t, ',') + 1) : $t;

        return strtoupper(trim($t)) ?: null;
    }

    private static function entero(?string $crudo): ?int
    {
        return $crudo !== null && preg_match('/(-?\d+)/', $crudo, $m) ? (int) $m[1] : null;
    }

    /** La ONT apagada informa 0 o valores fuera de rango: no es una medición. */
    private static function dbm(?int $crudo): ?float
    {
        if ($crudo === null || $crudo <= 0 || $crudo >= 65535) {
            return null;
        }

        $dbm = round($crudo * 0.002 - 30, 2);

        return $dbm > -50 && $dbm < 10 ? $dbm : null;
    }

    private static function fase(?int $e): string
    {
        return [1 => 'logging', 2 => 'LOS', 3 => 'sincronizando', 4 => 'working', 5 => 'DyingGasp', 6 => 'auth failed', 7 => 'offline'][$e] ?? 'desconocido';
    }
}
