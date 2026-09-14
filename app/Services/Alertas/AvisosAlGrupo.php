<?php

namespace App\Services\Alertas;

use App\Models\Alerta;
use App\Models\Company;
use App\Services\NetplayWhatsAppService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Las alertas de la red, al grupo de WhatsApp de los técnicos.
 *
 * Al momento sólo va lo que pide salir a la calle (crítico): puerto PON caído,
 * OLT o túnel sin respuesta, cliente caído por fibra, señal crítica. Cuando se
 * cierra, un "resuelto" para que nadie vaya a revisar algo que ya volvió. Los
 * avisos menores esperan al resumen de la mañana: mandados uno por uno el
 * grupo se vuelve ruido y lo terminan silenciando.
 *
 * Sale siempre por la línea de WhatsApp Web: la API de Meta no maneja grupos.
 */
class AvisosAlGrupo
{
    /** Tipos que le sirven al técnico. "datos" es trabajo de oficina. */
    private const TIPOS_DE_RED = ['corte', 'olt', 'tunel', 'senal', 'caida'];

    /** Más que esto en una tanda se resume, para no llenar la pantalla. */
    private const MAXIMO_POR_MENSAJE = 10;

    public function __construct(private int $companyId) {}

    public function grupo(): ?string
    {
        return Company::whereKey($this->companyId)->value('alertas_grupo_jid') ?: null;
    }

    /**
     * Avisa las críticas nuevas y las que se resolvieron desde la última vez.
     *
     * @return array{abiertas:int, resueltas:int}
     */
    public function enviarPendientes(): array
    {
        if (!$this->grupo()) {
            return ['abiertas' => 0, 'resueltas' => 0];
        }

        $nuevas = Alerta::where('company_id', $this->companyId)
            ->abiertas()
            ->where('nivel', 'critico')
            ->whereIn('tipo', self::TIPOS_DE_RED)
            ->whereNull('avisada_en')
            ->orderBy('abierta_en')
            ->get();

        // Sólo el resuelto de lo que se anunció: críticas de red. Un aviso menor
        // que se cierra no tiene por qué aparecer en el grupo.
        $resueltas = Alerta::where('company_id', $this->companyId)
            ->where('nivel', 'critico')
            ->whereIn('tipo', self::TIPOS_DE_RED)
            ->whereNotNull('cerrada_en')
            ->whereNotNull('avisada_en')
            ->whereNull('cierre_avisado_en')
            ->orderBy('cerrada_en')
            ->get();

        // Sólo se marca lo que salió: si WhatsApp falla, se reintenta en la
        // próxima revisión en vez de perderse.
        if ($nuevas->isNotEmpty() && $this->enviar($this->textoDeNuevas($nuevas))) {
            Alerta::whereIn('id', $nuevas->pluck('id'))->update(['avisada_en' => now()]);
        }

        if ($resueltas->isNotEmpty() && $this->enviar($this->textoDeResueltas($resueltas))) {
            Alerta::whereIn('id', $resueltas->pluck('id'))->update(['cierre_avisado_en' => now()]);
        }

        return ['abiertas' => $nuevas->count(), 'resueltas' => $resueltas->count()];
    }

    /** Resumen de la mañana: lo que sigue abierto en la red. */
    public function enviarResumen(): bool
    {
        if (!$this->grupo()) {
            return false;
        }

        $abiertas = Alerta::where('company_id', $this->companyId)
            ->abiertas()
            ->whereIn('tipo', self::TIPOS_DE_RED)
            ->orderByRaw("nivel = 'critico' desc")
            ->orderBy('abierta_en')
            ->get();

        if ($abiertas->isEmpty()) {
            return $this->enviar("☀️ *Resumen de la red*\nSin novedades: no hay alertas abiertas.");
        }

        $criticas = $abiertas->where('nivel', 'critico');
        $lineas = ["☀️ *Resumen de la red* · {$abiertas->count()} abiertas ({$criticas->count()} críticas)", ''];

        foreach ($abiertas->take(self::MAXIMO_POR_MENSAJE * 2) as $a) {
            $lineas[] = ($a->nivel === 'critico' ? '🔴 ' : '🟡 ') . $a->titulo . ' · desde ' . $this->desde($a);
        }

        if ($abiertas->count() > self::MAXIMO_POR_MENSAJE * 2) {
            $lineas[] = '… y ' . ($abiertas->count() - self::MAXIMO_POR_MENSAJE * 2) . ' más en el panel (Alertas).';
        }

        return $this->enviar(implode("\n", $lineas));
    }

    /** Mensaje de prueba al asociar el grupo. */
    public function probar(): bool
    {
        return $this->enviar("✅ Grupo asociado. Acá van a llegar las alertas de la red: cortes de puerto, OLT o túnel caídos, clientes caídos por fibra y señal crítica, más un resumen cada mañana.");
    }

    /**
     * Los grupos de la línea de WhatsApp Web de la empresa.
     *
     * @return list<array{jid:string, nombre:string, participantes:int}>
     */
    public function gruposDeLaLinea(): array
    {
        $empresa = Company::find($this->companyId);

        if (!$empresa?->wa_instance_id || !$empresa->wa_api_key) {
            throw new \RuntimeException('La empresa no tiene una línea de WhatsApp Web vinculada.');
        }

        $base = rtrim(preg_replace('#/crm$#', '', (string) config('services.netplay_whatsapp.base_url')), '/');
        $res = Http::timeout(20)
            ->withHeaders(['x-api-key' => $empresa->wa_api_key])
            ->get("{$base}/crm/instances/{$empresa->wa_instance_id}/groups");

        if (!$res->successful()) {
            throw new \RuntimeException('La línea de WhatsApp Web no respondió: ' . ($res->json('message') ?? $res->status()));
        }

        return array_map(fn ($g) => [
            'jid'           => (string) ($g['jid'] ?? ''),
            'nombre'        => (string) ($g['name'] ?? ''),
            'participantes' => (int) ($g['participants'] ?? 0),
        ], $res->json('groups') ?? []);
    }

    // ── Interno ───────────────────────────────────────────────────────────

    private function textoDeNuevas($alertas): string
    {
        if ($alertas->count() === 1) {
            $a = $alertas->first();

            return implode("\n", array_filter([
                "🚨 *{$a->titulo}*",
                $a->detalle,
                $this->ubicacion($a),
                '🕒 Desde ' . $this->desde($a),
            ]));
        }

        $lineas = ["🚨 *{$alertas->count()} alertas críticas nuevas*", ''];

        foreach ($alertas->take(self::MAXIMO_POR_MENSAJE) as $a) {
            $lugar = $this->ubicacion($a);
            $lineas[] = "• {$a->titulo}" . ($lugar ? "\n  " . str_replace("\n", "\n  ", $lugar) : '');
        }

        if ($alertas->count() > self::MAXIMO_POR_MENSAJE) {
            $lineas[] = '… y ' . ($alertas->count() - self::MAXIMO_POR_MENSAJE) . ' más en el panel (Alertas).';
        }

        return implode("\n", $lineas);
    }

    private function textoDeResueltas($alertas): string
    {
        $lineas = [$alertas->count() === 1 ? '✅ *Resuelto*' : "✅ *{$alertas->count()} alertas resueltas*"];

        foreach ($alertas->take(self::MAXIMO_POR_MENSAJE) as $a) {
            $lineas[] = "• {$a->titulo} · duró " . $this->duracion($a);
        }

        if ($alertas->count() > self::MAXIMO_POR_MENSAJE) {
            $lineas[] = '… y ' . ($alertas->count() - self::MAXIMO_POR_MENSAJE) . ' más.';
        }

        return implode("\n", $lineas);
    }

    /** Dónde ir: dirección del cliente y puerto de la OLT, si se saben. */
    private function ubicacion(Alerta $a): ?string
    {
        $partes = [];

        if ($a->user_id) {
            $direccion = DB::table('user_data')->where('user_id', $a->user_id)->value('address');
            if ($direccion) {
                $partes[] = "📍 {$direccion}";
            }
        }

        $datos = $a->datos ?? [];
        if (!empty($datos['olt'])) {
            $puerto = isset($datos['fsp']) ? " · puerto {$datos['fsp']}" . (isset($datos['ont_id']) ? " ONT {$datos['ont_id']}" : '') : '';
            $partes[] = "🔌 {$datos['olt']}{$puerto}";
        }

        return $partes ? implode("\n", $partes) : null;
    }

    private function desde(Alerta $a): string
    {
        $abierta = $a->abierta_en ?? $a->created_at;

        return $abierta->isToday() ? $abierta->format('H:i') : $abierta->format('d/m H:i');
    }

    private function duracion(Alerta $a): string
    {
        $abierta = $a->abierta_en ?? $a->created_at;

        return $abierta->diffForHumans($a->cerrada_en ?? now(), \Carbon\CarbonInterface::DIFF_ABSOLUTE, true, 2);
    }

    private function enviar(string $texto): bool
    {
        $grupo = $this->grupo();

        if (!$grupo) {
            return false;
        }

        try {
            // true: aunque la empresa tenga apagado WhatsApp para clientes, las
            // alertas internas igual tienen que salir.
            (new NetplayWhatsAppService($this->companyId, true))->mensajeInformativo($grupo, $texto);

            return true;
        } catch (\Throwable $e) {
            Log::warning('[Alertas] No se pudo avisar al grupo', ['empresa' => $this->companyId, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
