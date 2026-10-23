<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * El celular como lector de códigos del computador.
 *
 * El computador abre una sesión (con su sesión de usuario) y muestra un QR con el token. El
 * celular entra por ese enlace, sin iniciar sesión, y manda cada código que lee. El computador
 * los recoge, los procesa como si los hubiera leído él, y contesta cada uno: el celular muestra
 * esa respuesta. El token es largo, vence a la media hora sin uso y sólo sirve para mandar
 * códigos a esa pantalla: no da acceso a nada más.
 */
class EscanerCelularController extends Controller
{
    /** Sin uso durante este tiempo, la sesión vence. */
    private const MINUTOS = 30;

    // ── El computador (con sesión) ───────────────────────────────────────

    /** POST api/escaner/sesiones {modo, titulo} */
    public function abrir(Request $request): JsonResponse
    {
        $user = JWTAuth::user();
        $datos = $request->validate(['modo' => 'nullable|in:serial,producto', 'titulo' => 'nullable|string|max:120']);

        // Lo de días anteriores no le sirve a nadie: se borra con sus lecturas.
        $viejas = DB::table('escaner_sesiones')->where('created_at', '<', now()->subDay())->pluck('id');
        if ($viejas->isNotEmpty()) {
            DB::table('escaner_lecturas')->whereIn('sesion_id', $viejas)->delete();
            DB::table('escaner_sesiones')->whereIn('id', $viejas)->delete();
        }

        // Las que dejó abiertas esta persona ya no sirven: una pantalla, un celular.
        DB::table('escaner_sesiones')->where('user_id', $user->id)->whereNull('cerrada_en')->update(['cerrada_en' => now(), 'updated_at' => now()]);

        $token = Str::random(48);
        DB::table('escaner_sesiones')->insert([
            'token' => $token, 'company_id' => (int) $user->company_id, 'user_id' => $user->id,
            'modo' => $datos['modo'] ?? 'producto', 'titulo' => $datos['titulo'] ?? null,
            'expira_en' => now()->addMinutes(self::MINUTOS), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return standardApiReponse('OK', ['token' => $token, 'minutos' => self::MINUTOS], 0, JsonResponse::HTTP_OK);
    }

    /** GET api/escaner/sesiones/{token}/lecturas?desde=ID — lo que mandó el celular. */
    public function lecturas(Request $request, string $token): JsonResponse
    {
        $s = $this->propia($token);

        if (!$s) {
            return standardApiReponse('La sesión del celular venció.', null, 1, JsonResponse::HTTP_GONE);
        }

        $this->extender($s);
        $lecturas = DB::table('escaner_lecturas')->where('sesion_id', $s->id)->where('id', '>', (int) $request->query('desde', 0))
            ->orderBy('id')->limit(50)->get(['id', 'codigo']);

        return standardApiReponse('OK', ['conectado' => (bool) $s->conectado_en, 'lecturas' => $lecturas], 0, JsonResponse::HTTP_OK);
    }

    /** POST api/escaner/sesiones/{token}/veredicto {id, bien, texto} — lo que se le muestra al celular. */
    public function veredicto(Request $request, string $token): JsonResponse
    {
        $s = $this->propia($token);
        $datos = $request->validate(['id' => 'required|integer', 'bien' => 'required|boolean', 'texto' => 'nullable|string|max:160']);

        if ($s) {
            DB::table('escaner_lecturas')->where('sesion_id', $s->id)->where('id', $datos['id'])->whereNull('respondida_en')
                ->update(['bien' => $datos['bien'], 'texto' => $datos['texto'] ?? null, 'respondida_en' => now(), 'updated_at' => now()]);
        }

        return standardApiReponse('OK', null, 0, JsonResponse::HTTP_OK);
    }

    /** DELETE api/escaner/sesiones/{token} */
    public function cerrar(string $token): JsonResponse
    {
        if ($s = $this->propia($token)) {
            DB::table('escaner_sesiones')->where('id', $s->id)->update(['cerrada_en' => now(), 'updated_at' => now()]);
        }

        return standardApiReponse('OK', null, 0, JsonResponse::HTTP_OK);
    }

    // ── El celular (sin sesión, sólo con el token) ───────────────────────

    /** GET api/escaner/publico/{token} — qué pantalla lo espera. */
    public function datos(string $token): JsonResponse
    {
        $s = $this->vigente($token);

        if (!$s) {
            return standardApiReponse('Este código ya venció. Vuelva a abrir «Usar el celular» en el computador y escanee el nuevo.', null, 1, JsonResponse::HTTP_GONE);
        }

        DB::table('escaner_sesiones')->where('id', $s->id)->update(['conectado_en' => now(), 'updated_at' => now()]);
        $this->extender($s);

        return standardApiReponse('OK', [
            'modo' => $s->modo, 'titulo' => $s->titulo,
            'empresa' => DB::table('companies')->where('id', $s->company_id)->value('name'),
            'quien' => DB::table('user_data')->where('user_id', $s->user_id)->value('names'),
        ], 0, JsonResponse::HTTP_OK);
    }

    /** POST api/escaner/publico/{token}/lecturas {codigo} */
    public function leer(Request $request, string $token): JsonResponse
    {
        $s = $this->vigente($token);
        $datos = $request->validate(['codigo' => 'required|string|max:120']);

        if (!$s) {
            return standardApiReponse('La sesión se cerró en el computador.', null, 1, JsonResponse::HTTP_GONE);
        }

        $this->extender($s);
        $id = DB::table('escaner_lecturas')->insertGetId([
            'sesion_id' => $s->id, 'codigo' => trim($datos['codigo']), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return standardApiReponse('OK', ['id' => $id], 0, JsonResponse::HTTP_OK);
    }

    /** GET api/escaner/publico/{token}/veredictos?ids=1,2,3 — las respuestas del computador. */
    public function veredictos(Request $request, string $token): JsonResponse
    {
        $s = DB::table('escaner_sesiones')->where('token', $token)->first();

        if (!$s) {
            return standardApiReponse('No existe.', null, 1, JsonResponse::HTTP_GONE);
        }

        $ids = array_slice(array_filter(array_map('intval', explode(',', (string) $request->query('ids', '')))), 0, 50);
        $listos = $ids ? DB::table('escaner_lecturas')->where('sesion_id', $s->id)->whereIn('id', $ids)->whereNotNull('respondida_en')->get(['id', 'bien', 'texto']) : [];

        return standardApiReponse('OK', [
            'cerrada' => $s->cerrada_en !== null || now()->greaterThan($s->expira_en),
            'veredictos' => $listos,
        ], 0, JsonResponse::HTTP_OK);
    }

    // ── Ayudas ───────────────────────────────────────────────────────────

    private function propia(string $token): ?object
    {
        $user = JWTAuth::user();

        return DB::table('escaner_sesiones')->where('token', $token)->where('user_id', $user->id)
            ->whereNull('cerrada_en')->where('expira_en', '>', now())->first();
    }

    private function vigente(string $token): ?object
    {
        return strlen($token) === 48
            ? DB::table('escaner_sesiones')->where('token', $token)->whereNull('cerrada_en')->where('expira_en', '>', now())->first()
            : null;
    }

    private function extender(object $s): void
    {
        DB::table('escaner_sesiones')->where('id', $s->id)->update(['expira_en' => now()->addMinutes(self::MINUTOS)]);
    }
}
