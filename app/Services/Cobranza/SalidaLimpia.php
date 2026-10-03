<?php

namespace App\Services\Cobranza;

/**
 * Lo último que se le hace a un mensaje de un asistente antes de mandarlo.
 *
 * La IA a veces «narra» lo que hizo por dentro: escribió
 * «[ENVIAR_MEDIOS_DE_PAGO] Nequis (…)» —el nombre de la herramienta y lo que ésta
 * le devolvió, tal cual— como primer renglón de un mensaje a una clienta. Eso es
 * maquinaria interna y no le llega a nadie: aquí se quitan esos renglones.
 */
class SalidaLimpia
{
    public static function de(string $texto): string
    {
        $lineas = [];

        foreach (preg_split('/\R/u', $texto) as $linea) {
            $t = trim($linea);

            // Un renglón que arranca con una etiqueta interna: [ENVIAR_MEDIOS_DE_PAGO], [CLIENTE],
            // [SISTEMA], [tool_result]… o con una llamada escrita a mano: enviar_medios_de_pago(...).
            if (preg_match('/^\[\s*[A-Za-zÁÉÍÓÚÑáéíóúñ]+(?:[_ ][A-Za-zÁÉÍÓÚÑáéíóúñ]+)*\s*\]/u', $t, $m)
                && (str_contains($m[0], '_') || preg_match('/^\[\s*(CLIENTE|SISTEMA|INICIO|RECORDATORIO|HERRAMIENTA|RESULTADO|TOOL|ASISTENTE)\b/iu', $m[0]) || mb_strtoupper($m[0]) === $m[0])) {
                continue;
            }

            if (preg_match('/^(tool_code|tool_use|tool_result|function_call|print\()\b/i', $t) || preg_match('/^[a-z]+(_[a-z]+)+\s*\(.*\)\s*$/', $t)) {
                continue;
            }

            $lineas[] = $linea;
        }

        $limpio = trim(implode("\n", $lineas));
        // Bloques de código que el modelo deje colgados.
        $limpio = trim(preg_replace('/```[a-z_]*\s*```/i', '', $limpio));

        return trim(preg_replace('/\n{3,}/', "\n\n", $limpio));
    }
}
