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
use Illuminate\Validation\Rule;

class InstallationOrderController extends Controller
{
    public function index(Request $request)
    {
        $companyId = getSessionCompanyId();
        
        $query = InstallationOrder::where('company_id', $companyId)
            ->with(['client', 'plan', 'paymentMethod']);
        
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
        
        if ($installation->status !== 'pending') {
            return response()->json(['message' => 'No se puede eliminar una orden en proceso o completada'], 400);
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
    public function equiposDisponibles(int $id)
    {
        $orden = InstallationOrder::where('company_id', getSessionCompanyId())->findOrFail($id);

        if (!$orden->olt_id) {
            return response()->json([
                'status'  => 'error',
                'message' => 'La orden no dice por qué OLT entra este cliente. Completala desde Instalaciones.',
            ], 422);
        }

        try {
            $r = app(\App\UseCases\OltAdmin\OltAdminUseCase::class)->getUnauthorizedONTs((int) $orden->olt_id);
            $sinAutorizar = $r['data'] ?? [];
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No se pudo preguntarle a la OLT: ' . $e->getMessage(),
            ], 502);
        }

        return response()->json([
            'status' => 'success',
            'data'   => [
                'onts'       => $sinAutorizar,
                'inventario' => \App\Services\Instalaciones\EquipoDelInventario::ontsConStock((int) $orden->company_id),
                'aprovisiona' => (bool) \App\Models\GestionRemota::where('company_id', $orden->company_id)->value('aprovisionar'),
            ],
        ]);
    }

    /**
     * POST /api/installations/{id}/provisionar
     * Body: { fsp, ont_id, serial, inventory_id? }
     *
     * Da de alta al cliente, autoriza el equipo, se lo asigna, lo descuenta
     * del inventario y —si la empresa lo tiene encendido— deja programada la
     * configuración. Todo en un paso, desde la casa del cliente.
     */
    public function provisionar(Request $request, int $id)
    {
        $datos = $request->validate([
            'fsp'          => 'required|string|max:20',
            'ont_id'       => 'required|integer|min:0',
            'serial'       => 'required|string|max:40',
            'inventory_id' => 'nullable|integer',
        ]);

        $orden = InstallationOrder::where('company_id', getSessionCompanyId())->findOrFail($id);

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
            'total' => InstallationOrder::where('company_id', $companyId)->count(),
            'pending' => InstallationOrder::where('company_id', $companyId)->where('status', 'pending')->count(),
            'confirmed' => InstallationOrder::where('company_id', $companyId)->where('status', 'confirmed')->count(),
            'in_progress' => InstallationOrder::where('company_id', $companyId)->where('status', 'in_progress')->count(),
            'completed' => InstallationOrder::where('company_id', $companyId)->where('status', 'completed')->count(),
            'cancelled' => InstallationOrder::where('company_id', $companyId)->where('status', 'cancelled')->count(),
        ];
        
        $payments = [
            'pending' => InstallationOrder::where('company_id', $companyId)->where('payment_status', 'pending')->count(),
            'paid' => InstallationOrder::where('company_id', $companyId)->where('payment_status', 'paid')->count(),
            'verified' => InstallationOrder::where('company_id', $companyId)->where('payment_status', 'verified')->count(),
            'rejected' => InstallationOrder::where('company_id', $companyId)->where('payment_status', 'rejected')->count(),
        ];
        
        $pendingAmount = InstallationOrder::where('company_id', $companyId)
            ->whereIn('payment_status', ['pending', 'paid'])
            ->sum('payment_amount');
        
        $commissionTotal = InstallationOrder::where('company_id', $companyId)
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
