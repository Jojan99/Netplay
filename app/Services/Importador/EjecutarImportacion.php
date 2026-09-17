<?php

namespace App\Services\Importador;

use App\Models\CabFacturation;
use App\Models\ClienteExterno;
use App\Models\Importacion;
use App\Models\ImportacionFila;
use App\Models\InternetPlan;
use App\Models\TablaIp;
use App\Models\User;
use App\Models\UserData;
use App\Services\Red\AsignacionDeIp;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Da de alta (o actualiza) los clientes de una importación ya revisada.
 *
 * Deja a cada cliente igual que el alta desde el panel (CreateUserDataUseCase
 * + UserRepository): usuario con perfil USER y clave = documento, ficha con
 * plan, router, conexión y estado, ficha de IP si es IP fija y la cabecera de
 * facturación de su grupo. Pero sin los efectos del alta del panel:
 *
 * - No toca el MikroTik (ni ARP ni PPPoE): el cliente ya está configurado en
 *   su router desde la plataforma anterior.
 * - No manda avisos (NotificationRouterService new_user, WhatsApp, correo).
 * - No genera facturas: sólo la cabecera, como el panel. El saldo pendiente de
 *   la plataforma anterior se guarda como referencia en clientes_externos.
 *
 * Cada cliente va en su propia transacción: si uno falla, no arrastra a los
 * demás, y volver a correr la importación sigue desde los que faltan.
 */
class EjecutarImportacion
{
    private ?int $perfilCliente = null;

    /** @var array<string,int|null> clave de plan => internet_plans.id */
    private array $planes = [];

    /** @var array<string,int|null> clave de router => conection_routers.id */
    private array $routers = [];

    /** @var array<int,int> día de facturación => grupo */
    private array $grupoPorDia = [];

    public function __construct(private Importacion $imp)
    {
    }

    public function ejecutar(): void
    {
        $imp = $this->imp;
        $companyId = (int) $imp->company_id;
        $o = $imp->opciones ?? [];

        $imp->update([
            'estado'      => 'importando',
            'iniciada_en' => $imp->iniciada_en ?? now(),
            'detalle'     => 'Preparando planes y routers…',
        ]);

        $this->prepararPlanes($companyId, (array) ($o['planes'] ?? []), (string) ($o['tipo_plan'] ?? 'fibra'));
        $this->routers = array_map(fn ($v) => $v ? (int) $v : null, (array) ($o['routers'] ?? []));

        if (!empty($o['grupo_por_dia'])) {
            $this->grupoPorDia = DB::table('company_billing_schedules')
                ->where('company_id', $companyId)->where('active', true)
                ->pluck('grupo', 'billing_day')->map(fn ($g) => (int) $g)->all();
        }

        $contadores = [
            'creados'      => (int) $imp->creados,
            'actualizados' => (int) $imp->actualizados,
            'omitidos'     => (int) $imp->omitidos,
            'errores'      => (int) $imp->errores,
        ];
        $procesadas = (int) $imp->procesadas;
        $cancelada = false;

        ImportacionFila::where('importacion_id', $imp->id)
            ->whereNull('resultado')
            ->orderBy('id')
            ->chunkById(100, function ($filas) use (&$contadores, &$procesadas, &$cancelada, $imp) {
                foreach ($filas as $fila) {
                    if ($procesadas % 20 === 0 && Importacion::where('id', $imp->id)->value('estado') === 'cancelando') {
                        $cancelada = true;

                        return false;
                    }

                    [$resultado, $mensaje, $userId] = $this->procesar($fila);

                    $fila->update(['resultado' => $resultado, 'mensaje' => mb_substr($mensaje, 0, 255), 'user_id' => $userId]);

                    $contadores[match ($resultado) {
                        'creado' => 'creados', 'actualizado' => 'actualizados', 'omitido' => 'omitidos', default => 'errores',
                    }]++;
                    $procesadas++;

                    if ($procesadas % 10 === 0) {
                        $imp->update($contadores + [
                            'procesadas' => $procesadas,
                            'detalle'    => "Importando clientes: {$procesadas} de {$imp->total}…",
                        ]);
                    }
                }

                return true;
            });

        $imp->update($contadores + [
            'procesadas'   => $procesadas,
            'estado'       => $cancelada ? 'cancelada' : 'listo',
            'terminada_en' => now(),
            'detalle'      => ($cancelada ? 'Cancelada: ' : 'Terminada: ')
                . "{$contadores['creados']} creados, {$contadores['actualizados']} actualizados, "
                . "{$contadores['omitidos']} omitidos, {$contadores['errores']} con error.",
        ]);

        // El archivo subido tiene datos de clientes y contraseñas PPPoE: ya no hace falta.
        if (!$cancelada && $imp->archivo) {
            Storage::disk('local')->delete($imp->archivo);
            $imp->update(['archivo' => null]);
        }

        Log::info('[Importador] Importación terminada', ['importacion' => $imp->id, 'company_id' => $companyId] + $contadores);
    }

    /**
     * @return array{0:string,1:string,2:?int} resultado, mensaje, user_id
     */
    public function procesar(ImportacionFila $fila): array
    {
        $o = $this->imp->opciones ?? [];
        $d = $fila->datos;
        $companyId = (int) $this->imp->company_id;

        if ($fila->previo === 'invalido') {
            $errores = $fila->avisos['errores'] ?? [];

            return ['error', $errores[0] ?? 'La fila tiene datos inválidos.', null];
        }

        $estado = $d['estado'] ?? 'activo';
        if (!in_array($estado, (array) ($o['estados'] ?? ['activo', 'suspendido']), true)) {
            return ['omitido', "No se importan los clientes en estado {$estado}.", null];
        }

        $clavePlan = Normalizador::clave($d['plan'] ?? '');
        $planId = $this->planes[$clavePlan] ?? null;
        if (!$planId) {
            return ['error', 'No se eligió un plan para "' . ($d['plan'] ?: 'sin plan') . '".', null];
        }

        $routerId = $this->routers[Normalizador::clave($d['router'] ?? '')] ?? null;

        try {
            return DB::transaction(function () use ($d, $companyId, $planId, $routerId, $estado, $o) {
                $existente = $this->buscarExistente($companyId, $d);

                if ($error = $this->conflictos($companyId, $d, $existente)) {
                    return ['error', $error, $existente];
                }

                if ($existente) {
                    if (($o['existentes'] ?? 'omitir') !== 'actualizar') {
                        $this->vincular($companyId, $d, $existente);

                        return ['omitido', "Ya existía (documento {$d['dni']}): no se modificó.", $existente];
                    }

                    $this->actualizar($companyId, $existente, $d, $planId, $routerId, $estado);

                    return ['actualizado', 'Datos actualizados.', $existente];
                }

                $userId = $this->crear($companyId, $d, $planId, $routerId, $estado);

                return ['creado', 'Cliente creado.', $userId];
            });
        } catch (\Throwable $e) {
            Log::warning('[Importador] No se pudo importar un cliente', [
                'importacion' => $this->imp->id, 'fila' => $fila->fila, 'error' => $e->getMessage(),
            ]);

            return ['error', 'No se pudo guardar: ' . mb_substr($e->getMessage(), 0, 180), null];
        }
    }

    /** Los planes elegidos; los marcados "crear" se crean una sola vez (o se reusan si ya hay uno con ese nombre). */
    private function prepararPlanes(int $companyId, array $eleccion, string $tipo): void
    {
        $resumen = collect($this->imp->analisis['planes'] ?? [])->keyBy('clave');

        foreach ($eleccion as $clave => $valor) {
            $clave = (string) $clave;

            if ($valor !== 'crear') {
                $this->planes[$clave] = $valor ? (int) $valor : null;
                continue;
            }

            $p = $resumen[$clave] ?? null;
            if (!$p || $clave === '') {
                $this->planes[$clave] = null;
                continue;
            }

            $existente = InternetPlan::where('company_id', $companyId)->get(['id', 'plan_name'])
                ->first(fn ($x) => Normalizador::clave($x->plan_name) === $clave);

            $this->planes[$clave] = $existente ? (int) $existente->id : (int) InternetPlan::create([
                'company_id'     => $companyId,
                'plan_name'      => mb_substr($p['nombre'], 0, 255),
                'download_speed' => (string) ($p['bajada'] ?? 0),
                'upload_speed'   => (string) ($p['subida'] ?? 0),
                'monthly_price'  => (float) ($p['precio'] ?? 0),
                'description'    => 'Importado de ' . ucfirst($this->imp->origen),
                'type'           => in_array($tipo, ['fibra', 'wireless', 'cable', 'dsl', 'otro'], true) ? $tipo : 'fibra',
                'active'         => true,
            ])->id;
        }
    }

    private function buscarExistente(int $companyId, array $d): ?int
    {
        if (($d['external_id'] ?? '') !== '') {
            $id = DB::table('clientes_externos as ce')
                ->join('users as u', 'u.id', '=', 'ce.user_id')
                ->where('ce.company_id', $companyId)->where('u.company_id', $companyId)
                ->where('ce.origen', $this->imp->origen)->where('ce.external_id', $d['external_id'])
                ->value('ce.user_id');

            if ($id) {
                return (int) $id;
            }
        }

        $buscado = Normalizador::documentoParaComparar($d['dni']);

        // Se compara igual que en la vista previa: "1.098.765" y "1098765" son el mismo.
        $candidatos = DB::table('user_data as ud')
            ->join('users as u', 'u.id', '=', 'ud.user_id')
            ->where('u.company_id', $companyId)->where('ud.company_id', $companyId)
            ->where(function ($q) use ($d, $buscado) {
                $q->where('ud.dni', $d['dni'])->orWhere('ud.dni', $buscado)
                    ->orWhereRaw("REPLACE(REPLACE(REPLACE(ud.dni, '.', ''), ' ', ''), ',', '') = ?", [$buscado]);
            })
            ->pluck('ud.user_id');

        return $candidatos->isNotEmpty() ? (int) $candidatos->first() : null;
    }

    /** Lo que impide guardar, vuelto a mirar en el momento (pudo cambiar desde la vista previa). */
    private function conflictos(int $companyId, array $d, ?int $existente): ?string
    {
        if ($existente) {
            $perfil = DB::table('users as u')->join('profiles as p', 'p.id', '=', 'u.profile_id')
                ->where('u.id', $existente)->value('p.name');

            if (in_array(strtoupper((string) $perfil), ['ADMIN', 'TECNICO', 'CONTADOR'], true)) {
                return 'El documento es de un usuario del equipo de trabajo, no de un cliente.';
            }
        }

        if ($d['tipo_conexion'] === 'pppoe') {
            $otro = DB::table('user_data')->where('company_id', $companyId)
                ->where('pppoe_user', $d['pppoe_usuario'])
                ->when($existente, fn ($q) => $q->where('user_id', '<>', $existente))
                ->value('dni');

            if ($otro !== null) {
                return "El usuario PPPoE {$d['pppoe_usuario']} ya lo tiene otro cliente (documento {$otro}).";
            }
        } elseif ($d['ip'] !== '') {
            $otro = DB::table('user_data as ud')->join('tabla_ips as t', 't.id', '=', 'ud.ip_assignment_id')
                ->where('ud.company_id', $companyId)->where('ud.active', 1)->where('t.ip', $d['ip'])
                ->when($existente, fn ($q) => $q->where('ud.user_id', '<>', $existente))
                ->value('ud.dni');

            if ($otro !== null) {
                return "La IP {$d['ip']} ya la usa otro cliente (documento {$otro}).";
            }
        }

        return null;
    }

    /** @return array{active:int,status:int,status_internet_id:int} */
    private static function camposDeEstado(string $estado): array
    {
        // Los mismos valores que usan la suspensión (AutoSuspendService) y la
        // baja (UserRepository::DeleteUserData).
        return match ($estado) {
            'suspendido' => ['active' => 1, 'status' => 1, 'status_internet_id' => 2],
            'retirado'   => ['active' => 0, 'status' => 1, 'status_internet_id' => 2],
            default      => ['active' => 1, 'status' => 0, 'status_internet_id' => 1],
        };
    }

    private function crear(int $companyId, array $d, int $planId, ?int $routerId, string $estado): int
    {
        $user = User::create([
            'username'   => $d['dni'],
            'email'      => $d['email'],
            'password'   => Hash::make($d['dni']),
            'profile_id' => $this->perfilDeCliente($companyId),
            'company_id' => $companyId,
        ]);

        $esPppoe = $d['tipo_conexion'] === 'pppoe';

        // Un retirado no ocupa IP: si no, quedaría fuera de las IP libres.
        $ipId = null;
        if (!$esPppoe && $d['ip'] !== '' && $estado !== 'retirado') {
            $ipId = TablaIp::create([
                'company_id' => $companyId,
                'id_user'    => $user->id,
                'active'     => 1,
                'ip'         => $d['ip'],
                'mac'        => $d['mac'] ?: null,
            ])->id;
        }

        UserData::create([
            'names'              => $d['nombres'],
            'lastname'           => $d['apellidos'],
            'address'            => $d['direccion'],
            'user_id'            => $user->id,
            'company_id'         => $companyId,
            'role_id'            => $this->perfilDeCliente($companyId),
            'gender_id'          => 1,
            'dni_id'             => 1,
            'internet_plans_id'  => $planId,
            'country_id'         => 1,
            'dni'                => $d['dni'],
            'email'              => $d['email'],
            'phone'              => $d['telefono'],
            'birthday'           => 1,
            'ip_assignment_id'   => $ipId,
            'router_id'          => $routerId,
            'connection_type'    => $esPppoe ? 'pppoe' : 'static',
            'pppoe_user'         => $esPppoe ? $d['pppoe_usuario'] : null,
            'pppoe_password'     => $esPppoe && $d['pppoe_clave'] !== '' ? $d['pppoe_clave'] : null,
            'pppoe_profile'      => $esPppoe && $d['pppoe_perfil'] !== '' ? $d['pppoe_perfil'] : null,
        ] + self::camposDeEstado($estado));

        $this->crearFacturacion($companyId, (int) $user->id, $d);
        $this->vincular($companyId, $d, (int) $user->id);

        return (int) $user->id;
    }

    private function actualizar(int $companyId, int $userId, array $d, int $planId, ?int $routerId, string $estado): void
    {
        $ficha = UserData::where('user_id', $userId)->where('company_id', $companyId)->firstOrFail();

        // Sólo se pisa con lo que trae el origen: un dato vacío no borra el que hay.
        $cambios = array_filter([
            'names'    => $d['nombres'],
            'lastname' => $d['apellidos'],
            'address'  => $d['direccion'],
            'email'    => $d['email'],
            'phone'    => $d['telefono'],
        ], fn ($v) => $v !== '' && $v !== null);

        $cambios['internet_plans_id'] = $planId;
        if ($routerId) {
            $cambios['router_id'] = $routerId;
        }
        $cambios += self::camposDeEstado($estado);

        if ($d['tipo_conexion'] === 'pppoe') {
            $cambios['connection_type'] = 'pppoe';
            $cambios['pppoe_user'] = $d['pppoe_usuario'];
            if ($d['pppoe_clave'] !== '') {
                $cambios['pppoe_password'] = $d['pppoe_clave'];
            }
            if ($d['pppoe_perfil'] !== '') {
                $cambios['pppoe_profile'] = $d['pppoe_perfil'];
            }
            $cambios['ip_assignment_id'] = null;
        } else {
            $cambios['connection_type'] = 'static';
        }

        $ficha->update($cambios);

        if ($d['tipo_conexion'] !== 'pppoe' && $d['ip'] !== '') {
            $ficha->ip_assignment_id
                ? AsignacionDeIp::asignar($userId, $d['ip'], $companyId)
                : AsignacionDeIp::fichaPropia($userId, $d['ip'], $companyId);
        }

        if (!CabFacturation::where('user_id', $userId)->exists()) {
            $this->crearFacturacion($companyId, $userId, $d);
        }

        $this->vincular($companyId, $d, $userId);
    }

    /**
     * La cabecera de facturación, con la misma regla de fechas que el alta del
     * panel. Si se pidió cobrar el mes completo se la fecha un mes atrás: la
     * facturación prorratea a los clientes con menos de 20 días, y un cliente
     * que viene de otra plataforma no es nuevo.
     */
    private function crearFacturacion(int $companyId, int $userId, array $d): void
    {
        $o = $this->imp->opciones ?? [];
        $grupo = (int) ($o['grupo'] ?? 1);

        if (!empty($d['dia_pago']) && isset($this->grupoPorDia[(int) $d['dia_pago']])) {
            $grupo = $this->grupoPorDia[(int) $d['dia_pago']];
        }

        $hoy = Carbon::now();
        $cabs = $grupo === 3
            ? [[1, 15], [2, 30]]
            : [[$grupo, $grupo === 1 ? 15 : 30]];

        foreach ($cabs as [$g, $dia]) {
            $cab = CabFacturation::create([
                'user_id'               => $userId,
                'company_id'            => $companyId,
                'date_init_facturation' => (clone $hoy)->setDate((int) $hoy->format('Y'), (int) $hoy->format('m'), $dia)->format('Y-m-d'),
                'group'                 => $g,
            ]);

            if (!empty($o['cobro_mes_completo'])) {
                $cab->timestamps = false;
                $cab->created_at = now()->subMonth()->startOfDay();
                $cab->save();
            }
        }
    }

    /** Guarda el id de origen para que la próxima importación lo reconozca. */
    private function vincular(int $companyId, array $d, int $userId): void
    {
        if (($d['external_id'] ?? '') === '') {
            return;
        }

        ClienteExterno::updateOrCreate(
            ['company_id' => $companyId, 'origen' => $this->imp->origen, 'external_id' => $d['external_id']],
            ['user_id' => $userId, 'importacion_id' => $this->imp->id, 'saldo_origen' => $d['saldo']]
        );
    }

    /** El perfil USER de la empresa, creándolo si falta (igual que UserRepository). */
    private function perfilDeCliente(int $companyId): int
    {
        if ($this->perfilCliente) {
            return $this->perfilCliente;
        }

        $id = DB::table('profiles')->where('company_id', $companyId)->where('name', 'USER')->value('id');

        return $this->perfilCliente = (int) ($id ?: DB::table('profiles')->insertGetId([
            'company_id' => $companyId,
            'name'       => 'USER',
            'active'     => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }
}
