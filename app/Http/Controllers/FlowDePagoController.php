<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\Pagos\FlowDePago;
use App\Services\Pagos\SobreCifrado;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * La puerta por la que Meta habla con la pantalla de pago del chat.
 *
 * Todo entra y sale cifrado, y la respuesta es texto plano en base64 (no JSON):
 * así lo espera Meta. Un error acá se ve en el teléfono del cliente como
 * «algo salió mal», sin más detalle, así que cada falla queda registrada de
 * este lado con su motivo.
 */
class FlowDePagoController extends Controller
{
    public function __invoke(Request $request, FlowDePago $flow)
    {
        try {
            $sobre = SobreCifrado::abrir($request->all());
        } catch (\Throwable $e) {
            Log::warning('[Flow de pago] No se pudo abrir el sobre', ['error' => $e->getMessage()]);

            // 421: Meta lo entiende como "esta llave ya no sirve" y deja de
            // reintentar con ella.
            return response('', 421);
        }

        $datos  = $sobre['datos'];
        $accion = (string) ($datos['action'] ?? '');

        // Meta golpea la puerta al publicar el Flow para ver si está viva.
        if ($accion === 'ping') {
            return $this->responder(['data' => ['status' => 'active']], $sobre);
        }

        if (!empty($datos['error'])) {
            Log::warning('[Flow de pago] El teléfono reportó un error', ['detalle' => $datos['error']]);

            return $this->responder(['data' => ['acknowledged' => true]], $sobre);
        }

        [$companyId, $userId] = $this->deQuienEs($datos);

        // Sin cliente reconocido es la vista previa de Meta: se muestran las
        // pantallas con datos de ejemplo, en vez de un error que parece falla.
        if (!$companyId || !$userId) {
            return $this->responder($flow->pantallaDeEjemplo(), $sobre);
        }

        $paso = (string) ($datos['data']['paso'] ?? '');

        $respuesta = match (true) {
            $accion === 'INIT'          => $flow->pantallaInicial($companyId, $userId),
            $paso === 'metodo'          => $flow->eligioMedio($companyId, $userId, (string) ($datos['data']['metodo'] ?? '')),
            $paso === 'nequi'           => $flow->enviarCobroNequi($companyId, $userId, (string) ($datos['data']['celular'] ?? '')),
            $paso === 'afuera'          => $flow->esperandoAlBanco(),
            default                     => $flow->pantallaInicial($companyId, $userId),
        };

        return $this->responder($respuesta, $sobre);
    }

    /**
     * De quién es esta pantalla.
     *
     * El Flow se abre con datos que mandamos nosotros al enviarlo
     * (flow_action_payload), así que el cliente no puede cambiarlos: vienen
     * firmados dentro del propio mensaje de WhatsApp.
     *
     * @return array{0:?int, 1:?int}
     */
    private function deQuienEs(array $datos): array
    {
        $companyId = (int) ($datos['data']['empresa'] ?? $datos['flow_token_empresa'] ?? 0);
        $userId    = (int) ($datos['data']['cliente'] ?? 0);

        // El flow_token es lo que viaja siempre: "pago:<empresa>:<cliente>".
        if ((!$companyId || !$userId) && preg_match('/^pago:(\d+):(\d+)$/', (string) ($datos['flow_token'] ?? ''), $m)) {
            $companyId = (int) $m[1];
            $userId    = (int) $m[2];
        }

        if (!$companyId || !$userId) {
            return [null, null];
        }

        // Que el cliente sea de esa empresa: un token armado a mano no alcanza
        // para mirar la deuda de otro.
        $suyo = DB::table('users')->where('id', $userId)->where('company_id', $companyId)->exists();

        return $suyo && Company::whereKey($companyId)->exists() ? [$companyId, $userId] : [null, null];
    }

    /** La respuesta va cifrada y como texto plano, no como JSON. */
    private function responder(array $respuesta, array $sobre)
    {
        return response(
            SobreCifrado::cerrar($respuesta, $sobre['clave'], $sobre['iv']),
            200,
        )->header('Content-Type', 'text/plain');
    }
}
