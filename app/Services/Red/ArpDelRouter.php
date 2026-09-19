<?php

namespace App\Services\Red;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RouterOS\Query;

/**
 * Qué IP tiene cada cliente según el MikroTik.
 *
 * Se reconoce a cada cliente con el criterio común (IdentidadEnElRouter): el
 * documento en el comment, el nombre que traía de la plataforma de la que se
 * importó, o su IP. La clave del arreglo sigue siendo el documento en dígitos,
 * que es lo que esperan quienes lo usan.
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
                    $ip = trim((string) ($fila['address'] ?? ''));

                    if ($ip === '') {
                        continue;
                    }

                    // De quién es la entrada, con el criterio común: documento,
                    // nombre en la plataforma de origen o su IP. Sin esto, los
                    // clientes importados (que en el router llevan el nombre de
                    // servicio de WispHub) no tenían ninguna IP "de verdad".
                    $cliente = IdentidadEnElRouter::clienteDeEntrada($companyId, [
                        'address' => $ip,
                        'comment' => trim((string) ($fila['comment'] ?? '')),
                    ]);

                    if (!$cliente || $cliente['documento'] === '') {
                        continue;
                    }

                    $porDocumento[$cliente['documento']][] = $ip;
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
