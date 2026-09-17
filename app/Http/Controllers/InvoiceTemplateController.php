<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\InvoiceTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class InvoiceTemplateController extends Controller
{
    private function getCompanyId(): int
    {
        $id = getSessionCompanyId();
        if (!$id) {
            abort(403, 'No company in session');
        }
        return (int) $id;
    }

    /**
     * GET /api/company/invoice-templates
     */
    public function index(): JsonResponse
    {
        $companyId = $this->getCompanyId();
        $templates = InvoiceTemplate::where('company_id', $companyId)
            ->orderBy('is_default', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'message' => 'OK',
            'data'    => $templates,
            'status'  => 0,
        ]);
    }

    /**
     * GET /api/company/invoice-templates/{id}
     */
    public function show(int $id): JsonResponse
    {
        $companyId = $this->getCompanyId();
        $template = InvoiceTemplate::where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        if (!$template) {
            return response()->json([
                'message' => 'Plantilla no encontrada',
                'data'    => null,
                'status'  => 1,
            ], 404);
        }

        return response()->json([
            'message' => 'OK',
            'data'    => $template,
            'status'  => 0,
        ]);
    }

    /**
     * POST /api/company/invoice-templates
     */
    public function store(Request $request): JsonResponse
    {
        $companyId = $this->getCompanyId();

        $validator = Validator::make($request->all(), [
            'name'   => 'required|string|max:100',
            'type'   => 'required|in:classic,modern,minimal,receipt',
            'config' => 'nullable|array',
            'is_default' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos inválidos',
                'data'    => $validator->errors(),
                'status'  => 1,
            ], 422);
        }

        $data = $validator->validated();
        $data['company_id'] = $companyId;

        // Si es default, quitar default de las demás
        if (!empty($data['is_default'])) {
            InvoiceTemplate::where('company_id', $companyId)->update(['is_default' => false]);
        }

        $template = InvoiceTemplate::create($data);

        // Si es la primera plantilla, asignarla a la empresa
        $company = Company::find($companyId);
        if ($company && !$company->invoice_template_id) {
            $company->invoice_template_id = $template->id;
            $company->save();
        }

        return response()->json([
            'message' => 'Plantilla creada',
            'data'    => $template,
            'status'  => 0,
        ], 201);
    }

    /**
     * PUT /api/company/invoice-templates/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $companyId = $this->getCompanyId();
        $template = InvoiceTemplate::where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        if (!$template) {
            return response()->json([
                'message' => 'Plantilla no encontrada',
                'data'    => null,
                'status'  => 1,
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name'   => 'sometimes|string|max:100',
            'type'   => 'sometimes|in:classic,modern,minimal,receipt',
            'config' => 'nullable|array',
            'is_default' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos inválidos',
                'data'    => $validator->errors(),
                'status'  => 1,
            ], 422);
        }

        $data = $validator->validated();

        if (array_key_exists('is_default', $data) && $data['is_default']) {
            InvoiceTemplate::where('company_id', $companyId)->update(['is_default' => false]);
        }

        $template->update($data);

        return response()->json([
            'message' => 'Plantilla actualizada',
            'data'    => $template,
            'status'  => 0,
        ]);
    }

    /**
     * DELETE /api/company/invoice-templates/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $companyId = $this->getCompanyId();
        $template = InvoiceTemplate::where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        if (!$template) {
            return response()->json([
                'message' => 'Plantilla no encontrada',
                'data'    => null,
                'status'  => 1,
            ], 404);
        }

        // Si la empresa usa esta plantilla, limpiar referencia
        Company::where('id', $companyId)
            ->where('invoice_template_id', $id)
            ->update(['invoice_template_id' => null]);

        $template->delete();

        return response()->json([
            'message' => 'Plantilla eliminada',
            'data'    => null,
            'status'  => 0,
        ]);
    }

    /**
     * PUT /api/company/invoice-templates/{id}/set-default
     */
    public function setDefault(int $id): JsonResponse
    {
        $companyId = $this->getCompanyId();
        $template = InvoiceTemplate::where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        if (!$template) {
            return response()->json([
                'message' => 'Plantilla no encontrada',
                'data'    => null,
                'status'  => 1,
            ], 404);
        }

        InvoiceTemplate::where('company_id', $companyId)->update(['is_default' => false]);
        $template->update(['is_default' => true]);

        // También asignar a la empresa
        Company::where('id', $companyId)->update(['invoice_template_id' => $id]);

        return response()->json([
            'message' => 'Plantilla por defecto actualizada',
            'data'    => $template,
            'status'  => 0,
        ]);
    }

    /**
     * GET /api/company/invoice-templates/preview
     * Devuelve HTML de preview para el frontend
     */
    public function preview(Request $request): JsonResponse
    {
        $companyId = $this->getCompanyId();

        $type = $request->input('type', 'classic');
        $config = $request->input('config', []);
        if (is_string($config)) {
            $config = json_decode($config, true) ?? [];
        }
        if (!is_array($config)) {
            $config = [];
        }

        $company = Company::find($companyId);
        $co = $this->loadCompanyData($company);

        $pdfT = new \App\Resources\Templates\TemplatesPdf();
        $html = $pdfT->renderTemplate($type, $this->dummyInvoiceData(), $co, 0, $config);

        return response()->json([
            'message' => 'OK',
            'data'    => ['html' => $html],
            'status'  => 0,
        ]);
    }

    /** Mismo membrete que la factura real: solo datos de la propia empresa. */
    private function loadCompanyData(?Company $company): array
    {
        if (!$company) {
            abort(404, 'Empresa no encontrada');
        }

        return \App\Resources\Templates\TemplatesPdf::datosEmpresa($company);
    }

    private function dummyInvoiceData(): array
    {
        return [
            'names' => 'Juan',
            'lastname' => 'Pérez',
            'address' => 'Calle 123 # 45-67',
            'phone' => '3001234567',
            'dni' => '1234567890',
            'number_facture' => 'DEMO-0001',
            'date_facturation' => now()->toDateString(),
            'monthly_price' => 80000,
            'price_total' => 80000,
            'price_discount' => 0,
            'porcentage_discount' => 0,
            'days_facture' => 30,
            'plan_name' => 'Plan Internet 50Mbps',
            'create_facture_manual' => 0,
        ];
    }
}
