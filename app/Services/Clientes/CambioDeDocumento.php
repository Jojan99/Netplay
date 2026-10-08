<?php

namespace App\Services\Clientes;

use App\Services\Red\ClienteEnElRouter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Corrige la cédula (el documento) de un cliente.
 *
 * En la ficha el documento es de solo lectura a propósito: no es un dato más.
 * Va copiado en el usuario de acceso (612 de 625 clientes entran con la
 * cédula), en el comment de sus entradas del MikroTik, en el CRM, en la
 * identidad que recuerda el bot de WhatsApp y en los tickets. Cambiarlo solo en
 * user_data dejaba todo eso con el número viejo. Aquí se cambia en todos lados
 * de una vez y queda en el historial del cliente.
 *
 * Lo que no se toca: el espejo de Alegra (se corrige en Alegra y vuelve solo al
 * sincronizar) y las facturas electrónicas ya emitidas, que no se pueden cambiar.
 */
class CambioDeDocumento
{
    /** Cédulas antiguas de 6 dígitos; pasaportes y extranjería, hasta 15. */
    private const MINIMO = 5;
    private const MAXIMO = 15;


    /**
     * @return array{ok:bool, mensaje:string, viejo?:string, nuevo?:string, hecho?:list<string>, avisos?:list<string>}
     */
    public function cambiar(int $companyId, int $userId, string $escrito, ?int $autor): array
    {
        $nuevo = self::limpiar($escrito);

        if (strlen($nuevo) < self::MINIMO || strlen($nuevo) > self::MAXIMO) {
            return ['ok' => false, 'mensaje' => 'Escriba un documento válido, de ' . self::MINIMO . ' a ' . self::MAXIMO . ' caracteres, sin puntos ni espacios.'];
        }

        $cliente = DB::table('user_data')->where('company_id', $companyId)->where('user_id', $userId)->first(['user_id', 'dni', 'names', 'lastname']);

        if (!$cliente) {
            return ['ok' => false, 'mensaje' => 'Cliente no encontrado.'];
        }

        $viejo = trim((string) $cliente->dni);

        if ($viejo === $nuevo) {
            return ['ok' => false, 'mensaje' => 'Ese ya es su documento.'];
        }

        // Dos clientes con la misma cédula rompen el bot, el CRM y el router: cada
        // uno se reconoce por ella. Cuentan también los eliminados, que se pueden reinstalar.
        $otro = DB::table('user_data')->where('company_id', $companyId)->where('dni', $nuevo)->where('user_id', '<>', $userId)
            ->first(['names', 'lastname', 'active']);

        if ($otro) {
            return ['ok' => false, 'mensaje' => 'Ese documento ya lo tiene ' . trim("{$otro->names} {$otro->lastname}") . ($otro->active ? '' : ' (cliente eliminado)') . '.'];
        }

        $usuario = DB::table('users')->where('id', $userId)->value('username');
        // El usuario de acceso se cambia solo si era la cédula; si alguien le puso otro, se respeta.
        $cambiaUsuario = trim((string) $usuario) === $viejo;

        if ($cambiaUsuario && DB::table('users')->where('username', $nuevo)->where('id', '<>', $userId)->exists()) {
            return ['ok' => false, 'mensaje' => 'Ya existe un usuario de acceso con ese documento. Revíselo antes de cambiar la cédula.'];
        }

        $hecho = [];
        $avisos = [];

        // Primero el router: con la cédula nueva ya guardada, las entradas que
        // llevan la vieja dejarían de reconocerse como suyas.
        $router = new ClienteEnElRouter(app(\App\Managers\Interfaces\ConectionRouterManagerInterface::class), $companyId);
        $enRouter = $router->cambiarDocumento($userId, $viejo, $nuevo);

        if ($enRouter['ok']) {
            $hecho[] = $enRouter['quitado']
                ? 'MikroTik: comentario actualizado en ' . implode(', ', $enRouter['quitado'])
                : 'MikroTik: no había entradas con la cédula vieja en el comentario';
        } else {
            // Sigue adelante: el router lo sigue encontrando por su IP o su usuario PPPoE.
            $avisos[] = 'No se pudo actualizar el MikroTik (' . implode('; ', $enRouter['errores']) . '). El cliente sigue funcionando; corrija el comentario a mano.';
        }

        try {
            DB::transaction(function () use ($companyId, $userId, $viejo, $nuevo, $cambiaUsuario, $autor, &$hecho) {
                DB::table('user_data')->where('company_id', $companyId)->where('user_id', $userId)->update(['dni' => $nuevo, 'updated_at' => now()]);
                $hecho[] = 'Ficha del cliente';

                if ($cambiaUsuario) {
                    DB::table('users')->where('id', $userId)->update(['username' => $nuevo, 'updated_at' => now()]);
                    $hecho[] = 'Usuario de acceso';
                }

                $copias = [
                    'CRM'                    => DB::table('crm_customers')->where('company_id', $companyId)->where('user_id', $userId)->update(['dni' => $nuevo, 'updated_at' => now()]),
                    'Identificación del CRM' => DB::table('crm_identificaciones')->where('company_id', $companyId)->where('user_id', $userId)->where('dni', $viejo)->update(['dni' => $nuevo, 'updated_at' => now()]),
                    'Bot de WhatsApp'        => DB::table('wa_identities')->where('company_id', $companyId)->where('user_id', $userId)->update(['dni' => $nuevo, 'updated_at' => now()]),
                    'Tickets'                => DB::table('tickets')->where('company_id', $companyId)->where('user_id', $userId)->where('cedula', $viejo)->update(['cedula' => $nuevo, 'updated_at' => now()]),
                ];

                foreach ($copias as $donde => $filas) {
                    if ($filas > 0) {
                        $hecho[] = "{$donde} ({$filas})";
                    }
                }

                DB::table('user_audit_logs')->insert([
                    'user_id'       => $userId,
                    // La columna no admite nulo: 0 es «el sistema», como en el resto del historial.
                    'changed_by'    => $autor ?? 0,
                    'company_id'    => $companyId,
                    'field_changed' => 'dni',
                    'old_value'     => $viejo,
                    'new_value'     => $nuevo,
                    'description'   => 'Cambio de documento',
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            });
        } catch (\Throwable $e) {
            // La base no se cambió: el router no puede quedar con la cédula nueva.
            if ($enRouter['ok'] && $enRouter['quitado']) {
                $router->cambiarDocumento($userId, $nuevo, $viejo);
            }

            Log::error('[Clientes] Falló el cambio de documento', ['cliente' => $userId, 'error' => $e->getMessage()]);

            return ['ok' => false, 'mensaje' => 'No se pudo cambiar el documento; no quedó nada a medias. Intente de nuevo.'];
        }

        if (DB::table('alegra_contactos')->where('user_id', $userId)->exists()) {
            $avisos[] = 'En Alegra el contacto sigue con el documento ' . $viejo . ': corríjalo allá y se actualiza al sincronizar.';
        }

        Log::info('[Clientes] Cambio de documento', ['empresa' => $companyId, 'cliente' => $userId, 'de' => $viejo, 'a' => $nuevo, 'por' => $autor, 'hecho' => $hecho]);

        return ['ok' => true, 'mensaje' => "Documento cambiado de {$viejo} a {$nuevo}.", 'viejo' => $viejo, 'nuevo' => $nuevo, 'hecho' => $hecho, 'avisos' => $avisos];
    }

    /** Sin puntos, espacios ni guiones de formato: «1.041.850.072» es 1041850072. */
    public static function limpiar(string $documento): string
    {
        return strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $documento));
    }
}
