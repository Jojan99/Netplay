<?php

namespace App\Services\Olt;

use App\Services\HuaweiSnmpReader;

/**
 * Señal óptica de las OLT GPON C-Data (FD16xx), por SNMP.
 *
 * Estas OLT no publican ni la MIB de Huawei ni la NSCRTV de las EPON, así que
 * la pantalla de potencia salía vacía: "La OLT no devolvió mediciones ópticas
 * por SNMP". Sí publican su propia MIB (enterprise 17409), verificada contra la
 * OLT Feria (FD1608S-B1):
 *
 *   1.3.6.1.4.1.17409.2.8.4.1.1.<col>.<indice>  ONT: 2 nombre, 3 serial,
 *                                               5 fabricante, 6 modelo,
 *                                               7 estado (1 en línea, 2 caída)
 *   1.3.6.1.4.1.17409.2.3.3.6.1.2.<indice>      potencia recibida, en dBm × 100
 *
 * El índice lleva dentro el puerto PON y el número de ONT:
 *
 *   indice = 0x480000 + (puerto - 1) * 16 * 256 + ont_id
 *
 * o sea 0x480002 = puerto 1, ONT 2. Se comprobó contra las 173 ONT de esa OLT:
 * 123 en el puerto 1, 3 en el 2, 46 en el 3 y 1 en el 4, con los mismos nombres
 * y estados que tiene la plataforma.
 */
class SnmpGponCdata
{
    private const ONT = '1.3.6.1.4.1.17409.2.8.4.1.1';
    private const RX  = '1.3.6.1.4.1.17409.2.3.3.6.1.2';

    /** Lo que el índice tiene fijo: identifica a la tabla de ONT de la OLT. */
    private const BASE = 0x480000;

    public function __construct(private HuaweiSnmpReader $snmp) {}

    /** ¿La OLT publica la tabla de ONT de esta MIB? */
    public function esCompatible(): bool
    {
        try {
            return $this->columna(self::ONT . '.2') !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Las ONT con la misma forma que devuelve el lector de Huawei, para que el
     * resto de la plataforma no tenga que distinguir de qué marca vienen.
     *
     * @return list<array<string,mixed>>
     */
    public function onts(): array
    {
        $nombre = $this->columna(self::ONT . '.2');
        $serial = $this->columna(self::ONT . '.3');
        $marca  = $this->columna(self::ONT . '.5');
        $modelo = $this->columna(self::ONT . '.6');
        $estado = $this->columna(self::ONT . '.7');

        $onts = [];

        foreach ($nombre as $indice => $valor) {
            [$fsp, $ontId] = self::ubicacion($indice);

            $onts[] = [
                'fsp'         => $fsp,
                'ont_id'      => $ontId,
                'serial'      => self::serial($serial[$indice] ?? null),
                'description' => self::texto($valor),
                'status'      => self::entero($estado[$indice] ?? null) === 2 ? 'offline' : 'online',
                'modelo'      => self::texto($modelo[$indice] ?? null),
                'marca'       => self::texto($marca[$indice] ?? null),
            ];
        }

        usort($onts, fn ($a, $b) => strnatcmp($a['fsp'], $b['fsp']) ?: $a['ont_id'] <=> $b['ont_id']);

        return $onts;
    }

    /**
     * La potencia de todas las ONT, con la forma de
     * HuaweiSnmpReader::senalDeTodasLasOnts().
     *
     * La OLT informa 0 en las ONT caídas: eso no es una medición y se manda
     * vacío, para que no arrastre el promedio de la empresa.
     *
     * @return list<array<string,mixed>>
     */
    public function senal(): array
    {
        $filas = [];

        foreach ($this->columna(self::RX) as $indice => $valor) {
            [$fsp, $ontId] = self::ubicacion($indice);
            $rx = self::entero($valor);

            $filas[] = [
                'fsp'         => $fsp,
                'ont_id'      => $ontId,
                'potencia'    => $rx === null || $rx === 0 ? null : round($rx / 100, 2),
                'tx'          => null,
                'corriente'   => null,
                'voltaje'     => null,
                'temperatura' => null,
            ];
        }

        usort($filas, fn ($a, $b) => strnatcmp($a['fsp'], $b['fsp']) ?: $a['ont_id'] <=> $b['ont_id']);

        return $filas;
    }

    /**
     * Una sola ONT, con consultas puntuales en vez de recorrer la OLT entera:
     * para la ficha del cliente.
     *
     * @return array<string,mixed>|null  null si la OLT no conoce esa ONT
     */
    public function una(string $fsp, int $ontId): ?array
    {
        $indice = self::indiceDe($fsp, $ontId);

        if ($indice === null) {
            return null;
        }

        $ont = fn (int $col) => $this->valor(self::ONT . ".{$col}.{$indice}");
        $nombre = $ont(2);

        if ($nombre === null && $this->valor(self::ONT . ".7.{$indice}") === null) {
            return null;
        }

        $rx = self::entero($this->valor(self::RX . ".{$indice}"));

        return [
            'fsp'         => $fsp,
            'ont_id'      => $ontId,
            'serial'      => self::serial($ont(3)),
            'description' => self::texto($nombre),
            'status'      => self::entero($ont(7)) === 2 ? 'offline' : 'online',
            'modelo'      => self::texto($ont(6)),
            'marca'       => self::texto($ont(5)),
            'potencia'    => $rx === null || $rx === 0 ? null : round($rx / 100, 2),
            'tx'          => null,
            'corriente'   => null,
            'voltaje'     => null,
            'temperatura' => null,
        ];
    }

    // ── Índice de la MIB ──────────────────────────────────────────────────

    /** @return array{0:string, 1:int} el puerto PON y el número de ONT */
    private static function ubicacion(int $indice): array
    {
        $puerto = intdiv(($indice >> 8) & 0xFF, 16) + 1;

        return ["0/0/{$puerto}", $indice & 0xFF];
    }

    private static function indiceDe(string $fsp, int $ontId): ?int
    {
        if (!preg_match('#(\d+)/(\d+)/(\d+)#', $fsp, $m) || $ontId < 0 || $ontId > 255) {
            return null;
        }

        $puerto = (int) $m[3];

        return $puerto >= 1 && $puerto <= 16
            ? self::BASE + ($puerto - 1) * 16 * 256 + $ontId
            : null;
    }

    // ── Lectura ───────────────────────────────────────────────────────────

    /** @return array<int,string> por índice de la MIB */
    private function columna(string $oid): array
    {
        $valores = [];

        foreach ($this->snmp->walkRaw($oid) as $sufijo => $valor) {
            $indice = (int) trim((string) $sufijo, '.');

            if ($indice > 0) {
                $valores[$indice] = (string) $valor;
            }
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

        $texto = trim(preg_replace('/^\s*(STRING|Hex-STRING|OID)\s*:\s*/i', '', $crudo), " \t\r\n\"");

        return $texto === '' ? null : $texto;
    }

    /** El serial viene en hexadecimal: las 4 primeras letras son el fabricante. */
    private static function serial(?string $crudo): ?string
    {
        $texto = self::texto($crudo);

        if ($texto === null) {
            return null;
        }

        $bytes = preg_split('/\s+/', trim($texto));

        if (count($bytes) < 8 || !preg_match('/^[0-9A-Fa-f]{2}$/', $bytes[0])) {
            return $texto;
        }

        $marca = '';

        foreach (array_slice($bytes, 0, 4) as $b) {
            $marca .= chr(hexdec($b));
        }

        return strtoupper($marca . implode('', array_slice($bytes, 4, 4)));
    }

    private static function entero(?string $crudo): ?int
    {
        if ($crudo === null || !preg_match('/-?\d+/', $crudo, $m)) {
            return null;
        }

        return (int) $m[0];
    }
}
