<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;

/**
 * Las páginas legales que se ven sin entrar a la plataforma.
 *
 * Meta las exige para aprobar una app: una con la política de privacidad y
 * otra que explique cómo pedir que borren los datos. También sirven de
 * respaldo frente a la Ley 1581 de 2012, que obliga a tener a la vista la
 * política de tratamiento de datos.
 *
 * Hay dos juegos de textos, porque hay dos responsables distintos:
 *
 *  - En el subdominio de una empresa (netplay.netvula.com) habla el ISP: es él
 *    quien responde por los datos de sus suscriptores. La empresa se saca del
 *    dominio, así cada una muestra su nombre, su NIT y sus datos de contacto
 *    sin que haya que escribir una página por cliente.
 *
 *  - En la raíz (netvula.com) habla Netvula como producto: responsable de los
 *    datos de las empresas que se registran y encargada de los datos que esas
 *    empresas cargan. Esos textos viven en legal/plataforma.
 */
class PaginasLegalesController extends Controller
{
    public function privacidad(Request $request)
    {
        return $this->pagina($request, 'privacidad');
    }

    public function eliminacionDeDatos(Request $request)
    {
        return $this->pagina($request, 'eliminacion');
    }

    public function terminos(Request $request)
    {
        return $this->pagina($request, 'terminos');
    }

    private function pagina(Request $request, string $cual)
    {
        $empresa = $this->empresaDelDominio($request);

        return $empresa
            ? view("legal.$cual", $this->datosDeEmpresa($empresa))
            : view("legal.plataforma.$cual", $this->datosDePlataforma());
    }

    /** Los datos de la empresa dueña del dominio por el que entraron. */
    private function datosDeEmpresa(Company $empresa): array
    {
        $nombre = $empresa->invoice_business_name ?: ($empresa->name ?: 'Netvula');
        $tel    = $empresa->invoice_phone ?: $empresa->phone;

        return [
            'empresa'     => $nombre,
            'nit'         => $empresa->invoice_nit ?: $empresa->nit,
            'correo'      => $empresa->email ?: $empresa->mailjet_from_email,
            'telefono'    => $tel,
            'whatsapp'    => $tel ? preg_replace('/\D/', '', $tel) : null,
            'direccion'   => trim(implode(', ', array_filter([
                $empresa->invoice_address ?: $empresa->address,
                $empresa->invoice_city,
                $empresa->invoice_country ?: 'Colombia',
            ]))),
            'plataforma'  => (string) config('plataforma.nombre', 'Netvula'),
            'actualizado' => (string) config('plataforma.legal.actualizado'),
        ];
    }

    /** Los datos de Netvula como responsable, tal como estén en la configuración. */
    private function datosDePlataforma(): array
    {
        $legal = (array) config('plataforma.legal', []);
        $tel   = $legal['telefono'] ?? null;

        return [
            'empresa'     => $legal['razon_social'] ?: 'Netvula',
            'marca'       => (string) config('plataforma.nombre', 'Netvula'),
            'dominio'     => (string) config('plataforma.dominio', 'netvula.com'),
            'nit'         => $legal['nit'] ?? null,
            'correo'      => $legal['correo'] ?? null,
            'telefono'    => $tel,
            'whatsapp'    => $tel ? preg_replace('/\D/', '', $tel) : null,
            'direccion'   => trim(implode(', ', array_filter([
                $legal['direccion'] ?? null,
                $legal['ciudad'] ?? null,
                'Colombia',
            ]))),
            'ciudad'      => $legal['ciudad'] ?? null,
            'version'     => (string) ($legal['version'] ?? ''),
            'pruebaDias'  => (int) config('plataforma.prueba_dias', 0),
            'actualizado' => (string) ($legal['actualizado'] ?? ''),
        ];
    }

    private function empresaDelDominio(Request $request): ?Company
    {
        $host = $request->getHost();
        $sub  = explode('.', $host)[0] ?? '';

        // En el dominio raíz (netvula.com) no hay empresa: se muestran los
        // textos de la plataforma.
        if (!$sub || in_array($sub, ['www', 'netvula', 'localhost'], true)) {
            return null;
        }

        return Company::where('subdomain', $sub)->orWhere('slug', $sub)->first();
    }
}
