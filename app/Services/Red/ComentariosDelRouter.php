<?php

namespace App\Services\Red;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RouterOS\Query;

/**
 * Deja los comentarios del ARP con el documento del cliente.
 *
 * Los clientes que vienen importados están en el router con el comment que
 * usaba la plataforma anterior (en WispHub, el nombre del servicio). El
 * sistema ya los reconoce así —IdentidadEnElRouter—, de modo que esto no hace
 * falta: es sólo para el ISP que prefiere tener todo el router con la cédula,
 * como lo escribe la plataforma.
 *
 * Es opcional y en dos pasos: primero se mira qué cambiaría y recién con la
 * confirmación se escribe. Antes de escribir se guarda cómo estaba cada
 * entrada, con un archivo para deshacerlo. Nunca borra entradas ni toca las
 * que no son de un cliente de la empresa.
 */
class ComentariosDelRouter
{
    public function __construct(
        private ConectionRouterManagerInterface $conexion,
        private int $companyId,
    ) {}

    /**
     * Qué quedaría con qué comentario, sin tocar nada.
     *
     * @return array<string,mixed>
     */
    public function revisar(?int $routerId = null): array
    {
        return $this->trabajar($routerId, false);
    }

    /**
     * Escribe los comentarios, después de guardar el respaldo.
     *
     * @return array<string,mixed>
     */
    public function aplicar(?int $routerId = null): array
    {
        return $this->trabajar($routerId, true);
    }

    /** @return array<string,mixed> */
    private function trabajar(?int $routerId, bool $escribir): array
    {
        $routers = DB::table('conection_routers')
            ->where('company_id', $this->companyId)
            ->when($routerId, fn ($q) => $q->where('id', $routerId))
            ->get(['id', 'name', 'token']);

        $resultado = [
            'aplicado'    => $escribir,
            'routers'     => [],
            'al_dia'      => 0,   // ya tienen el documento
            'para_cambiar' => 0,
            'cambiados'   => 0,
            'sin_cliente' => 0,
            'ambiguos'    => 0,
            'sin_documento' => 0,
            'cambios'     => [],
            'respaldo'    => null,
            'errores'     => [],
        ];

        if ($routers->isEmpty()) {
            $resultado['errores'][] = 'La empresa no tiene routers configurados.';

            return $resultado;
        }

        foreach ($routers as $router) {
            try {
                $api = $this->conexion->conection($router->token);

                $q = new Query('/ip/arp/print');
                $q->add('=.proplist=.id,address,mac-address,interface,comment,disabled');
                $entradas = $api->query($q)->read();
            } catch (\Throwable $e) {
                $resultado['errores'][] = "No se pudo leer {$router->name}: " . $e->getMessage();
                $resultado['routers'][] = ['id' => (int) $router->id, 'nombre' => $router->name, 'ok' => false, 'entradas' => 0];
                continue;
            }

            $resultado['routers'][] = ['id' => (int) $router->id, 'nombre' => $router->name, 'ok' => true, 'entradas' => count($entradas)];

            $delRouter = [];

            foreach ($entradas as $e) {
                $comment = trim((string) ($e['comment'] ?? ''));
                $quien = IdentidadEnElRouter::resolver($this->companyId, $e);

                if ($quien['estado'] === 'ambiguo') {
                    $resultado['ambiguos']++;
                    continue;
                }

                if ($quien['estado'] !== 'cliente' || !$quien['identidad']) {
                    $resultado['sin_cliente']++;
                    continue;
                }

                $identidad = $quien['identidad'];

                if ($identidad['documento'] === '') {
                    $resultado['sin_documento']++;
                    continue;
                }

                if (IdentidadEnElRouter::digitos($comment) === $identidad['documento']) {
                    $resultado['al_dia']++;
                    continue;
                }

                $resultado['para_cambiar']++;

                $cambio = [
                    'router'    => $router->name,
                    'router_id' => (int) $router->id,
                    'id'        => (string) ($e['.id'] ?? ''),
                    'ip'        => (string) ($e['address'] ?? ''),
                    'mac'       => (string) ($e['mac-address'] ?? ''),
                    'antes'     => $comment,
                    'ahora'     => $identidad['dni'],
                    'cliente'   => $identidad['nombre'],
                    'reconocido_por' => $quien['via'],
                ];

                $delRouter[] = $cambio;

                if (count($resultado['cambios']) < 300) {
                    $resultado['cambios'][] = $cambio;
                }
            }

            if (!$escribir || !$delRouter) {
                continue;
            }

            // Antes de tocar nada: cómo está hoy cada entrada del router.
            $resultado['respaldo'] = $this->respaldar($router, $entradas, $delRouter);

            foreach ($delRouter as $c) {
                if ($c['id'] === '') {
                    continue;
                }

                try {
                    $api->query((new Query('/ip/arp/set'))->equal('.id', $c['id'])->equal('comment', $c['ahora']))->read();
                    $resultado['cambiados']++;
                } catch (\Throwable $e) {
                    $resultado['errores'][] = "No se pudo cambiar el comentario de {$c['ip']}: " . $e->getMessage();
                }
            }

            Log::info('[Router] Comentarios normalizados', [
                'company_id' => $this->companyId,
                'router'     => $router->id,
                'cambiados'  => $resultado['cambiados'],
                'respaldo'   => $resultado['respaldo'],
            ]);
        }

        return $resultado;
    }

    /**
     * Guarda el estado actual de los comentarios y un archivo de comandos para
     * volver atrás desde la terminal del MikroTik.
     *
     * @param  array<int,array<string,mixed>> $entradas
     * @param  array<int,array<string,mixed>> $cambios
     * @return array{json:string, rsc:string}
     */
    private function respaldar(object $router, array $entradas, array $cambios): array
    {
        $sello = now()->format('Ymd-His');
        $base = "router-comentarios/{$this->companyId}-router{$router->id}-{$sello}";

        $foto = array_map(fn ($e) => [
            'ip'      => $e['address'] ?? '',
            'mac'     => $e['mac-address'] ?? '',
            'comment' => $e['comment'] ?? '',
        ], $entradas);

        Storage::disk('local')->put($base . '.json', json_encode([
            'empresa'    => $this->companyId,
            'router'     => ['id' => (int) $router->id, 'nombre' => $router->name],
            'fecha'      => now()->toIso8601String(),
            'entradas'   => $foto,
            'cambios'    => $cambios,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Para deshacerlo: se pega en la terminal del MikroTik y cada entrada
        // vuelve al comentario que tenía.
        $lineas = ["# Volver los comentarios como estaban antes ({$sello})"];

        foreach ($cambios as $c) {
            $antes = str_replace('"', '', $c['antes']);
            $lineas[] = "/ip arp set [find address=\"{$c['ip']}\"] comment=\"{$antes}\"";
        }

        Storage::disk('local')->put($base . '.rsc', implode("\n", $lineas) . "\n");

        return ['json' => $base . '.json', 'rsc' => $base . '.rsc'];
    }
}
