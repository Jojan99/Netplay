<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Guía de primeros pasos del panel.
 *
 * No es un tour de burbujas sobre la pantalla: es una lista de las cosas que
 * una empresa necesita dejar listas para operar, y cada paso se marca solo
 * mirando si el dato existe de verdad. Así la guía sirve igual para quien
 * recién entra y para quien ya lleva la mitad hecha, y nunca dice "completá
 * esto" sobre algo que ya está cargado.
 */
class OnboardingController extends Controller
{
    /**
     * GET /api/onboarding
     * Devuelve los pasos con su estado real y el avance general.
     */
    public function index(): JsonResponse
    {
        $usuario   = JWTAuth::user();
        $companyId = getSessionCompanyId() ?: ($usuario->company_id ?? null);

        if (!$companyId) {
            return response()->json(['message' => 'Sin empresa en sesión.', 'data' => null, 'error' => 1], 403);
        }

        $empresa = DB::table('companies')->where('id', $companyId)->first();
        $pasos   = $this->pasos((int) $companyId, $empresa);

        $hechos = count(array_filter($pasos, fn ($p) => $p['hecho']));

        return response()->json([
            'message' => 'OK',
            'error'   => 0,
            'data'    => [
                'pasos'        => $pasos,
                'total'        => count($pasos),
                'completados'  => $hechos,
                'porcentaje'   => count($pasos) ? (int) round($hechos * 100 / count($pasos)) : 0,
                'empresa'      => $empresa->name ?? '',
                'oculta'       => !empty($usuario->onboarding_done_at),
                'perfil'       => DB::table('profiles')->where('id', $usuario->profile_id)->value('name') ?? '',
            ],
        ]);
    }

    /**
     * POST /api/onboarding/done   { ver: bool }
     * Guarda si la persona quiere seguir viendo la guía en el panel.
     */
    public function toggle(\Illuminate\Http\Request $request): JsonResponse
    {
        $usuario = JWTAuth::user();

        if (!$usuario) {
            return response()->json(['message' => 'No autenticado.', 'data' => null, 'error' => 1], 401);
        }

        if (!Schema::hasColumn('users', 'onboarding_done_at')) {
            return response()->json(['message' => 'OK', 'data' => null, 'error' => 0]);
        }

        // ver=true significa "quiero volver a verla": se limpia la marca.
        $volverAVer = $request->boolean('ver');

        DB::table('users')->where('id', $usuario->id)->update([
            'onboarding_done_at' => $volverAVer ? null : now(),
        ]);

        return response()->json([
            'message' => $volverAVer ? 'Guía reactivada' : 'Guía ocultada',
            'error'   => 0,
            'data'    => ['oculta' => !$volverAVer],
        ]);
    }

    /**
     * Los pasos, en el orden en que conviene hacerlos.
     *
     * Cada uno dice a qué ruta del panel lleva y cómo se sabe que ya está
     * resuelto. `cuenta` es informativo (lo que ya hay cargado) y se muestra
     * al lado del paso para que se vea el avance real.
     *
     * @return array<int,array<string,mixed>>
     */
    private function pasos(int $companyId, ?object $empresa): array
    {
        $planes    = $this->contar('internet_plans', $companyId);
        $clientes  = $this->contarClientes($companyId);
        $facturas  = $this->contar('cab_facturations', $companyId);
        $staff     = $this->contarStaff($companyId);
        $metodos   = $this->contar('payment_methods', $companyId);

        $datosFacturacion = !empty($empresa->invoice_business_name) || !empty($empresa->invoice_nit ?? null);

        return [
            [
                'clave'       => 'facturacion',
                'titulo'      => 'Completá los datos de tu empresa',
                'detalle'     => 'Razón social, NIT, dirección y logo. Es lo que sale impreso en cada factura que emitís.',
                'ruta'        => '/dashboard/billing-config',
                'boton'       => 'Configurar facturación',
                'hecho'       => $datosFacturacion,
                'cuenta'      => null,
            ],
            [
                'clave'       => 'planes',
                'titulo'      => 'Cargá tus planes de internet',
                'detalle'     => 'La velocidad y el precio de cada plan. Sin esto no vas a poder asignarle un servicio a un cliente.',
                'ruta'        => '/dashboard/planes-internet',
                'boton'       => 'Crear planes',
                'hecho'       => $planes > 0,
                'cuenta'      => $planes ? "$planes cargados" : null,
            ],
            [
                'clave'       => 'clientes',
                'titulo'      => 'Registrá tus clientes',
                'detalle'     => 'Podés cargarlos de a uno o importarlos. Cada cliente queda con su plan, su dirección y su estado de servicio.',
                'ruta'        => '/dashboard/usuario',
                'boton'       => 'Ir a clientes',
                'hecho'       => $clientes > 0,
                'cuenta'      => $clientes ? "$clientes registrados" : null,
            ],
            [
                'clave'       => 'metodos-pago',
                'titulo'      => 'Definí cómo te pagan',
                'detalle'     => 'Nequi, transferencia, efectivo o la pasarela en línea. Es lo que aparece en la factura y en el portal del cliente.',
                'ruta'        => '/dashboard/finanzas/metodos-pago',
                'boton'       => 'Métodos de pago',
                'hecho'       => $metodos > 0 || !empty($empresa->pg_active),
                'cuenta'      => $metodos ? "$metodos configurados" : null,
            ],
            [
                'clave'       => 'facturar',
                'titulo'      => 'Generá tu primera facturación',
                'detalle'     => 'Desde Finanzas se emiten las facturas del período y quedan listas para enviarse por WhatsApp o correo.',
                'ruta'        => '/dashboard/finanzas',
                'boton'       => 'Ir a finanzas',
                'hecho'       => $facturas > 0,
                'cuenta'      => $facturas ? "$facturas emitidas" : null,
            ],
            [
                'clave'       => 'whatsapp',
                'titulo'      => 'Conectá WhatsApp',
                'detalle'     => 'Escaneás un QR y desde ahí salen las facturas, los avisos de mora y la bandeja de conversaciones con tus clientes.',
                'ruta'        => '/dashboard/whatsapp',
                'boton'       => 'Conectar WhatsApp',
                // La api_key se aprovisiona sola al registrarse, así que no
                // alcanza como señal: lo que marca que WhatsApp quedó andando
                // es tener una instancia vinculada (o una línea de Meta).
                'hecho'       => !empty($empresa->wa_instance_id) || !empty($empresa->wa_phone_number_id),
                'cuenta'      => null,
            ],
            [
                'clave'       => 'equipo',
                'titulo'      => 'Sumá a tu equipo',
                'detalle'     => 'Creá los usuarios de tus técnicos y administrativos, y decidí qué módulos ve cada perfil.',
                'ruta'        => '/dashboard/staff',
                'boton'       => 'Gestionar staff',
                'hecho'       => $staff > 1,
                'cuenta'      => $staff > 1 ? "$staff personas" : null,
            ],
        ];
    }

    private function contar(string $tabla, int $companyId): int
    {
        if (!Schema::hasTable($tabla) || !Schema::hasColumn($tabla, 'company_id')) {
            return 0;
        }

        return (int) DB::table($tabla)->where('company_id', $companyId)->count();
    }

    /** Clientes = usuarios de la empresa con perfil de cliente. */
    private function contarClientes(int $companyId): int
    {
        $perfilesCliente = DB::table('profiles')
            ->where('company_id', $companyId)
            ->whereRaw('UPPER(name) IN (?, ?)', ['USER', 'CLIENTE'])
            ->pluck('id');

        if ($perfilesCliente->isEmpty()) {
            return 0;
        }

        return (int) DB::table('users')
            ->where('company_id', $companyId)
            ->whereIn('profile_id', $perfilesCliente)
            ->count();
    }

    /** Staff = usuarios de la empresa que no son clientes. */
    private function contarStaff(int $companyId): int
    {
        $perfilesCliente = DB::table('profiles')
            ->where('company_id', $companyId)
            ->whereRaw('UPPER(name) IN (?, ?)', ['USER', 'CLIENTE'])
            ->pluck('id');

        return (int) DB::table('users')
            ->where('company_id', $companyId)
            ->when($perfilesCliente->isNotEmpty(), fn ($q) => $q->whereNotIn('profile_id', $perfilesCliente))
            ->count();
    }
}
