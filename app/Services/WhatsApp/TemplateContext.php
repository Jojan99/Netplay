<?php

namespace App\Services\WhatsApp;

use App\Models\Company;
use App\Models\WaTemplateBinding;
use Illuminate\Support\Facades\DB;

/**
 * Qué vale cada variable para un cliente concreto.
 *
 * El panel decide en qué orden van las variables en la plantilla; aquí se
 * resuelve cuánto vale cada una. Separarlo permite que el envío masivo, los
 * recordatorios y la vista previa de prueba muestren exactamente lo mismo.
 */
class TemplateContext
{
    /**
     * @param  object $cliente  Fila de user_data (o equivalente) con names,
     *                          lastname, phone, plan…
     * @param  array  $extra    Valores que solo conoce quien llama: el texto
     *                          libre de un comunicado, la factura del
     *                          recordatorio, los días de mora.
     * @return array<string, string>
     */
    public function forClient(Company $company, object $cliente, array $extra = []): array
    {
        $completo = trim(($cliente->names ?? '') . ' ' . ($cliente->lastname ?? ''));

        $contexto = [
            'cliente'          => $this->primerNombre((string) ($cliente->names ?? '')),
            'cliente_completo' => $completo !== '' ? $completo : 'Cliente',
            'plan'             => (string) ($cliente->plan ?? $this->planDe($cliente)),
            'empresa'          => (string) $company->name,
            'soporte'          => $this->soporte($company),
            'fecha'            => now()->format('d/m/Y'),
        ];

        // Lo que se calcula solo si hace falta: son consultas extra y la
        // mayoría de plantillas no las usan.
        if ($this->necesita($extra, ['total_pendiente', 'saldo'])) {
            $contexto['total_pendiente'] = $this->dinero($this->deudaTotal($company->id, (int) $cliente->user_id));
            $contexto['saldo']           = $contexto['total_pendiente'];
        }

        return array_merge($contexto, array_map(
            static fn ($v) => (string) $v,
            array_filter($extra, static fn ($v) => $v !== null)
        ));
    }

    /**
     * Valores de muestra para la vista previa y el envío de prueba.
     *
     * Salen del catálogo de variables, así que si mañana se agrega una nueva
     * la prueba la muestra sin tocar nada más.
     */
    public function sample(Company $company): array
    {
        $muestra = [];

        foreach (WaTemplateBinding::VARIABLES as $clave => $meta) {
            $muestra[$clave] = (string) ($meta['example'] ?? '');
        }

        $muestra['empresa'] = (string) $company->name;
        $muestra['soporte'] = $this->soporte($company);
        $muestra['fecha']   = now()->format('d/m/Y');

        return $muestra;
    }

    /**
     * Arma los parámetros de la plantilla en el orden que espera Meta.
     *
     * Una variable vacía no se deja en blanco: Meta rechaza el mensaje entero
     * si un {{n}} llega vacío, y perder el aviso por un dato faltante es peor
     * que mandar un guion.
     *
     * @param  array<int, string> $orden  Claves de variable, por posición.
     */
    public function toParameters(array $orden, array $contexto): array
    {
        $params = [];

        foreach ($orden as $variable) {
            $valor = trim((string) ($contexto[$variable] ?? ''));
            $params[] = $valor !== '' ? $valor : '-';
        }

        return $params;
    }

    // ─── Interno ─────────────────────────────────────────────────────────────

    private function necesita(array $extra, array $claves): bool
    {
        foreach ($claves as $clave) {
            if (!array_key_exists($clave, $extra)) {
                return true;
            }
        }

        return false;
    }

    /** "Hola Juan" se lee mejor que "Hola JUAN CARLOS PÉREZ GÓMEZ". */
    private function primerNombre(string $nombre): string
    {
        $primero = trim(explode(' ', trim($nombre))[0] ?? '');

        return $primero !== ''
            ? mb_convert_case(mb_strtolower($primero), MB_CASE_TITLE, 'UTF-8')
            : 'Cliente';
    }

    private function planDe(object $cliente): string
    {
        if (empty($cliente->internet_plans_id)) {
            return '';
        }

        return (string) (DB::table('internet_plans')
            ->where('id', $cliente->internet_plans_id)
            ->value('plan_name') ?: '');
    }

    /**
     * El número al que el cliente debe escribir si algo no cuadra.
     *
     * Si la empresa no configuró uno, se usa el propio WhatsApp del negocio:
     * es preferible a dejar el hueco, porque la plantilla lo anuncia como el
     * teléfono de contacto y un guion ahí queda muy mal.
     */
    private function soporte(Company $company): string
    {
        $propio = trim((string) ($company->invoice_phone ?: $company->phone ?: ''));

        if ($propio !== '') {
            return $propio;
        }

        try {
            return (string) (new \App\Services\MetaWhatsAppService($company->id))->businessPhoneNumber();
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function deudaTotal(int $companyId, int $userId): float
    {
        return (float) DB::table('cab_facturations as cf')
            ->join('det_facturations as df', 'df.cab_id', '=', 'cf.id')
            ->where('cf.company_id', $companyId)
            ->where('cf.user_id', $userId)
            ->where('df.paid', 0)
            ->sum(DB::raw('GREATEST(df.price_total - COALESCE(df.price_discount,0) - COALESCE(df.price_abone,0), 0)'));
    }

    private function dinero(float $valor): string
    {
        return '$' . number_format($valor, 0, ',', '.');
    }
}
