<?php

namespace App\Support;

/**
 * Catálogo de variables que se pueden usar en una plantilla de contrato.
 *
 * Antes la lista vivía repetida en tres sitios (el panel, el preview del PDF y
 * buildFieldValues) y ya no coincidían entre sí: el panel ofrecía variables que
 * el backend no reemplazaba nunca. Aquí queda una sola lista, con el grupo, la
 * explicación para el dueño del ISP y un valor de ejemplo para las vistas
 * previas. Las claves son las mismas de siempre ({{nombre}}, {{dni}}…), así que
 * las plantillas ya guardadas siguen funcionando igual.
 */
class VariablesContrato
{
    /**
     * @return array<int, array{clave:string, etiqueta:string, grupo:string, ayuda:string, ejemplo:string}>
     */
    public static function catalogo(): array
    {
        return [
            // ── Cliente ───────────────────────────────────────────────────
            self::v('{{nombre_completo}}', 'Nombre completo', 'Cliente', 'Nombres y apellidos del cliente, tal como están en su ficha.', 'JUAN PEREZ GOMEZ'),
            self::v('{{nombre}}', 'Nombres', 'Cliente', 'Solo los nombres.', 'JUAN'),
            self::v('{{apellido}}', 'Apellidos', 'Cliente', 'Solo los apellidos.', 'PEREZ GOMEZ'),
            self::v('{{tipo_documento}}', 'Tipo de documento', 'Cliente', 'CC, NIT, CE… según el tipo cargado en la ficha del cliente.', 'CC'),
            self::v('{{dni}}', 'Número de documento', 'Cliente', 'La cédula o NIT del cliente.', '12345678'),
            self::v('{{telefono}}', 'Teléfono', 'Cliente', 'Celular de contacto.', '3001234567'),
            self::v('{{email}}', 'Correo', 'Cliente', 'Correo electrónico del cliente.', 'juan@ejemplo.com'),
            self::v('{{direccion}}', 'Dirección', 'Cliente', 'Dirección donde se presta el servicio.', 'Calle 123 # 45-67'),

            // ── Fecha ─────────────────────────────────────────────────────
            self::v('{{fecha}}', 'Fecha', 'Fecha', 'Fecha del día en que el cliente abre o firma el contrato.', '17/09/2026'),
            self::v('{{fecha_hora}}', 'Fecha y hora', 'Fecha', 'Fecha con la hora.', '17/09/2026 15:40'),
            self::v('{{dia}}', 'Día', 'Fecha', 'Solo el día, para los contratos que lo piden en casillas separadas.', '17'),
            self::v('{{mes}}', 'Mes', 'Fecha', 'Solo el mes.', '09'),
            self::v('{{anio}}', 'Año', 'Fecha', 'Solo el año.', '2026'),

            // ── Plan y valores ────────────────────────────────────────────
            self::v('{{plan_nombre}}', 'Nombre del plan', 'Plan y valores', 'Plan de internet asignado al cliente.', 'INTERNET 200MB'),
            self::v('{{plan_velocidad}}', 'Velocidad', 'Plan y valores', 'Velocidad de bajada del plan.', '200 Mb'),
            self::v('{{plan_precio}}', 'Precio mensual', 'Plan y valores', 'Mensualidad del plan del cliente.', '$70.000'),
            self::v('{{plan_instalacion}}', 'Instalación de la orden', 'Plan y valores', 'Costo de instalación que trae la orden de instalación del cliente.', '$60.000'),
            self::v('{{valor_instalacion}}', 'Valor de instalación', 'Plan y valores', 'El valor fijo que usted define en esta plantilla; si lo deja vacío, se usa el de la orden del cliente.', '$60.000'),
            self::v('{{plazo}}', 'Plazo (meses)', 'Plan y valores', 'Meses de permanencia que usted define en esta plantilla.', '12'),
            self::v('{{promocion_nombre}}', 'Promoción', 'Plan y valores', 'Descripción de la promoción del plan.', 'Promoción verano 200Mb'),

            // ── Casillas ──────────────────────────────────────────────────
            self::v('{{check_200mb}}', 'Casilla 200 Mb', 'Casillas', 'Escribe una X si el plan del cliente es de 200 Mb.', 'X'),
            self::v('{{check_300mb}}', 'Casilla 300 Mb', 'Casillas', 'Escribe una X si el plan del cliente es de 300 Mb.', ''),
            self::v('{{check_400mb}}', 'Casilla 400 Mb', 'Casillas', 'Escribe una X si el plan del cliente es de 400 Mb.', ''),
            self::v('{{check_otra}}', 'Casilla otra velocidad', 'Casillas', 'Escribe una X si el plan no es de 200, 300 ni 400 Mb.', ''),
            self::v('{{check_os_nuevo}}', 'Casilla orden nueva', 'Casillas', 'Escribe una X si es la primera instalación del cliente.', 'X'),
            self::v('{{check_os_mod}}', 'Casilla modificación', 'Casillas', 'Escribe una X si el cliente ya tenía instalaciones anteriores.', ''),
            self::v('{{check}}', 'Casilla siempre marcada', 'Casillas', 'Escribe siempre una X. Sirve para marcar una casilla fija del formato.', 'X'),

            // ── Contrato ──────────────────────────────────────────────────
            self::v('{{contrato_id}}', 'Número de contrato', 'Contrato', 'Número interno del contrato del cliente.', '999'),
            self::v('{{firma}}', 'Firma del cliente', 'Contrato', 'Solo para el PDF con coordenadas: es donde se pega la imagen de la firma.', '', true),
        ];
    }

    /** Claves del catálogo, para validar lo que escribe el operador. */
    public static function claves(): array
    {
        return array_column(self::catalogo(), 'clave');
    }

    /** Valores de ejemplo, para las vistas previas del panel y del PDF. */
    public static function ejemplos(): array
    {
        return array_column(self::catalogo(), 'ejemplo', 'clave');
    }

    /** Variables que aparecen escritas en un contenido de plantilla. */
    public static function usadas(?string $contenido): array
    {
        preg_match_all('/\{\{\s*[a-z0-9_]+\s*\}\}/i', (string) $contenido, $m);

        return array_values(array_unique($m[0]));
    }

    /**
     * Deja el contrato listo para mostrar: primero se sanea la plantilla (la
     * escribe un usuario del panel y la página de firma es pública) y después se
     * meten los datos del cliente ya escapados. En ese orden nada que venga del
     * cliente puede crear HTML.
     *
     * Lo que no tiene valor, y lo que alguien escribió mal, queda como una raya
     * en blanco: en un contrato se lee natural, y antes se le mostraba al cliente
     * el {{nombre}} tal cual (con una arroba delante, encima).
     *
     * La usan la página de firma y la vista previa del panel: así el operador ve
     * exactamente lo mismo que el cliente.
     */
    public static function pintar(?string $plantilla, array $valores, string $hueco = '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;'): string
    {
        $html = self::unirLineasCortadas(self::sinNotasInternas(HtmlSeguro::limpiar($plantilla)));
        $vacio = '<span class="dato dato--vacio">' . $hueco . '</span>';

        foreach ($valores as $clave => $valor) {
            $valor = trim((string) $valor);
            $html = str_replace($clave, $valor === '' ? $vacio : '<span class="dato">' . e($valor) . '</span>', $html);
        }

        // Variables escritas a mano que no existen en el catálogo.
        return preg_replace('/\{\{\s*[a-z0-9_]+\s*\}\}/i', $vacio, $html) ?? $html;
    }

    /**
     * Quita las notas que el conversor de PDF le dejaba al operador dentro del
     * propio contenido ("Guía: Reemplace los textos entre corchetes o use las
     * variables rápidas ({{nombre}}, {{dni}}, etc.)").
     *
     * Eso se guardó dentro de contracts.content y terminaba impreso en la página
     * de firma, encima con las variables del ejemplo ya reemplazadas por los
     * datos del cliente: el cliente leía "use las variables rápidas (LORENA,
     * 1143…, etc.)". Se filtra al mostrar; el dato guardado no se toca.
     */
    public static function sinNotasInternas(string $html): string
    {
        $patrones = [
            '#<p[^>]*class\s*=\s*["\']?contract-guide["\']?[^>]*>.*?</p>#is',
            '#<p[^>]*>\s*<strong>\s*Gu[íi]a\s*:?\s*</strong>.*?</p>#is',
            '#<p[^>]*>\s*Gu[íi]a\s*:\s*Reemplace.*?</p>#is',
        ];

        foreach ($patrones as $patron) {
            $html = preg_replace($patron, '', $html) ?? $html;
        }

        return $html;
    }

    /**
     * Junta los párrafos que en realidad son renglones cortados.
     *
     * El conversor de PDF a texto crea un <p> por cada línea visual del PDF, así
     * que un párrafo de seis renglones llegaba como seis párrafos sueltos y se
     * leía a los saltos. Se unen los que no terminan en signo de final de frase,
     * que es justo lo que pasa cuando una oración sigue en el renglón siguiente.
     * Sólo toca <p> sin atributos y de más de 30 caracteres, para no pegar
     * etiquetas de formulario ni celdas cortas.
     */
    public static function unirLineasCortadas(string $html): string
    {
        // Cada pasada une un par; un párrafo de varios renglones necesita varias.
        for ($vuelta = 0; $vuelta < 12; $vuelta++) {
            $antes = $html;
            $html = self::unirUnaVuelta($html);
            if ($html === $antes) {
                break;
            }
        }

        return $html;
    }

    private static function unirUnaVuelta(string $html): string
    {
        return preg_replace_callback(
            '#<p>([^<]{31,}?)</p>\s*<p>#u',
            function (array $m) {
                $texto = rtrim($m[1]);
                $final = mb_substr($texto, -1);

                return in_array($final, ['.', ':', ';', '?', '!', ')', '»', '"', '·'], true)
                    ? $m[0]
                    : '<p>' . $texto . ' ';
            },
            $html
        ) ?? $html;
    }

    private static function v(string $clave, string $etiqueta, string $grupo, string $ayuda, string $ejemplo, bool $soloPdf = false): array
    {
        return compact('clave', 'etiqueta', 'grupo', 'ayuda', 'ejemplo') + ['solo_pdf' => $soloPdf];
    }
}
