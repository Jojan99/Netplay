<?php

namespace App\Console\Commands;

use App\Support\DatosDelCliente;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Completa en los clientes que ya existen los datos que piden la DIAN y Alegra.
 *
 * Sin --aplicar sólo muestra lo que haría. Nunca pisa un dato que el cliente ya tenga.
 *
 *  - Ciudad, departamento, municipio, país e indicativo: los de la empresa (o los que se pasen).
 *  - Tipo de documento: el que tiene su contacto en Alegra, que es donde alguien ya lo eligió
 *    mirando el documento. Para quien no está en Alegra, cédula sólo si el número tiene diez
 *    dígitos (las cédulas colombianas nuevas); los demás quedan en la lista «por revisar»,
 *    porque un número de siete u ocho dígitos puede ser una cédula vieja o un documento
 *    venezolano, y eso no se puede saber sin preguntar.
 */
class ClientesCompletarDatos extends Command
{
    protected $signature = 'clientes:completar-datos {empresa : Id de la empresa}
        {--ciudad= : Ciudad para quien no la tiene}
        {--departamento= : Departamento para quien no lo tiene}
        {--municipio= : Código DANE del municipio (5 dígitos)}
        {--aplicar : Escribir los cambios (sin esto, sólo simula)}';

    protected $description = 'Completa ciudad, departamento y tipo de documento de los clientes existentes';

    public function handle(): int
    {
        $empresa = (int) $this->argument('empresa');
        $aplicar = (bool) $this->option('aplicar');
        $base    = DatosDelCliente::porDefecto($empresa);

        $ubicacion = [
            'ciudad'           => $this->option('ciudad') ?: $base['ciudad'],
            'departamento'     => $this->option('departamento') ?: $base['departamento'],
            'fiscal_municipio' => $this->option('municipio') ?: $base['municipio'],
            'pais'             => $base['pais'],
            'prefijo_telefono' => $base['prefijo_telefono'],
        ];

        if (!$ubicacion['ciudad'] || !$ubicacion['departamento']) {
            $this->error('Falta la ciudad o el departamento: páselos con --ciudad y --departamento.');

            return self::FAILURE;
        }
        if ($ubicacion['fiscal_municipio'] && !preg_match('/^\d{5}$/', (string) $ubicacion['fiscal_municipio'])) {
            $this->error('El código del municipio debe tener cinco dígitos.');

            return self::FAILURE;
        }

        $enAlegra = DB::table('alegra_contactos')->where('company_id', $empresa)->whereNotNull('user_id')->whereNotNull('tipo_documento')->pluck('tipo_documento', 'user_id');

        $clientes = DB::table('user_data as u')->join('users as us', 'us.id', '=', 'u.user_id')
            ->where('us.company_id', $empresa)
            ->whereNotIn('us.profile_id', fn ($q) => $q->select('id')->from('profiles')->where('company_id', $empresa)->whereIn('name', ['ADMIN', 'TECNICO', 'CONTADOR']))
            ->get(['u.id', 'u.user_id', 'u.names', 'u.lastname', 'u.dni', 'u.phone', 'u.active', 'u.fiscal_tipo_documento', 'u.ciudad', 'u.departamento', 'u.fiscal_municipio', 'u.pais', 'u.prefijo_telefono']);

        $cuenta = ['ubicacion' => 0, 'tipo_de_alegra' => 0, 'tipo_por_numero' => 0, 'por_revisar' => 0];
        $porTipo = [];
        $revisar = [];
        $respaldo = [];

        foreach ($clientes as $c) {
            $cambios = [];

            foreach ($ubicacion as $columna => $valor) {
                if ($valor && ($c->{$columna} === null || $c->{$columna} === '')) {
                    $cambios[$columna] = $valor;
                }
            }

            // El indicativo sale del propio teléfono cuando lo trae (12 dígitos que empiezan por 57 o 58).
            $digitos = preg_replace('/\D/', '', (string) $c->phone);
            if (isset($cambios['prefijo_telefono']) && strlen($digitos) === 12 && in_array(substr($digitos, 0, 2), ['57', '58'], true)) {
                $cambios['prefijo_telefono'] = substr($digitos, 0, 2);
            }

            if (array_diff_key($cambios, ['fiscal_tipo_documento' => 1])) {
                $cuenta['ubicacion']++;
            }

            if (!$c->fiscal_tipo_documento) {
                $numero = preg_replace('/\D/', '', (string) $c->dni);
                $tipo   = null;

                if (isset($enAlegra[$c->user_id]) && isset(DatosDelCliente::TIPOS_DE_DOCUMENTO[$enAlegra[$c->user_id]])) {
                    $tipo = $enAlegra[$c->user_id];
                    $cuenta['tipo_de_alegra']++;
                } elseif (strlen($numero) === 10) {
                    $tipo = 'CC';
                    $cuenta['tipo_por_numero']++;
                } elseif ((int) $c->active === 1) {
                    $cuenta['por_revisar']++;
                    $revisar[] = [trim($c->names . ' ' . $c->lastname), $c->dni, strlen($numero) . ' dígitos'];
                }

                if ($tipo) {
                    $cambios['fiscal_tipo_documento'] = $tipo;
                    $cambios['fiscal_tipo_persona']   = $tipo === 'NIT' ? 'juridica' : 'natural';
                    $porTipo[$tipo] = ($porTipo[$tipo] ?? 0) + 1;
                }
            }

            if (!$cambios) {
                continue;
            }

            $respaldo[] = ['user_data_id' => $c->id, 'antes' => array_intersect_key((array) $c, $cambios + ['fiscal_tipo_documento' => 1]), 'despues' => $cambios];

            if ($aplicar) {
                DB::table('user_data')->where('id', $c->id)->update($cambios + ['updated_at' => now()]);
            }
        }

        $this->line(($aplicar ? 'APLICADO' : 'SIMULACIÓN') . ' · empresa ' . $empresa . ' · ' . $clientes->count() . ' clientes');
        $this->line('Ubicación que se pone a quien no la tiene: ' . $ubicacion['ciudad'] . ', ' . $ubicacion['departamento'] . ' · municipio ' . ($ubicacion['fiscal_municipio'] ?: '(sin código)') . ' · ' . $ubicacion['pais'] . ' +' . $ubicacion['prefijo_telefono']);
        $this->line('Clientes a los que se les completa la ubicación: ' . $cuenta['ubicacion']);
        $this->line('Tipo de documento tomado de su contacto en Alegra: ' . $cuenta['tipo_de_alegra']);
        $this->line('Tipo «cédula» por tener diez dígitos (no están en Alegra): ' . $cuenta['tipo_por_numero']);
        $this->line('Por tipo: ' . json_encode($porTipo));
        $this->line('Clientes vigentes que quedan POR REVISAR a mano: ' . $cuenta['por_revisar']);

        $carpeta = storage_path('app/datos-de-clientes');
        @mkdir($carpeta, 0775, true);
        $marca = now()->format('Ymd-His');

        if ($revisar) {
            $archivo = "{$carpeta}/empresa-{$empresa}-por-revisar-{$marca}.csv";
            file_put_contents($archivo, "Cliente;Documento;Largo\n" . implode("\n", array_map(fn ($f) => implode(';', $f), $revisar)) . "\n");
            $this->line('Lista por revisar: ' . $archivo);
        }
        if ($aplicar && $respaldo) {
            $archivo = "{$carpeta}/empresa-{$empresa}-respaldo-{$marca}.json";
            file_put_contents($archivo, json_encode($respaldo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->line('Copia de lo que había antes: ' . $archivo);
        }
        if (!$aplicar) {
            $this->line('Nada se escribió. Para aplicarlo, agregue --aplicar al mismo comando.');
        }

        return self::SUCCESS;
    }
}
