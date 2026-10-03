<?php

namespace App\Console\Commands;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use App\Services\Red\ClienteEnElRouter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Suspende en el MikroTik e inactiva en la plataforma a los clientes de una lista «nombre, cédula».
 *
 * Hace lo mismo que «Eliminar cliente» del panel con la opción de suspender en el router:
 * corta el servicio (secret deshabilitado / ARP) y deja la ficha inactiva (active = 0,
 * status_internet_id = 2), con su anotación en el historial.
 *
 * Sin --aplicar sólo muestra lo que haría. El cliente se busca por cédula y el nombre de la
 * lista tiene que parecerse al de la ficha; lo que no cuadra queda en «por revisar».
 */
class ClientesInactivarLista extends Command
{
    protected $signature = 'clientes:inactivar-lista {empresa : Id de la empresa}
        {archivo : Lista dentro de storage/app (nombre y cédula separados por tabulación)}
        {--aplicar : Suspender e inactivar (sin esto, sólo simula)}';

    protected $description = 'Suspende en el router e inactiva en la plataforma a los clientes de una lista';

    public function handle(ConectionRouterManagerInterface $conexion): int
    {
        $empresa = (int) $this->argument('empresa');
        $aplicar = (bool) $this->option('aplicar');
        $ruta    = storage_path('app/' . ltrim((string) $this->argument('archivo'), '/'));

        if (!is_file($ruta)) {
            $this->error('No existe el archivo ' . $ruta);

            return self::FAILURE;
        }

        $fichas = DB::table('user_data as u')->join('users as us', 'us.id', '=', 'u.user_id')
            ->where('us.company_id', $empresa)
            ->get(['u.id', 'u.user_id', 'u.names', 'u.lastname', 'u.dni', 'u.active', 'u.status_internet_id']);

        $porCedula = [];
        $porNombre = [];
        foreach ($fichas as $f) {
            $porCedula[preg_replace('/\D/', '', (string) $f->dni)][] = $f;
            $porNombre[$this->limpio($f->names . ' ' . $f->lastname)][] = $f;
        }

        $deuda = DB::table('det_facturations as df')->join('cab_facturations as cb', 'cb.id', '=', 'df.cab_id')
            ->where('cb.company_id', $empresa)->where('df.paid', '<>', 1)->whereNull('df.anulada_en')
            ->groupBy('cb.user_id')->selectRaw('cb.user_id, COUNT(*) n')->pluck('n', 'user_id');

        $elegidos = [];
        $yaInactivos = [];
        $revisar = [];

        foreach (file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
            $partes = array_map('trim', explode("\t", $linea));
            if (count($partes) < 2) {
                $revisar[] = [$linea, '', 'Renglón incompleto'];
                continue;
            }
            [$nombre, $cedula] = [$partes[0], preg_replace('/\D/', '', $partes[1])];

            $suyas = array_values(array_filter($porCedula[$cedula] ?? [], fn ($f) => $this->seParecen($nombre, $f->names . ' ' . $f->lastname)));
            $nota = '';

            if (!$suyas && isset($porCedula[$cedula])) {
                $f = $porCedula[$cedula][0];
                $revisar[] = [$nombre, $cedula, 'La cédula es de «' . trim($f->names . ' ' . $f->lastname) . '»'];
                continue;
            }
            if (!$suyas) {
                $suyas = $porNombre[$this->limpio($nombre)] ?? [];
                if (count($suyas) !== 1) {
                    $revisar[] = [$nombre, $cedula, $suyas ? 'Hay varios clientes con ese nombre' : 'No hay cliente con esa cédula'];
                    continue;
                }
                $nota = 'por nombre; cédula en la ficha ' . $suyas[0]->dni;
            }

            foreach ($suyas as $f) {
                if (isset($elegidos[$f->user_id])) {
                    continue;
                }
                if ((int) $f->active === 0) {
                    $yaInactivos[] = [trim($f->names . ' ' . $f->lastname), $f->dni];
                    continue;
                }
                $elegidos[$f->user_id] = [
                    'user_id' => (int) $f->user_id, 'cliente' => trim($f->names . ' ' . $f->lastname), 'dni' => $f->dni,
                    'servicio' => (int) $f->status_internet_id === 2 ? 'suspendido' : 'activo',
                    'pendientes' => (int) ($deuda[$f->user_id] ?? 0), 'nota' => $nota,
                ];
            }
        }

        $this->line(($aplicar ? 'APLICANDO' : 'SIMULACIÓN') . ' · empresa ' . $empresa);
        $this->table(['Usuario', 'Cliente', 'Cédula', 'Servicio hoy', 'Facturas pendientes', 'Nota'],
            array_map(fn ($c) => [$c['user_id'], Str::limit($c['cliente'], 34), $c['dni'], $c['servicio'], $c['pendientes'], $c['nota']], $elegidos));
        $this->line('Se inactivan: ' . count($elegidos) . ' (con servicio activo: ' . count(array_filter($elegidos, fn ($c) => $c['servicio'] === 'activo')) . ') · ya estaban inactivos: ' . count($yaInactivos) . ' · por revisar: ' . count($revisar));

        if ($yaInactivos) {
            $this->line('Ya inactivos (no se tocan): ' . implode(', ', array_map(fn ($c) => $c[0], $yaInactivos)));
        }
        if ($revisar) {
            $this->warn('Por revisar (no se tocan):');
            $this->table(['Nombre en la lista', 'Cédula', 'Motivo'], $revisar);
        }

        if (!$aplicar) {
            $this->line('Nada se cambió. Para suspender e inactivar, repita el comando con --aplicar.');

            return self::SUCCESS;
        }

        $router = new ClienteEnElRouter($conexion, $empresa);
        $fallos = [];
        $hechos = 0;

        foreach ($elegidos as $c) {
            // Primero el router: si no se pudo cortar, la ficha no se marca y se informa.
            $r = $router->suspender($c['user_id']);
            if (!($r['ok'] ?? false)) {
                $fallos[] = [$c['cliente'], implode('; ', $r['errores'] ?? ['sin respuesta del MikroTik'])];
                continue;
            }

            DB::table('user_data')->where('company_id', $empresa)->where('user_id', $c['user_id'])
                ->update(['active' => 0, 'status' => 1, 'status_internet_id' => 2, 'updated_at' => now()]);
            DB::table('user_audit_logs')->insert([
                'user_id' => $c['user_id'], 'changed_by' => 0, 'company_id' => $empresa,
                'field_changed' => 'cliente', 'old_value' => 'activo', 'new_value' => 'eliminado',
                'description' => 'Inactivado por lista (' . basename($ruta) . '); en el MikroTik: ' . (implode(', ', $r['quitado'] ?? []) ?: 'no tenía nada configurado'),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $hechos++;
            $this->line('✓ ' . $c['cliente']);
        }

        $this->info("Suspendidos e inactivados: {$hechos} de " . count($elegidos));
        if ($fallos) {
            $this->warn('No se tocaron porque el MikroTik no respondió (vuelva a correr el comando para reintentarlos):');
            $this->table(['Cliente', 'Error'], $fallos);
        }

        return self::SUCCESS;
    }

    private function limpio(string $texto): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Z ]/', '', Str::upper(Str::ascii($texto)))));
    }

    /** Dos palabras del nombre en común (o todas, si el nombre de la lista es más corto). */
    private function seParecen(string $a, string $b): bool
    {
        $pa = array_unique(explode(' ', $this->limpio($a)));
        $pb = array_unique(explode(' ', $this->limpio($b)));
        $comunes = 0;
        foreach ($pa as $palabra) {
            foreach ($pb as $otra) {
                if ($palabra === $otra || (strlen($palabra) > 4 && levenshtein($palabra, $otra) <= 1)) {
                    $comunes++;
                    break;
                }
            }
        }

        return $comunes >= min(2, count($pa));
    }
}
