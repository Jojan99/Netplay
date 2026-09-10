<?php

namespace App\Services\Red;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Le da a cada cliente su propio registro de IP, con la IP que tiene en el router.
 *
 * Casi todos los "conflictos de IP" de la plataforma no son conflictos de red:
 * son clientes pegados al registro de otro. En el router uno solo tiene esa IP
 * y el resto no tiene entrada ARP; la comparten únicamente porque en la base
 * apuntan al mismo renglón de tabla_ips.
 *
 * Por eso se arreglan sin tocar el MikroTik y sin cortarle el servicio a
 * nadie: se separan los renglones y a cada cliente se le pone la IP que el
 * router dice que tiene. Al que no tiene entrada ARP se le deja el registro
 * vacío, que es la verdad — hoy no tiene IP configurada en el router.
 *
 * Cuando dos clientes sí tienen la misma IP en el ARP el conflicto es real y
 * hay que decidir a quién se le cambia: esos no se tocan, se reportan.
 */
class SepararFichasCompartidas
{
    public function __construct(
        private ConectionRouterManagerInterface $conexion,
        private int $companyId,
    ) {}

    /** @return array<string,mixed> */
    public function ejecutar(bool $simular = false): array
    {
        $arp = ArpDelRouter::documentos($this->conexion, $this->companyId);

        if ($arp === null) {
            return [
                'simulacion'   => true,
                'separados'    => [],
                'conflictos'   => [],
                'errores'      => ['No se pudo leer el ARP del router. No se cambió nada.'],
            ];
        }

        $grupos = (new ConflictosDeIp($this->companyId))->listar($arp);

        $separados  = [];
        $conflictos = [];

        foreach (array_merge($grupos['compartidas'], $grupos['repetidas']) as $grupo) {
            $conIpReal = [];

            foreach ($grupo['clientes'] as $cliente) {
                $documento = preg_replace('/\D/', '', (string) $cliente['dni']);
                $ipReal    = $arp[$documento] ?? null;

                $conIpReal[] = $cliente + ['ip_real' => $ipReal];
            }

            // ¿Más de uno con esta IP en el router? Ahí sí se pelean el ARP y
            // alguien tiene que cambiar de IP: es una decisión, no un arreglo.
            $duenos = array_filter($conIpReal, fn ($c) => $c['ip_real'] === $grupo['ip']);

            if (count($duenos) > 1) {
                $conflictos[] = [
                    'ip'       => $grupo['ip'],
                    'clientes' => array_values(array_map(
                        fn ($c) => ['nombre' => $c['nombre'], 'dni' => $c['dni']],
                        $duenos
                    )),
                ];
                continue;
            }

            foreach ($conIpReal as $cliente) {
                $separados[] = [
                    'user_id' => $cliente['user_id'],
                    'cliente' => $cliente['nombre'],
                    'dni'     => $cliente['dni'],
                    'antes'   => $grupo['ip'],
                    'ahora'   => $cliente['ip_real'],
                ];
            }
        }

        if (!$simular && $separados) {
            DB::transaction(fn () => $this->aplicar($separados));

            Log::info('[Separar fichas] Registros de IP separados', [
                'company_id' => $this->companyId,
                'clientes'   => count($separados),
            ]);
        }

        return [
            'simulacion' => $simular,
            'separados'  => $separados,
            'conflictos' => $conflictos,
            'errores'    => [],
        ];
    }

    /**
     * Un registro nuevo por cliente, con su IP real.
     *
     * Todos reciben registro propio, incluido el que ya tenía la IP correcta:
     * dejarlo en el renglón viejo mantendría la referencia compartida y el
     * conflicto volvería a aparecer.
     *
     * @param  array<int,array<string,mixed>>  $separados
     */
    private function aplicar(array $separados): void
    {
        $ultimoId = (int) DB::table('tabla_ips')->max('id');

        $filas = [];

        foreach ($separados as $s) {
            $filas[] = [
                'company_id' => $this->companyId,
                'id_user'    => $s['user_id'],
                'ip'         => $s['ahora'],
                'name'       => '',
                'mac'        => null,
                'active'     => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($filas, 200) as $lote) {
            DB::table('tabla_ips')->insert($lote);
        }

        $nuevas = DB::table('tabla_ips')
            ->where('id', '>', $ultimoId)
            ->where('company_id', $this->companyId)
            ->pluck('id', 'id_user');

        $casos = '';
        $valores = [];
        $usuarios = [];

        foreach ($separados as $s) {
            if (!isset($nuevas[$s['user_id']])) {
                continue;
            }

            $casos .= ' WHEN ? THEN ?';
            $valores[] = $s['user_id'];
            $valores[] = $nuevas[$s['user_id']];
            $usuarios[] = (int) $s['user_id'];
        }

        if (!$usuarios) {
            return;
        }

        DB::update(
            'UPDATE user_data SET ip_assignment_id = CASE user_id' . $casos . ' END WHERE user_id IN ('
            . implode(',', $usuarios) . ')',
            $valores
        );
    }
}
