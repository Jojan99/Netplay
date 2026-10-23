<?php

namespace App\Services\Tv;

use App\Models\Alerta;
use App\Models\TvCanal;
use App\Models\TvPanel;
use App\Services\Alertas\AvisosAlGrupo;
use App\Services\NotificationRouterService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Vigila los canales del panel de TV y avisa al grupo cuando uno se cae y cuando vuelve.
 *
 * Un canal fuera del aire (caído o reintentando) abre una alerta «canal» después de
 * MINUTOS_PARA_AVISAR: el XUI reinicia solo los que se cortan un momento y avisar cada reinicio
 * llenaría el grupo. Los que ya estaban caídos hace más de un día la primera vez que se vieron
 * quedan sin vigilar (son canales que la empresa dejó de dar); si vuelven al aire, se vigilan.
 */
class VigiaDeCanales
{
    public const MINUTOS_PARA_AVISAR = 3;

    /** Desde aquí el aviso no lista canal por canal: lo más probable es la fuente (Astra). */
    private const CAIDA_MASIVA = 8;

    public function __construct(private TvPanel $panel) {}

    /** @return array{canales:int, al_aire:int, fuera:int, abiertas:int, cerradas:int} */
    public function revisar(): array
    {
        $empresa = (int) $this->panel->company_id;
        $ahora = now();
        $vistos = [];
        $nuevos = TvCanal::where('company_id', $empresa)->doesntExist();

        foreach ((new PanelXui($this->panel))->canales() as $s) {
            $id = (int) $s['id'];
            $vistos[] = $id;
            $estado = match ((string) $s['stream_status']) { '0' => 'al_aire', '2' => 'arrancando', default => 'caido' };
            $desdeElPanel = (int) ($s['stream_started'] ?? 0) > 0 ? Carbon::createFromTimestamp((int) $s['stream_started']) : $ahora;

            $canal = TvCanal::firstOrNew(['company_id' => $empresa, 'stream_id' => $id]);
            $antes = $canal->exists ? $canal->estado : null;
            $fuera = $estado !== 'al_aire';

            if (!$canal->exists) {
                $canal->estado_desde = $desdeElPanel;
                // La primera vez: un canal caído hace más de un día no es una emergencia de hoy.
                $canal->vigilar = !($fuera && $desdeElPanel->lt($ahora->copy()->subDay()));
            } elseif (($antes !== 'al_aire') !== $fuera) {
                $canal->estado_desde = $ahora;
            }
            if (!$fuera && !$canal->vigilar && $antes && $antes !== 'al_aire') {
                $canal->vigilar = true; // Volvió: es un canal que se está dando.
            }

            $canal->fill([
                'nombre' => trim((string) $s['stream_display_name']) ?: "Canal {$id}",
                'fuente' => $s['current_source'] ?? null,
                'estado' => $estado,
                'bitrate' => isset($s['bitrate']) ? (int) $s['bitrate'] : null,
                'espectadores' => (int) ($s['clients'] ?? 0),
                'revisado_en' => $ahora,
            ])->save();
        }

        // Un canal que ya no está en el panel no se vigila más.
        TvCanal::where('company_id', $empresa)->whereNotIn('stream_id', $vistos ?: [0])->delete();

        [$abiertas, $cerradas] = $this->alertas($empresa, $ahora);
        $this->avisar($empresa);

        $canales = TvCanal::where('company_id', $empresa)->get();
        Log::info('[TV] Canales revisados', ['empresa' => $empresa, 'canales' => $canales->count(), 'primera_vez' => $nuevos]);

        return [
            'canales' => $canales->count(), 'al_aire' => $canales->where('estado', 'al_aire')->count(),
            'fuera' => $canales->where('estado', '!=', 'al_aire')->count(), 'abiertas' => $abiertas, 'cerradas' => $cerradas,
        ];
    }

    /** Abre la alerta del canal que pasó el tiempo fuera del aire y cierra la del que volvió. */
    private function alertas(int $empresa, Carbon $ahora): array
    {
        $abiertas = 0;
        $cerradas = 0;
        $limite = $ahora->copy()->subMinutes(self::MINUTOS_PARA_AVISAR);

        $canales = TvCanal::where('company_id', $empresa)->get()->keyBy('stream_id');
        $alertas = Alerta::where('company_id', $empresa)->where('tipo', 'canal')->abiertas()->get()->keyBy('clave');

        foreach ($canales as $c) {
            $clave = "canal:{$c->stream_id}";
            $fuera = $c->estado !== 'al_aire';

            if ($fuera && $c->vigilar && $c->estado_desde && $c->estado_desde->lte($limite) && !$alertas->has($clave)) {
                // Una fila por canal (la clave es única): si ya se cayó antes, se reabre la misma.
                $a = Alerta::firstOrNew(['company_id' => $empresa, 'clave' => $clave]);
                $a->forceFill([
                    'tipo' => 'canal', 'nivel' => 'critico',
                    'titulo' => "Canal sin señal · {$c->nombre}",
                    'detalle' => 'Sin señal desde las ' . $c->estado_desde->format('H:i') . ($c->estado === 'arrancando' ? ': el panel lo está reintentando.' : '.'),
                    'datos' => ['stream_id' => $c->stream_id, 'fuente' => $c->fuente],
                    'abierta_en' => $c->estado_desde, 'vista_en' => null, 'cerrada_en' => null,
                    'avisada_en' => null, 'cierre_avisado_en' => null,
                ])->save();
                $abiertas++;
            }

            // Volvió al aire, o se dejó de vigilar: la alerta se cierra (y el grupo recibe el «volvió»).
            if ((!$fuera || !$c->vigilar) && $alertas->has($clave)) {
                // Si solo se dejó de vigilar, no hay «volvió» que mandar.
                $alertas[$clave]->update(['cerrada_en' => $ahora] + ($fuera ? ['cierre_avisado_en' => $ahora] : []));
                $cerradas++;
            }
        }

        // La de un canal que desapareció del panel tampoco sigue abierta.
        foreach ($alertas as $clave => $a) {
            if (!$canales->has((int) substr($clave, 6))) {
                $a->update(['cerrada_en' => $ahora, 'cierre_avisado_en' => $ahora]);
            }
        }

        return [$abiertas, $cerradas];
    }

    /** Al grupo de «Canales caídos»: lo nuevo y lo que volvió, en un mensaje cada uno. */
    private function avisar(int $empresa): void
    {
        $destinos = array_keys(NotificationRouterService::destinos($empresa, 'alerta_tv'));
        if (!$destinos) {
            return;
        }

        $base = Alerta::where('company_id', $empresa)->where('tipo', 'canal');
        $nuevas = (clone $base)->abiertas()->whereNull('avisada_en')->orderBy('abierta_en')->get();
        $volvieron = (clone $base)->whereNotNull('cerrada_en')->whereNotNull('avisada_en')->whereNull('cierre_avisado_en')->orderBy('cerrada_en')->get();
        $grupo = new AvisosAlGrupo($empresa);

        $enviar = function (string $texto) use ($grupo, $destinos): bool {
            $ok = false;
            foreach ($destinos as $d) {
                $ok = $grupo->enviarA($d, $texto) || $ok;
            }

            return $ok;
        };

        if ($nuevas->isNotEmpty() && $enviar($this->textoDeCaidos($nuevas))) {
            Alerta::whereIn('id', $nuevas->pluck('id'))->update(['avisada_en' => now()]);
        }
        if ($volvieron->isNotEmpty() && $enviar($this->textoDeVolvieron($volvieron))) {
            Alerta::whereIn('id', $volvieron->pluck('id'))->update(['cierre_avisado_en' => now()]);
        }
    }

    private function nombre(Alerta $a): string
    {
        return trim(str_replace('Canal sin señal ·', '', $a->titulo));
    }

    private function textoDeCaidos($alertas): string
    {
        $n = $alertas->count();
        if ($n >= self::CAIDA_MASIVA) {
            return "🔴 *{$n} canales sin señal a la vez*\nCuando caen tantos juntos casi siempre es la fuente (Astra o la antena), no los canales uno por uno.\n\n"
                . $alertas->take(15)->map(fn ($a) => '• ' . $this->nombre($a))->implode("\n")
                . ($n > 15 ? "\n… y " . ($n - 15) . ' más en la plataforma (Televisión).' : '');
        }

        return ($n === 1 ? "🔴 *Canal sin señal*\n" : "🔴 *{$n} canales sin señal*\n")
            . $alertas->map(fn ($a) => '• ' . $this->nombre($a) . ' · desde las ' . $a->abierta_en->format('H:i'))->implode("\n");
    }

    private function textoDeVolvieron($alertas): string
    {
        $n = $alertas->count();

        return ($n === 1 ? "✅ *Volvió al aire*\n" : "✅ *Volvieron al aire {$n} canales*\n")
            . $alertas->take(20)->map(fn ($a) => '• ' . $this->nombre($a) . ' · estuvo caído ' . $this->duracion($a))->implode("\n");
    }

    private function duracion(Alerta $a): string
    {
        $min = (int) $a->abierta_en->diffInMinutes($a->cerrada_en ?? now());

        return $min < 60 ? "{$min} min" : intdiv($min, 60) . ' h ' . ($min % 60) . ' min';
    }
}
