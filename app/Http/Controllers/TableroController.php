<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * El tablero de inicio, armado por cada usuario.
 *
 * Aquí sólo viven los paneles elegidos y su orden. Cada panel pide sus propios
 * datos a la pantalla que ya los tenía, así que agregar uno nuevo no toca este
 * controlador: basta con sumarlo a PANELES para que se pueda guardar.
 */
class TableroController extends Controller
{
    /**
     * Los paneles que existen. Lo que no esté aquí no se guarda, así una
     * sesión vieja o un curioso no meten cualquier cosa en la fila.
     */
    public const PANELES = [
        'resolver', 'vivo', 'plata', 'mora', 'metodos', 'deudores', 'cobranza',
        'salud', 'borde', 'equipos', 'tickets', 'clientes', 'novedades',
    ];

    /** El tablero de fábrica, para quien todavía no armó el suyo. */
    public const DE_FABRICA = ['resolver', 'plata', 'salud', 'vivo', 'deudores', 'tickets', 'novedades'];

    /** Cuántos paneles puede tener un tablero. */
    private const MAXIMO = 20;

    /** GET api/company/tablero */
    public function ver(): JsonResponse
    {
        $user = JWTAuth::user();

        if (!$user) {
            return standardApiReponse('Sin sesión', null, 1, JsonResponse::HTTP_UNAUTHORIZED);
        }

        $guardado = Schema::hasTable('tablero_usuario')
            ? DB::table('tablero_usuario')->where('user_id', $user->id)->value('paneles')
            : null;

        $paneles = $this->limpiar(json_decode((string) $guardado, true) ?: []);

        return standardApiReponse('OK', [
            'paneles'    => $paneles ?: self::DE_FABRICA,
            'armado'     => (bool) $paneles,
            'de_fabrica' => self::DE_FABRICA,
        ], 0, JsonResponse::HTTP_OK);
    }

    /** PUT api/company/tablero */
    public function guardar(Request $request): JsonResponse
    {
        $user = JWTAuth::user();

        if (!$user) {
            return standardApiReponse('Sin sesión', null, 1, JsonResponse::HTTP_UNAUTHORIZED);
        }

        if (!Schema::hasTable('tablero_usuario')) {
            return standardApiReponse('Falta correr la migración del tablero.', null, 1, JsonResponse::HTTP_CONFLICT);
        }

        $paneles = $this->limpiar($request->input('paneles', []));

        DB::table('tablero_usuario')->updateOrInsert(
            ['user_id' => $user->id],
            [
                'company_id' => (int) ($user->company_id ?? 0),
                'paneles'    => json_encode(array_values($paneles)),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        return standardApiReponse('Su tablero quedó guardado.', ['paneles' => $paneles], 0, JsonResponse::HTTP_OK);
    }

    /** DELETE api/company/tablero — volver al de fábrica. */
    public function olvidar(): JsonResponse
    {
        $user = JWTAuth::user();

        if ($user && Schema::hasTable('tablero_usuario')) {
            DB::table('tablero_usuario')->where('user_id', $user->id)->delete();
        }

        return standardApiReponse('Volviste al tablero de fábrica.', ['paneles' => self::DE_FABRICA], 0, JsonResponse::HTTP_OK);
    }

    /** Sólo paneles conocidos, sin repetidos y sin pasarse de largo. */
    private function limpiar(mixed $paneles): array
    {
        if (!is_array($paneles)) {
            return [];
        }

        $limpios = array_values(array_unique(array_filter(
            array_map(fn ($p) => is_string($p) ? $p : '', $paneles),
            fn ($p) => in_array($p, self::PANELES, true),
        )));

        return array_slice($limpios, 0, self::MAXIMO);
    }
}
