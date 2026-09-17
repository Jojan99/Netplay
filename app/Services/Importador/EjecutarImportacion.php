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

    /** El router que se le pone a todo el que no tenga uno propio. */
    private int $routerParaTodos = 0;

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
        $this->routerParaTodos = (int) ($o['router_todos'] ?? 0);

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

                    $fila->update([
                        'resultado' => $resultado,
                        'mensaje'   => mb_substr($mensaje, 0, 255),
                        'user_id'   => $userId,
                    ] + (Esquema::filasAmpliadas() ? [
                        'factura_id'     => $fila->factura_id,
                        'grupo_elegido'  => $fila->grupo_elegido,
                        'router_elegido' => $fila->router_elegido,
                    ] : []));

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
        // El nombre se parte con la regla que eligió el administrador.
        $d = Normalizador::conRegla($fila->datos, (string) ($o['regla_nombre'] ?? 'auto'));
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

        $routerId = $this->routerDe($fila, $d);
        $grupo = $this->grupoDe($fila, $d, $clavePlan);

        // Queda escrito lo que efectivamente se aplicó: es lo que después
        // muestra el reporte.
        if (Esquema::filasAmpliadas()) {
            $fila->grupo_elegido = $grupo;
            $fila->router_elegido = $routerId;
        }

        if (!$grupo) {
            return ['error', 'No hay grupo de facturación para este cliente.', null];
        }

        try {
            return DB::transaction(function () use ($fila, $d, $companyId, $planId, $routerId, $estado, $grupo, $o) {
                $existente = $this->buscarExistente($companyId, $d);

                if ($error = $this->conflictos($companyId, $d, $existente)) {
                    return ['error', $error, $existente];
                }

                if ($existente) {
                    if (($o['existentes'] ?? 'omitir') !== 'actualizar') {
                        $this->vincular($companyId, $d, $existente);

                        return ['omitido', "Ya existía (documento {$d['dni']}): no se modificó.", $existente];
                    }

                    $this->actualizar($companyId, $existente, $d, $planId, $routerId, $estado, $grupo);
                    $factura = $this->facturaDeSaldo($companyId, $existente, $d, $grupo, $fila);

                    return ['actualizado', 'Datos actualizados.' . $this->textoFactura($factura), $existente];
                }

                $userId = $this->crear($companyId, $d, $planId, $routerId, $estado, $grupo);
                $factura = $this->facturaDeSaldo($companyId, $userId, $d, $grupo, $fila);

                return ['creado', 'Cliente creado.' . $this->textoFactura($factura), $userId];
            });
        } catch (\Throwable $e) {
            Log::warning('[Importador] No se pudo importar un cliente', [
                'importacion' => $this->imp->id, 'fila' => $fila->fila, 'error' => $e->getMessage(),
            ]);

            // La transacción se deshizo: la factura que se hubiera creado adentro
            // tampoco existe.
            if (Esquema::filasAmpliadas()) {
                $fila->factura_id = null;
            }

            return ['error', 'No se pudo guardar: ' . mb_substr($e->getMessage(), 0, 180), null];
        }
    }

    /** Lo que el administrador eligió a mano para ese cliente, si la base ya lo guarda. */
    private function deLaFila(ImportacionFila $fila, string $campo): ?int
    {
        return Esquema::filasAmpliadas() ? ((int) $fila->getOriginal($campo) ?: null) : null;
    }

    private function textoFactura(?array $factura): string
    {
        return $factura
            ? ' Factura de saldo ' . $factura['numero'] . ' por $' . number_format($factura['valor'], 0, ',', '.') . '.'
            : '';
    }

    /**
     * El router del cliente: el que le eligieron a mano, el del nombre que
     * traía el archivo, o el que se puso para todos.
     *
     * @param  array<string,mixed> $d
     */
    private function routerDe(ImportacionFila $fila, array $d): ?int
    {
        if ($this->deLaFila($fila, 'router_elegido')) {
            return (int) $fila->getOriginal('router_elegido');
        }

        $porNombre = $this->routers[Normalizador::clave($d['router'] ?? '')] ?? null;

        return $porNombre ?: ($this->routerParaTodos ?: null);
    }

    /**
     * El grupo de facturación: el elegido a mano, el de la regla (por plan,
     * router o estado), el que sale del día de corte del origen, o el general.
     *
     * @param  array<string,mixed> $d
     */
    private function grupoDe(ImportacionFila $fila, array $d, string $clavePlan): ?int
    {
        if ($this->deLaFila($fila, 'grupo_elegido')) {
            return (int) $fila->getOriginal('grupo_elegido');
        }

        $o = $this->imp->opciones ?? [];

        $porRegla = match ((string) ($o['grupo_modo'] ?? 'todos')) {
            'plan'   => $o['grupos_por_plan'][$clavePlan] ?? null,
            'router' => $o['grupos_por_router'][Normalizador::clave($d['router'] ?? '')] ?? null,
            'estado' => $o['grupos_por_estado'][$d['estado'] ?? 'activo'] ?? null,
            default  => null,
        };

        if ($porRegla) {
            return (int) $porRegla;
        }

        if (!empty($d['dia_pago']) && isset($this->grupoPorDia[(int) $d['dia_pago']])) {
            return $this->grupoPorDia[(int) $d['dia_pago']];
        }

        return (int) ($o['grupo'] ?? 0) ?: null;
    }

    /**
     * La factura del saldo que el cliente traía de la otra plataforma.
     *
     * Se crea con el mismo camino que una factura manual del panel
     * (FacturationRepository: prefijo y consecutivo por empresa), nunca se
     * envía desde acá y queda anotada en la fila para el reporte.
     *
     * @param  array<string,mixed> $d
     * @return array{id:int, numero:string, valor:float}|null
     */
    private function facturaDeSaldo(int $companyId, int $userId, array $d, int $grupo, ImportacionFila $fila): ?array
    {
        $o = $this->imp->opciones['saldo'] ?? [];
        $valor = (float) ($d['saldo'] ?? 0);

        if (empty($o['crear']) || $valor <= 0 || !Esquema::conceptoEnFacturas()) {
            return null;
        }

        $concepto = mb_substr(trim((string) ($o['concepto'] ?? '')) ?: 'Saldo anterior', 0, 160);

        $cab = CabFacturation::where('user_id', $userId)->where('company_id', $companyId)
            ->orderByRaw('CASE WHEN `group` = ? THEN 0 ELSE 1 END', [$grupo])
            ->orderBy('id')
            ->first();

        if (!$cab) {
            return null;
        }

        // Volver a correr la importación no le crea la factura dos veces.
        $ya = DB::table('det_facturations')->where('cab_id', $cab->id)->where('concepto', $concepto)->first(['id', 'number_facture', 'price_total']);

        if ($ya) {
            if (Esquema::filasAmpliadas()) {
                $fila->factura_id = (int) $ya->id;
            }

            return ['id' => (int) $ya->id, 'numero' => (string) $ya->number_facture, 'valor' => (float) $ya->price_total];
        }

        $fecha = $this->fechaDeFactura($companyId, $grupo, $cab);

        // Se arma como un POST de verdad: un FormRequest sin método lee del
        // query string y los datos no llegaban.
        $datos = \App\Http\Requests\Facturation\CreateFacturationRequest::create('/importador/saldo', 'POST', [
            'cab_id'                  => $cab->id,
            'date_facturation'        => $fecha,
            'date_create_facturation' => $fecha,
            'total'                   => 1,
            'price_total'             => round($valor, 2),
            'porcentage_discount'     => 0,
            'days_facture'            => 0,
            'discount'                => 0,
            'price_discount'          => 0,
            'create_facture_manual'   => 1,
        ]);

        $det = (new \App\Repositories\FacturationRepository())->createDetFacturation($datos);

        // El concepto y, si se pidió, la marca de "ya enviada" para que el
        // envío diario de facturas por correo no salga con estas.
        $cambios = ['concepto' => $concepto];
        if (!empty($o['evitar_envio'])) {
            $cambios['email_sent_at'] = now();
        }
        DB::table('det_facturations')->where('id', $det->id)->update($cambios);

        if (Esquema::filasAmpliadas()) {
            $fila->factura_id = (int) $det->id;
        }

        return ['id' => (int) $det->id, 'numero' => (string) $det->number_facture, 'valor' => round($valor, 2)];
    }

    /** La fecha que lleva la factura del saldo. */
    private function fechaDeFactura(int $companyId, int $grupo, CabFacturation $cab): string
    {
        $o = $this->imp->opciones['saldo'] ?? [];

        if (($o['fecha_modo'] ?? 'corte') === 'fecha' && !empty($o['fecha'])) {
            return (string) $o['fecha'];
        }
        if (($o['fecha_modo'] ?? 'corte') === 'hoy') {
            return now()->format('Y-m-d');
        }

        // Día de corte del grupo, en el mes en curso.
        $dia = (int) (DB::table('company_billing_schedules')
            ->where('company_id', $companyId)->where('grupo', $grupo)->value('billing_day') ?: 0);

        if (!$dia) {
            return (string) ($cab->date_init_facturation ?: now()->format('Y-m-d'));
        }

        $hoy = Carbon::now();

        return $hoy->copy()->setDate((int) $hoy->format('Y'), (int) $hoy->format('m'), min($dia, (int) $hoy->copy()->endOfMonth()->format('d')))->format('Y-m-d');
    }

    /** Los planes elegidos; los marcados "crear" se crean una sola vez (o se reusan si ya hay uno con ese nombre). */
    private function prepararPlanes(int $companyId, array $eleccion, string $tipo): void
    {
        $resumen = collect($this->imp->analisis['planes'] ?? [])->keyBy('clave');
        $precios = (array) ($this->imp->opciones['precios'] ?? []);

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

            $precio = (float) ($precios[$clave] ?? $p['precio'] ?? 0);

            $existente = InternetPlan::where('company_id', $companyId)->get(['id', 'plan_name'])
                ->first(fn ($x) => Normalizador::clave($x->plan_name) === $clave);

            if ($existente) {
                // Ya había uno con ese nombre: se reusa y sólo se le pone el
                // precio si estaba en cero y ahora lo cargaron.
                if ($precio > 0 && (float) InternetPlan::where('id', $existente->id)->value('monthly_price') <= 0) {
                    InternetPlan::where('id', $existente->id)->update(['monthly_price' => $precio]);
                }

                $this->planes[$clave] = (int) $existente->id;
                continue;
            }

            $this->planes[$clave] = (int) InternetPlan::create([
                'company_id'     => $companyId,
                'plan_name'      => mb_substr($p['nombre'], 0, 255),
                'download_speed' => (string) ($p['bajada'] ?? 0),
                'upload_speed'   => (string) ($p['subida'] ?? 0),
                'monthly_price'  => $precio,
                'description'    => 'Importado de ' . ucfirst($this->imp->origen),
                'type'           => in_array($tipo, ['fibra', 'wireless', 'cable', 'dsl', 'otro'], true) ? $tipo : 'fibra',
                'active'         => true,
            ])->id;
        }

        // Precios de planes que ya existían en la empresa, sólo si el
        // administrador marcó pisarlos.
        foreach ((array) ($this->imp->opciones['actualizar_precio'] ?? []) as $clave => $si) {
            $planId = $this->planes[(string) $clave] ?? null;
            $precio = (float) ($precios[(string) $clave] ?? 0);

            if ($si && $planId && $precio > 0) {
                InternetPlan::where('id', $planId)->where('company_id', $companyId)->update(['monthly_price' => $precio]);
            }
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

    private function crear(int $companyId, array $d, int $planId, ?int $routerId, string $estado, int $grupo): int
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

        $this->crearFacturacion($companyId, (int) $user->id, $grupo);
        $this->vincular($companyId, $d, (int) $user->id);

        return (int) $user->id;
    }

    private function actualizar(int $companyId, int $userId, array $d, int $planId, ?int $routerId, string $estado, int $grupo): void
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
            $this->crearFacturacion($companyId, $userId, $grupo);
        }

        $this->vincular($companyId, $d, $userId);
    }

    /**
     * La cabecera de facturación, con la misma regla de fechas que el alta del
     * panel. Si se pidió cobrar el mes completo se la fecha un mes atrás: la
     * facturación prorratea a los clientes con menos de 20 días, y un cliente
     * que viene de otra plataforma no es nuevo.
     */
    private function crearFacturacion(int $companyId, int $userId, int $grupo): void
    {
        $o = $this->imp->opciones ?? [];
        $hoy = Carbon::now();
        // El día que se le cobra es el del grupo de la empresa; si el grupo no
        // está configurado se cae al 15/30 del alta del panel.
        $dias = $this->diasDeGrupo($companyId);
        $cabs = $grupo === 3 && !isset($dias[3])
            ? [[1, $dias[1] ?? 15], [2, $dias[2] ?? 30]]
            : [[$grupo, $dias[$grupo] ?? ($grupo === 1 ? 15 : 30)]];

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

    /** @var array<int,int>|null grupo => día de facturación */
    private ?array $diasPorGrupo = null;

    /** @return array<int,int> */
    private function diasDeGrupo(int $companyId): array
    {
        return $this->diasPorGrupo ??= DB::table('company_billing_schedules')
            ->where('company_id', $companyId)->where('active', true)
            ->pluck('billing_day', 'grupo')
            ->map(fn ($d) => (int) $d)
            ->all();
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
