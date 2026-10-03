<?php

namespace App\Support;

use App\Models\FacturaElectronicaConfig;
use Illuminate\Support\Facades\DB;

/**
 * Los datos de identificación y ubicación del cliente que piden la DIAN y Alegra.
 *
 * Un solo lugar para la lista de tipos de documento y para los valores por defecto de la
 * empresa (la ciudad donde atiende), que usan el alta del cliente, su ficha y el envío a Alegra.
 */
class DatosDelCliente
{
    /** Tipos de documento que se pueden elegir. DIE es como Alegra llama al documento extranjero. */
    public const TIPOS_DE_DOCUMENTO = [
        'CC'  => 'Cédula de ciudadanía',
        'TI'  => 'Tarjeta de identidad',
        'CE'  => 'Cédula de extranjería',
        'PPT' => 'Permiso por protección temporal',
        'PEP' => 'Permiso especial de permanencia',
        'PP'  => 'Pasaporte',
        'DIE' => 'Documento de identificación extranjero',
        'NIT' => 'NIT',
    ];

    /** Las reglas de validación de estos campos, para el alta y para la ficha. */
    public static function reglas(): array
    {
        return [
            'tipo_documento'   => 'nullable|in:' . implode(',', array_keys(self::TIPOS_DE_DOCUMENTO)),
            'dv'               => 'nullable|digits:1',
            'estrato'          => 'nullable|integer|between:1,6',
            'barrio'           => 'nullable|string|max:120',
            'ciudad'           => 'nullable|string|max:80',
            'departamento'     => 'nullable|string|max:80',
            'pais'             => 'nullable|string|max:60',
            'municipio'        => 'nullable|digits:5',
            'prefijo_telefono' => 'nullable|digits_between:1,4',
        ];
    }

    /**
     * Lo que se propone al dar de alta un cliente: la ciudad donde atiende la empresa.
     *
     * Sale de los ajustes de factura electrónica; si no están, de lo que más se repite entre
     * sus clientes. Un ISP de barrio tiene a casi todos en el mismo municipio.
     *
     * @return array{ciudad:?string, departamento:?string, municipio:?string, pais:string, prefijo_telefono:string}
     */
    public static function porDefecto(int $companyId): array
    {
        $config = FacturaElectronicaConfig::where('company_id', $companyId)->first();

        $comun = fn (string $columna) => DB::table('user_data')->where('company_id', $companyId)->whereNotNull($columna)->where($columna, '<>', '')
            ->selectRaw("{$columna} v, COUNT(*) n")->groupBy('v')->orderByDesc('n')->value('v');

        // Último recurso: la ciudad con que están sus contactos en Alegra, si la empresa lo usa.
        $deAlegra = DB::table('alegra_contactos')->where('company_id', $companyId)->whereNotNull('ciudad')->whereNotNull('departamento')
            ->selectRaw('ciudad, departamento, COUNT(*) n')->groupBy('ciudad', 'departamento')->orderByDesc('n')->first();

        return [
            'ciudad'           => ($config?->ajuste('ciudad') ?: $comun('ciudad')) ?: ($deAlegra->ciudad ?? null),
            'departamento'     => ($config?->ajuste('departamento') ?: $comun('departamento')) ?: ($deAlegra->departamento ?? null),
            'municipio'        => ($config?->ajuste('municipio') ?: $comun('fiscal_municipio')) ?: null,
            'pais'             => $comun('pais') ?: 'Colombia',
            'prefijo_telefono' => $comun('prefijo_telefono') ?: '57',
        ];
    }

    /**
     * Las columnas de user_data que corresponden a lo que llegó en la petición.
     * Sólo las que vinieron: lo que no se mandó no se toca.
     *
     * @param  array<string,mixed> $d
     * @return array<string,mixed>
     */
    public static function columnas(array $d): array
    {
        $mapa = [
            'tipo_documento' => 'fiscal_tipo_documento', 'dv' => 'fiscal_dv', 'tipo_persona' => 'fiscal_tipo_persona', 'municipio' => 'fiscal_municipio',
            'estrato' => 'estrato', 'barrio' => 'barrio', 'ciudad' => 'ciudad', 'departamento' => 'departamento', 'pais' => 'pais', 'prefijo_telefono' => 'prefijo_telefono',
        ];
        $c = [];

        foreach ($mapa as $campo => $columna) {
            if (array_key_exists($campo, $d)) {
                $v = is_string($d[$campo]) ? trim($d[$campo]) : $d[$campo];
                $c[$columna] = $v === '' ? null : $v;
            }
        }

        // Con NIT es una empresa; con cualquier otro documento, una persona.
        if (isset($c['fiscal_tipo_documento']) && !isset($c['fiscal_tipo_persona'])) {
            $c['fiscal_tipo_persona'] = $c['fiscal_tipo_documento'] === 'NIT' ? 'juridica' : 'natural';
        }

        return $c;
    }
}
