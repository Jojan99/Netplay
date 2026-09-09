<?php

namespace App\Support;

/**
 * Catálogo de plantillas que el sistema le crea a cada empresa.
 *
 * Por qué existe: una plantilla aprobada solo sirve dentro de la cuenta de
 * Meta donde fue aprobada. No se pueden "compartir" las de una empresa con
 * otra. Lo que sí se puede compartir es el texto, y dejar que el sistema las
 * cree en la cuenta de cada quien para que Meta se las apruebe.
 *
 * Redactarlas a mano es lo que rompe: el orden de las variables tiene que
 * coincidir exactamente con lo que el sistema manda en cada evento. Acá el
 * cuerpo y el orden se definen juntos, así no se pueden desalinear.
 *
 * Reglas de Meta que condicionan el texto:
 *  - Categoría UTILITY: son avisos sobre algo que el cliente ya tiene con la
 *    empresa (su factura, su pago, su servicio). MARKETING se aprueba peor y
 *    puede cobrarse distinto.
 *  - El cuerpo no puede empezar ni terminar con una variable, ni tener dos
 *    variables seguidas.
 *  - Las variables van numeradas y en orden: {{1}}, {{2}}, …
 */
class PlantillasSemilla
{
    /**
     * @return array<string, array{
     *   evento:string, nombre:string, categoria:string, idioma:string,
     *   descripcion:string, variables:array<int,string>, cuerpo:string,
     *   pie:?string, boton_url:?array{texto:string, url:string, ejemplo:string}
     * }>
     */
    public static function todas(): array
    {
        return [
            'envio_factura' => [
                'evento'      => 'envio_factura',
                'nombre'      => 'netplay_envio_factura',
                'categoria'   => 'UTILITY',
                'idioma'      => 'es',
                'descripcion' => 'Sale con la facturación del mes, cuando se genera la factura.',
                'variables'   => ['cliente', 'numero_factura', 'valor_numero', 'fecha_emision', 'fecha_vence', 'empresa'],
                'cuerpo'      => "Hola {{1}} 👋\n\nTu factura ya está disponible.\n\n🧾 Factura: {{2}}\n💰 Valor: \${{3}}\n📅 Emitida: {{4}}\n⏰ Vence: {{5}}\n\nGracias por confiar en {{6}}.",
                'pie'         => 'Si ya pagaste, ignorá este mensaje.',
                'boton_url'   => ['texto' => 'Ver mi factura', 'url' => '{{1}}', 'ejemplo' => 'https://netplay.com.co/api/factura/ejemplo'],
            ],

            'recordatorio_pago' => [
                'evento'      => 'recordatorio_pago',
                'nombre'      => 'netplay_recordatorio_pago',
                'categoria'   => 'UTILITY',
                'idioma'      => 'es',
                'descripcion' => 'Avisa antes del vencimiento, para que el cliente no entre en mora.',
                'variables'   => ['cliente', 'numero_factura', 'valor_numero', 'fecha_vence', 'empresa'],
                'cuerpo'      => "Hola {{1}} 👋\n\nTe recordamos que tu factura {{2}} por \${{3}} vence el {{4}}.\n\nPodés pagarla desde el botón de abajo.\n\n{{5}}",
                'pie'         => 'Si ya pagaste, ignorá este mensaje.',
                'boton_url'   => ['texto' => 'Pagar ahora', 'url' => '{{1}}', 'ejemplo' => 'https://netplay.com.co/api/pay/ejemplo'],
            ],

            'suspension_mora' => [
                'evento'      => 'suspension_mora',
                'nombre'      => 'netplay_suspension_mora',
                'categoria'   => 'UTILITY',
                'idioma'      => 'es',
                'descripcion' => 'Avisa que el servicio se suspenderá si no se paga.',
                'variables'   => ['cliente', 'numero_factura', 'valor_numero', 'fecha_vence', 'dias_mora', 'empresa'],
                'cuerpo'      => "Hola {{1}},\n\nTu factura {{2}} por \${{3}} venció el {{4}} y lleva {{5}} días sin pago.\n\nPara evitar la suspensión del servicio, regularizá el pago lo antes posible.\n\n{{6}}",
                'pie'         => 'Si ya pagaste, escribinos y lo verificamos.',
                'boton_url'   => ['texto' => 'Pagar ahora', 'url' => '{{1}}', 'ejemplo' => 'https://netplay.com.co/api/pay/ejemplo'],
            ],

            'servicio_reactivado' => [
                'evento'      => 'servicio_reactivado',
                'nombre'      => 'netplay_servicio_reactivado',
                'categoria'   => 'UTILITY',
                'idioma'      => 'es',
                'descripcion' => 'El cliente pagó y su servicio volvió a quedar activo.',
                'variables'   => ['cliente', 'valor_numero', 'empresa'],
                'cuerpo'      => "¡Listo {{1}}! ✅\n\nRecibimos tu pago de \${{2}} y tu servicio ya está activo de nuevo.\n\nGracias por estar al día con {{3}}.",
                'pie'         => null,
                'boton_url'   => null,
            ],

            'pago_aprobado' => [
                'evento'      => 'pago_aprobado',
                'nombre'      => 'netplay_pago_aprobado',
                'categoria'   => 'UTILITY',
                'idioma'      => 'es',
                'descripcion' => 'El pago se acreditó sobre las facturas del cliente.',
                'variables'   => ['cliente', 'valor_numero', 'numero_factura', 'medio_pago', 'empresa'],
                'cuerpo'      => "¡Gracias {{1}}! ✅\n\nTu pago de \${{2}} sobre la factura {{3}} quedó registrado.\n\n💳 Medio: {{4}}\n\nSaludos de {{5}}.",
                'pie'         => 'Este es tu comprobante de pago.',
                'boton_url'   => null,
            ],

            'pago_fallido' => [
                'evento'      => 'pago_fallido',
                'nombre'      => 'netplay_pago_fallido',
                'categoria'   => 'UTILITY',
                'idioma'      => 'es',
                'descripcion' => 'La pasarela rechazó el pago o la transacción fue anulada.',
                'variables'   => ['cliente', 'valor_numero', 'numero_factura', 'medio_pago', 'motivo', 'empresa'],
                'cuerpo'      => "Hola {{1}},\n\nTu pago de \${{2}} sobre la factura {{3}} no se pudo completar.\n\n💳 Medio: {{4}}\n📄 Motivo: {{5}}\n\nNo se te hizo ningún cobro. Podés intentarlo de nuevo cuando quieras.\n\n{{6}}",
                'pie'         => null,
                'boton_url'   => ['texto' => 'Reintentar el pago', 'url' => '{{1}}', 'ejemplo' => 'https://netplay.com.co/api/pay/ejemplo'],
            ],

            'pago_pendiente' => [
                'evento'      => 'pago_pendiente',
                'nombre'      => 'netplay_pago_pendiente',
                'categoria'   => 'UTILITY',
                'idioma'      => 'es',
                'descripcion' => 'El cobro se generó pero todavía no se acredita (efectivo, transferencia).',
                'variables'   => ['cliente', 'valor_numero', 'numero_factura', 'empresa'],
                'cuerpo'      => "Hola {{1}},\n\nRegistramos tu pago de \${{2}} sobre la factura {{3}}, pero todavía no se acredita.\n\nApenas se confirme te avisamos por acá.\n\n{{4}}",
                'pie'         => 'Puede tardar unos minutos.',
                'boton_url'   => null,
            ],

            // ── Hueco detectado en la auditoría ──────────────────────────
            // El envío del contrato para firmar salía como texto libre, así
            // que fuera de la ventana de 24 h no llegaba. Es un aviso que el
            // sistema inicia, no una respuesta al cliente.
            'contrato_firma' => [
                'evento'      => 'contrato_firma',
                'nombre'      => 'netplay_contrato_firma',
                'categoria'   => 'UTILITY',
                'idioma'      => 'es',
                'descripcion' => 'Le manda al cliente el enlace para firmar su contrato.',
                'variables'   => ['cliente', 'contrato', 'empresa'],
                'cuerpo'      => "Hola {{1}} 👋\n\nTe compartimos tu contrato *{{2}}* para que lo revises y lo firmes.\n\nAbrí el botón desde tu teléfono para completar la firma.\n\n{{3}}",
                'pie'         => 'El enlace es personal, no lo compartas.',
                'boton_url'   => ['texto' => 'Firmar contrato', 'url' => '{{1}}', 'ejemplo' => 'https://netplay.com.co/contrato/ejemplo'],
            ],
        ];
    }

    /** ¿Este nombre es de una plantilla del catálogo del sistema? */
    public static function esDelSistema(string $nombre): bool
    {
        $nombre = strtolower(trim($nombre));

        foreach (self::todas() as $def) {
            if (strtolower($def['nombre']) === $nombre) {
                return true;
            }
        }

        return false;
    }

    /** Los nombres del catálogo, para que el panel sepa cuáles proteger. */
    public static function nombres(): array
    {
        return array_values(array_map(fn ($d) => $d['nombre'], self::todas()));
    }

    /** Solo la definición de un evento. */
    public static function para(string $evento): ?array
    {
        return self::todas()[$evento] ?? null;
    }

    /**
     * Traduce una definición al formato que espera la API de Meta.
     *
     * @return array{name:string, category:string, language:string, components:array}
     */
    public static function payloadMeta(array $def): array
    {
        $componentes = [];

        // El ejemplo del cuerpo es obligatorio cuando hay variables: Meta lo
        // usa para revisar la plantilla y sin él la rechaza.
        $cuerpo = ['type' => 'BODY', 'text' => $def['cuerpo']];

        if (!empty($def['variables'])) {
            $cuerpo['example'] = [
                'body_text' => [array_map(
                    fn (string $v) => self::ejemploDe($v),
                    $def['variables']
                )],
            ];
        }

        $componentes[] = $cuerpo;

        if (!empty($def['pie'])) {
            $componentes[] = ['type' => 'FOOTER', 'text' => $def['pie']];
        }

        if (!empty($def['boton_url'])) {
            $componentes[] = [
                'type'    => 'BUTTONS',
                'buttons' => [[
                    'type'    => 'URL',
                    'text'    => $def['boton_url']['texto'],
                    'url'     => $def['boton_url']['url'],
                    'example' => [$def['boton_url']['ejemplo']],
                ]],
            ];
        }

        return [
            'name'       => $def['nombre'],
            'category'   => $def['categoria'],
            'language'   => $def['idioma'],
            'components' => $componentes,
        ];
    }

    /** Valor de muestra para que Meta pueda revisar la plantilla. */
    private static function ejemploDe(string $variable): string
    {
        return match ($variable) {
            'cliente'         => 'Manuel',
            'cliente_completo'=> 'Manuel Pombo',
            'numero_factura'  => 'NT16536',
            'valor_numero'    => '60.000',
            'valor'           => '$60.000',
            'fecha_emision'   => '01/09/2026',
            'fecha_vence'     => '15/09/2026',
            'dias_mora'       => '5',
            'medio_pago'      => 'Wompi',
            'motivo'          => 'Fondos insuficientes',
            'contrato'        => 'Contrato de servicio de internet',
            'empresa'         => 'Netplay SAS',
            default           => 'Ejemplo',
        };
    }
}
