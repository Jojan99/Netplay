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

class InstallationOrderController extends Controller
{
    public function index(Request $request)
    {
        $companyId = getSessionCompanyId();
        
        $query = InstallationOrder::where('company_id', $companyId)
            ->with(['client', 'plan', 'technician1', 'technician2', 'paymentMethod']);
        
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }
        
        if ($request->has('payment_status') && $request->payment_status) {
            $query->where('payment_status', $request->payment_status);
        }
        
        if ($request->has('technician_id') && $request->technician_id) {
            $query->where(function ($q) use ($request) {
                $q->where('technician_1_id', $request->technician_id)
                  ->orWhere('technician_2_id', $request->technician_id)
                  ->orWhereJsonContains('technician_ids', (int) $request->technician_id);
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
                $inst->technicians_list = Employee::whereIn('id', $inst->technician_ids)->get(['id', 'first_name', 'last_name']);
            }
            return $inst;
        });
        
        return response()->json($installations);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_name' => 'required|string|max:255',
            'client_dni' => 'required|string|max:50',
            'client_phone' => 'required|string|max:20',
            'client_email' => 'nullable|email',
            'address' => 'required|string|max:500',
            'neighborhood' => 'nullable|string|max:255',
            'internet_plan_id' => 'nullable|exists:internet_plans,id',
            'scheduled_date' => 'required|date',
            'scheduled_time' => 'required',
            'installation_cost' => 'nullable|numeric|min:0',
            'technician_ids' => 'nullable|array',
            'technician_ids.*' => 'exists:employees,id',
            'payment_method_id' => 'nullable|exists:payment_methods,id',
            'commission_amount' => 'nullable|numeric|min:0',
            'observations' => 'nullable|string',
            'user_data_id' => 'nullable|exists:user_data,id',
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
        
        return response()->json([
            'message' => 'Orden de instalación creada exitosamente',
            'data' => $installation
        ], 201);
    }

    public function show($id)
    {
        $installation = InstallationOrder::where('company_id', getSessionCompanyId())
            ->with(['client', 'plan', 'technician1', 'technician2', 'paymentMethod', 'creator', 'assignee', 'logs'])
            ->findOrFail($id);
        
        return response()->json($installation);
    }

    public function update(Request $request, $id)
    {
        $installation = InstallationOrder::where('company_id', getSessionCompanyId())
            ->findOrFail($id);
        
        $validated = $request->validate([
            'client_name' => 'sometimes|string|max:255',
            'client_dni' => 'sometimes|string|max:50',
            'client_phone' => 'sometimes|string|max:20',
            'client_email' => 'nullable|email',
            'address' => 'sometimes|string|max:500',
            'neighborhood' => 'nullable|string|max:255',
            'internet_plan_id' => 'nullable|exists:internet_plans,id',
            'scheduled_date' => 'sometimes|date',
            'scheduled_time' => 'sometimes',
            'installation_cost' => 'nullable|numeric|min:0',
            'technician_ids' => 'nullable|array',
            'technician_ids.*' => 'exists:employees,id',
            'commission_amount' => 'nullable|numeric|min:0',
            'observations' => 'nullable|string',
            'user_data_id' => 'nullable|exists:user_data,id',
        ]);
        
        $installation->update($validated);
        
        return response()->json([
            'message' => 'Orden de instalación actualizada',
            'data' => $installation->fresh(['client', 'plan', 'technician1', 'technician2', 'paymentMethod'])
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
            'payment_method_id' => 'nullable|exists:payment_methods,id',
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
            'technician_ids.*' => 'exists:employees,id',
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
            'data' => $installation->fresh(['technician1', 'technician2'])
        ]);
    }

    public function calculateCommission($id)
    {
        $installation = InstallationOrder::where('company_id', getSessionCompanyId())
            ->findOrFail($id);
        
        $commission = 0;
        
        if ($installation->technician1) {
            $commission += $installation->commission_amount / 2;
        }
        
        if ($installation->technician2) {
            $commission += $installation->commission_amount / 2;
        }
        
        return response()->json([
            'installation_id' => $installation->id,
            'commission_amount' => $installation->commission_amount,
            'technician_1' => $installation->technician1 ? [
                'id' => $installation->technician1->id,
                'name' => $installation->technician1->first_name . ' ' . $installation->technician1->last_name,
                'commission' => $installation->commission_amount / 2
            ] : null,
            'technician_2' => $installation->technician2 ? [
                'id' => $installation->technician2->id,
                'name' => $installation->technician2->first_name . ' ' . $installation->technician2->last_name,
                'commission' => $installation->commission_amount / 2
            ] : null,
            'total_commission' => $commission
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
        $installation = InstallationOrder::findOrFail($id);
        $logs = $installation->logs()->get();
        
        return response()->json($logs);
    }

    public function createLog(Request $request, $id)
    {
        $installation = InstallationOrder::findOrFail($id);
        
        $log = InstallationLog::create([
            'installation_id' => $id,
            'action' => $request->input('action'),
            'description' => $request->input('description'),
            'notes' => $request->input('notes'),
            'created_by' => auth()->id() ?? null,
        ]);
        
        return response()->json($log);
    }
}