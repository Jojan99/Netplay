<?php

namespace App\Services\Inventario;

use App\Models\Alerta;
use App\Services\NotificationRouterService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Avisa cuando algo se está acabando: en las alertas del panel y por WhatsApp al destino que
 * la empresa eligió para «inventario_bajo».
 *
 * Se avisa una vez por faltante (inventories.aviso_bajo_en). Cuando vuelve a haber existencia
 * por encima del mínimo la marca se quita, y el próximo faltante se avisa de nuevo.
 */
class AvisosDeInventario
{
    /** Un ítem acaba de quedar en el mínimo: aviso inmediato. Nunca lanza. */
    public static function existenciaBaja(int $companyId, int $inventoryId): void
    {
        try {
            $i = collect((new AnalisisDeInventario($companyId))->items())->firstWhere('id', $inventoryId);

            if (!$i || !in_array($i['estado'], ['agotado', 'bajo'], true)) {
                return;
            }

            self::abrirAlerta($companyId, $i);
            NotificationRouterService::dispatch($companyId, 'inventario_bajo', self::texto([$i]));
            DB::table('inventories')->where('id', $inventoryId)->update(['aviso_bajo_en' => now()]);
        } catch (\Throwable $e) {
            Log::warning('[Inventario] No se pudo avisar la existencia baja', ['item' => $inventoryId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * La pasada diaria: avisa lo que está bajo y nadie avisó todavía (por ejemplo, porque le
     * pusieron el mínimo después), y cierra las alertas de lo que ya se repuso.
     *
     * @return array{avisados:int, cerradas:int}
     */
    public static function revisar(int $companyId): array
    {
        $items = (new AnalisisDeInventario($companyId))->items();
        $bajos = array_values(array_filter($items, fn ($i) => in_array($i['estado'], ['agotado', 'bajo'], true)));
        $vivas = [];
        $nuevos = [];

        foreach ($bajos as $i) {
            $vivas[] = self::abrirAlerta($companyId, $i);

            if (!DB::table('inventories')->where('id', $i['id'])->value('aviso_bajo_en')) {
                $nuevos[] = $i;
            }
        }

        if ($nuevos) {
            NotificationRouterService::dispatch($companyId, 'inventario_bajo', self::texto($nuevos));
            DB::table('inventories')->whereIn('id', array_column($nuevos, 'id'))->update(['aviso_bajo_en' => now()]);
        }

        $cerradas = Alerta::where('company_id', $companyId)->abiertas()->where('tipo', 'inventario')
            ->when($vivas, fn ($q) => $q->whereNotIn('clave', $vivas))->update(['cerrada_en' => now()]);

        return ['avisados' => count($nuevos), 'cerradas' => $cerradas];
    }

    /** El resumen de la semana: lo que tienen los técnicos hace tiempo. */
    public static function resumenDeTecnicos(int $companyId): bool
    {
        $viejos = array_filter((new AnalisisDeInventario($companyId))->tecnicos(), fn ($t) => $t['equipos_viejos'] > 0);

        if (!$viejos) {
            return false;
        }

        $lineas = ["🧰 *Equipos en manos de técnicos hace más de " . AnalisisDeInventario::DIAS_CON_TECNICO . " días*"];

        foreach ($viejos as $t) {
            $lineas[] = "• {$t['nombre']}: {$t['equipos_viejos']} equipo(s), el más viejo hace {$t['dias_max']} días";
        }

        $lineas[] = 'Pídales que los devuelvan a la bodega o que digan dónde quedaron.';
        NotificationRouterService::dispatch($companyId, 'inventario_bajo', implode("\n", $lineas));

        return true;
    }

    private static function abrirAlerta(int $companyId, array $i): string
    {
        $clave = 'inventario:' . $i['id'];
        $alerta = Alerta::firstOrNew(['company_id' => $companyId, 'clave' => $clave]);

        if ($alerta->exists && $alerta->cerrada_en !== null) {
            $alerta->abierta_en = null;
        }

        $alerta->fill([
            'tipo' => 'inventario', 'nivel' => $i['estado'] === 'agotado' ? 'critico' : 'aviso',
            'titulo' => $i['estado'] === 'agotado' ? "Se acabó «{$i['nombre']}»" : "«{$i['nombre']}» se está acabando",
            'detalle' => self::linea($i), 'datos' => ['inventory_id' => $i['id'], 'en_bodega' => $i['en_bodega'], 'minimo' => $i['minimo']], 'cerrada_en' => null,
        ]);
        $alerta->abierta_en ??= now();
        $alerta->save();

        return $clave;
    }

    private static function linea(array $i): string
    {
        $n = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
        $t = "Quedan {$n($i['en_bodega'])} {$i['unidad']} en bodega (mínimo {$n($i['minimo'])}).";

        if ($i['dias_de_cobertura'] !== null && $i['en_bodega'] > 0) {
            $t .= " Alcanza para {$i['dias_de_cobertura']} día(s).";
        }
        if ($i['con_tecnicos'] > 0) {
            $t .= " Los técnicos tienen {$n($i['con_tecnicos'])} más.";
        }
        if ($i['sugerido_pedir'] > 0) {
            $t .= " Sugerido pedir: {$i['sugerido_pedir']}.";
        }

        return $t;
    }

    private static function texto(array $items): string
    {
        $lineas = ['📦 *Inventario: se está acabando*'];

        foreach ($items as $i) {
            $lineas[] = ($i['estado'] === 'agotado' ? '🔴' : '🟠') . " *{$i['nombre']}* — " . self::linea($i);
        }

        return implode("\n", $lineas);
    }
}
