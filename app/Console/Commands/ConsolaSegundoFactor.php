<?php

namespace App\Console\Commands;

use App\Models\PlataformaUsuario;
use App\Services\Plataforma\SegundoFactor;
use App\Services\Seguridad\CodigoDeUnSoloUso as Totp;
use Illuminate\Console\Command;

/**
 * La salida de emergencia del authenticator de la consola.
 *
 * Hoy hay **un solo** usuario en la consola de Netvula, así que no existe otro
 * administrador que pueda resetearle el segundo factor. Si pierde el teléfono
 * y se le acabaron los códigos de recuperación, la única forma de volver a
 * entrar es desde el servidor. Sin esto, activar el authenticator sería
 * cambiar un riesgo por otro peor.
 *
 *   php artisan consola:2fa estado
 *   php artisan consola:2fa quitar --email=alguien@netvula.com
 *   php artisan consola:2fa codigos --email=alguien@netvula.com
 */
class ConsolaSegundoFactor extends Command
{
    protected $signature = 'consola:2fa {accion=estado : estado, quitar o codigos} {--email= : De quién}';

    protected $description = 'Ver, quitar o renovar el authenticator de un usuario de la consola';

    public function handle(): int
    {
        return match ($this->argument('accion')) {
            'quitar'  => $this->quitar(),
            'codigos' => $this->codigos(),
            default   => $this->estado(),
        };
    }

    private function estado(): int
    {
        $usuarios = PlataformaUsuario::when($this->option('email'), fn ($q, $e) => $q->where('email', $e))->get();

        if ($usuarios->isEmpty()) {
            $this->warn('No hay usuarios de consola con ese correo.');

            return self::FAILURE;
        }

        $this->table(
            ['Correo', 'Activo', 'Authenticator', 'Desde', 'Códigos que quedan'],
            $usuarios->map(fn ($u) => [
                $u->email,
                $u->activo ? 'sí' : 'no',
                $u->tieneSegundoFactor() ? 'sí' : 'no',
                $u->totp_activo_en?->format('Y-m-d H:i') ?? '—',
                $u->tieneSegundoFactor() ? SegundoFactor::recuperacionesQueQuedan($u) : '—',
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function quitar(): int
    {
        $usuario = $this->usuario();

        if (!$usuario) {
            return self::FAILURE;
        }

        if (!$usuario->tieneSegundoFactor()) {
            $this->info("{$usuario->email} no tiene el authenticator activo.");

            return self::SUCCESS;
        }

        if (!$this->confirm("¿Quitarle el authenticator a {$usuario->email}? Va a poder entrar sólo con la contraseña.")) {
            return self::SUCCESS;
        }

        SegundoFactor::quitar($usuario);
        $this->info('Listo. Que lo vuelva a activar en cuanto recupere el teléfono.');

        return self::SUCCESS;
    }

    /**
     * Códigos de recuperación nuevos, sin tocar el authenticator.
     *
     * Para cuando se gastaron todos pero el teléfono sigue funcionando: los
     * viejos dejan de servir en el momento.
     */
    private function codigos(): int
    {
        $usuario = $this->usuario();

        if (!$usuario) {
            return self::FAILURE;
        }

        if (!$usuario->tieneSegundoFactor()) {
            $this->warn("{$usuario->email} no tiene el authenticator activo: no hay códigos que renovar.");

            return self::FAILURE;
        }

        $nuevos = Totp::codigosDeRecuperacion();
        $usuario->forceFill(['totp_recuperacion' => $nuevos['guardados']])->save();

        $this->warn('Los anteriores dejaron de servir. Estos se muestran una sola vez:');
        $this->newLine();

        foreach ($nuevos['claros'] as $c) {
            $this->line('   ' . $c);
        }

        $this->newLine();

        return self::SUCCESS;
    }

    private function usuario(): ?PlataformaUsuario
    {
        $email = $this->option('email');

        if (!$email) {
            $this->error('Falta --email=');

            return null;
        }

        $usuario = PlataformaUsuario::whereRaw('LOWER(email) = ?', [strtolower(trim($email))])->first();

        if (!$usuario) {
            $this->error("No hay ningún usuario de consola con el correo {$email}.");

            return null;
        }

        return $usuario;
    }
}
