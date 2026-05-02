<?php

namespace App\Http\Controllers\Landing;

use App\Constants\ApiResponseConstants;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * API pública (sin JWT) para cargar la landing de una empresa por slug.
 * GET /api/landing/{slug}
 */
class LandingPublicController extends Controller
{
    public function show(string $slug): JsonResponse
    {
        $company = DB::table('companies')
            ->where('slug', $slug)
            ->where('active', 1)
            ->select('id', 'name', 'slug', 'logo', 'phone', 'email', 'address', 'nit')
            ->first();

        if (!$company) {
            return response()->json([
                'message' => 'Empresa no encontrada',
                'data'    => null,
                'status'  => ApiResponseConstants::ERROR,
            ], JsonResponse::HTTP_NOT_FOUND);
        }

        $config = DB::table('landing_configs')
            ->where('company_id', $company->id)
            ->first();

        // Si no tiene landing configurada aún, devolvemos defaults con datos de la empresa
        if (!$config) {
            $config = $this->defaultConfig($company);
        }

        // Si no está publicada, devolver 404 (para visitantes)
        if (!$config->is_published) {
            return response()->json([
                'message' => 'Landing no disponible',
                'data'    => null,
                'status'  => ApiResponseConstants::ERROR,
            ], JsonResponse::HTTP_NOT_FOUND);
        }

        $sections = DB::table('landing_sections')
            ->where('company_id', $company->id)
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function ($s) {
                $s->content = $s->content ? json_decode($s->content, true) : null;
                $s->styles  = $s->styles  ? json_decode($s->styles, true)  : null;
                return $s;
            });

        $navItems = DB::table('landing_nav_items')
            ->where('company_id', $company->id)
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->get();

        // Planes de internet activos de la empresa (para sección plans)
        $plans = DB::table('internet_plans')
            ->where('company_id', $company->id)
            ->where('active', 1)
            ->select('id', 'plan_name', 'monthly_price', 'download_speed', 'upload_speed', 'description', 'type')
            ->orderBy('monthly_price')
            ->get();

        return response()->json([
            'message' => 'OK',
            'data'    => [
                'company'  => $company,
                'config'   => $config,
                'sections' => $sections,
                'nav'      => $navItems,
                'plans'    => $plans,
            ],
            'status' => ApiResponseConstants::SUCCESS,
        ], JsonResponse::HTTP_OK);
    }

    private function defaultConfig(object $company): object
    {
        return (object) [
            'company_id'        => $company->id,
            'logo_url'          => $company->logo,
            'primary_color'     => '#2563EB',
            'secondary_color'   => '#1E40AF',
            'accent_color'      => '#10B981',
            'hero_title'        => 'Internet para tu hogar y empresa',
            'hero_subtitle'     => 'Conectividad rápida, estable y confiable. Elige el plan perfecto para ti.',
            'hero_cta_text'     => 'Ver planes',
            'hero_cta_url'      => '#planes',
            'hero_image_url'    => null,
            'hero_bg_color'     => null,
            'sections_order'    => json_encode(['hero', 'plans', 'features', 'contact']),
            'contact_whatsapp'  => $company->phone,
            'contact_email'     => $company->email,
            'contact_phone'     => $company->phone,
            'contact_address'   => $company->address,
            'meta_title'        => $company->name,
            'meta_description'  => 'Proveedor de internet - ' . $company->name,
            'is_published'      => true, // defaults siempre visibles para preview
        ];
    }
}
