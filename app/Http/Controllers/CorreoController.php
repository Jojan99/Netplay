<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use App\Services\Correo\Correo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Correo (Mailjet) de la empresa en sesión.
 *
 * Por defecto los correos salen de la cuenta de la plataforma
 * (no-reply@netvula.com) con el nombre de la empresa. Aquí el administrador
 * puede conectar la cuenta de Mailjet propia de su empresa.
 */
class CorreoController extends Controller
{
    /** GET /api/correo/configuracion */
    public function configuracion(): JsonResponse
    {
        $company = Company::findOrFail(getSessionCompanyId());

        return response()->json(['status' => 0, 'data' => $this->estado($company)]);
    }

    /** PUT /api/correo/configuracion */
    public function guardar(Request $request): JsonResponse
    {
        $request->validate([
            'activo'     => 'nullable|boolean',
            'api_key'    => 'nullable|string|max:100',
            'api_secret' => 'nullable|string|max:200',
            'from_email' => 'nullable|email|max:191',
            'from_name'  => 'nullable|string|max:191',
        ]);

        $company = Company::findOrFail(getSessionCompanyId());

        $datos = [];
        foreach (['api_key', 'from_email', 'from_name'] as $campo) {
            if ($request->has($campo)) {
                $datos["mailjet_{$campo}"] = trim((string) $request->input($campo)) ?: null;
            }
        }
        // El secreto solo se toca si lo mandan: el panel nunca lo muestra.
        if ($request->filled('api_secret')) {
            $datos['mailjet_api_secret'] = trim((string) $request->input('api_secret'));
        }

        $activo = $request->has('activo') ? $request->boolean('activo') : (bool) $company->mailjet_activo;

        $key    = $datos['mailjet_api_key']    ?? $company->mailjet_api_key;
        $secret = $datos['mailjet_api_secret'] ?? $this->secretoActual($company);
        $desde  = $datos['mailjet_from_email'] ?? $company->mailjet_from_email;

        if ($activo) {
            if (!$key || !$secret || !$desde) {
                return response()->json([
                    'status'  => 1,
                    'message' => 'Para activar su propio correo necesita la API Key, la Secret Key y el correo remitente.',
                ], 422);
            }

            $prueba = Correo::probarCredenciales((string) $key, (string) $secret, (string) $desde);
            if (!$prueba['ok']) {
                return response()->json([
                    'status'  => 1,
                    'message' => $prueba['detalle'],
                    'data'    => ['llaves_validas' => $prueba['llaves_validas'], 'remitente_activo' => $prueba['remitente_activo']],
                ], 422);
            }

            $datos['mailjet_activo'] = true;
            $datos['mailjet_verificado_en'] = now();
        } else {
            $datos['mailjet_activo'] = false;
            // Si cambió alguna credencial, la verificación anterior ya no vale.
            if (array_key_exists('mailjet_api_key', $datos) || array_key_exists('mailjet_api_secret', $datos) || array_key_exists('mailjet_from_email', $datos)) {
                $datos['mailjet_verificado_en'] = null;
            }
        }

        $company->update($datos);

        return response()->json([
            'status'  => 0,
            'message' => $company->mailjet_activo
                ? 'Listo: sus correos ahora salen desde su propia cuenta de Mailjet.'
                : 'Configuración guardada. Los correos salen desde ' . Correo::remitentePlataforma() . '.',
            'data'    => $this->estado($company->fresh()),
        ]);
    }

    /** POST /api/correo/probar — envía un correo de prueba real. */
    public function probar(Request $request): JsonResponse
    {
        $request->validate(['email' => 'nullable|email|max:191']);

        $company = Company::findOrFail(getSessionCompanyId());
        $destino = trim((string) $request->input('email')) ?: trim((string) User::where('id', getSessionUserId())->value('email'));

        if (!filter_var($destino, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['status' => 1, 'message' => 'Indique a qué correo enviamos la prueba.'], 422);
        }

        $correo = Correo::deEmpresa($company);
        $remitente = $correo->remitente();
        $nombre = trim((string) $company->invoice_business_name) ?: trim((string) $company->name);

        $html = "<p>Hola,</p>"
            . "<p>Esta es una prueba de correo de <strong>" . e($nombre) . "</strong>.</p>"
            . "<p>Salió desde <strong>" . e($remitente['email']) . "</strong> ("
            . ($remitente['origen'] === Correo::PROPIA ? 'la cuenta de Mailjet de su empresa' : 'la cuenta de correo de Netvula') . ")."
            . ($remitente['responder_a'] ? " Las respuestas llegan a " . e($remitente['responder_a']) . "." : '')
            . "</p>"
            . "<p>Si recibiste este mensaje, el envío de facturas y avisos por correo va a funcionar.</p>";

        $resultado = $correo->enviar($destino, 'Prueba de correo — ' . ($nombre ?: 'Netvula'), $html);

        return response()->json([
            'status'  => $resultado['ok'] ? 0 : 1,
            'message' => $resultado['ok']
                ? "Correo de prueba enviado a {$destino}. Revise la bandeja (y el spam)."
                : 'No se pudo enviar: ' . $resultado['detalle'],
            'data'    => [
                'enviado'    => $resultado['ok'],
                'message_id' => $resultado['message_id'],
                'remitente'  => $remitente,
            ],
        ], $resultado['ok'] ? 200 : 422);
    }

    /** DELETE /api/correo/configuracion — vuelve a la cuenta de la plataforma. */
    public function desconectar(): JsonResponse
    {
        $company = Company::findOrFail(getSessionCompanyId());
        $company->update([
            'mailjet_activo'        => false,
            'mailjet_api_key'       => null,
            'mailjet_api_secret'    => null,
            'mailjet_from_email'    => null,
            'mailjet_from_name'     => null,
            'mailjet_verificado_en' => null,
        ]);

        return response()->json([
            'status'  => 0,
            'message' => 'Cuenta de Mailjet desconectada. Sus correos vuelven a salir desde ' . Correo::remitentePlataforma() . '.',
            'data'    => $this->estado($company->fresh()),
        ]);
    }

    // ─── Internos ─────────────────────────────────────────────────────────────

    private function estado(Company $company): array
    {
        $correo = Correo::deEmpresa($company);
        $remitente = $correo->remitente();
        $key = trim((string) $company->mailjet_api_key);

        return [
            'activo'            => (bool) $company->mailjet_activo,
            'api_key'           => $key !== '' ? $this->enmascarar($key) : null,
            'tiene_secreto'     => $this->secretoActual($company) !== null,
            'from_email'        => $company->mailjet_from_email,
            'from_name'         => $company->mailjet_from_name,
            'verificado_en'     => optional($company->mailjet_verificado_en)->format('Y-m-d H:i:s'),
            // Qué se está usando ahora mismo
            'usando'            => $remitente['origen'],
            'usando_texto'      => $remitente['origen'] === Correo::PROPIA
                ? 'propia: ' . $remitente['email']
                : 'sin cuenta: la empresa todavía no puede enviar correos',
            'puede_enviar'      => $remitente['origen'] === Correo::PROPIA,
            'falta_cuenta'      => Correo::FALTA_CUENTA,
            'remitente_actual'  => $remitente,
            'correo_empresa'    => $company->email,
            'remitente_plataforma' => Correo::remitentePlataforma(),
        ];
    }

    /** El secreto guardado, o null si no hay (o si no se puede descifrar). */
    private function secretoActual(Company $company): ?string
    {
        try {
            $s = trim((string) $company->mailjet_api_secret);
            return $s !== '' ? $s : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Solo los últimos 4 caracteres de la llave. */
    private function enmascarar(string $valor): string
    {
        $fin = substr($valor, -4);
        return str_repeat('•', max(strlen($valor) - 4, 4)) . $fin;
    }
}
