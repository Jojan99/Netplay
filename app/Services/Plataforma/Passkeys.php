<?php

namespace App\Services\Plataforma;

use App\Models\PlataformaUsuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Passkeys para la consola de Netvula.
 *
 * El navegador guarda una llave en el dispositivo —teléfono, laptop o una
 * llave física— y la desbloquea con la huella, la cara o el PIN. Aquí sólo
 * queda su clave **pública**.
 *
 * Lo que las hace mejores que un código de seis dígitos: **están atadas al
 * dominio**. Un código se puede escribir en una página falsa que imite a
 * Netvula; una passkey no, porque el navegador se niega a usarla fuera de
 * admin.netvula.com. Eso cierra el engaño, que es como se roban las cuentas
 * en la práctica.
 *
 * Nada de esto se verifica a mano: la comprobación de firmas, CBOR y claves
 * COSE la hace web-auth/webauthn-lib. Equivocarse en un detalle daría un
 * segundo factor que parece funcionar y no protege nada.
 */
class Passkeys
{
    /** Lo que dura el desafío mientras la persona pone el dedo. */
    private const MINUTOS = 5;

    /**
     * El dominio al que quedan atadas. Fuera de aquí no sirven.
     *
     * Es el host de la consola y no el dominio raíz a propósito: con
     * «netvula.com» la misma passkey valdría en cualquier subdominio,
     * incluidos los paneles de las empresas. La llave de la cuenta que
     * administra todo tiene que abrir una sola puerta.
     */
    public static function dominio(): string
    {
        return (string) config('plataforma.consola_host', 'admin.netvula.com');
    }

    // ── Registrar una passkey ───────────────────────────────────────────────

    /**
     * Las opciones que el navegador necesita para crear la llave.
     *
     * Se piden credenciales «descubribles» para que después se pueda entrar
     * sin escribir el correo: el navegador ya sabe de quién es.
     */
    public static function opcionesParaRegistrar(PlataformaUsuario $usuario): array
    {
        $opciones = PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create(config('plataforma.nombre', 'Netvula'), self::dominio()),
            PublicKeyCredentialUserEntity::create($usuario->email, (string) $usuario->id, $usuario->nombre ?: $usuario->email),
            random_bytes(32),
            [
                PublicKeyCredentialParameters::create('public-key', -7),    // ES256
                PublicKeyCredentialParameters::create('public-key', -257),  // RS256
            ],
            AuthenticatorSelectionCriteria::create(
                null,
                AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
            ),
            // Sin attestation: no nos interesa saber la marca del dispositivo,
            // y pedirla sólo agrega un diálogo más al usuario.
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            // Las que ya tiene, para que el navegador no ofrezca repetir una.
            self::descriptoresDe($usuario),
            60_000,
        );

        $json = self::serializador()->serialize($opciones, 'json');

        Cache::put(self::clave('alta', $usuario->id), $json, now()->addMinutes(self::MINUTOS));

        return json_decode($json, true);
    }

    /**
     * Comprueba lo que devolvió el navegador y guarda la passkey.
     *
     * @return array{ok: bool, motivo: ?string}
     */
    public static function guardar(PlataformaUsuario $usuario, string $respuesta, ?string $nombre = null): array
    {
        $pedido = Cache::pull(self::clave('alta', $usuario->id));

        if (!$pedido) {
            return ['ok' => false, 'motivo' => 'Se venció el tiempo para confirmar. Pruebe de nuevo.'];
        }

        try {
            $serializador = self::serializador();

            $credencial = $serializador->deserialize($respuesta, PublicKeyCredential::class, 'json');

            if (!$credencial->response instanceof AuthenticatorAttestationResponse) {
                return ['ok' => false, 'motivo' => 'El navegador no devolvió una passkey nueva.'];
            }

            $opciones = $serializador->deserialize($pedido, PublicKeyCredentialCreationOptions::class, 'json');

            $registro = AuthenticatorAttestationResponseValidator::create(
                (new CeremonyStepManagerFactory())->creationCeremony(),
            )->check($credencial->response, $opciones, self::dominio());

            DB::table('plataforma_passkeys')->insert([
                'usuario_id'    => $usuario->id,
                'credential_id' => self::id($registro->publicKeyCredentialId),
                'datos'         => $serializador->serialize($registro, 'json'),
                'nombre'        => Str::limit(trim((string) $nombre) ?: 'Este dispositivo', 110, ''),
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            return ['ok' => true, 'motivo' => null];
        } catch (\Throwable $e) {
            Log::warning('[Consola] No se pudo registrar la passkey', ['usuario' => $usuario->id, 'error' => $e->getMessage()]);

            return ['ok' => false, 'motivo' => 'No se pudo guardar la passkey: ' . $e->getMessage()];
        }
    }

    // ── Entrar con una passkey ──────────────────────────────────────────────

    /**
     * El desafío para entrar, sin pedir el correo.
     *
     * No se listan credenciales a propósito: el navegador muestra las que
     * tenga para este dominio. Decirle cuáles existen le diría a cualquiera
     * qué cuentas hay.
     */
    public static function opcionesParaEntrar(): array
    {
        $opciones = PublicKeyCredentialRequestOptions::create(
            random_bytes(32),
            self::dominio(),
            [],
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            60_000,
        );

        $json = self::serializador()->serialize($opciones, 'json');
        $pase = Str::random(48);

        Cache::put(self::clave('entrar', $pase), $json, now()->addMinutes(self::MINUTOS));

        return ['pase' => $pase, 'opciones' => json_decode($json, true)];
    }

    /**
     * Comprueba la firma y devuelve de quién es la passkey.
     *
     * @return array{usuario: ?PlataformaUsuario, motivo: ?string}
     */
    public static function entrar(string $pase, string $respuesta, Request $request): array
    {
        $pedido = Cache::pull(self::clave('entrar', $pase));

        if (!$pedido) {
            return ['usuario' => null, 'motivo' => 'Se venció el tiempo para confirmar. Pruebe de nuevo.'];
        }

        try {
            $serializador = self::serializador();

            $credencial = $serializador->deserialize($respuesta, PublicKeyCredential::class, 'json');

            if (!$credencial->response instanceof AuthenticatorAssertionResponse) {
                return ['usuario' => null, 'motivo' => 'El navegador no devolvió una passkey.'];
            }

            $fila = DB::table('plataforma_passkeys')
                ->where('credential_id', self::id($credencial->rawId))
                ->first();

            if (!$fila) {
                return ['usuario' => null, 'motivo' => 'Esa passkey no está registrada en la consola.'];
            }

            $usuario = PlataformaUsuario::find($fila->usuario_id);

            if (!$usuario || !$usuario->activo) {
                return ['usuario' => null, 'motivo' => 'Esa cuenta está desactivada.'];
            }

            $guardada = $serializador->deserialize($fila->datos, CredentialRecord::class, 'json');
            $opciones = $serializador->deserialize($pedido, PublicKeyCredentialRequestOptions::class, 'json');

            $actualizada = AuthenticatorAssertionResponseValidator::create(
                (new CeremonyStepManagerFactory())->requestCeremony(),
            )->check(
                $guardada,
                $credencial->response,
                $opciones,
                self::dominio(),
                (string) $usuario->id,
            );

            // El contador de uso sube en cada ingreso: guardarlo es lo que
            // permite notar una passkey clonada.
            DB::table('plataforma_passkeys')->where('id', $fila->id)->update([
                'datos'         => $serializador->serialize($actualizada, 'json'),
                'ultimo_uso_en' => now(),
                'updated_at'    => now(),
            ]);

            return ['usuario' => $usuario, 'motivo' => null];
        } catch (\Throwable $e) {
            Log::warning('[Consola] Passkey rechazada', ['ip' => $request->ip(), 'error' => $e->getMessage()]);

            return ['usuario' => null, 'motivo' => 'No se pudo comprobar la passkey.'];
        }
    }

    // ── Lista y borrado ─────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    public static function deUsuario(PlataformaUsuario $usuario): array
    {
        return DB::table('plataforma_passkeys')
            ->where('usuario_id', $usuario->id)
            ->orderByDesc('id')
            ->get(['id', 'nombre', 'ultimo_uso_en', 'created_at'])
            ->map(fn ($p) => (array) $p)
            ->all();
    }

    public static function borrar(PlataformaUsuario $usuario, int $id): bool
    {
        return DB::table('plataforma_passkeys')
            ->where('usuario_id', $usuario->id)
            ->where('id', $id)
            ->delete() > 0;
    }

    // ── Interno ─────────────────────────────────────────────────────────────

    /** @return list<PublicKeyCredentialDescriptor> */
    private static function descriptoresDe(PlataformaUsuario $usuario): array
    {
        return DB::table('plataforma_passkeys')
            ->where('usuario_id', $usuario->id)
            ->pluck('credential_id')
            ->map(fn ($id) => PublicKeyCredentialDescriptor::create('public-key', self::deId((string) $id)))
            ->all();
    }

    private static function serializador(): \Symfony\Component\Serializer\SerializerInterface
    {
        return (new WebauthnSerializerFactory(AttestationStatementSupportManager::create()))->create();
    }

    /** El id en base64url, que es como lo manda el navegador. */
    private static function id(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function deId(string $texto): string
    {
        return (string) base64_decode(strtr($texto, '-_', '+/'), true);
    }

    private static function clave(string $que, string|int $de): string
    {
        return "consola:passkey:{$que}:" . hash('sha256', (string) $de);
    }
}
