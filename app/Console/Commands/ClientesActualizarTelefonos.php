<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Actualiza el teléfono de los clientes a partir de una lista «nombre, cédula, teléfono».
 *
 * Sin --aplicar sólo muestra lo que haría. El cliente se busca por la cédula y el nombre de
 * la lista tiene que parecerse al de la ficha: una cédula mal digitada no puede dejar el
 * teléfono de una persona en la ficha de otra. Lo que no cuadra queda en «por revisar».
 */
class ClientesActualizarTelefonos extends Command
{
    protected $signature = 'clientes:actualizar-telefonos {empresa : Id de la empresa}
        {archivo : Lista dentro de storage/app (nombre, cédula y, en la última columna, el teléfono; separados por tabulación)}
        {--aplicar : Escribir los cambios (sin esto, sólo simula)}';

    protected $description = 'Actualiza el teléfono de los clientes de una lista con nombre, cédula y teléfono';

    public function handle(): int
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
            ->get(['u.id', 'u.names', 'u.lastname', 'u.dni', 'u.phone', 'u.prefijo_telefono', 'u.active']);

        $porCedula = [];
        $porNombre = [];
        $porTelefono = [];
        foreach ($fichas as $f) {
            $porCedula[preg_replace('/\D/', '', (string) $f->dni)][] = $f;
            $porNombre[$this->limpio($f->names . ' ' . $f->lastname)][] = $f;
            $porTelefono[substr(preg_replace('/\D/', '', (string) $f->phone), -10)][] = $f->id;
        }

        $cambios = [];
        $iguales = [];
        $revisar = [];
        $vistos  = [];

        foreach (file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
            $partes = array_map('trim', explode("\t", $linea));
            if (count($partes) < 3) {
                $revisar[] = [$linea, '', '', 'Renglón incompleto'];
                continue;
            }
            // El teléfono es la última columna: la lista puede traer otras en el medio (el estado).
            $original = trim((string) end($partes));
            [$nombre, $cedula, $telefono] = [$partes[0], preg_replace('/\D/', '', $partes[1]), preg_replace('/\D/', '', $original)];
            $partes[2] = $original;

            // Número de otro país escrito con su indicativo (+58…, +507…): se guarda tal cual.
            $extranjero = null;
            if (str_starts_with($original, '+') && !str_starts_with($telefono, '57') && strlen($telefono) >= 8 && strlen($telefono) <= 15) {
                foreach (['1', '7', '20', '27', '30', '31', '32', '33', '34', '39', '44', '49', '51', '52', '53', '54', '55', '56', '58', '501', '502', '503', '504', '505', '506', '507', '509', '591', '593', '595', '598', '599'] as $ind) {
                    if (str_starts_with($telefono, $ind)) {
                        $extranjero = strlen($ind) > strlen((string) $extranjero) ? $ind : $extranjero;
                    }
                }
            }

            if (isset($vistos[$cedula . '|' . $telefono])) {
                continue;
            }
            $vistos[$cedula . '|' . $telefono] = true;

            if (strlen($telefono) === 12 && str_starts_with($telefono, '57')) {
                $telefono = substr($telefono, 2);
            }
            if (!$extranjero && (strlen($telefono) !== 10 || $telefono[0] !== '3')) {
                $revisar[] = [$nombre, $cedula, $partes[2], 'El teléfono no es un celular de diez dígitos'];
                continue;
            }

            $nota = '';
            $suyas = array_values(array_filter($porCedula[$cedula] ?? [], fn ($f) => $this->seParecen($nombre, $f->names . ' ' . $f->lastname)));

            if (!$suyas && isset($porCedula[$cedula])) {
                $f = $porCedula[$cedula][0];
                $revisar[] = [$nombre, $cedula, $telefono, 'La cédula es de «' . trim($f->names . ' ' . $f->lastname) . '»'];
                continue;
            }
            if (!$suyas) {
                // Cédula mal digitada en la lista: sólo vale si el nombre completo es idéntico.
                $suyas = $porNombre[$this->limpio($nombre)] ?? [];
                if (!$suyas || count(array_unique(array_map(fn ($f) => $f->dni, $suyas))) > 1) {
                    $revisar[] = [$nombre, $cedula, $telefono, 'No hay cliente con esa cédula'];
                    continue;
                }
                $nota = 'por nombre; la cédula de la ficha es ' . $suyas[0]->dni;
            }

            $nuevo = $extranjero ? '+' . $telefono : '+57' . $telefono;
            $prefijo = $extranjero ?: '57';
            foreach ($suyas as $f) {
                $otros = array_diff($porTelefono[$telefono] ?? [], array_map(fn ($s) => $s->id, $suyas));
                $aviso = trim($nota . ($otros ? ' · el número ya está en la ficha ' . implode(', ', $otros) : ''), ' ·');

                if ($f->phone === $nuevo) {
                    $iguales[] = [trim($f->names . ' ' . $f->lastname), $f->dni, $nuevo];
                    continue;
                }
                $cambios[] = ['id' => $f->id, 'cliente' => trim($f->names . ' ' . $f->lastname), 'dni' => $f->dni,
                    'activo' => (int) $f->active === 1 ? 'sí' : 'no', 'antes' => $f->phone, 'prefijo_antes' => $f->prefijo_telefono, 'despues' => $nuevo, 'prefijo' => $prefijo, 'nota' => $aviso];
            }
        }

        $this->line(($aplicar ? 'APLICADO' : 'SIMULACIÓN') . ' · empresa ' . $empresa);
        $this->table(['Ficha', 'Cliente', 'Cédula', 'Activo', 'Teléfono actual', 'Teléfono nuevo', 'Nota'],
            array_map(fn ($c) => [$c['id'], Str::limit($c['cliente'], 34), $c['dni'], $c['activo'], $c['antes'], $c['despues'], $c['nota']], $cambios));
        $this->line('Cambian: ' . count($cambios) . ' · ya tenían ese número: ' . count($iguales) . ' · por revisar: ' . count($revisar));

        if ($revisar) {
            $this->warn('Por revisar (no se tocan):');
            $this->table(['Nombre en la lista', 'Cédula', 'Teléfono', 'Motivo'], $revisar);
        }

        if (!$aplicar) {
            $this->line('Nada se escribió. Para escribir, repita el comando con --aplicar.');

            return self::SUCCESS;
        }
        if (!$cambios) {
            return self::SUCCESS;
        }

        $respaldo = 'datos-de-clientes/telefonos-antes-' . now()->format('Ymd-His') . '.json';
        file_put_contents(storage_path('app/' . $respaldo), json_encode($cambios, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        DB::transaction(function () use ($cambios) {
            foreach ($cambios as $c) {
                DB::table('user_data')->where('id', $c['id'])->update(['phone' => $c['despues'], 'prefijo_telefono' => $c['prefijo'] ?? '57', 'updated_at' => now()]);
            }
        });

        $this->info('Respaldo de los números anteriores: storage/app/' . $respaldo);

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
