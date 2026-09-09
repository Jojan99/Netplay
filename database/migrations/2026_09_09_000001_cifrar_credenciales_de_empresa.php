<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Cifra las credenciales que hasta ahora estaban en texto plano en `companies`.
 *
 * El modelo Company pasó a declarar estos campos como 'encrypted'. Eloquent
 * descifra al leer, así que los valores que ya están guardados en claro hay que
 * convertirlos una sola vez o dejarían de poder leerse.
 *
 * La migración es idempotente: si un valor ya está cifrado se detecta al
 * intentar descifrarlo y se deja como está, de modo que volver a ejecutarla no
 * produce doble cifrado.
 */
return new class extends Migration
{
    /** Campos a convertir, si la columna existe. */
    private const CAMPOS = [
        'pg_private_key',
        'pg_events_secret',
        'pg_integrity_secret',
        'wa_access_token',
    ];

    public function up(): void
    {
        $campos = array_values(array_filter(
            self::CAMPOS,
            fn ($c) => Schema::hasColumn('companies', $c)
        ));

        if (!$campos) {
            return;
        }

        $convertidos = 0;
        $yaCifrados  = 0;

        foreach (DB::table('companies')->select(array_merge(['id'], $campos))->cursor() as $fila) {
            $cambios = [];

            foreach ($campos as $campo) {
                $valor = $fila->$campo ?? null;

                if ($valor === null || $valor === '') {
                    continue;
                }

                if ($this->yaEstaCifrado($valor)) {
                    $yaCifrados++;
                    continue;
                }

                $cambios[$campo] = Crypt::encryptString($valor);
            }

            if ($cambios) {
                DB::table('companies')->where('id', $fila->id)->update($cambios);
                $convertidos += count($cambios);
            }
        }

        Log::info('[Migración] Credenciales de empresa cifradas', [
            'valores_convertidos' => $convertidos,
            'ya_estaban_cifrados' => $yaCifrados,
        ]);
    }

    /**
     * Revertir deja las credenciales de nuevo en texto plano, que es como
     * estaban antes. Solo tiene sentido si además se quitan los casts del
     * modelo; si no, la aplicación no podrá leerlas.
     */
    public function down(): void
    {
        $campos = array_values(array_filter(
            self::CAMPOS,
            fn ($c) => Schema::hasColumn('companies', $c)
        ));

        foreach (DB::table('companies')->select(array_merge(['id'], $campos))->cursor() as $fila) {
            $cambios = [];

            foreach ($campos as $campo) {
                $valor = $fila->$campo ?? null;

                if ($valor === null || $valor === '' || !$this->yaEstaCifrado($valor)) {
                    continue;
                }

                try {
                    $cambios[$campo] = Crypt::decryptString($valor);
                } catch (\Throwable $e) {
                    // Si no se puede descifrar se deja como está antes que perderlo
                }
            }

            if ($cambios) {
                DB::table('companies')->where('id', $fila->id)->update($cambios);
            }
        }
    }

    private function yaEstaCifrado(string $valor): bool
    {
        try {
            Crypt::decryptString($valor);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
};
