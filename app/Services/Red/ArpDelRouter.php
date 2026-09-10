<?php

namespace App\Services\Red;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RouterOS\Query;

/**
 * Qué IP tiene cada cliente según el MikroTik.
 *
 * En el router el cliente se identifica por su número de documento, guardado
 * en el comment de la entrada ARP. El comment puede venir con puntos, espacios
 * o guiones, así que se compara sólo por los dígitos.
 */
class ArpDelRouter
{
    /**
     * Documento => IP, sólo cuando el router tiene una sola entrada para ese
     * documento: con dos no se sabe cuál es la buena y es mejor no adivinar.
     *
     * @return array<string,string>|null  null si el router no respondió
     */
    public static function documentos(ConectionRouterManagerInterface $conexion, int $companyId): ?array
    {
        $tokens = DB::table('conection_routers')
            ->where('company_id', $companyId)
            ->pluck('token');

        if ($tokens->isEmpty()) {
            return null;
        }

        $porDocumento = [];
        $algunoRespondio = false;

        foreach ($tokens as $token) {
            try {
                $api = $conexion->conection($token);

                $query = new Query('/ip/arp/print');
                $query->add('=.proplist=address,comment');

                foreach ($api->query($query)->read() as $fila) {
                    $documento = preg_replace('/\D/', '', trim((string) ($fila['comment'] ?? '')));
                    $ip        = trim((string) ($fila['address'] ?? ''));

                    if ($documento === '' || $ip === '') {
                        continue;
                    }

                    $porDocumento[$documento][] = $ip;
                }

                $algunoRespondio = true;
            } catch (\Throwable $e) {
                Log::warning('[ARP] No se pudo leer el router', [
                    'company_id' => $companyId,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        if (!$algunoRespondio) {
            return null;
        }

        $unicos = [];

        foreach ($porDocumento as $documento => $ips) {
            $ips = array_values(array_unique($ips));

            if (count($ips) === 1) {
                $unicos[$documento] = $ips[0];
            }
        }

        return $unicos;
    }
}
