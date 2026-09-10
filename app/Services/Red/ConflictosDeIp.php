<?php

namespace App\Services\Red;

use Illuminate\Support\Facades\DB;

/**
 * Encuentra los clientes que están compartiendo una misma IP.
 *
 * Hay dos formas de que eso pase y se arreglan distinto:
 *
 * - **Ficha compartida**: varios user_data apuntan al mismo registro de
 *   tabla_ips. No es que se hayan repartido la misma IP dos veces, es que
 *   comparten el renglón: si a uno se le migra la IP, se le cambia a todos.
 * - **IP repetida**: fichas distintas con el mismo valor de IP. Es lo que
 *   producía la lista de IPs libres cuando se calculaba sólo con el ARP del
 *   router, que no ve a los clientes apagados.
 *
 * En los dos casos el efecto en la red es el mismo: conflicto de ARP e
 * internet intermitente para los involucrados.
 */
class ConflictosDeIp
{
    public function __construct(private int $companyId) {}

    /** Documento => IP según el ARP del router; null si no se pudo leer. */
    private ?array $arp = null;

    /**
     * @param  array<string,string>|null  $arp  documento => IP en el router.
     *   Con esto se distingue el conflicto de verdad —dos clientes con la
     *   misma IP en el router— del que es sólo el dato mal en la plataforma,
     *   que es la mayoría: clientes pegados al registro de otro, sin entrada
     *   propia en el ARP. Sin el ARP se cae al estado del cliente, que marca
     *   de más.
     * @return array{compartidas:array, repetidas:array, resumen:array}
     */
    public function listar(?array $arp = null): array
    {
        $this->arp   = $arp;
        $compartidas = $this->fichasCompartidas();
        $repetidas   = $this->ipsRepetidas();

        $afectados = [];

        foreach (array_merge($compartidas, $repetidas) as $grupo) {
            foreach ($grupo['clientes'] as $c) {
                $afectados[$c['user_id']] = true;
            }
        }

        return [
            'compartidas' => $compartidas,
            'repetidas'   => $repetidas,
            'resumen'     => [
                'compartidas'        => count($compartidas),
                'repetidas'          => count($repetidas),
                'clientes_afectados' => count($afectados),
                'urgentes'           => count(array_filter(
                    array_merge($compartidas, $repetidas),
                    fn ($g) => $g['urgente']
                )),
                'solo_dato'          => count(array_filter(
                    array_merge($compartidas, $repetidas),
                    fn ($g) => $g['solo_dato'] ?? false
                )),
                'con_router'         => $arp !== null,
            ],
        ];
    }

    /** Un mismo registro de tabla_ips referenciado por más de un cliente. */
    private function fichasCompartidas(): array
    {
        $filas = $this->clientesConIp()
            ->whereIn('ud.ip_assignment_id', function ($q) {
                $q->select('ud2.ip_assignment_id')
                    ->from('user_data as ud2')
                    ->join('users as u2', 'u2.id', '=', 'ud2.user_id')
                    ->where('u2.company_id', $this->companyId)
                    ->where('ud2.active', 1)
                    ->whereNotNull('ud2.ip_assignment_id')
                    ->groupBy('ud2.ip_assignment_id')
                    ->havingRaw('COUNT(*) > 1');
            })
            ->get();

        return $this->agrupar($filas, 'ip_assignment_id', 'ficha');
    }

    /**
     * Misma IP en fichas distintas.
     *
     * Quedan fuera las que ya aparecen como ficha compartida: es el mismo
     * problema visto dos veces y confunde encontrarlo repetido en la pantalla.
     */
    private function ipsRepetidas(): array
    {
        $compartidas = DB::table('user_data as ud2')
            ->join('users as u2', 'u2.id', '=', 'ud2.user_id')
            ->where('u2.company_id', $this->companyId)
            ->where('ud2.active', 1)
            ->whereNotNull('ud2.ip_assignment_id')
            ->groupBy('ud2.ip_assignment_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('ud2.ip_assignment_id')
            ->all();

        $filas = $this->clientesConIp()
            ->when($compartidas, fn ($q) => $q->whereNotIn('ud.ip_assignment_id', $compartidas))
            ->whereIn('t.ip', function ($q) {
                $q->select('t2.ip')
                    ->from('tabla_ips as t2')
                    ->join('user_data as ud2', 'ud2.ip_assignment_id', '=', 't2.id')
                    ->join('users as u2', 'u2.id', '=', 'ud2.user_id')
                    ->where('u2.company_id', $this->companyId)
                    ->where('ud2.active', 1)
                    ->whereNotNull('t2.ip')
                    ->where('t2.ip', '<>', '')
                    ->groupBy('t2.ip')
                    ->havingRaw('COUNT(DISTINCT t2.id) > 1');
            })
            ->get();

        return $this->agrupar($filas, 'ip', 'ip');
    }

    /** Clientes de la empresa con su ficha de IP, nombre y estado. */
    private function clientesConIp()
    {
        return DB::table('user_data as ud')
            ->join('users as u', 'u.id', '=', 'ud.user_id')
            ->join('tabla_ips as t', 't.id', '=', 'ud.ip_assignment_id')
            ->leftJoin('internet_status as st', 'st.id', '=', 'ud.status_internet_id')
            ->leftJoin('conection_routers as r', 'r.id', '=', 'ud.router_id')
            ->where('u.company_id', $this->companyId)
            // Los retirados no cuentan: al darlos de baja se les deja el
            // registro de IP como estaba, así que si no se filtran aparecen
            // peleándole la IP a un cliente que sí está activo.
            ->where('ud.active', 1)
            ->orderBy('t.ip')
            ->select([
                'ud.user_id',
                'ud.names',
                'ud.lastname',
                'ud.dni',
                'ud.phone',
                'ud.router_id',
                'ud.ip_assignment_id',
                't.ip',
                DB::raw('COALESCE(st.name, "") as estado'),
                DB::raw('COALESCE(r.name, "") as router'),
            ]);
    }

    private function agrupar($filas, string $clave, string $tipo): array
    {
        $grupos = [];

        foreach ($filas as $f) {
            $k = $f->{$clave};

            if (!isset($grupos[$k])) {
                $grupos[$k] = [
                    'tipo'             => $tipo,
                    'ip'               => $f->ip,
                    'ip_assignment_id' => (int) $f->ip_assignment_id,
                    'clientes'         => [],
                ];
            }

            $grupos[$k]['clientes'][] = [
                'user_id'   => (int) $f->user_id,
                'nombre'    => trim($f->names . ' ' . $f->lastname),
                'dni'       => $f->dni,
                'ip_router' => $this->arp === null
                    ? null
                    : ($this->arp[preg_replace('/\D/', '', (string) $f->dni)] ?? ''),
                'phone'     => $f->phone,
                'router_id' => $f->router_id ? (int) $f->router_id : null,
                'router'    => $f->router,
                'estado'    => $f->estado,
            ];
        }

        // Con un solo cliente ya no hay conflicto: puede quedar así si al otro
        // le cambiaron la IP mientras se miraba la pantalla.
        $grupos = array_filter($grupos, fn ($g) => count($g['clientes']) > 1);

        foreach ($grupos as $k => $g) {
            $activos = array_filter($g['clientes'], fn ($c) => strtoupper($c['estado']) === 'ACTIVE');
            $grupos[$k]['activos'] = count($activos);

            if ($this->arp === null) {
                // Sin el router sólo queda mirar el estado del cliente, que
                // marca de más: no sabe quién tiene la IP de verdad.
                $grupos[$k]['urgente'] = count($activos) > 1;
                $grupos[$k]['solo_dato'] = false;
                continue;
            }

            // Se pelean el ARP únicamente si los dos tienen esa IP en el
            // router. Si sólo uno la tiene, el otro la está heredando del
            // registro compartido y no hay problema en la red: alcanza con
            // separar los registros.
            $duenos = array_filter($g['clientes'], fn ($c) => $c['ip_router'] === $g['ip']);

            $grupos[$k]['duenos']    = count($duenos);
            $grupos[$k]['urgente']   = count($duenos) > 1;
            $grupos[$k]['solo_dato'] = count($duenos) <= 1;
        }

        // Primero lo que ya está causando cortes, después lo latente.
        usort($grupos, function ($a, $b) {
            return [$b['urgente'], $b['activos'], count($b['clientes'])]
               <=> [$a['urgente'], $a['activos'], count($a['clientes'])];
        });

        return array_values($grupos);
    }
}
