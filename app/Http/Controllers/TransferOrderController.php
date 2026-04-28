<?php

namespace App\Http\Controllers;

use App\Models\TransferOrder;
use App\Models\UserData;
use App\Models\ConectionRouter;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TransferOrderController extends Controller
{
    public function index(Request $request)
    {
        $companyId = getSessionCompanyId();
        
        $query = TransferOrder::where('company_id', $companyId)
            ->with(['client', 'oldRouter', 'newRouter', 'technician1', 'technician2']);
        
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }
        
        if ($request->has('payment_status') && $request->payment_status) {
            $query->where('payment_status', $request->payment_status);
        }
        
        if ($request->has('technician_id') && $request->technician_id) {
            $query->where(function ($q) use ($request) {
                $q->where('technician_1_id', $request->technician_id)
                  ->orWhere('technician_2_id', $request->technician_id);
            });
        }
        
        if ($request->has('date_from') && $request->date_from) {
            $query->where('scheduled_date', '>=', $request->date_from);
        }
        
        if ($request->has('date_to') && $request->date_to) {
            $query->where('scheduled_date', '<=', $request->date_to);
        }
        
        $transfers = $query->orderBy('scheduled_date', 'asc')
            ->orderBy('scheduled_time', 'asc')
            ->paginate($request->get('per_page', 20));
        
        return response()->json($transfers);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_data_id' => 'required|exists:user_data,id',
            'new_address' => 'required|string|max:500',
            'new_neighborhood' => 'nullable|string|max:255',
            'new_router_id' => 'nullable|exists:conection_routers,id',
            'new_ip' => 'nullable|ip',
            'scheduled_date' => 'required|date',
            'scheduled_time' => 'required',
            'transfer_cost' => 'nullable|numeric|min:0',
            'technician_1_id' => 'nullable|exists:employees,id',
            'technician_2_id' => 'nullable|exists:employees,id',
            'commission_amount' => 'nullable|numeric|min:0',
            'observations' => 'nullable|string',
        ]);
        
        $client = UserData::findOrFail($request->user_data_id);
        
        $validated['company_id'] = getSessionCompanyId();
        $validated['created_by'] = Auth::id();
        $validated['old_address'] = $client->address;
        $validated['old_ip'] = $client->ip_assignment_id;
        $validated['status'] = 'pending';
        $validated['payment_status'] = $request->transfer_cost > 0 ? 'pending' : 'not_required';
        
        $transfer = TransferOrder::create($validated);
        
        return response()->json([
            'message' => 'Orden de traslado creada exitosamente',
            'data' => $transfer->load(['client', 'oldRouter', 'newRouter', 'technician1', 'technician2'])
        ], 201);
    }

    public function show($id)
    {
        $transfer = TransferOrder::where('company_id', getSessionCompanyId())
            ->with(['client', 'oldRouter', 'newRouter', 'technician1', 'technician2', 'creator', 'assignee'])
            ->findOrFail($id);
        
        return response()->json($transfer);
    }

    public function update(Request $request, $id)
    {
        $transfer = TransferOrder::where('company_id', getSessionCompanyId())
            ->findOrFail($id);
        
        $validated = $request->validate([
            'new_address' => 'sometimes|string|max:500',
            'new_neighborhood' => 'nullable|string|max:255',
            'new_router_id' => 'nullable|exists:conection_routers,id',
            'new_ip' => 'nullable|ip',
            'scheduled_date' => 'sometimes|date',
            'scheduled_time' => 'sometimes',
            'transfer_cost' => 'nullable|numeric|min:0',
            'technician_1_id' => 'nullable|exists:employees,id',
            'technician_2_id' => 'nullable|exists:employees,id',
            'commission_amount' => 'nullable|numeric|min:0',
            'observations' => 'nullable|string',
        ]);
        
        $transfer->update($validated);
        
        return response()->json([
            'message' => 'Orden de traslado actualizada',
            'data' => $transfer->fresh(['client', 'oldRouter', 'newRouter', 'technician1', 'technician2'])
        ]);
    }

    public function destroy($id)
    {
        $transfer = TransferOrder::where('company_id', getSessionCompanyId())
            ->findOrFail($id);
        
        if ($transfer->status !== 'pending') {
            return response()->json(['message' => 'No se puede eliminar una orden en proceso o completada'], 400);
        }
        
        $transfer->delete();
        
        return response()->json(['message' => 'Orden de traslado eliminada']);
    }

    public function confirm($id)
    {
        $transfer = TransferOrder::where('company_id', getSessionCompanyId())
            ->findOrFail($id);
        
        if ($transfer->status !== 'pending') {
            return response()->json(['message' => 'Solo se pueden confirmar órdenes pendientes'], 400);
        }
        
        $transfer->update([
            'status' => 'confirmed',
            'assigned_by' => Auth::id()
        ]);
        
        return response()->json([
            'message' => 'Orden confirmada',
            'data' => $transfer->fresh()
        ]);
    }

    public function start($id)
    {
        $transfer = TransferOrder::where('company_id', getSessionCompanyId())
            ->findOrFail($id);
        
        if ($transfer->status !== 'confirmed') {
            return response()->json(['message' => 'Solo se pueden iniciar órdenes confirmadas'], 400);
        }
        
        $transfer->update([
            'status' => 'in_progress',
            'started_at' => now()
        ]);
        
        return response()->json([
            'message' => 'Traslado iniciado',
            'data' => $transfer->fresh()
        ]);
    }

    public function complete(Request $request, $id)
    {
        $transfer = TransferOrder::where('company_id', getSessionCompanyId())
            ->findOrFail($id);
        
        if ($transfer->status !== 'in_progress') {
            return response()->json(['message' => 'Solo se pueden completar órdenes en proceso'], 400);
        }
        
        $validated = $request->validate([
            'new_ip' => 'nullable|ip',
            'technical_notes' => 'nullable|string',
            'update_client_address' => 'nullable|boolean',
        ]);
        
        $updateData = [
            'status' => 'completed',
            'finished_at' => now()
        ];
        
        if ($request->has('technical_notes')) {
            $updateData['technical_notes'] = $request->technical_notes;
        }
        
        if ($request->has('new_ip')) {
            $updateData['new_ip'] = $request->new_ip;
        }
        
        $transfer->update($updateData);
        
        if ($request->update_client_address === true) {
            $client = $transfer->client;
            $client->update([
                'address' => $transfer->new_address,
                'ip_assignment_id' => $transfer->new_ip
            ]);
        }
        
        return response()->json([
            'message' => 'Traslado completado',
            'data' => $transfer->fresh()
        ]);
    }

    public function cancel($id)
    {
        $transfer = TransferOrder::where('company_id', getSessionCompanyId())
            ->findOrFail($id);
        
        if ($transfer->status === 'completed') {
            return response()->json(['message' => 'No se puede cancelar una orden completada'], 400);
        }
        
        $transfer->update(['status' => 'cancelled']);
        
        return response()->json([
            'message' => 'Orden cancelada',
            'data' => $transfer->fresh()
        ]);
    }

    public function updatePayment(Request $request, $id)
    {
        $transfer = TransferOrder::where('company_id', getSessionCompanyId())
            ->findOrFail($id);
        
        $validated = $request->validate([
            'payment_status' => 'required|in:not_required,pending,paid,verified,rejected',
            'payment_amount' => 'nullable|numeric|min:0',
            'payment_reference' => 'nullable|string|max:100',
            'payment_image_url' => 'nullable|string|max:500',
        ]);
        
        $transfer->update($validated);
        
        return response()->json([
            'message' => 'Pago actualizado',
            'data' => $transfer->fresh()
        ]);
    }

    public function assignTechnicians(Request $request, $id)
    {
        $transfer = TransferOrder::where('company_id', getSessionCompanyId())
            ->findOrFail($id);
        
        $validated = $request->validate([
            'technician_1_id' => 'nullable|exists:employees,id',
            'technician_2_id' => 'nullable|exists:employees,id',
            'commission_amount' => 'nullable|numeric|min:0',
        ]);
        
        $validated['assigned_by'] = Auth::id();
        
        $transfer->update($validated);
        
        return response()->json([
            'message' => 'Técnicos asignados',
            'data' => $transfer->fresh(['technician1', 'technician2'])
        ]);
    }

    public function calculateCommission($id)
    {
        $transfer = TransferOrder::where('company_id', getSessionCompanyId())
            ->findOrFail($id);
        
        $commission = 0;
        
        if ($transfer->technician1) {
            $commission += $transfer->commission_amount / 2;
        }
        
        if ($transfer->technician2) {
            $commission += $transfer->commission_amount / 2;
        }
        
        return response()->json([
            'transfer_id' => $transfer->id,
            'commission_amount' => $transfer->commission_amount,
            'technician_1' => $transfer->technician1 ? [
                'id' => $transfer->technician1->id,
                'name' => $transfer->technician1->first_name . ' ' . $transfer->technician1->last_name,
                'commission' => $transfer->commission_amount / 2
            ] : null,
            'technician_2' => $transfer->technician2 ? [
                'id' => $transfer->technician2->id,
                'name' => $transfer->technician2->first_name . ' ' . $transfer->technician2->last_name,
                'commission' => $transfer->commission_amount / 2
            ] : null,
            'total_commission' => $commission
        ]);
    }

    public function dashboard()
    {
        $companyId = getSessionCompanyId();
        
        $stats = [
            'total' => TransferOrder::where('company_id', $companyId)->count(),
            'pending' => TransferOrder::where('company_id', $companyId)->where('status', 'pending')->count(),
            'confirmed' => TransferOrder::where('company_id', $companyId)->where('status', 'confirmed')->count(),
            'in_progress' => TransferOrder::where('company_id', $companyId)->where('status', 'in_progress')->count(),
            'completed' => TransferOrder::where('company_id', $companyId)->where('status', 'completed')->count(),
            'cancelled' => TransferOrder::where('company_id', $companyId)->where('status', 'cancelled')->count(),
        ];
        
        $payments = [
            'not_required' => TransferOrder::where('company_id', $companyId)->where('payment_status', 'not_required')->count(),
            'pending' => TransferOrder::where('company_id', $companyId)->where('payment_status', 'pending')->count(),
            'paid' => TransferOrder::where('company_id', $companyId)->where('payment_status', 'paid')->count(),
            'verified' => TransferOrder::where('company_id', $companyId)->where('payment_status', 'verified')->count(),
            'rejected' => TransferOrder::where('company_id', $companyId)->where('payment_status', 'rejected')->count(),
        ];
        
        $commissionTotal = TransferOrder::where('company_id', $companyId)
            ->where('status', 'completed')
            ->sum('commission_amount');
        
        return response()->json([
            'status' => $stats,
            'payments' => $payments,
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

    public function routers()
    {
        $routers = ConectionRouter::where('company_id', getSessionCompanyId())
            ->orderBy('name')
            ->get(['id', 'name', 'ip_address']);
        
        return response()->json($routers);
    }
}