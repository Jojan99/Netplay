<?php

namespace App\Services\Olt;

use App\Models\OltAdmin;
use App\OltDrivers\FabricaDeDrivers;
use App\Services\HuaweiSnmpReader;
use App\Services\OltTelnetDispatcher;

/**
 * Por qué una OLT no responde.
 *
 * Hasta ahora, cuando una consulta fallaba el operador veía un solo mensaje
 * ("no se pudo conectar") sin saber si el problema era el jump host, el puerto,
 * el usuario o la comunidad SNMP. Esto prueba cada eslabón por separado y dice
 * exactamente dónde se corta la cadena.
 */
class DiagnosticoDeOlt
{
    /**
     * @return array<string,mixed>
     */
    public static function correr(OltAdmin $olt): array
    {
        $pasos = [];

        $pasos[] = self::marca($olt);

        if ($olt->access_mode === 'jump') {
            $pasos[] = self::jumpHost($olt);
        }

        $pasos[] = self::consola($olt);
        $pasos[] = self::snmp($olt);

        $fallaron = array_values(array_filter($pasos, fn ($p) => $p['ok'] === false));

        return [
            'olt_id'    => (int) $olt->id,
            'nombre'    => $olt->name,
            'pasos'     => $pasos,
            'ok'        => $fallaron === [],
            'resumen'   => $fallaron === []
                ? 'Todo responde: consola y SNMP funcionando.'
                : $fallaron[0]['detalle'],
        ];
    }

    /** ¿La marca configurada tiene driver? */
    private static function marca(OltAdmin $olt): array
    {
        $soporta = FabricaDeDrivers::soporta($olt->brand);

        return [
            'paso'    => 'Marca configurada',
            'ok'      => $soporta,
            'detalle' => $soporta
                ? 'Hay driver para ' . strtoupper((string) $olt->brand) . '.'
                : 'La marca "' . ($olt->brand ?: 'sin definir') . '" no tiene driver. '
                  . 'Marcas disponibles: ' . implode(', ', array_column(FabricaDeDrivers::marcas(), 'valor')) . '.',
        ];
    }

    /** El bastión SSH por el que se llega a la OLT. */
    private static function jumpHost(OltAdmin $olt): array
    {
        $inicio = microtime(true);

        try {
            $ssh = new \phpseclib3\Net\SSH2($olt->jump_host, (int) ($olt->jump_port ?: 22), 12);

            if (!$ssh->login($olt->jump_user, $olt->jump_pass)) {
                return [
                    'paso'    => 'Jump host',
                    'ok'      => false,
                    'detalle' => "El jump host {$olt->jump_host} rechazó el usuario {$olt->jump_user}.",
                ];
            }

            $ssh->disconnect();

            return [
                'paso'    => 'Jump host',
                'ok'      => true,
                'detalle' => "Sesión SSH abierta en {$olt->jump_host}.",
                'ms'      => (int) ((microtime(true) - $inicio) * 1000),
            ];
        } catch (\Throwable $e) {
            return [
                'paso'    => 'Jump host',
                'ok'      => false,
                'detalle' => "No se llegó al jump host {$olt->jump_host}: " . $e->getMessage(),
            ];
        }
    }

    /**
     * La consola de la OLT: conexión, login y un comando de sólo lectura.
     *
     * Va por el despachador y no abriendo una sesión aparte, porque la OLT
     * admite pocas sesiones a la vez: una conexión extra para diagnosticar
     * dejaba el equipo respondiendo "session limit reached" al resto de la
     * plataforma.
     */
    private static function consola(OltAdmin $olt): array
    {
        $inicio = microtime(true);

        try {
            $version = trim((string) app(OltTelnetDispatcher::class)->dispatch((int) $olt->id, 'getVersion'));

            return [
                'paso'    => 'Consola de la OLT',
                'ok'      => $version !== '',
                'detalle' => $version !== ''
                    ? 'La OLT contestó el comando de versión.'
                    : 'Conectó y autenticó, pero la OLT no devolvió nada al pedir la versión.',
                'salida'  => mb_substr($version, 0, 1200),
                'ms'      => (int) ((microtime(true) - $inicio) * 1000),
            ];
        } catch (\Throwable $e) {
            return [
                'paso'    => 'Consola de la OLT',
                'ok'      => false,
                'detalle' => $e->getMessage(),
            ];
        }
    }

    /** La lectura por SNMP, que es de donde sale la ficha del equipo. */
    private static function snmp(OltAdmin $olt): array
    {
        $inicio = microtime(true);

        try {
            $identidad = (new HuaweiSnmpReader($olt))->identidad();

            if (empty($identidad['descripcion']) && empty($identidad['modelo'])) {
                return [
                    'paso'    => 'SNMP',
                    'ok'      => false,
                    'detalle' => 'La OLT no contestó por SNMP. Verifique la comunidad "'
                        . ($olt->snmp_community ?: 'public') . '", la versión '
                        . ($olt->snmp_version ?: '2c') . ' y que el equipo tenga SNMP habilitado.',
                ];
            }

            return [
                'paso'    => 'SNMP',
                'ok'      => true,
                'detalle' => 'Responde como ' . ($identidad['marca_nombre'] ?: 'equipo desconocido')
                    . ' ' . ($identidad['modelo'] ?: '') . '.',
                'ms'      => (int) ((microtime(true) - $inicio) * 1000),
            ];
        } catch (\Throwable $e) {
            return [
                'paso'    => 'SNMP',
                'ok'      => false,
                'detalle' => $e->getMessage(),
            ];
        }
    }
}
