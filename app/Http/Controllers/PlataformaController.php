<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\Plataforma\EmpresaDelDominio;
use App\Support\Modules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * La plataforma vista desde afuera: qué empresa es el dominio que se abrió,
 * su logo, los planes de la página pública y el subdominio de cada empresa.
 */
class PlataformaController extends Controller
{
    public function __construct(private EmpresaDelDominio $dominio) {}

    /**
     * GET /api/plataforma/sitio[?empresa=netplay]
     *
     * En netplay.netvula.com devuelve la empresa. En la raíz se puede pedir
     * una con ?empresa=: sirve para el enlace de "entrá a tu empresa" y para
     * probar antes de que exista el DNS comodín.
     */
    public function sitio(Request $request): JsonResponse
    {
        $sub     = $this->dominio->subdominioPedido($request);
        $empresa = $this->dominio->empresa($request);

        if ($sub === null && $request->filled('empresa')) {
            $sub     = strtolower(trim((string) $request->query('empresa')));
            $empresa = Company::where('subdomain', $sub)->first();
        }

        return response()->json([
            'message' => 'OK',
            'error'   => 0,
            'data'    => [
                'plataforma' => [
                    'nombre'              => config('plataforma.nombre'),
                    'dominio'             => $this->dominio->dominioBase(),
                    'subdominios_activos' => $this->dominio->activos(),
                ],
                'tipo'    => $sub === null ? 'plataforma' : ($empresa ? 'empresa' : 'desconocida'),
                'empresa' => $empresa ? $this->publico($empresa) : null,
            ],
        ]);
    }

    /** GET /api/plataforma/planes */
    public function planes(): JsonResponse
    {
        $modulos = [];
        foreach (Modules::CATALOG as $grupo => $items) {
            $modulos[] = [
                'grupo' => $grupo,
                'items' => array_values(array_map(
                    fn ($m) => ['nombre' => $m['label'], 'detalle' => $m['description']],
                    array_filter($items, fn ($m) => empty($m['admin_only']))
                )),
            ];
        }

        return response()->json([
            'message' => 'OK',
            'error'   => 0,
            'data'    => [
                'moneda'        => config('plataforma.moneda', 'COP'),
                'prueba_dias'   => (int) config('plataforma.prueba_dias', 0),
                'nota_precios'  => config('plataforma.nota_precios', ''),
                'incluye_todos' => config('plataforma.incluye_todos', []),
                // Los planes salen de la base cuando existe la tabla; si no,
                // del config de siempre. La página pública no se entera.
                'planes'        => \App\Services\Plataforma\PlanesDeLaPlataforma::publicos(),
                'modulos' => $modulos,
            ],
        ]);
    }

    /**
     * GET /api/plataforma/logo/{slug}
     *
     * El logo guardado en la configuración de factura es un data URI. Sólo
     * se sirven formatos de imagen rasterizada: un SVG en el mismo dominio
     * del panel puede llevar scripts.
     */
    public function logo(string $slug)
    {
        $empresa = Company::where('slug', $slug)->first(['id', 'invoice_logo_base64', 'invoice_logo_url', 'updated_at']);

        $fuente = $empresa?->invoice_logo_base64 ?: $empresa?->invoice_logo_url;

        if (!$fuente) {
            abort(404);
        }

        if (preg_match('#^https?://#i', $fuente)) {
            return redirect()->away($fuente, 302);
        }

        if (!preg_match('#^data:(image/(?:png|jpe?g|webp|gif));base64,(.+)$#s', $fuente, $m)) {
            abort(404);
        }

        $binario = base64_decode($m[2], true);

        if ($binario === false) {
            Log::warning('[Logo público] base64 inválido', ['company_id' => $empresa->id]);
            abort(404);
        }

        return response($binario, 200, [
            'Content-Type'           => $m[1] === 'image/jpg' ? 'image/jpeg' : $m[1],
            'Cache-Control'          => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** GET /api/plataforma/subdominio/disponible?s=netplay | ?nombre=Netplay SAS */
    public function disponible(Request $request): JsonResponse
    {
        $request->validate([
            's'      => 'nullable|string|max:60',
            'nombre' => 'nullable|string|max:255',
        ]);

        $sub = strtolower(trim((string) $request->query('s', '')));

        if ($sub === '' && $request->filled('nombre')) {
            $sub = $this->dominio->libreDesde((string) $request->query('nombre'));
        }

        $problema = $sub === '' ? 'Escribí la dirección que querés.' : $this->dominio->problemaCon($sub);

        return response()->json([
            'message' => $problema ?? 'Disponible',
            'error'   => 0,
            'data'    => [
                'subdominio' => $sub,
                'disponible' => $problema === null,
                'direccion'  => $sub . '.' . $this->dominio->dominioBase(),
            ],
        ]);
    }

    /** GET /api/plataforma/mi-subdominio */
    public function miSubdominio(): JsonResponse
    {
        $empresa = Company::findOrFail(getSessionCompanyId());

        return response()->json([
            'message' => 'OK',
            'error'   => 0,
            'data'    => [
                'subdominio'          => $empresa->subdomain,
                'direccion'           => $empresa->subdomain . '.' . $this->dominio->dominioBase(),
                'subdominios_activos' => $this->dominio->activos(),
            ],
        ]);
    }

    /**
     * PUT /api/plataforma/mi-subdominio  { subdominio }
     *
     * Los enlaces que ya salieron con la dirección anterior dejan de llevar a
     * la empresa: el panel lo advierte antes de guardar.
     */
    public function cambiarSubdominio(Request $request): JsonResponse
    {
        $request->validate(['subdominio' => 'required|string|max:40']);

        $empresa  = Company::findOrFail(getSessionCompanyId());
        $sub      = strtolower(trim((string) $request->input('subdominio')));
        $problema = $this->dominio->problemaCon($sub, $empresa->id);

        if ($problema) {
            return response()->json(['message' => $problema, 'error' => 1, 'data' => null], 422);
        }

        $anterior = $empresa->subdomain;
        $empresa->update(['subdomain' => $sub]);

        Log::info('[Subdominio] cambiado', ['company_id' => $empresa->id, 'antes' => $anterior, 'ahora' => $sub]);

        return response()->json([
            'message' => 'Listo: tu empresa ahora entra por ' . $sub . '.' . $this->dominio->dominioBase(),
            'error'   => 0,
            'data'    => ['subdominio' => $sub, 'direccion' => $sub . '.' . $this->dominio->dominioBase()],
        ]);
    }

    /**
     * GET /api/plataforma/codigo?c=LANZAMIENTO25
     *
     * Revisa, antes de registrarse, si un código sirve: puede ser un cupón de
     * descuento o el código de referido de otra empresa. Público a propósito
     * (el formulario de alta todavía no tiene sesión), con tope de intentos.
     */
    public function codigo(Request $request): JsonResponse
    {
        $request->validate(['c' => 'required|string|max:40']);

        $codigo    = strtoupper(trim((string) $request->query('c')));
        $referidor = \App\Services\Plataforma\Referidos::empresaDelCodigo($codigo);

        if ($referidor) {
            $empresa = Company::find($referidor);

            return response()->json(['message' => 'Código de referido válido', 'error' => 0, 'data' => [
                'tipo'    => 'referido',
                'valido'  => true,
                'detalle' => 'Te invita ' . ($empresa->name ?? 'otra empresa') . '.',
            ]]);
        }

        $revision = \App\Services\Plataforma\Cupones::revisar($codigo);

        if ($revision['ok']) {
            $c = $revision['cupon'];

            return response()->json(['message' => 'Código válido', 'error' => 0, 'data' => [
                'tipo'    => 'cupon',
                'valido'  => true,
                'detalle' => $c->tipo === 'porcentaje'
                    ? rtrim(rtrim(number_format((float) $c->valor, 2, '.', ''), '0'), '.') . '% de descuento'
                    : 'Descuento de $' . number_format((float) $c->valor, 0, ',', '.'),
            ]]);
        }

        return response()->json(['message' => $revision['motivo'], 'error' => 0, 'data' => [
            'tipo'    => null,
            'valido'  => false,
            'detalle' => $revision['motivo'],
        ]]);
    }

    /**
     * GET /api/plataforma/mi-referido
     *
     * Lo que ve la empresa en SU panel: su código, el enlace para compartir y
     * cuánto crédito lleva ganado. Es lo que hace que el programa funcione.
     */
    public function miReferido(): JsonResponse
    {
        $companyId   = (int) getSessionCompanyId();
        $suscripcion = \App\Services\Plataforma\SuscripcionDeEmpresa::asegurar($companyId);

        if (!$suscripcion) {
            return response()->json([
                'message' => 'El programa de referidos todavía no está disponible.',
                'error'   => 0,
                'data'    => ['activo' => false],
            ]);
        }

        $ajustes = \App\Services\Plataforma\Referidos::ajustes();
        $base    = rtrim((string) config('app.url'), '/');

        $referidos = \Illuminate\Support\Facades\Schema::hasTable('plataforma_referidos')
            ? \Illuminate\Support\Facades\DB::table('plataforma_referidos as r')
                ->join('companies as e', 'e.id', '=', 'r.referida_company_id')
                ->where('r.referidor_company_id', $companyId)
                ->orderByDesc('r.id')
                ->get(['r.estado', 'r.credito_otorgado', 'r.created_at', 'e.name as empresa'])
            : collect();

        return response()->json(['message' => 'OK', 'error' => 0, 'data' => [
            'activo'       => (bool) ($ajustes['activo'] ?? false),
            'codigo'       => $suscripcion->codigo_referido,
            'enlace'       => $base . '/register?ref=' . urlencode((string) $suscripcion->codigo_referido),
            'credito'      => \App\Services\Plataforma\SuscripcionDeEmpresa::credito($companyId),
            'moneda'       => config('plataforma.moneda', 'COP'),
            'referidos'    => $referidos,
            'total'        => $referidos->count(),
            'activos'      => $referidos->where('estado', 'activo')->count(),
            'beneficio'    => $ajustes['beneficio_referidor'] ?? null,
            'para_el_otro' => $ajustes['beneficio_referido'] ?? null,
            'acreditar_en' => $ajustes['acreditar_en'] ?? 'primer_pago',
        ]]);
    }

    /** Lo que cualquiera puede ver de una empresa en su página de acceso. */
    private function publico(Company $empresa): array
    {
        return [
            'nombre'     => $empresa->name,
            'subdominio' => $empresa->subdomain,
            'logo'       => $this->dominio->logoDe($empresa),
            'activa'     => (bool) $empresa->active,
        ];
    }
}
