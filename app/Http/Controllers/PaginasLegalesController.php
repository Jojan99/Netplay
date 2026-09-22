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
 * La empresa se saca del dominio por el que entraron (netplay.netvula.com →
 * «netplay»), así cada ISP de la plataforma muestra su propio nombre, su NIT
 * y sus datos de contacto sin que haya que escribir una página por cliente.
 */
class PaginasLegalesController extends Controller
{
    public function privacidad(Request $request)
    {
        return view('legal.privacidad', $this->datos($request));
    }

    public function eliminacionDeDatos(Request $request)
    {
        return view('legal.eliminacion', $this->datos($request));
    }

    /** Los datos de la empresa dueña del dominio por el que entraron. */
    private function datos(Request $request): array
    {
        $empresa = $this->empresaDelDominio($request);

        $nombre = $empresa?->invoice_business_name ?: ($empresa?->name ?: 'Netvula');
        $tel    = $empresa?->invoice_phone ?: $empresa?->phone;

        return [
            'empresa'     => $nombre,
            'nit'         => $empresa?->invoice_nit ?: $empresa?->nit,
            'correo'      => $empresa?->email ?: $empresa?->mailjet_from_email,
            'telefono'    => $tel,
            'whatsapp'    => $tel ? preg_replace('/\D/', '', $tel) : null,
            'direccion'   => trim(implode(', ', array_filter([
                $empresa?->invoice_address ?: $empresa?->address,
                $empresa?->invoice_city,
                $empresa?->invoice_country ?: 'Colombia',
            ]))),
            'actualizado' => 'septiembre de 2026',
        ];
    }

    private function empresaDelDominio(Request $request): ?Company
    {
        $host = $request->getHost();
        $sub  = explode('.', $host)[0] ?? '';

        // En el dominio raíz (netvula.com) no hay empresa: se muestra la
        // política de la plataforma, sin nombre de ISP.
        if (!$sub || in_array($sub, ['www', 'netvula', 'localhost'], true)) {
            return null;
        }

        return Company::where('subdomain', $sub)->orWhere('slug', $sub)->first();
    }
}
