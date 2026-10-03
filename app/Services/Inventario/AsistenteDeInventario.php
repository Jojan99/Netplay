<?php

namespace App\Services\Inventario;

use App\Services\Cobranza\Ia;
use Illuminate\Support\Facades\Log;

/**
 * El asistente del inventario: responde preguntas y da recomendaciones sobre los números de
 * la bodega y de los técnicos.
 *
 * No inventa cifras: todo lo que sabe se lo entrega AnalisisDeInventario en cada pregunta, y
 * se le ordena responder sólo con eso. Sin IA conectada devuelve las recomendaciones que
 * salen de las reglas, que ya dicen qué pedir y a quién reclamarle equipos.
 */
class AsistenteDeInventario
{
    /** Lo que se conserva de la charla: suficiente para seguir el hilo sin crecer sin fin. */
    private const HISTORIAL_MAX = 12;

    public function __construct(private int $companyId) {}

    /** @return array{resumen:array, recomendaciones:list<array>, con_ia:bool} */
    public function panel(): array
    {
        $a = new AnalisisDeInventario($this->companyId);
        $items = $a->items();
        $tecnicos = $a->tecnicos();

        return [
            'resumen' => $a->resumen($items, $tecnicos),
            'recomendaciones' => $a->recomendaciones($items, $tecnicos),
            'con_ia' => Ia::disponible($this->companyId),
        ];
    }

    /**
     * Una pregunta al asistente.
     *
     * @param  list<array{rol:string, texto:string}> $historial
     * @return array{respuesta:string, con_ia:bool}
     */
    public function preguntar(string $pregunta, array $historial = []): array
    {
        $a = new AnalisisDeInventario($this->companyId);
        $items = $a->items();
        $tecnicos = $a->tecnicos();
        $recomendaciones = $a->recomendaciones($items, $tecnicos);

        if (!Ia::disponible($this->companyId)) {
            return ['respuesta' => $this->sinIa($recomendaciones), 'con_ia' => false];
        }

        $mensajes = [];

        foreach (array_slice($historial, -self::HISTORIAL_MAX) as $h) {
            $texto = trim((string) ($h['texto'] ?? ''));

            if ($texto !== '') {
                $mensajes[] = ['role' => ($h['rol'] ?? '') === 'asistente' ? 'assistant' : 'user', 'content' => [['type' => 'text', 'text' => mb_substr($texto, 0, 1500)]]];
            }
        }

        // La conversación tiene que empezar con el usuario.
        while ($mensajes && $mensajes[0]['role'] !== 'user') {
            array_shift($mensajes);
        }

        $mensajes[] = ['role' => 'user', 'content' => [['type' => 'text', 'text' => mb_substr(trim($pregunta), 0, 1500)]]];

        try {
            $r = Ia::para($this->companyId)->mensaje($this->sistema($a->resumen($items, $tecnicos), $items, $tecnicos, $recomendaciones), $mensajes, [], 900);
            $texto = trim(implode("\n", array_map(fn ($b) => ($b['type'] ?? '') === 'text' ? (string) $b['text'] : '', $r['content'] ?? [])));

            return ['respuesta' => $texto !== '' ? $texto : $this->sinIa($recomendaciones), 'con_ia' => $texto !== ''];
        } catch (\Throwable $e) {
            Log::warning('[Inventario] El asistente no pudo contestar', ['empresa' => $this->companyId, 'error' => $e->getMessage()]);

            return ['respuesta' => "El asistente no está disponible en este momento. Esto es lo que dicen los números:\n\n" . $this->sinIa($recomendaciones), 'con_ia' => false];
        }
    }

    private function sinIa(array $recomendaciones): string
    {
        if (!$recomendaciones) {
            return 'Todo en orden: ningún ítem está en el mínimo y ningún técnico tiene equipos hace más de ' . AnalisisDeInventario::DIAS_CON_TECNICO . ' días.';
        }

        return implode("\n", array_map(fn ($r) => "• {$r['titulo']}. {$r['detalle']}", array_slice($recomendaciones, 0, 12)));
    }

    private function sistema(array $resumen, array $items, array $tecnicos, array $recomendaciones): string
    {
        // Compacto: sólo lo que sirve para razonar, sin seriales de más ni ids internos.
        $datosItems = array_map(fn ($i) => array_intersect_key($i, array_flip(['nombre', 'categoria', 'unidad', 'en_bodega', 'con_tecnicos', 'danadas', 'minimo', 'maximo', 'consumo_30d', 'instaladas_30d', 'dias_de_cobertura', 'dias_sin_movimiento', 'valor_en_bodega', 'estado', 'sugerido_pedir'])), array_slice($items, 0, 150));

        $datosTecnicos = array_map(fn ($t) => [
            'nombre' => $t['nombre'], 'equipos' => $t['equipos_total'], 'equipos_con_mas_de_' . AnalisisDeInventario::DIAS_CON_TECNICO . '_dias' => $t['equipos_viejos'],
            'dias_del_mas_viejo' => $t['dias_max'], 'dias_promedio' => $t['dias_promedio'], 'valor' => $t['valor'], 'instaladas_30d' => $t['instaladas_30d'],
            'detalle_equipos' => array_map(fn ($e) => ['item' => $e['item'], 'serial' => $e['serial'], 'dias' => $e['dias']], array_slice($t['equipos'], 0, 25)),
            'material' => array_map(fn ($m) => ['item' => $m['item'], 'cantidad' => $m['cantidad'], 'unidad' => $m['unidad'], 'dias' => $m['dias']], $t['material']),
        ], $tecnicos);

        $datos = json_encode(['hoy' => now()->toDateString(), 'resumen' => $resumen, 'items' => $datosItems, 'tecnicos' => $datosTecnicos,
            'recomendaciones_de_las_reglas' => array_map(fn ($r) => $r['titulo'] . '. ' . $r['detalle'], $recomendaciones)], JSON_UNESCAPED_UNICODE);

        return <<<TXT
Eres el asistente de inventario de un proveedor de internet (ISP) en Colombia. Ayudas al encargado de la bodega a decidir qué comprar, qué reclamarles a los técnicos y cómo cuidar la existencia.

Reglas:
- Responde SOLO con los datos de abajo. Si te preguntan algo que no está en ellos, dilo: no inventes cifras, nombres ni seriales.
- «en_bodega» es lo que hay para entregar. «con_tecnicos» ya salió de la bodega pero no se ha instalado: no es consumo, sigue siendo de la empresa.
- «dias_de_cobertura» es para cuántos días alcanza lo de bodega al ritmo de los últimos 30 días (null = no hubo consumo).
- Cuando compares técnicos, mira cuánto tienen, hace cuántos días y cuánto instalaron en el mes. Más de 15 días con un equipo sin instalar merece que se le pregunte.
- Da pasos concretos y cortos: qué pedir y cuánto, a quién llamar, qué mínimo poner. Si viene al caso, agrega un consejo práctico de manejo de bodega (conteo periódico, entregar contra orden de instalación, mínimos según el consumo de dos semanas, revisar garantías de lo dañado).
- Español de Colombia, trato de «usted», tono directo. Sin saludos largos ni relleno. Usa viñetas cuando haya varios puntos. Pesos colombianos con punto de miles.
- No des órdenes al sistema ni ofrezcas hacer cambios: solo recomiendas; quien registra los movimientos es la persona.

Datos del inventario (JSON):
{$datos}
TXT;
    }
}
