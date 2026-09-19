<?php

namespace App\Services\Plataforma;

use App\Models\PlataformaUsuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Quién hizo qué en la consola de Netvula.
 *
 * Se anota todo lo que cambia algo: plan, precio, cupón, crédito, cobro,
 * pago, suspensión. El "quién" es un usuario de la consola
 * (`plataforma_usuarios`), no uno del panel de una empresa. Cuando algo lo
 * dispara el sistema —el crédito de un referido al registrarse una empresa,
 * por ejemplo— queda anotado como "sistema", y así se ve.
 *
 * Nunca tumba la acción: si la bitácora falla, se registra en el log y se
 * sigue.
 */
class Bitacora
{
    public static function anotar(string $accion, ?int $companyId = null, array $detalle = [], ?string $entidad = null, string|int|null $entidadId = null): void
    {
        self::anotarComo(self::usuarioActual(), $accion, $companyId, $detalle, $entidad, $entidadId);
    }

    public static function anotarComo(?PlataformaUsuario $usuario, string $accion, ?int $companyId = null, array $detalle = [], ?string $entidad = null, string|int|null $entidadId = null): void
    {
        try {
            if (!Schema::hasTable('plataforma_bitacora')) {
                return;
            }

            DB::table('plataforma_bitacora')->insert([
                'user_id'    => $usuario?->id,
                'usuario'    => $usuario?->email ?? 'sistema',
                'accion'     => $accion,
                'company_id' => $companyId,
                'entidad'    => $entidad,
                'entidad_id' => $entidadId === null ? null : (string) $entidadId,
                'detalle'    => $detalle ? json_encode($detalle, JSON_UNESCAPED_UNICODE) : null,
                'ip'         => request()->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Consola] no se pudo anotar en la bitácora', ['accion' => $accion, 'error' => $e->getMessage()]);
        }
    }

    /** El usuario de la consola de esta petición, si lo hay. */
    public static function usuarioActual(): ?PlataformaUsuario
    {
        $u = request()->attributes->get('consola_usuario');

        return $u instanceof PlataformaUsuario ? $u : null;
    }

    /** Su id, para las columnas user_id de la facturación de la plataforma. */
    public static function idActual(): ?int
    {
        return self::usuarioActual()?->id;
    }
}
