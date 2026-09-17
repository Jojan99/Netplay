<?php

namespace App\Services\Importador;

use Illuminate\Support\Facades\DB;

/**
 * La vista previa: sin escribir nada, cuenta qué pasaría con cada cliente.
 *
 * Qué clientes ya existen (por el id de origen ya importado o por documento),
 * qué filas no se pueden importar y por qué, qué planes y routers trae el
 * origen y a cuáles de la empresa se parecen.
 */
class AnalisisDeImportacion
{
    /** @var array<string, array{user_id:int, perfil:string}> documento para comparar => cliente */
    private array $porDocumento = [];

    /** @var array<string,int> external_id => user_id */
    private array $externos = [];

    /** @var array<string, array{user_id:int, dni:string}> ip => cliente activo que la tiene */
    private array $ips = [];

    /** @var array<string,int> usuario pppoe (minúsculas) => user_id */
    private array $pppoe = [];

    public function __construct(private int $companyId, private string $origen)
    {
    }

    /**
     * @param  array<int, array<string,mixed>> $clientes  normalizados
     * @return array{filas: array<int, array<string,mixed>>, resumen: array<string,mixed>}
     */
    public function analizar(array $clientes): array
    {
        $this->cargarEmpresa();

        $filas = [];
        $vistosDni = [];
        $vistosIp = [];
        $vistosPppoe = [];
        $vistosExterno = [];

        foreach (array_values($clientes) as $i => $d) {
            $n = $i + 1;
            $errores = [];
            $avisos = $d['avisos_origen'] ?? [];

            $dniComp = $d['dni'] !== '' ? Normalizador::documentoParaComparar($d['dni']) : '';

            if ($d['dni'] === '') {
                $errores[] = 'No tiene documento (cédula / NIT).';
            } elseif (isset($vistosDni[$dniComp])) {
                $errores[] = "Documento repetido: ya está en la fila {$vistosDni[$dniComp]} (en el sistema cada cliente tiene un solo plan).";
            } else {
                $vistosDni[$dniComp] = $n;
            }

            if ($d['external_id'] !== '') {
                if (isset($vistosExterno[$d['external_id']])) {
                    $errores[] = "El ID {$d['external_id']} está repetido (fila {$vistosExterno[$d['external_id']]}).";
                } else {
                    $vistosExterno[$d['external_id']] = $n;
                }
            }

            if ($d['nombres'] === '') {
                $errores[] = 'No tiene nombre.';
            }

            // ¿Ya existe? Primero por el id del origen (una importación anterior)
            // y después por documento.
            $existente = null;
            if ($d['external_id'] !== '' && isset($this->externos[$d['external_id']])) {
                $existente = $this->externos[$d['external_id']];
            } elseif ($dniComp !== '' && isset($this->porDocumento[$dniComp])) {
                $existente = $this->porDocumento[$dniComp]['user_id'];
            }

            if ($dniComp !== '' && isset($this->porDocumento[$dniComp]) && in_array($this->porDocumento[$dniComp]['perfil'], ['ADMIN', 'TECNICO', 'CONTADOR'], true)) {
                $errores[] = 'El documento es de un usuario del equipo de trabajo, no de un cliente.';
            }

            // Conexión
            if ($d['tipo_conexion'] === 'pppoe') {
                $clave = mb_strtolower($d['pppoe_usuario']);

                if ($clave === '') {
                    $errores[] = 'Es PPPoE pero no trae el usuario PPPoE.';
                } else {
                    if (isset($vistosPppoe[$clave])) {
                        $errores[] = "El usuario PPPoE {$d['pppoe_usuario']} está repetido (fila {$vistosPppoe[$clave]}).";
                    } else {
                        $vistosPppoe[$clave] = $n;
                    }
                    if (isset($this->pppoe[$clave]) && $this->pppoe[$clave] !== $existente) {
                        $errores[] = "El usuario PPPoE {$d['pppoe_usuario']} ya lo tiene otro cliente de la empresa.";
                    }
                }
                if ($d['pppoe_clave'] === '') {
                    $avisos[] = 'No trae la contraseña PPPoE: queda vacía en la ficha.';
                }
                if ($d['ip'] !== '') {
                    $avisos[] = 'En PPPoE la IP la da el router: no se guarda la IP ' . $d['ip'] . '.';
                }
            } elseif ($d['ip'] === '') {
                $avisos[] = 'IP fija sin IP: queda como "sin IP asignada".';
            } elseif (!filter_var($d['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $errores[] = "La IP \"{$d['ip']}\" no es válida.";
            } else {
                if (isset($vistosIp[$d['ip']])) {
                    $errores[] = "La IP {$d['ip']} está repetida (fila {$vistosIp[$d['ip']]}).";
                } else {
                    $vistosIp[$d['ip']] = $n;
                }
                if (isset($this->ips[$d['ip']]) && $this->ips[$d['ip']]['user_id'] !== $existente) {
                    $errores[] = "La IP {$d['ip']} ya la usa otro cliente (documento {$this->ips[$d['ip']]['dni']}).";
                }
            }

            if ($d['plan'] === '') {
                $avisos[] = 'No tiene plan: hay que elegirle uno.';
            }
            if ($d['estado'] === null) {
                $avisos[] = $d['estado_origen'] !== ''
                    ? "Estado \"{$d['estado_origen']}\" desconocido: se toma como activo."
                    : 'Sin estado: se toma como activo.';
            }
            if ($d['telefono'] === '') {
                $avisos[] = 'Sin teléfono.';
            }
            if (($d['saldo'] ?? 0) > 0) {
                $cuantas = (int) ($d['facturas_pendientes'] ?? 0);
                $avisos[] = 'Debe $' . number_format((float) $d['saldo'], 0, ',', '.')
                    . ($cuantas ? " en {$cuantas} factura(s)" : '') . ' en la plataforma anterior.';
            }

            $filas[] = [
                'fila'              => $n,
                'datos'             => $d,
                'errores'           => $errores,
                'avisos'            => $avisos,
                'previo'            => $errores ? 'invalido' : ($existente ? 'existente' : 'nuevo'),
                'existente_user_id' => $existente,
            ];
        }

        return ['filas' => $filas, 'resumen' => $this->resumen($filas)];
    }

    private function cargarEmpresa(): void
    {
        $clientes = DB::table('user_data as ud')
            ->join('users as u', 'u.id', '=', 'ud.user_id')
            ->leftJoin('profiles as p', 'p.id', '=', 'u.profile_id')
            ->where('u.company_id', $this->companyId)
            ->where('ud.company_id', $this->companyId)
            ->get(['ud.user_id', 'ud.dni', 'ud.pppoe_user', 'p.name as perfil']);

        foreach ($clientes as $c) {
            $dni = Normalizador::documentoParaComparar((string) $c->dni);
            if ($dni !== '' && !isset($this->porDocumento[$dni])) {
                $this->porDocumento[$dni] = ['user_id' => (int) $c->user_id, 'perfil' => strtoupper((string) $c->perfil)];
            }
            if ($c->pppoe_user) {
                $this->pppoe[mb_strtolower($c->pppoe_user)] = (int) $c->user_id;
            }
        }

        $this->externos = !\Illuminate\Support\Facades\Schema::hasTable('clientes_externos') ? [] : DB::table('clientes_externos as ce')
            ->join('users as u', 'u.id', '=', 'ce.user_id')
            ->where('ce.company_id', $this->companyId)
            ->where('u.company_id', $this->companyId)
            ->where('ce.origen', $this->origen)
            ->pluck('ce.user_id', 'ce.external_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        $ips = DB::table('user_data as ud')
            ->join('tabla_ips as t', 't.id', '=', 'ud.ip_assignment_id')
            ->where('ud.company_id', $this->companyId)
            ->where('ud.active', 1)
            ->whereNotNull('t.ip')
            ->get(['t.ip', 'ud.user_id', 'ud.dni']);

        foreach ($ips as $x) {
            $this->ips[trim($x->ip)] = ['user_id' => (int) $x->user_id, 'dni' => (string) $x->dni];
        }
    }

    /**
     * @param  array<int, array<string,mixed>> $filas
     * @return array<string,mixed>
     */
    private function resumen(array $filas): array
    {
        $r = [
            'total'      => count($filas),
            'nuevos'     => 0,
            'existentes' => 0,
            'invalidos'  => 0,
            'con_avisos' => 0,
            'por_estado' => ['activo' => 0, 'suspendido' => 0, 'retirado' => 0, 'desconocido' => 0],
            'conexiones' => ['pppoe' => 0, 'static' => 0, 'sin_ip' => 0],
            'saldo'      => ['clientes' => 0, 'total' => 0.0, 'facturas' => 0],
            'errores'    => [],
            'dias_pago'  => [],
        ];

        $planes = [];
        $routers = [];

        foreach ($filas as $f) {
            $d = $f['datos'];
            $r[match ($f['previo']) { 'nuevo' => 'nuevos', 'existente' => 'existentes', default => 'invalidos' }]++;
            $r['con_avisos'] += $f['avisos'] ? 1 : 0;
            $r['por_estado'][$d['estado'] ?? 'desconocido']++;

            if ($d['tipo_conexion'] === 'pppoe') {
                $r['conexiones']['pppoe']++;
            } else {
                $r['conexiones']['static']++;
                $r['conexiones']['sin_ip'] += $d['ip'] === '' ? 1 : 0;
            }

            if (($d['saldo'] ?? 0) > 0) {
                $r['saldo']['clientes']++;
                $r['saldo']['total'] += (float) $d['saldo'];
                $r['saldo']['facturas'] += (int) ($d['facturas_pendientes'] ?? 1);
            }

            foreach ($f['errores'] as $e) {
                // Agrupados por tipo, sin el dato puntual.
                $tipo = preg_replace(['/\s*\([^)]*\)/u', '/"[^"]*"|\b\d[\d.]*\b|\bPPPoE \S+ (?=ya|está)/u'], ['', '…'], $e);
                $r['errores'][$tipo] = ($r['errores'][$tipo] ?? 0) + 1;
            }

            if ($d['dia_pago']) {
                $r['dias_pago'][$d['dia_pago']] = ($r['dias_pago'][$d['dia_pago']] ?? 0) + 1;
            }

            if ($f['previo'] === 'invalido') {
                continue;
            }

            $kp = Normalizador::clave($d['plan']);
            $planes[$kp] ??= ['clave' => $kp, 'nombre' => $d['plan'] ?: '(sin plan)', 'clientes' => 0, 'precio' => null, 'bajada' => null, 'subida' => null, 'precios' => []];
            $planes[$kp]['clientes']++;
            $planes[$kp]['bajada'] ??= $d['plan_bajada'];
            $planes[$kp]['subida'] ??= $d['plan_subida'];
            if ($d['plan_precio'] !== null) {
                $planes[$kp]['precios'][(string) $d['plan_precio']] = ($planes[$kp]['precios'][(string) $d['plan_precio']] ?? 0) + 1;
            }

            $kr = Normalizador::clave($d['router']);
            $routers[$kr] ??= ['clave' => $kr, 'nombre' => $d['router'] ?: '(sin router)', 'clientes' => 0];
            $routers[$kr]['clientes']++;
        }

        $r['saldo']['total'] = round($r['saldo']['total'], 2);
        ksort($r['dias_pago']);
        arsort($r['errores']);

        $r['planes'] = $this->sugerirPlanes(array_values($planes));
        $r['routers'] = $this->sugerirRouters(array_values($routers));

        return $r;
    }

    /**
     * Para cada plan del origen: el plan de la empresa con el mismo nombre, o
     * con la misma velocidad y precio. Si no hay, se propone crearlo.
     */
    private function sugerirPlanes(array $planes): array
    {
        $propios = DB::table('internet_plans')->where('company_id', $this->companyId)
            ->get(['id', 'plan_name', 'download_speed', 'upload_speed', 'monthly_price']);

        foreach ($planes as &$p) {
            // El precio más repetido entre sus clientes.
            arsort($p['precios']);
            $p['precio'] = $p['precios'] ? (float) array_key_first($p['precios']) : null;
            $p['precios_distintos'] = count($p['precios']);
            unset($p['precios']);

            $p['sugerencia'] = null;
            $p['motivo'] = $p['clave'] === '' ? 'Elegí un plan para los clientes sin plan.' : 'No hay uno parecido: se puede crear.';

            // Un precio de tres cifras casi nunca es una mensualidad: suele ser
            // la velocidad leída de la columna equivocada. Se marca para que el
            // administrador lo cargue a mano.
            $p['precio_confiable'] = $p['precio'] !== null && $p['precio'] >= 1000;

            if (!$p['precio_confiable']) {
                $p['precio'] = null;
            }

            if ($p['clave'] === '') {
                continue;
            }

            foreach ($propios as $x) {
                if (Normalizador::clave($x->plan_name) === $p['clave']) {
                    $p['sugerencia'] = (int) $x->id;
                    $p['motivo'] = 'Mismo nombre.';
                    continue 2;
                }
            }

            foreach ($propios as $x) {
                if ($p['bajada'] && (int) $x->download_speed === (int) $p['bajada']
                    && $p['precio'] !== null && abs((float) $x->monthly_price - $p['precio']) < 1) {
                    $p['sugerencia'] = (int) $x->id;
                    $p['motivo'] = 'Misma velocidad y precio.';
                    continue 2;
                }
            }
        }

        usort($planes, fn ($a, $b) => $b['clientes'] <=> $a['clientes']);

        return $planes;
    }

    private function sugerirRouters(array $routers): array
    {
        $propios = DB::table('conection_routers')->where('company_id', $this->companyId)->get(['id', 'name']);

        foreach ($routers as &$r) {
            $r['sugerencia'] = null;

            foreach ($propios as $x) {
                if ($r['clave'] !== '' && Normalizador::clave($x->name) === $r['clave']) {
                    $r['sugerencia'] = (int) $x->id;
                    continue 2;
                }
            }

            // Con un solo router en la empresa no hay a dónde más ir.
            if ($propios->count() === 1) {
                $r['sugerencia'] = (int) $propios->first()->id;
            }
        }

        usort($routers, fn ($a, $b) => $b['clientes'] <=> $a['clientes']);

        return $routers;
    }
}
