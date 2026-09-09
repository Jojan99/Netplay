<?php

namespace App\Http\Controllers;

use App\Services\ClientStatementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Estado de cuenta del cliente: sus facturas y por qué medio pagó cada una.
 *
 * Tres formas de verlo:
 *  - panel:  GET api/management/clients/{userId}/statement   (JSON, con JWT)
 *  - enlace: GET api/estado-cuenta/{token}                   (página, sin login)
 *  - portal: GET api/client/statement                        (JSON, sesión del cliente)
 */
class ClientStatementController extends Controller
{
    public function __construct(private ClientStatementService $service) {}

    /** Panel: estado de cuenta de un cliente de la empresa en sesión. */
    public function show(int $userId): JsonResponse
    {
        $companyId = getSessionCompanyId();
        $data = $this->service->build($userId, $companyId ? (int) $companyId : null);

        if (!$data) {
            return response()->json(['message' => 'Cliente no encontrado', 'data' => null, 'error' => 1], JsonResponse::HTTP_NOT_FOUND);
        }

        $data['share_url'] = ClientStatementService::urlFor($userId);
        return response()->json(['message' => 'OK', 'data' => $data, 'error' => 0]);
    }

    /** Portal del cliente: su propio estado de cuenta. */
    public function mine(): JsonResponse
    {
        $user = \Tymon\JWTAuth\Facades\JWTAuth::user();
        if (!$user) return response()->json(['status' => 1, 'message' => 'No autenticado.'], 401);

        $data = $this->service->build((int) $user->id, (int) $user->company_id);
        return response()->json(['status' => 0, 'data' => $data]);
    }

    /** Enlace público firmado: página lista para compartir por WhatsApp. */
    public function publicView(string $token, Request $request)
    {
        $userId = ClientStatementService::userFromToken($token);
        if (!$userId) {
            return response('Enlace inválido o vencido.', 404)->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        $data = $this->service->build($userId);
        if (!$data) {
            return response('No encontramos la cuenta.', 404)->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        $company = DB::table('companies')->where('id', DB::table('users')->where('id', $userId)->value('company_id'))
            ->first(['name', 'phone', 'invoice_business_name', 'invoice_logo_base64']);

        if ($request->query('format') === 'json') {
            return response()->json(['message' => 'OK', 'data' => $data, 'error' => 0]);
        }

        return response($this->html($data, $company))->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /* ── Página del enlace público ───────────────────────────────────── */
    private function html(array $data, ?object $company): string
    {
        $money = fn($v) => '$' . number_format((float) $v, 0, ',', '.');
        $fecha = function ($s) { $t = strtotime((string) $s); return $t ? date('d/m/Y', $t) : '—'; };
        $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        $empresa = $company->invoice_business_name ?? $company->name ?? 'Netplay';
        $c = $data['client'];
        $s = $data['summary'];

        $filas = '';
        foreach ($data['invoices'] as $f) {
            [$etiqueta, $color, $fondo] = match ($f['status']) {
                'paid'    => ['Pagada',    '#047857', '#ecfdf5'],
                'partial' => ['Abonada',   '#b45309', '#fffbeb'],
                'overdue' => ['Vencida',   '#b91c1c', '#fef2f2'],
                default   => ['Pendiente', '#b45309', '#fffbeb'],
            };

            $detalle = '';
            foreach ($f['payments'] as $p) {
                $ref = $p['referencia'] ? ' · Ref. ' . $e($p['referencia']) : '';
                $op  = !empty($p['operador']) ? ' · registró ' . $e($p['operador']) : '';
                $detalle .= '<div style="font-size:12px;color:#475569;padding:3px 0;">'
                    . '<b>' . $e($p['medio']) . '</b> · ' . $money($p['monto']) . ' · ' . $fecha($p['fecha']) . $ref . $op
                    . '</div>';
            }
            if (!$detalle) {
                $detalle = '<div style="font-size:12px;color:#94a3b8;padding:3px 0;">Sin pagos registrados todavía.</div>';
            }

            $filas .= '<tr>
                <td style="padding:14px 12px;border-bottom:1px solid #eef2f6;vertical-align:top;">
                  <div style="font-weight:700;font-size:14px;color:#0f172a;">' . $e($f['number']) . '</div>
                  <div style="font-size:12px;color:#94a3b8;margin-top:2px;">Vence ' . $fecha($f['due_date']) . '</div>
                  <div style="margin-top:8px;">' . $detalle . '</div>
                </td>
                <td style="padding:14px 12px;border-bottom:1px solid #eef2f6;text-align:right;vertical-align:top;white-space:nowrap;">
                  <div style="font-weight:700;font-size:15px;color:#0f172a;">' . $money($f['total']) . '</div>
                  ' . ($f['balance'] > 0 ? '<div style="font-size:12px;color:#b45309;margin-top:2px;">Saldo ' . $money($f['balance']) . '</div>' : '') . '
                  <div style="display:inline-block;margin-top:8px;padding:3px 9px;border-radius:999px;background:' . $fondo . ';color:' . $color . ';font-size:11px;font-weight:700;">' . $etiqueta . '</div>
                  <div style="margin-top:8px;"><a href="' . $e($f['pdf_url']) . '" style="font-size:12px;color:#0d9488;text-decoration:none;">Ver factura</a></div>
                </td>
              </tr>';
        }

        $logo = $company->invoice_logo_base64 ?? null;

        return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Estado de cuenta · ' . $e($c['name']) . '</title>
<style>
  body { margin:0; background:#f1f5f9; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; color:#0f172a; }
  .wrap { max-width:760px; margin:0 auto; padding:20px 14px 40px; }
  .card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; margin-bottom:14px; }
  .head { background:#0f172a; color:#fff; padding:22px 20px; }
  .kpis { display:table; width:100%; border-collapse:collapse; }
  .kpi { display:table-cell; padding:14px 16px; border-right:1px solid #eef2f6; }
  .kpi:last-child { border-right:0; }
  .kpi b { display:block; font-size:20px; }
  .kpi small { color:#94a3b8; font-size:11px; text-transform:uppercase; letter-spacing:.06em; }
  table { width:100%; border-collapse:collapse; }
  @media (max-width:560px) { .kpi { display:block; border-right:0; border-bottom:1px solid #eef2f6; } }
</style></head>
<body><div class="wrap">

  <div class="card">
    <div class="head">
      ' . ($logo ? '<img src="' . $logo . '" alt="" style="max-height:38px;margin-bottom:10px;">' : '') . '
      <div style="font-size:11px;letter-spacing:.14em;text-transform:uppercase;opacity:.7;">Estado de cuenta</div>
      <div style="font-size:22px;font-weight:700;margin-top:3px;">' . $e($c['name']) . '</div>
      <div style="font-size:12.5px;opacity:.75;margin-top:6px;line-height:1.7;">
        CC/NIT ' . $e($c['dni']) . '<br>' . $e($c['address']) . '<br>' . $e($c['plan'] ?: 'Sin plan') . ' · ' . $e($c['service_status'] ?: '—') . '
      </div>
    </div>
    <div class="kpis">
      <div class="kpi"><small>Saldo pendiente</small><b style="color:' . ($s['balance'] > 0 ? '#b91c1c' : '#047857') . ';">' . $money($s['balance']) . '</b></div>
      <div class="kpi"><small>Facturas pendientes</small><b>' . $s['pending'] . '</b></div>
      <div class="kpi"><small>Facturas pagadas</small><b>' . $s['paid'] . '</b></div>
    </div>
  </div>

  <div class="card">
    <div style="padding:14px 16px;border-bottom:1px solid #eef2f6;font-weight:700;font-size:14px;">Facturas y forma de pago</div>
    <table>' . ($filas ?: '<tr><td style="padding:26px;text-align:center;color:#94a3b8;font-size:13px;">Todavía no hay facturas.</td></tr>') . '</table>
  </div>

  <div style="text-align:center;font-size:12px;color:#94a3b8;line-height:1.7;">
    ' . $e($empresa) . ($company->phone ?? null ? ' · ' . $e($company->phone) : '') . '<br>
    Este enlace es personal, no lo compartas.
  </div>
</div></body></html>';
    }
}
