<?php

namespace App\Support;

/**
 * Limpieza de HTML que viene del editor de plantillas.
 *
 * El contenido del contrato lo escribe un usuario del panel, pero la página de
 * firma es pública: la abre el cliente final desde un enlace. Si alguien mete un
 * <script> en la plantilla, ese script corre en el navegador del cliente, sobre
 * nuestro dominio, mientras sube su cédula y firma. Por eso el contenido se
 * sanea antes de renderizarlo.
 *
 * No se usa una lista blanca estricta de etiquetas porque las plantillas ya
 * cargadas usan tablas, listas y formato variado, y recortarlas rompería
 * contratos que hoy están en uso. Se elimina lo que puede ejecutar código y se
 * deja intacto el formato.
 */
class HtmlSeguro
{
    /** Etiquetas que se borran completas, con su contenido. */
    private const ETIQUETAS_PELIGROSAS = [
        'script', 'iframe', 'object', 'embed', 'applet', 'meta', 'link', 'base', 'form', 'svg',
    ];

    /** Protocolos que no deben aparecer nunca en un href/src. */
    private const PROTOCOLOS_PELIGROSOS = ['javascript', 'vbscript', 'data'];

    public static function limpiar(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        // 1. Fuera las etiquetas ejecutables, con todo lo que lleven dentro.
        foreach (self::ETIQUETAS_PELIGROSAS as $tag) {
            $html = preg_replace('#<' . $tag . '\b[^>]*>.*?</' . $tag . '\s*>#is', '', $html) ?? $html;
            // Y las que vengan sin cierre (<meta>, <link>, <base>)
            $html = preg_replace('#<' . $tag . '\b[^>]*/?>#is', '', $html) ?? $html;
        }

        // 2. Fuera los manejadores de eventos (onclick, onerror, onload, onmouseover…).
        //    Cubre las tres formas de comillas, incluida la ausencia de comillas.
        $html = preg_replace('#\son[a-z-]+\s*=\s*"[^"]*"#i', '', $html) ?? $html;
        $html = preg_replace("#\\son[a-z-]+\\s*=\\s*'[^']*'#i", '', $html) ?? $html;
        $html = preg_replace('#\son[a-z-]+\s*=\s*[^\s>]+#i', '', $html) ?? $html;

        // 3. Fuera los enlaces con protocolo ejecutable. Se neutraliza el atributo
        //    entero en vez de solo el esquema, para que no quede un href a medias.
        foreach (self::PROTOCOLOS_PELIGROSOS as $proto) {
            $html = preg_replace(
                '#\s(href|src|xlink:href|action|formaction)\s*=\s*["\']?\s*' . $proto . '\s*:[^"\'>\s]*["\']?#i',
                '',
                $html
            ) ?? $html;
        }

        // 4. Fuera los style= (ya se hacía antes de este cambio: las plantillas
        //    traen estilos del editor que descuadran la página de firma) y los
        //    srcdoc, que son HTML embebido.
        $html = preg_replace('#\sstyle\s*=\s*["\'][^"\']*["\']#i', '', $html) ?? $html;
        $html = preg_replace('#\ssrcdoc\s*=\s*["\'][^"\']*["\']#i', '', $html) ?? $html;

        return $html;
    }
}
