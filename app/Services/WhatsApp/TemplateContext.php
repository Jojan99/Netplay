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

        // La factura más vieja sin pagar: de ahí salen los días de mora y el número de factura.
        // Los avisos automáticos ya traen estos datos; un envío masivo no, y sin esto la
        // plantilla salía con guiones en «días de mora».
        if ($this->necesita($extra, ['dias_mora', 'factura', 'fecha_emision'])) {
            $vieja = DB::table('det_facturations as df')->join('cab_facturations as cf', 'cf.id', '=', 'df.cab_id')
                ->where('cf.company_id', $company->id)->where('cf.user_id', (int) $cliente->user_id)
                ->where('df.paid', 0)->whereNull('df.anulada_en')
                ->whereRaw('(df.price_total - COALESCE(df.price_discount,0) - COALESCE(df.price_abone,0)) > 0')
                ->orderBy('df.date_facturation')->first(['df.number_facture', 'df.date_facturation']);

            if ($vieja) {
                $emision = \Carbon\Carbon::parse($vieja->date_facturation)->startOfDay();
                $contexto['factura']       = (string) $vieja->number_facture;
                $contexto['fecha_emision'] = $emision->format('d/m/Y');
                $contexto['dias_mora']     = (string) max(0, (int) $emision->diffInDays(now()->startOfDay(), false));
            }
        }

        $contexto = array_merge($contexto, array_map(
            static fn ($v) => (string) $v,
            array_filter($extra, static fn ($v) => $v !== null)
        ));

        // Algunas plantillas ya escriben el símbolo (por ejemplo "Valor: ${{3}}"),
        // así que se ofrece el mismo importe sin el "$" para no duplicarlo.
        if (!isset($contexto['valor_numero'])) {
            $base = $contexto['valor'] ?? $contexto['total_pendiente'] ?? '';
            $contexto['valor_numero'] = ltrim((string) $base, '$ ');
        }

        return $contexto;
    }

    /**
     * El texto que escribe el operador puede llevar datos del cliente entre llaves:
     * «Tienes un saldo de {total_pendiente}». Se reemplazan los que existan; lo demás queda igual.
     * Meta no acepta saltos de renglón ni tabulaciones dentro de una variable: se vuelven espacios.
     */
    public function rellenar(string $texto, array $contexto): string
    {
        $texto = preg_replace_callback('/\{\s*([a-z_]+)\s*\}/u', fn ($m) => array_key_exists($m[1], $contexto) && $m[1] !== 'texto_libre' ? (string) $contexto[$m[1]] : $m[0], $texto);

        return trim((string) preg_replace('/ {4,}/', '   ', (string) preg_replace('/[\r\n\t]+/u', ' ', (string) $texto)));
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

        // Las plantillas que crea el sistema nombran dos datos distinto que los avisos viejos.
        $alias = ['numero_factura' => 'factura', 'fecha_vence' => 'fecha_vencimiento'];

        foreach ($orden as $variable) {
            $valor = trim((string) ($contexto[$variable] ?? $contexto[$alias[$variable] ?? ''] ?? ''));
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
            ->where('df.paid', 0)->whereNull('df.anulada_en')
            ->sum(DB::raw('GREATEST(df.price_total - COALESCE(df.price_discount,0) - COALESCE(df.price_abone,0), 0)'));
    }

    private function dinero(float $valor): string
    {
        return '$' . number_format($valor, 0, ',', '.');
    }
}
