<?php

namespace App\Http\Controllers\Landing;

use App\Constants\ApiResponseConstants;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * CRUD de landing page desde el panel admin (requiere jwt.verify + role:admin).
 */
class LandingAdminController extends Controller
{
    private function companyId(): int
    {
        return (int) JWTAuth::user()->company_id;
    }

    // ── Config principal ────────────────────────────────────────────────────

    /**
     * GET /api/landing-admin/config
     * Devuelve la configuración actual (o defaults si no existe).
     */
    public function getConfig(): JsonResponse
    {
        $companyId = $this->companyId();

        $config = DB::table('landing_configs')->where('company_id', $companyId)->first();

        $company = DB::table('companies')->where('id', $companyId)
            ->select('id', 'name', 'slug', 'logo', 'phone', 'email', 'address')
            ->first();

        $plans = DB::table('internet_plans')
            ->where('company_id', $companyId)
            ->where('active', 1)
            ->select('id', 'plan_name', 'monthly_price', 'download_speed', 'upload_speed', 'description', 'type')
            ->orderBy('monthly_price')
            ->get();

        return response()->json([
            'message' => 'OK',
            'data'    => [
                'config'  => $config,
                'company' => $company,
                'plans'   => $plans,
            ],
            'status' => ApiResponseConstants::SUCCESS,
        ]);
    }

    /**
     * POST /api/landing-admin/config
     * Crea o actualiza la configuración principal.
     */
    public function saveConfig(Request $request): JsonResponse
    {
        $companyId = $this->companyId();

        $allowed = [
            'logo_url', 'primary_color', 'secondary_color', 'accent_color',
            'hero_title', 'hero_subtitle', 'hero_cta_text', 'hero_cta_url', 'hero_image_url', 'hero_bg_color',
            'sections_order',
            'contact_whatsapp', 'contact_email', 'contact_phone', 'contact_address',
            'meta_title', 'meta_description', 'is_published',
        ];

        $data = $request->only($allowed);

        if (isset($data['sections_order']) && is_array($data['sections_order'])) {
            $data['sections_order'] = json_encode($data['sections_order']);
        }

        $data['updated_at'] = now();

        $exists = DB::table('landing_configs')->where('company_id', $companyId)->exists();

        if ($exists) {
            DB::table('landing_configs')->where('company_id', $companyId)->update($data);
        } else {
            $data['company_id'] = $companyId;
            $data['created_at'] = now();
            DB::table('landing_configs')->insert($data);
        }

        $config = DB::table('landing_configs')->where('company_id', $companyId)->first();

        return response()->json([
            'message' => 'Configuración guardada',
            'data'    => $config,
            'status'  => ApiResponseConstants::SUCCESS,
        ]);
    }

    // ── Secciones ───────────────────────────────────────────────────────────

    /**
     * GET /api/landing-admin/sections
     */
    public function getSections(): JsonResponse
    {
        $companyId = $this->companyId();

        $sections = DB::table('landing_sections')
            ->where('company_id', $companyId)
            ->orderBy('sort_order')
            ->get()
            ->map(function ($s) {
                $s->content = $s->content ? json_decode($s->content, true) : null;
                $s->styles  = $s->styles  ? json_decode($s->styles, true)  : null;
                return $s;
            });

        return response()->json([
            'message' => 'OK',
            'data'    => $sections,
            'status'  => ApiResponseConstants::SUCCESS,
        ]);
    }

    /**
     * POST /api/landing-admin/sections
     * Crea una nueva sección.
     */
    public function createSection(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type'  => 'required|string|in:hero,plans,features,faq,testimonials,contact,custom_html',
            'title' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos inválidos',
                'data'    => $validator->errors(),
                'status'  => ApiResponseConstants::ERROR,
            ], 422);
        }

        $companyId = $this->companyId();

        // sort_order = al final
        $maxOrder = DB::table('landing_sections')
            ->where('company_id', $companyId)
            ->max('sort_order') ?? -1;

        $id = DB::table('landing_sections')->insertGetId([
            'company_id'  => $companyId,
            'type'        => $request->type,
            'title'       => $request->title,
            'sort_order'  => $maxOrder + 1,
            'is_visible'  => true,
            'content'     => $request->content ? json_encode($request->content) : null,
            'styles'      => $request->styles  ? json_encode($request->styles)  : null,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $section = DB::table('landing_sections')->where('id', $id)->first();

        return response()->json([
            'message' => 'Sección creada',
            'data'    => $section,
            'status'  => ApiResponseConstants::SUCCESS,
        ]);
    }

    /**
     * PUT /api/landing-admin/sections/{id}
     * Actualiza contenido, título, visibilidad o estilos de una sección.
     */
    public function updateSection(Request $request, int $id): JsonResponse
    {
        $companyId = $this->companyId();

        $section = DB::table('landing_sections')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        if (!$section) {
            return response()->json([
                'message' => 'Sección no encontrada',
                'data'    => null,
                'status'  => ApiResponseConstants::ERROR,
            ], 404);
        }

        $data = ['updated_at' => now()];

        if ($request->has('title'))      $data['title']      = $request->title;
        if ($request->has('is_visible')) $data['is_visible'] = (bool) $request->is_visible;
        if ($request->has('content'))    $data['content']    = json_encode($request->content);
        if ($request->has('styles'))     $data['styles']     = json_encode($request->styles);

        DB::table('landing_sections')->where('id', $id)->update($data);

        $updated = DB::table('landing_sections')->where('id', $id)->first();
        $updated->content = $updated->content ? json_decode($updated->content, true) : null;
        $updated->styles  = $updated->styles  ? json_decode($updated->styles, true)  : null;

        return response()->json([
            'message' => 'Sección actualizada',
            'data'    => $updated,
            'status'  => ApiResponseConstants::SUCCESS,
        ]);
    }

    /**
     * DELETE /api/landing-admin/sections/{id}
     */
    public function deleteSection(int $id): JsonResponse
    {
        $companyId = $this->companyId();

        $deleted = DB::table('landing_sections')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->delete();

        return response()->json([
            'message' => $deleted ? 'Sección eliminada' : 'Sección no encontrada',
            'data'    => null,
            'status'  => $deleted ? ApiResponseConstants::SUCCESS : ApiResponseConstants::ERROR,
        ]);
    }

    /**
     * PUT /api/landing-admin/sections/reorder
     * Reordena las secciones. Body: { "order": [3, 1, 5, 2] } (array de IDs en nuevo orden)
     */
    public function reorderSections(Request $request): JsonResponse
    {
        $companyId = $this->companyId();

        $order = $request->input('order', []);

        foreach ($order as $index => $sectionId) {
            DB::table('landing_sections')
                ->where('id', $sectionId)
                ->where('company_id', $companyId)
                ->update(['sort_order' => $index, 'updated_at' => now()]);
        }

        return response()->json([
            'message' => 'Orden actualizado',
            'data'    => null,
            'status'  => ApiResponseConstants::SUCCESS,
        ]);
    }

    // ── Menú de navegación ─────────────────────────────────────────────────

    /**
     * GET /api/landing-admin/nav
     */
    public function getNav(): JsonResponse
    {
        $nav = DB::table('landing_nav_items')
            ->where('company_id', $this->companyId())
            ->orderBy('sort_order')
            ->get();

        return response()->json(['message' => 'OK', 'data' => $nav, 'status' => ApiResponseConstants::SUCCESS]);
    }

    /**
     * POST /api/landing-admin/nav
     */
    public function createNav(Request $request): JsonResponse
    {
        $companyId = $this->companyId();
        $maxOrder  = DB::table('landing_nav_items')->where('company_id', $companyId)->max('sort_order') ?? -1;

        $id = DB::table('landing_nav_items')->insertGetId([
            'company_id' => $companyId,
            'label'      => $request->label,
            'href'       => $request->href,
            'target'     => $request->input('target', '_self'),
            'is_visible' => true,
            'sort_order' => $maxOrder + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Ítem creado',
            'data'    => DB::table('landing_nav_items')->where('id', $id)->first(),
            'status'  => ApiResponseConstants::SUCCESS,
        ]);
    }

    /**
     * PUT /api/landing-admin/nav/{id}
     */
    public function updateNav(Request $request, int $id): JsonResponse
    {
        $companyId = $this->companyId();
        $data = ['updated_at' => now()];

        if ($request->has('label'))      $data['label']      = $request->label;
        if ($request->has('href'))       $data['href']        = $request->href;
        if ($request->has('target'))     $data['target']      = $request->target;
        if ($request->has('is_visible')) $data['is_visible']  = (bool) $request->is_visible;

        DB::table('landing_nav_items')->where('id', $id)->where('company_id', $companyId)->update($data);

        return response()->json(['message' => 'Ítem actualizado', 'data' => null, 'status' => ApiResponseConstants::SUCCESS]);
    }

    /**
     * DELETE /api/landing-admin/nav/{id}
     */
    public function deleteNav(int $id): JsonResponse
    {
        DB::table('landing_nav_items')->where('id', $id)->where('company_id', $this->companyId())->delete();
        return response()->json(['message' => 'Ítem eliminado', 'data' => null, 'status' => ApiResponseConstants::SUCCESS]);
    }
}
