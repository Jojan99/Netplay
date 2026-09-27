<?php

namespace App\Http\Controllers;

use App\Models\InstallationOrder;
use App\Models\InstallationLog;
use App\Models\UserData;
use App\Models\InternetPlan;
use App\Models\Employee;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class InstallationOrderController extends Controller
{
    /** El perfil de quien hace la petición, en minúscula ('admin', 'tecnico', 'contador'…). */
    private function miPerfil(): string
    {
        return strtolower((string) DB::table('profiles')->where('id', getSessionUserProfileId())->value('name'));
    }

    /** Su id de empleado, si lo tiene; null si es admin/contador o no está de alta como empleado. */
    private function miEmpleadoId(): ?int
    {
        return Employee::where('company_id', getSessionCompanyId())->where('user_id', getSessionUserId())->value('id');
    }

    public function index(Request $request)
    {
        $companyId = getSessionCompanyId();

        $query = InstallationOrder::where('company_id', $companyId)
            ->with(['client', 'plan', 'paymentMethod']);

        // Un técnico sólo ve lo suyo, y sólo lo que tiene por instalar: pendiente, confirmada o en
        // proceso. Ni la agenda completa de la empresa, ni lo ya completado o cancelado. Sin ficha
        // de empleado, no ve ninguna (no que vea todo por descarte).
        if ($this->miPerfil() === 'tecnico') {
            $miId = $this->miEmpleadoId();
            $miId ? $query->whereJsonContains('technician_ids', $miId) : $query->whereRaw('0 = 1');
            $query->whereIn('status', ['pending', 'confirmed', 'in_progress']);
        }

        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }
        
        if ($request->has('payment_status') && $request->payment_status) {
            $query->where('payment_status', $request->payment_status);
        }
        
        if ($request->has('technician_id') && $request->technician_id) {
            $query->where(function ($q) use ($request) {
                $q->whereJsonContains('technician_ids', (int) $request->technician_id);
            });
        }
        
        if ($request->has('date_from') && $request->date_from) {
            $query->where('scheduled_date', '>=', $request->date_from);
        }
        
        if ($request->has('date_to') && $request->date_to) {
            $query->where('scheduled_date', '<=', $request->date_to);
        }
        
        $installations = $query->orderBy('scheduled_date', 'asc')
            ->orderBy('scheduled_time', 'asc')
            ->paginate($request->get('per_page', 20));
        
        $installations->getCollection()->transform(function ($inst) {
            if (!empty($inst->technician_ids)) {
                $inst->technicians_list = Employee::where('company_id', getSessionCompanyId())->whereIn('id', $inst->technician_ids)->get(['id', 'first_name', 'last_name']);
            }
            return $inst;
        });
        
        return response()->json($installations);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_name' => 'required|string|max:255',
            'client_firstname' => 'nullable|string|max:255',
            'client_lastname' => 'nullable|string|max:255',
            'client_dni' => 'required|string|max:50',
            'client_phone' => 'required|string|max:20',
            'client_email' => 'nullable|email',
            'address' => 'required|string|max:500',
            'neighborhood' => 'nullable|string|max:255',
            'internet_plan_id' => ['nullable', $this->deLaEmpresa('internet_plans')],
            // El servicio y la conexión que se acordaron al tomar el pedido.
            // Viajan con la orden hasta la calle: es lo que el técnico aplica
            // sin llamar a la oficina.
            'grupo_facturacion' => 'nullable|integer|in:1,2,3',
            'connection_type' => 'nullable|in:pppoe,static',
            'pppoe_user' => 'nullable|string|max:120',
            'pppoe_password' => 'nullable|string|max:120',
            'pppoe_profile' => 'nullable|string|max:120',
            'ip_asignada' => 'nullable|ip',
            'router_id' => ['nullable', $this->deLaEmpresa('conection_routers')],
            'olt_id' => ['nullable', $this->deLaEmpresa('olt_admins')],
            'vlan' => 'nullable|integer|min:1|max:4094',
            // Los perfiles con los que se autoriza la ONT: se eligen de los que
            // la OLT tiene sincronizados, no se escriben a mano.
            'line_profile_id' => 'nullable|integer|min:0',
            'srv_profile_id' => 'nullable|integer|min:0',
            'onu_type' => 'nullable|string|max:60',
            // La ñ y las tildes las rechaza el equipo, y el SSID y la clave se
            // mandan juntos: uno malo hace fallar los dos.
            'wifi_ssid' => 'nullable|string|max:32|regex:/^[A-Za-z0-9\-_. ]+$/',
            'wifi_password' => 'nullable|string|min:8|max:63|regex:/^[A-Za-z0-9\-_.@#$%&*+=!?():,]+$/',
            'scheduled_date' => 'required|date',
            'scheduled_time' => 'required',
            'installation_cost' => 'nullable|numeric|min:0',
            'technician_ids' => 'nullable|array',
            'technician_ids.*' => [$this->deLaEmpresa('employees')],
            'payment_method_id' => ['nullable', $this->deLaEmpresa('payment_methods')],
            'commission_amount' => 'nullable|numeric|min:0',
            'observations' => 'nullable|string',
            'user_data_id' => ['nullable', $this->deLaEmpresa('user_data')],
        ]);
        
        // ¿Esta orden va a dar de alta un cliente nuevo? Entonces necesita un lugar en el plan, y el
        // aviso tiene que llegar ahora, a quien toma el pedido: si no, el técnico se quedaría en la
        // casa del cliente sin poder terminar.
        $esNuevo = empty($validated['user_data_id'] ?? null)
            && !DB::table('user_data')->where('company_id', getSessionCompanyId())->where('dni', trim($validated['client_dni']))->exists();

        if ($esNuevo && ($motivo = \App\Services\Plataforma\LimiteDeClientes::motivoDeBloqueo((int) getSessionCompanyId()))) {
            return response()->json(['message' => $motivo, 'data' => ['limite' => \App\Services\Plataforma\LimiteDeClientes::estado((int) getSessionCompanyId())]], 422);
        }

        // Dar de alta una instalación (precio, comisión, a quién se asigna) es de oficina: la ruta
        // sólo llega hasta acá con «role:admin,contador» (ver routes/api/installationRoutes.php). Un
        // técnico no crea órdenes; lo que sí puede es una de práctica, aparte, con practica().

        $validated['company_id'] = getSessionCompanyId();
        $validated['created_by'] = Auth::id();
        $validated['status'] = 'pending';
        $validated['payment_status'] = 'pending';

        $installation = InstallationOrder::create($validated);
        
        // Create log for installation creation
        InstallationLog::create([
            'installation_id' => $installation->id,
            'action' => 'create',
            'description' => 'Orden de instalación creada',
            'notes' => "Cliente: {$validated['client_name']}, Dirección: {$validated['address']}",
            'created_by' => Auth::id(),
        ]);

        $this->avisarInstalacionAgendada($installation, $validated);

        return response()->json([
            'message' => 'Orden de instalación creada exitosamente',
            'data' => $installation
        ], 201);
    }

    public function show($id)
    {
        $installation = InstallationOrder::where('company_id', getSessionCompanyId())
            ->with(['client', 'plan', 'paymentMethod', 'creator', 'assignee', 'logs'])
            ->findOrFail($id);
        
        return response()->json($installation);
    }

    public function update(Request $request, $id)
    {
        $installation = InstallationOrder::where('company_id', getSessionCompanyId())
            ->findOrFail($id);
        
        $validated = $request->validate([
            'client_name' => 'sometimes|string|max:255',
            'client_firstname' => 'nullable|string|max:255',
            'client_lastname' => 'nullable|string|max:255',
            'client_dni' => 'sometimes|string|max:50',
            'client_phone' => 'sometimes|string|max:20',
            'client_email' => 'nullable|email',
            'address' => 'sometimes|string|max:500',
            'neighborhood' => 'nullable|string|max:255',
            'internet_plan_id' => ['nullable', $this->deLaEmpresa('internet_plans')],
            // El servicio y la conexión que se acordaron al tomar el pedido.
            // Viajan con la orden hasta la calle: es lo que el técnico aplica
            // sin llamar a la oficina.
            'grupo_facturacion' => 'nullable|integer|in:1,2,3',
            'connection_type' => 'nullable|in:pppoe,static',
            'pppoe_user' => 'nullable|string|max:120',
            'pppoe_password' => 'nullable|string|max:120',
            'pppoe_profile' => 'nullable|string|max:120',
            'ip_asignada' => 'nullable|ip',
            'router_id' => ['nullable', $this->deLaEmpresa('conection_routers')],
            'olt_id' => ['nullable', $this->deLaEmpresa('olt_admins')],
            'vlan' => 'nullable|integer|min:1|max:4094',
            // Los perfiles con los que se autoriza la ONT: se eligen de los que
            // la OLT tiene sincronizados, no se escriben a mano.
            'line_profile_id' => 'nullable|integer|min:0',
            'srv_profile_id' => 'nullable|integer|min:0',
            'onu_type' => 'nullable|string|max:60',
            // La ñ y las tildes las rechaza el equipo, y el SSID y la clave se
            // mandan juntos: uno malo hace fallar los dos.
            'wifi_ssid' => 'nullable|string|max:32|regex:/^[A-Za-z0-9\-_. ]+$/',
            'wifi_password' => 'nullable|string|min:8|max:63|regex:/^[A-Za-z0-9\-_.@#$%&*+=!?():,]+$/',
            'scheduled_date' => 'sometimes|date',
            'scheduled_time' => 'sometimes',
            'installation_cost' => 'nullable|numeric|min:0',
            'technician_ids' => 'nullable|array',
            'technician_ids.*' => [$this->deLaEmpresa('employees')],
            'commission_amount' => 'nullable|numeric|min:0',
            'observations' => 'nullable|string',
            'user_data_id' => ['nullable', $this->deLaEmpresa('user_data')],
        ]);
        
        $installation->update($validated);
        
        return response()->json([
            'message' => 'Orden de instalación actualizada',
            'data' => $installation->fresh(['client', 'plan', 'paymentMethod'])
        ]);
    }

    public function destroy($id)
    {
        $installation = InstallationOrder::where('company_id', getSessionCompanyId())
            ->findOrFail($id);

        if ($installation->status !== 'pending' && !$installation->modo_practica) {
            return response()->json(['message' => 'No se puede eliminar una orden en proceso o completada'], 400);
        }

        // Un técnico ya no crea órdenes de verdad (ver store()), así que esto en la práctica sólo
        // le deja borrar su propia orden de práctica. Borrar la de otro sigue siendo de oficina.
        if (!in_array($this->miPerfil(), ['admin', 'contador']) && $installation->created_by !== Auth::id()) {
            return response()->json(['message' => 'Esa orden no es suya'], 403);
        }

        $installation->delete();

        return response()->json(['message' => 'Orden de instalación eliminada']);
    }

    public function confirm($id)
    {
        $installation = InstallationOrder::where('company_id', getSessionCompanyId())
            ->findOrFail($id);
        
        if ($installation->status !== 'pending') {
            return response()->json(['message' => 'Solo se pueden confirmar órdenes pendientes'], 400);
        }
        
        $installation->update([
            'status' => 'confirmed',
            'assigned_by' => Auth::id()
        ]);
        
        // Create log
        InstallationLog::create([
            'installation_id' => $id,
            'action' => 'confirm',
            'description' => 'Instalación confirmada',
            'created_by' => Auth::id(),
        ]);
        
        return response()->json([
            'message' => 'Orden confirmada',
            'data' => $installation->fresh()
        ]);
    }

    public function start($id)
    {
        $installation = InstallationOrder::where('company_id', getSessionCompanyId())
            ->findOrFail($id);
        
        if ($installation->status !== 'confirmed') {
            return response()->json(['message' => 'Solo se pueden iniciar órdenes confirmadas'], 400);
        }
        
        $installation->update([
            'status' => 'in_progress',
            'started_at' => now()
        ]);
        
        // Create log
        InstallationLog::create([
            'installation_id' => $id,
            'action' => 'start',
            'description' => 'Técnicos iniciaron la instalación',
            'created_by' => Auth::id(),
        ]);
        
        return response()->json([
            'message' => 'Instalación iniciada',
            'data' => $installation->fresh()
        ]);
    }

    public function complete(Request $request, $id)
    {
        $installation = InstallationOrder::where('company_id', getSessionCompanyId())
            ->findOrFail($id);
        
        if ($installation->status !== 'in_progress') {
            return response()->json(['message' => 'Solo se pueden completar órdenes en proceso'], 400);
        }
        
        $validated = $request->validate([
            'technical_notes' => 'nullable|string',
        ]);
        
        $updateData = [
            'status' => 'completed',
            'finished_at' => now()
        ];
        
        if ($request->has('technical_notes')) {
            $updateData['technical_notes'] = $request->technical_notes;
        }
        
        $installation->update($updateData);
        
        // Create log
        InstallationLog::create([
            'installation_id' => $id,
            'action' => 'complete',
            'description' => 'Instalación completada',
            'notes' => $request->technical_notes,
            'created_by' => Auth::id(),
        ]);
        
        return response()->json([
            'message' => 'Instalación completada',
            'data' => $installation->fresh()
        ]);
    }

    // ── Lo que hace el técnico en la calle ──────────────────────────────

    /**
     * GET /api/installations/{id}/equipos
     *
     * Las ONT que la OLT de esa orden está viendo sin autorizar, más los
     * renglones de inventario con stock.
     *
     * El técnico elige de la lista en vez de escribir el serial: no se
     * equivoca al tipear y, sobre todo, que el equipo aparezca ahí ya prueba
     * que está conectado y encendido.
     */
    /**
     * Crea una orden de PRÁCTICA para quien la pide, ya confirmada, para recorrer el flujo de instalar.
     *
     * No avisa por WhatsApp, no reserva lugar en el plan, no cuenta en el tablero ni en las comisiones, y al
     * terminarla no se toca la red: el equipo es simulado. Si quien la crea es un técnico, queda asignada a él.
     */
    public function practica()
    {
        $empresa = (int) getSessionCompanyId();
        $tecnico = Employee::where('company_id', $empresa)->where('user_id', getSessionUserId())->value('id');

        $orden = InstallationOrder::create([
            'company_id' => $empresa, 'created_by' => Auth::id(), 'status' => 'confirmed', 'modo_practica' => true, 'payment_status' => 'pending',
            'client_name' => 'CLIENTE DE PRÁCTICA', 'client_firstname' => 'CLIENTE', 'client_lastname' => 'DE PRÁCTICA',
            'client_dni' => 'PRACTICA-' . random_int(1000, 9999), 'client_phone' => '3000000000', 'address' => 'Calle de práctica 123', 'neighborhood' => 'Barrio de práctica',
            'internet_plan_id' => DB::table('internet_plans')->where('company_id', $empresa)->orderBy('id')->value('id'),
            'grupo_facturacion' => 1, 'connection_type' => 'pppoe', 'pppoe_user' => 'practica', 'pppoe_profile' => 'practica', 'vlan' => 100,
            'wifi_ssid' => 'PRACTICA_WIFI', 'wifi_password' => 'practica2026',
            'scheduled_date' => now()->toDateString(), 'scheduled_time' => now()->format('H:i'), 'installation_cost' => 0, 'commission_amount' => 0,
            'technician_ids' => $tecnico ? [(int) $tecnico] : null,
            'observations' => 'ORDEN DE PRÁCTICA: nada de esto toca la red, ni crea clientes, ni descuenta inventario.',
        ]);

        InstallationLog::create(['installation_id' => $orden->id, 'action' => 'create', 'description' => 'Orden de práctica creada', 'notes' => 'Modo práctica', 'created_by' => Auth::id()]);

        return response()->json(['message' => 'Orden de práctica creada. Inícielo y siga los pasos: nada de esto toca la red.', 'data' => $orden], 201);
    }

    /** Lo que ve el técnico en una práctica: equipos, OLT, VLAN y perfiles de mentira, con la forma de los reales. */
    private function equiposDePractica(InstallationOrder $orden, int $oltPedida): array
    {
        $olts = [
            ['id' => -1, 'name' => 'OLT Norte (práctica)', 'brand' => 'huawei', 'model' => 'MA5800', 'host' => '10.0.0.1', 'ont_lineprofile_id' => 10, 'ont_srvprofile_id' => 20],
            ['id' => -2, 'name' => 'OLT Sur (práctica)',   'brand' => 'zte',    'model' => 'C320',   'host' => '10.0.0.2', 'ont_lineprofile_id' => 10, 'ont_srvprofile_id' => 20],
        ];
        $oltId = $oltPedida < 0 ? $oltPedida : -1;

        // Otra OLT trae otros equipos: así se ve qué pasa cuando el cliente no sale por donde decía la orden.
        $onts = $oltId === -1
            ? [['serial' => 'PRACTICA-HWTC0001', 'sn' => 'PRACTICA-HWTC0001', 'fsp' => '0/1/1', 'ont_id' => 0, 'model' => 'HG8145V5 (simulada)'],
               ['serial' => 'PRACTICA-HWTC0002', 'sn' => 'PRACTICA-HWTC0002', 'fsp' => '0/1/2', 'ont_id' => 1, 'model' => 'HG8145V5 (simulada)']]
            : [['serial' => 'PRACTICA-ZTEG0003', 'sn' => 'PRACTICA-ZTEG0003', 'fsp' => '1/2/1', 'ont_id' => 0, 'model' => 'F670L (simulada)']];

        return [
            'onts'        => $onts,
            'inventario'  => [['id' => -1, 'name' => 'ONT HG8145V5 (práctica)', 'quantity' => 12], ['id' => -2, 'name' => 'ONT F670L (práctica)', 'quantity' => 8]],
            'aprovisiona' => true,
            'olts'        => $olts,
            'olt_id'      => $oltId,
            'perfiles'    => [
                'line' => [['profile_id' => 10, 'profile_name' => 'Residencial 100M (práctica)'], ['profile_id' => 11, 'profile_name' => 'Residencial 200M (práctica)']],
                'srv'  => [['profile_id' => 20, 'profile_name' => 'Internet + WiFi (práctica)'], ['profile_id' => 21, 'profile_name' => 'Solo internet (práctica)']],
            ],
            'capacidades' => ['etiqueta_perfil_linea' => 'Perfil de línea', 'etiqueta_perfil_servicio' => 'Perfil de servicio', 'perfil_servicio_en_alta' => true],
            'vlans'       => [['vlan' => 100, 'nombre' => 'vlan100', 'red' => '10.100.0.0/24', 'clientes' => 34], ['vlan' => 120, 'nombre' => 'vlan120', 'red' => '10.120.0.0/24', 'clientes' => 12]],
            'plan'        => ['olt_id' => -1, 'vlan' => $orden->vlan ? (int) $orden->vlan : 100, 'line_profile_id' => 10, 'srv_profile_id' => 20, 'onu_type' => null],
            'practica'    => true,
        ];
    }

    /**
     * Todo lo que el técnico necesita para instalar, en una sola consulta.
     *
     * Está en la calle con datos móviles: pedirle cuatro peticiones para armar
     * la pantalla es media instalación esperando.
     *
     * Con `olt_id` responde por otra OLT sin tocar la orden. Pasa seguido: la
     * orden se toma en la oficina y cuando el técnico llega el cliente sale por
     * otro nodo. Si el equipo no aparece en la lista, es que está en otra OLT.
     */
    public function equiposDisponibles(Request $request, int $id)
    {
        $orden = InstallationOrder::where('company_id', getSessionCompanyId())->findOrFail($id);

        // Una orden de práctica no le pregunta nada a la OLT ni al router: todo es de mentira, pero con la
        // misma forma que lo real, para que el técnico vea la pantalla tal como la va a ver en la calle.
        if ($orden->modo_practica) {
            return response()->json(['status' => 'success', 'data' => $this->equiposDePractica($orden, (int) $request->integer('olt_id'))]);
        }

        $oltId = (int) ($request->integer('olt_id') ?: $orden->olt_id);

        // De la empresa, para el selector de «por dónde entra» y para el caso de abajo, sin repetir
        // la consulta: el técnico no ve más OLT que las de su propia empresa.
        $olts = \App\Models\OltAdmin::where('company_id', $orden->company_id)
            ->get(['id', 'name', 'brand', 'model', 'host', 'ont_lineprofile_id', 'ont_srvprofile_id'])
            ->map(fn ($o) => $o->toArray())->all();

        if (!$oltId) {
            // La orden no trae por qué OLT entra el cliente, y el técnico ya no puede editarla (eso es
            // de oficina): antes esto era un error sin salida. Ahora se le manda la lista de OLT de la
            // empresa para que elija una desde acá mismo; al elegirla, el navegador vuelve a pedir esto
            // mismo con «olt_id» y sigue como si la orden la hubiera traído.
            return response()->json([
                'status' => 'success',
                'data'   => [
                    'onts'        => [],
                    'inventario'  => \App\Services\Instalaciones\EquipoDelInventario::ontsConStock((int) $orden->company_id),
                    'aprovisiona' => (bool) \App\Models\GestionRemota::where('company_id', $orden->company_id)->value('aprovisionar'),
                    'olts'        => $olts,
                    'olt_id'      => null,
                    'falta_olt'   => true,
                    'perfiles'    => ['line' => [], 'srv' => []],
                    'capacidades' => [],
                    'vlans'       => [],
                    'plan'        => [
                        'olt_id'          => null,
                        'vlan'            => $orden->vlan ? (int) $orden->vlan : null,
                        'line_profile_id' => $orden->line_profile_id ? (int) $orden->line_profile_id : null,
                        'srv_profile_id'  => $orden->srv_profile_id ? (int) $orden->srv_profile_id : null,
                        'onu_type'        => $orden->onu_type,
                    ],
                ],
            ]);
        }

        // Que la OLT sea de la empresa: el id llega del navegador.
        $olt = \App\Models\OltAdmin::where('company_id', $orden->company_id)->find($oltId);

        if (!$olt) {
            return response()->json(['status' => 'error', 'message' => 'Esa OLT no es de esta empresa.'], 422);
        }

        $casoDeUso = app(\App\UseCases\OltAdmin\OltAdminUseCase::class);

        try {
            $r = $casoDeUso->getUnauthorizedONTs($oltId);
            $sinAutorizar = $r['data'] ?? [];
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No se pudo preguntarle a la OLT: ' . $e->getMessage(),
            ], 502);
        }

        // Los perfiles y las capacidades son de la OLT que se está mirando, no
        // de la que traía la orden.
        $perfiles = ['line' => [], 'srv' => []];
        $capacidades = [];

        try {
            $p = $casoDeUso->getProfiles($oltId);
            $perfiles = ['line' => $p['data']['line'] ?? [], 'srv' => $p['data']['srv'] ?? []];
            $capacidades = $casoDeUso->capacidades($oltId)['data'] ?? $casoDeUso->capacidades($oltId);
        } catch (\Throwable $e) {
            \Log::warning('[Instalación] No se pudieron leer los perfiles de la OLT', ['olt' => $oltId, 'error' => $e->getMessage()]);
        }

        return response()->json([
            'status' => 'success',
            'data'   => [
                'onts'        => $sinAutorizar,
                'inventario'  => \App\Services\Instalaciones\EquipoDelInventario::ontsConStock((int) $orden->company_id),
                'aprovisiona' => (bool) \App\Models\GestionRemota::where('company_id', $orden->company_id)->value('aprovisionar'),

                // Para poder corregir en el terreno lo que se planeó en la oficina.
                'olts'        => $olts,
                'olt_id'      => $oltId,
                'perfiles'    => $perfiles,
                'capacidades' => $capacidades,
                'vlans'       => $this->vlansDelRouter($orden),

                // Lo que trae la orden: es lo que se propone, no lo que se impone.
                'plan'        => [
                    'olt_id'          => $orden->olt_id ? (int) $orden->olt_id : null,
                    'vlan'            => $orden->vlan ? (int) $orden->vlan : null,
                    'line_profile_id' => $orden->line_profile_id ? (int) $orden->line_profile_id : null,
                    'srv_profile_id'  => $orden->srv_profile_id ? (int) $orden->srv_profile_id : null,
                    'onu_type'        => $orden->onu_type,
                ],
            ],
        ]);
    }

    /**
     * Las VLAN del router por donde puede salir el cliente.
     *
     * Si el router no contesta se devuelve vacío y el técnico escribe el número
     * a mano: quedarse sin instalar porque el MikroTik no respondió es peor.
     *
     * @return list<array<string,mixed>>
     */
    private function vlansDelRouter(InstallationOrder $orden): array
    {
        try {
            $r = app(\App\UseCases\ManagementRouter\Interfaces\GetIpAvaliblesUseCaseInterface::class)
                ->getLanSegments($orden->router_id ? (int) $orden->router_id : null);

            $filas = array_values((array) ($r['data'] ?? []));

            // Una entrada por VLAN, con su número: es lo que la OLT necesita.
            $porVlan = [];

            foreach ($filas as $f) {
                $f = (array) $f;
                $nombre = (string) ($f['names'] ?? '');

                preg_match('/\d+/', $nombre, $m);
                $vlan = isset($f['vlan_id']) && $f['vlan_id'] ? (int) $f['vlan_id'] : (int) ($m[0] ?? 0);

                if (!$vlan || isset($porVlan[$vlan])) continue;

                $porVlan[$vlan] = [
                    'vlan'      => $vlan,
                    'nombre'    => $nombre,
                    'red'       => $f['network'] ?? null,
                    'clientes'  => isset($f['clientes']) ? (int) $f['clientes'] : null,
                ];
            }

            ksort($porVlan);

            return array_values($porVlan);
        } catch (\Throwable $e) {
            \Log::warning('[Instalación] No se pudieron leer las VLAN del router', ['orden' => $orden->id, 'error' => $e->getMessage()]);

            return [];
        }
    }

    public function provisionar(Request $request, int $id)
    {
        $orden = InstallationOrder::where('company_id', getSessionCompanyId())->findOrFail($id);
        $practica = (bool) $orden->modo_practica;

        $datos = $request->validate([
            'fsp'          => 'required|string|max:20',
            'ont_id'       => 'required|integer|min:0',
            'serial'       => 'required|string|max:40',
            'inventory_id' => 'nullable|integer',

            // Lo que el técnico corrige estando en la casa: la orden se tomó
            // en la oficina y a veces el cliente sale por otro nodo u otra
            // VLAN. Si no viene, se usa lo que traía la orden.
            // En una práctica los ids de la OLT son de mentira: no existen en la base.
            'olt_id'          => $practica ? 'nullable|integer' : ['nullable', $this->deLaEmpresa('olt_admins')],
            'vlan'            => 'nullable|integer|min:1|max:4094',
            'line_profile_id' => 'nullable|integer|min:0',
            'srv_profile_id'  => 'nullable|integer|min:0',
            'onu_type'        => 'nullable|string|max:60',
        ]);

        if ($practica) {
            $r = \App\Services\Instalaciones\InstalarYAprovisionar::practica($orden, $datos, getSessionUserId());

            return response()->json([
                'status'  => $r['ok'] ? 'success' : 'error',
                'message' => $r['message'],
                'data'    => ['pasos' => $r['pasos'], 'avisos' => $r['avisos']] + $r['data'],
            ], $r['ok'] ? 200 : 422);
        }

        $r = \App\Services\Instalaciones\InstalarYAprovisionar::hacer($orden, $datos, getSessionUserId());

        return response()->json([
            'status'  => $r['ok'] ? 'success' : 'error',
            'message' => $r['message'],
            'data'    => ['pasos' => $r['pasos'], 'avisos' => $r['avisos']] + $r['data'],
        ], $r['ok'] ? 200 : 422);
    }

    /**
     * GET /api/installations/por-cedula/{dni}
     *
     * Lo que ya se sabe de ese cliente por su orden de instalación, para que
     * el alta en Clientes se llene sola. Se cargó una vez al tomar el pedido:
     * volver a escribirlo es donde aparecen las diferencias.
     */
    public function porCedula(string $dni)
    {
        $orden = InstallationOrder::where('company_id', getSessionCompanyId())
            ->where('client_dni', trim($dni))
            ->whereIn('status', ['pending', 'confirmed', 'in_progress', 'completed'])
            ->orderByDesc('id')
            ->first();

        if (!$orden) {
            return response()->json(['status' => 'success', 'data' => null, 'message' => 'Sin orden de instalación para esa cédula.']);
        }

        return response()->json(['status' => 'success', 'data' => [
            'installation_id'   => $orden->id,
            'names'             => $orden->client_name,
            'firstname'         => $orden->client_firstname,
            'lastname'          => $orden->client_lastname,
            'dni'               => $orden->client_dni,
            'phone'             => $orden->client_phone,
            'email'             => $orden->client_email,
            'address'           => $orden->address,
            'neighborhood'      => $orden->neighborhood,
            'internet_plans_id' => $orden->internet_plan_id,
            'connection_type'   => $orden->connection_type,
            'pppoe_user'        => $orden->pppoe_user,
            'pppoe_profile'     => $orden->pppoe_profile,
            'ip_assignment_id'  => $orden->ip_asignada,
            'router_id'         => $orden->router_id,
            'vlan'              => $orden->vlan,
            'group'             => $orden->grupo_facturacion,
            'wifi_ssid'         => $orden->wifi_ssid,
            'estado_orden'      => $orden->status,
        ]]);
    }

    public function cancel(Request $request, $id)
    {
        $installation = InstallationOrder::where('company_id', getSessionCompanyId())
            ->findOrFail($id);
        
        if ($installation->status === 'completed') {
            return response()->json(['message' => 'No se puede cancelar una orden completada'], 400);
        }
        
        $reason = $request->input('reason');
        $installation->update(['status' => 'cancelled']);
        
        // Create log
        InstallationLog::create([
            'installation_id' => $id,
            'action' => 'cancel',
            'description' => 'Instalación cancelada',
            'notes' => $reason,
            'created_by' => Auth::id(),
        ]);
        
        return response()->json([
            'message' => 'Orden cancelada',
            'data' => $installation->fresh()
        ]);
    }

    public function updatePayment(Request $request, $id)
    {
        $installation = InstallationOrder::where('company_id', getSessionCompanyId())
            ->findOrFail($id);
        
        $validated = $request->validate([
            'payment_status' => 'required|in:pending,paid,verified,rejected',
            'payment_amount' => 'nullable|numeric|min:0',
            'payment_reference' => 'nullable|string|max:100',
            'payment_image_url' => 'nullable|string|max:500',
            'payment_method_id' => ['nullable', $this->deLaEmpresa('payment_methods')],
        ]);
        
        $oldStatus = $installation->payment_status;
        $installation->update($validated);
        
        // Create log
        InstallationLog::create([
            'installation_id' => $id,
            'action' => 'payment_' . $validated['payment_status'],
            'description' => "Pago actualizado de {$oldStatus} a {$validated['payment_status']}",
            'notes' => $validated['payment_reference'] ?? null,
            'created_by' => Auth::id(),
        ]);
        
        return response()->json([
            'message' => 'Pago actualizado',
            'data' => $installation->fresh(['paymentMethod'])
        ]);
    }

    /**
     * El comprobante de la transferencia con la que el cliente pagó la instalación. Lo sube el mismo
     * técnico que está en la casa —no necesita esperar a la oficina para dejarlo registrado—, pero sólo
     * en una orden suya; el resto de la ficha de pago (monto, referencia, estado) sigue siendo de
     * administrador o contador.
     */
    public function uploadPaymentProof(Request $request, $id)
    {
        $installation = InstallationOrder::where('company_id', getSessionCompanyId())->findOrFail($id);

        if (!in_array($this->miPerfil(), ['admin', 'contador'])) {
            $miId = $this->miEmpleadoId();
            if (!$miId || !in_array($miId, $installation->technician_ids ?? [])) {
                return response()->json(['message' => 'Esa orden no es suya'], 403);
            }
        }

        $request->validate([
            'comprobante' => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:8192',
        ]);

        // El anterior se borra: si no, cada reemplazo deja un archivo suelto (mismo criterio que
        // OltAdminUseCase::guardarFoto).
        if ($installation->payment_image_url) {
            Storage::disk('public')->delete(str_replace(url('/storage') . '/', '', $installation->payment_image_url));
        }

        $ruta = $request->file('comprobante')->store('comprobantes-instalacion/' . getSessionCompanyId(), 'public');
        $installation->forceFill(['payment_image_url' => url('/storage/' . $ruta)])->save();

        InstallationLog::create([
            'installation_id' => $id,
            'action' => 'payment_proof',
            'description' => 'Comprobante de pago adjuntado',
            'created_by' => Auth::id(),
        ]);

        return response()->json(['message' => 'Comprobante guardado', 'data' => ['payment_image_url' => $installation->payment_image_url]]);
    }

    /** Quitar el comprobante: si se subió por error o no correspondía. Sólo oficina. */
    public function removePaymentProof($id)
    {
        $installation = InstallationOrder::where('company_id', getSessionCompanyId())->findOrFail($id);

        if ($installation->payment_image_url) {
            Storage::disk('public')->delete(str_replace(url('/storage') . '/', '', $installation->payment_image_url));
            $installation->forceFill(['payment_image_url' => null])->save();
        }

        return response()->json(['message' => 'Comprobante eliminado']);
    }

    public function assignTechnicians(Request $request, $id)
    {
        $installation = InstallationOrder::where('company_id', getSessionCompanyId())
            ->findOrFail($id);
        
        $validated = $request->validate([
            'technician_ids' => 'nullable|array',
            'technician_ids.*' => [$this->deLaEmpresa('employees')],
            'commission_amount' => 'nullable|numeric|min:0',
        ]);
        
        $validated['assigned_by'] = Auth::id();
        
        $installation->update($validated);
        
        // Create log
        InstallationLog::create([
            'installation_id' => $id,
            'action' => 'assign',
            'description' => 'Técnicos asignados',
            'notes' => $validated['commission_amount'] ? "Comisión: {$validated['commission_amount']}" : null,
            'created_by' => Auth::id(),
        ]);
        
        return response()->json([
            'message' => 'Técnicos asignados',
            'data' => $installation->fresh(['paymentMethod'])
        ]);
    }

    public function calculateCommission($id)
    {
        $installation = InstallationOrder::where('company_id', getSessionCompanyId())
            ->findOrFail($id);
        
        $technicians = $installation->technicians_list ?? collect([]);
        $count = $technicians->count();
        $commissionPerTech = $count > 0 ? $installation->commission_amount / $count : 0;
        
        $techData = $technicians->map(function ($tech) use ($commissionPerTech) {
            return [
                'id' => $tech->id,
                'name' => $tech->first_name . ' ' . $tech->last_name,
                'commission' => $commissionPerTech
            ];
        });
        
        return response()->json([
            'installation_id' => $installation->id,
            'commission_amount' => $installation->commission_amount,
            'technicians' => $techData,
            'total_commission' => $installation->commission_amount
        ]);
    }

    public function dashboard()
    {
        $companyId = getSessionCompanyId();
        
        $stats = [
            'total' => InstallationOrder::where('company_id', $companyId)->where('modo_practica', false)->count(),
            'pending' => InstallationOrder::where('company_id', $companyId)->where('modo_practica', false)->where('status', 'pending')->count(),
            'confirmed' => InstallationOrder::where('company_id', $companyId)->where('modo_practica', false)->where('status', 'confirmed')->count(),
            'in_progress' => InstallationOrder::where('company_id', $companyId)->where('modo_practica', false)->where('status', 'in_progress')->count(),
            'completed' => InstallationOrder::where('company_id', $companyId)->where('modo_practica', false)->where('status', 'completed')->count(),
            'cancelled' => InstallationOrder::where('company_id', $companyId)->where('modo_practica', false)->where('status', 'cancelled')->count(),
        ];
        
        $payments = [
            'pending' => InstallationOrder::where('company_id', $companyId)->where('modo_practica', false)->where('payment_status', 'pending')->count(),
            'paid' => InstallationOrder::where('company_id', $companyId)->where('modo_practica', false)->where('payment_status', 'paid')->count(),
            'verified' => InstallationOrder::where('company_id', $companyId)->where('modo_practica', false)->where('payment_status', 'verified')->count(),
            'rejected' => InstallationOrder::where('company_id', $companyId)->where('modo_practica', false)->where('payment_status', 'rejected')->count(),
        ];
        
        $pendingAmount = InstallationOrder::where('company_id', $companyId)->where('modo_practica', false)
            ->whereIn('payment_status', ['pending', 'paid'])
            ->sum('payment_amount');
        
        $commissionTotal = InstallationOrder::where('company_id', $companyId)->where('modo_practica', false)
            ->where('status', 'completed')
            ->sum('commission_amount');
        
        return response()->json([
            'status' => $stats,
            'payments' => $payments,
            'pending_amount' => $pendingAmount,
            'commission_total' => $commissionTotal
        ]);
    }

    public function availableTechnicians()
    {
        $companyId = getSessionCompanyId();
        
        $technicians = Employee::where('company_id', $companyId)
            ->where('job_title', 'like', '%técnico%')
            ->where('active', true)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'job_title']);
        
        return response()->json($technicians);
    }

    public function plans()
    {
        $plans = InternetPlan::where('company_id', getSessionCompanyId())
            ->where('active', true)
            ->orderBy('plan_name')
            ->get(['id', 'plan_name', 'monthly_price', 'download_speed', 'upload_speed']);
        
        return response()->json($plans);
    }

    public function paymentMethods()
    {
        $methods = PaymentMethod::where('company_id', getSessionCompanyId())
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
        
        return response()->json($methods);
    }

    public function logs($id)
    {
        $installation = InstallationOrder::where('id', $id)->where('company_id', getSessionCompanyId())->firstOrFail();
        $logs = $installation->logs()->get();
        
        return response()->json($logs);
    }

    public function createLog(Request $request, $id)
    {
        $installation = InstallationOrder::where('id', $id)->where('company_id', getSessionCompanyId())->firstOrFail();
        
        $log = InstallationLog::create([
            'installation_id' => $id,
            'action' => $request->input('action'),
            'description' => $request->input('description'),
            'notes' => $request->input('notes'),
            'created_by' => auth()->id() ?? null,
        ]);
        
        return response()->json($log);
    }

    // exists limitado a la empresa de la sesión
    private function deLaEmpresa(string $tabla)
    {
        return Rule::exists($tabla, 'id')->where('company_id', getSessionCompanyId());
    }

    /**
     * Avisa por WhatsApp que se agendó una instalación, al destino que la
     * empresa haya elegido en Avisos y destinos.
     *
     * Antes esto no avisaba nada: el único aviso de instalación salía cuando se
     * abría un ticket con tipo de servicio "instalación", que es otro camino.
     */
    private function avisarInstalacionAgendada(InstallationOrder $orden, array $datos): void
    {
        try {
            $plan = !empty($datos['internet_plan_id'])
                ? InternetPlan::where('id', $datos['internet_plan_id'])
                    ->where('company_id', getSessionCompanyId())
                    ->value('plan_name')
                : null;

            $tecnicos = !empty($datos['technician_ids'])
                ? Employee::whereIn('id', $datos['technician_ids'])
                    ->where('company_id', getSessionCompanyId())
                    ->get(['first_name', 'last_name'])
                    ->map(fn ($e) => trim("{$e->first_name} {$e->last_name}"))
                    ->implode(', ')
                : null;

            $fecha = trim(($datos['scheduled_date'] ?? '') . ' ' . ($datos['scheduled_time'] ?? ''));

            $direccion = ($datos['address'] ?? '')
                . (!empty($datos['neighborhood']) ? " ({$datos['neighborhood']})" : '');

            \App\Services\Avisos\MensajeDeAviso::nuevo('Instalación agendada', getSessionCompanyId(), '🗓')
                ->dato('Orden', "#{$orden->id}")
                ->dato('Cliente', $datos['client_name'] ?? null)
                ->dato('Cédula', $datos['client_dni'] ?? null)
                ->telefono('Teléfono', $datos['client_phone'] ?? null)
                ->dato('Dirección', $direccion)
                ->dato('Plan', $plan)
                ->dato('Técnicos', $tecnicos)
                ->fecha('Programada', $fecha, !empty($datos['scheduled_time']))
                ->bloque('Observación', $datos['observations'] ?? null)
                ->enviar('instalacion_creada');
        } catch (\Throwable $e) {
            // El aviso nunca puede tumbar el agendamiento.
            \Log::warning('[Instalaciones] No se pudo avisar la instalación agendada', [
                'orden' => $orden->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
