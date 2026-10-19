<?php

namespace App\Http\Controllers;

use App\Services\Red\PaginaDelEquipo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * El puente hacia la página web de la ONT del cliente (ver PaginaDelEquipo).
 *
 * abrir() es del panel (con sesión y rol). entrar(), validar() y sinSesion() los
 * llama nginx desde equipos.netvula.com: no llevan sesión de Laravel ni cifrado de
 * cookies, y sólo responden si nginx marca la petición como suya
 * (PAGINA_EQUIPO_INTERNO).
 */
class PaginaDelEquipoController extends Controller
{
    public function abrir(int $id): object
    {
        try {
            $r = (new PaginaDelEquipo((int) getSessionCompanyId()))->abrir($id, (int) (getSessionUserId() ?? 0));

            return standardApiReponse('Listo', $r, 0, JsonResponse::HTTP_OK);
        } catch (\InvalidArgumentException $e) {
            return standardApiReponse($e->getMessage(), null, 1, JsonResponse::HTTP_OK);
        }
    }

    /** Usuario y clave de administración del equipo del cliente (sólo administradores). */
    public function credenciales(int $id): object
    {
        try {
            $r = (new PaginaDelEquipo((int) getSessionCompanyId()))->credenciales($id, (int) (getSessionUserId() ?? 0));

            return standardApiReponse('Listo', $r, 0, JsonResponse::HTTP_OK);
        } catch (\InvalidArgumentException $e) {
            return standardApiReponse($e->getMessage(), null, 1, JsonResponse::HTTP_OK);
        }
    }

    public function entrar(Request $request): Response
    {
        if (!self::deNginx($request)) {
            abort(404);
        }

        $r = PaginaDelEquipo::entrar((string) $request->query('t', ''));

        if (!$r) {
            return response(self::aviso('El enlace ya se usó o venció. Vuelva a abrir la página del equipo desde la ficha del cliente.'), 410);
        }

        $respuesta = response('', 302, ['Location' => $r['inicio'], 'Cache-Control' => 'private, no-store']);
        $respuesta->headers->setCookie(Cookie::create(PaginaDelEquipo::COOKIE, $r['sesion'], 0, '/', null, true, true, false, Cookie::SAMESITE_LAX));

        // La sesión que el servidor abrió en el equipo: el navegador sigue con ella.
        foreach ($r['cookies'] as $nombre => $valor) {
            $respuesta->headers->setCookie(Cookie::create($nombre, $valor, 0, '/', null, true, false, true, Cookie::SAMESITE_LAX));
        }

        return $respuesta;
    }

    /** nginx (auth_request) pregunta a dónde reenviar cada petición. */
    public function validar(Request $request): Response
    {
        if (!self::deNginx($request)) {
            abort(404);
        }

        $destino = PaginaDelEquipo::destino((string) $request->cookies->get(PaginaDelEquipo::COOKIE, ''));

        return $destino
            ? response('', 200, [
                'X-Equipo-Destino' => $destino['ip'] . ':' . $destino['puerto'],
                'X-Equipo-Esquema' => $destino['esquema'],
                'X-Equipo-Host'    => $destino['host'],
            ])
            : response('', 401);
    }

    /** La página que muestra nginx cuando no hay sesión del puente. */
    public function sinSesion(Request $request): Response
    {
        if (!self::deNginx($request)) {
            abort(404);
        }

        return response(self::aviso('La sesión con el equipo venció o no se abrió desde la plataforma. Vuelva a abrirla desde la ficha del cliente.'), 401);
    }

    private static function deNginx(Request $request): bool
    {
        return $request->server('PAGINA_EQUIPO_INTERNO') === '1';
    }

    private static function aviso(string $texto): string
    {
        return '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Página del equipo</title>'
            . '<body style="font-family:system-ui,sans-serif;max-width:32rem;margin:15vh auto;padding:0 16px;color:#222">'
            . '<h2 style="font-weight:600">Página del equipo</h2><p>' . e($texto) . '</p></body>';
    }
}
