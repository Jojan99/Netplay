<?php

namespace App\Console\Commands;

use App\Models\PlataformaUsuario;
use App\Services\Plataforma\AccesoConsola;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Crea o actualiza un usuario de la consola de Netvula.
 *
 * Es la ÚNICA forma de dar acceso a la consola: no hay pantalla de alta, no
 * hay endpoint y la migración no siembra ninguno con clave conocida. La clave
 * se pide por consola (no se ve al tipearla) y no se puede pasar por
 * argumento, para que no quede en el historial del shell.
 *
 *   php artisan consola:usuario
 *   php artisan consola:usuario --email=dueño@netvula.com --nombre="Nombre"
 *   php artisan consola:usuario --email=... --desactivar
 *   php artisan consola:usuario --listar
 */
class ConsolaUsuario extends Command
{
    protected $signature = 'consola:usuario
        {--email= : El correo con el que entra}
        {--nombre= : Cómo se llama}
        {--desactivar : Le quita el acceso en vez de crearlo}
        {--activar : Se lo devuelve}
        {--listar : Muestra quién tiene acceso hoy}';

    protected $description = 'Crea, actualiza o desactiva un usuario de la consola de Netvula';

    /** Lo mínimo para que una clave no sea un chiste. */
    private const LARGO_MINIMO = 10;

    public function handle(): int
    {
        if (!Schema::hasTable('plataforma_usuarios')) {
            $this->error('Falta correr la migración de la consola:');
            $this->line('  php artisan migrate --force --path=database/migrations/2026_09_18_000001_consola_netvula.php');

            return self::FAILURE;
        }

        if ($this->option('listar')) {
            return $this->listar();
        }

        $email = strtolower(trim((string) ($this->option('email') ?: $this->ask('Correo'))));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Ese correo no es válido.');

            return self::FAILURE;
        }

        $usuario = PlataformaUsuario::whereRaw('LOWER(email) = ?', [$email])->first();

        if ($this->option('desactivar') || $this->option('activar')) {
            if (!$usuario) {
                $this->error('No hay ningún usuario de la consola con ese correo.');

                return self::FAILURE;
            }

            $activo = (bool) $this->option('activar');
            $usuario->forceFill(['activo' => $activo])->save();

            // Al quitarle el acceso se le cortan las sesiones abiertas: si no,
            // seguiría adentro hasta que venciera el token.
            if (!$activo) {
                AccesoConsola::cerrarTodas((int) $usuario->id);
            }

            $this->info($activo ? "Listo: {$email} vuelve a entrar." : "Listo: {$email} ya no entra, y sus sesiones quedaron cerradas.");

            return self::SUCCESS;
        }

        $nombre = trim((string) ($this->option('nombre') ?: $this->ask('Nombre', $usuario->nombre ?? '')));

        if ($nombre === '') {
            $this->error('Poné un nombre.');

            return self::FAILURE;
        }

        $clave = (string) $this->secret('Contraseña (mínimo ' . self::LARGO_MINIMO . ' caracteres)');

        if (strlen($clave) < self::LARGO_MINIMO) {
            $this->error('Muy corta: al menos ' . self::LARGO_MINIMO . ' caracteres.');

            return self::FAILURE;
        }

        if ($clave !== (string) $this->secret('Repetila')) {
            $this->error('Las dos contraseñas no coinciden.');

            return self::FAILURE;
        }

        if ($usuario) {
            $usuario->forceFill(['nombre' => $nombre, 'password' => Hash::make($clave), 'activo' => true])->save();
            AccesoConsola::cerrarTodas((int) $usuario->id);
            $this->info("Actualizado: {$email}. Las sesiones abiertas se cerraron.");
        } else {
            PlataformaUsuario::create([
                'nombre'   => $nombre,
                'email'    => $email,
                'password' => Hash::make($clave),
                'activo'   => true,
            ]);
            $this->info("Creado: {$email}.");
        }

        $host = AccesoConsola::host();
        $this->line('Entrá en ' . ($host !== '' ? "https://{$host}" : 'la dirección de la consola (falta configurar CONSOLA_HOST)'));

        return self::SUCCESS;
    }

    private function listar(): int
    {
        $filas = PlataformaUsuario::orderBy('email')->get(['id', 'nombre', 'email', 'activo', 'ultimo_ingreso']);

        if ($filas->isEmpty()) {
            $this->warn('Todavía no hay ningún usuario de la consola. Creá el primero con: php artisan consola:usuario');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'Nombre', 'Correo', 'Activo', 'Último ingreso'],
            $filas->map(fn ($u) => [
                $u->id, $u->nombre, $u->email,
                $u->activo ? 'sí' : 'no',
                $u->ultimo_ingreso?->format('Y-m-d H:i') ?? 'nunca',
            ])->all()
        );

        return self::SUCCESS;
    }
}
