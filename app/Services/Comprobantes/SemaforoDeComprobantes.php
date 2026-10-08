<?php

namespace App\Services\Comprobantes;

use App\Models\PaymentProof;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Qué tan creíble es un comprobante: verde, amarillo o rojo, con el porqué.
 *
 * No hay forma de saber desde la imagen que un comprobante es auténtico al
 * 100 %: eso solo lo confirma el extracto, que llega a fin de mes. Pero los
 * comprobantes que ya se aprobaron enseñan cómo es uno normal, y lo que se sale
 * de ahí se marca para que una persona lo mire. Se aprende de nuevo cada noche
 * (comprobantes:aprender), así que cada aprobación lo afina.
 *
 * Señales:
 *  - Referencia contra hora: en Nequi la referencia (M + 8 dígitos) crece a lo
 *    largo del día y se reinicia cada día; con los aprobados se ve que sigue la
 *    hora impresa casi perfecto (correlación 0,98). Si alguien edita la hora o
 *    la fecha de una captura, la referencia sigue marcando la original.
 *  - Cuenta destino: las cuentas de la empresa (el «Número Nequi» de un envío,
 *    o la «Llave» de uno por Bre-B) salen solas de los aprobados. Un pago a otra
 *    cuenta no es un pago a la empresa. Ojo: «¿Desde dónde se hizo el envío?» es
 *    el número de quien paga, no el destino.
 *  - Historial del cliente: si ya se le rechazó un comprobante, sobre todo por falso.
 *  - Lo que ya revisa ComprobanteConfiable: referencia repetida, pago viejo, monto.
 */
class SemaforoDeComprobantes
{
    /** Vecinos con los que se estima la referencia que corresponde a una hora. */
    private const VECINOS = 5;

    /** Mínimo de aprobados para confiar en la curva referencia-hora. */
    private const MINIMO_DE_PUNTOS = 20;

    /** Desvío de la referencia (en millones) que ya llama la atención, y el que ya no es normal. */
    private const DESVIO_AVISO = 3.0;
    private const DESVIO_ALERTA = 6.0;

    /**
     * Cuándo una cuenta destino es de la empresa: con 3 aprobados, o con al menos
     * uno y pagos de 5 clientes distintos (un estafador no recibe en su cuenta los
     * pagos de cinco clientes). La llave de Bre-B tenía 35 comprobantes y solo 2
     * aprobados: esperar 3 aprobados la marcaba como ajena.
     */
    private const APROBADOS_PARA_SER_CUENTA = 3;
    private const CLIENTES_PARA_SER_CUENTA = 5;

    private const HORA = '/\b(\d{1,2}):(\d{2})\s*(a\.?\s*m\.?|p\.?\s*m\.?)/i';

    /** @var array<int, array> empresa => modelo, para no ir a la caché por cada fila. */
    private array $modelos = [];

    /**
     * Recalcula lo aprendido con los comprobantes aprobados de la empresa.
     *
     * @return array{puntos: list<array{0:int,1:int}>, cuentas: array<string,int>, aprendido_en: string}
     */
    public function aprender(int $companyId): array
    {
        // Los rechazados no enseñan nada de cómo es un comprobante normal.
        $comprobantes = DB::table('payment_proofs')
            ->where('company_id', $companyId)
            ->whereNotIn('status', ['rejected', 'suspicious'])
            ->get(['status', 'user_id', 'reference_number', 'ocr_text']);

        $puntos = [];
        $aprobadosPorCuenta = [];
        $clientesPorCuenta = [];

        foreach ($comprobantes as $c) {
            $texto = (string) $c->ocr_text;
            $aprobado = in_array($c->status, ['approved', 'auto_approved'], true);

            // La curva referencia-hora sale solo de lo que una persona (o la regla) aprobó.
            if ($aprobado && ($minuto = self::minutoImpreso($texto)) !== null && ($ref = self::referenciaNequi($c->reference_number)) !== null) {
                $puntos[] = [$minuto, $ref];
            }

            if ($destino = self::destino($texto)) {
                $aprobadosPorCuenta[$destino] = ($aprobadosPorCuenta[$destino] ?? 0) + ($aprobado ? 1 : 0);
                if ($c->user_id) {
                    $clientesPorCuenta[$destino][$c->user_id] = true;
                }
            }
        }

        $cuentas = [];
        foreach ($aprobadosPorCuenta as $destino => $aprobados) {
            $clientes = count($clientesPorCuenta[$destino] ?? []);

            if ($aprobados >= self::APROBADOS_PARA_SER_CUENTA || ($aprobados >= 1 && $clientes >= self::CLIENTES_PARA_SER_CUENTA)) {
                $cuentas[$destino] = $aprobados;
            }
        }

        $modelo = [
            'puntos'       => $puntos,
            'cuentas'      => $cuentas,
            'aprendido_en' => now()->toDateTimeString(),
        ];

        Cache::forever(self::clave($companyId), $modelo);

        return $this->modelos[$companyId] = $modelo;
    }

    /** Lo aprendido; si nunca se aprendió, lo aprende ahora. */
    public function modelo(int $companyId): array
    {
        return $this->modelos[$companyId] ??= (Cache::get(self::clave($companyId)) ?: $this->aprender($companyId));
    }

    /**
     * @return array{color: 'verde'|'amarillo'|'rojo', senales: list<array{nivel: 'ok'|'aviso'|'alerta', texto: string}>}
     */
    public function evaluar(PaymentProof $p): array
    {
        $modelo = $this->modelo((int) $p->company_id);
        $texto = (string) $p->ocr_text;
        $senales = [];

        // ── Referencia contra hora (Nequi) ──
        $ref = self::referenciaNequi($p->reference_number);
        $minuto = self::minutoImpreso($texto);

        if ($ref !== null && $minuto !== null && count($modelo['puntos']) >= self::MINIMO_DE_PUNTOS) {
            $desvio = abs($ref - self::referenciaEsperada($modelo['puntos'], $minuto)) / 1_000_000;
            $hora = sprintf('%02d:%02d', intdiv($minuto, 60), $minuto % 60);

            $senales[] = match (true) {
                $desvio > self::DESVIO_ALERTA => ['nivel' => 'alerta', 'texto' => "La referencia no corresponde a la hora impresa ({$hora}): puede ser una captura editada."],
                $desvio > self::DESVIO_AVISO  => ['nivel' => 'aviso',  'texto' => "La referencia se aleja de lo normal para la hora impresa ({$hora})."],
                default                        => ['nivel' => 'ok',     'texto' => 'La referencia corresponde a la hora del pago.'],
            };
        }

        // ── Cuenta destino ──
        $cuentas = $modelo['cuentas'];
        $esBilletera = in_array(mb_strtolower((string) $p->bank_name), ['nequi', 'daviplata'], true);

        if ($cuentas && $esBilletera) {
            $destino = self::destino($texto);

            if ($destino === null) {
                $senales[] = ['nivel' => 'aviso', 'texto' => 'No se ve a qué cuenta se pagó.'];
            } elseif (isset($cuentas[$destino])) {
                $senales[] = ['nivel' => 'ok', 'texto' => 'Pagado a una cuenta de la empresa (…' . substr($destino, -4) . ').'];
            } else {
                $senales[] = ['nivel' => 'alerta', 'texto' => 'Pagado a una cuenta que no es de la empresa (…' . substr($destino, -4) . ').'];
            }
        }

        // ── Historial del cliente ──
        if ($p->user_id) {
            $rechazos = DB::table('payment_proofs')
                ->where('company_id', $p->company_id)->where('user_id', $p->user_id)
                ->where('id', '<>', $p->id)->where('status', 'rejected')
                ->get(['raw_payload']);

            $falsos = $rechazos->filter(fn ($r) => (json_decode((string) $r->raw_payload, true)['rechazo_tipo'] ?? null) === 'falso')->count();

            if ($falsos) {
                $senales[] = ['nivel' => 'alerta', 'texto' => "Al cliente ya se le rechazó {$falsos} comprobante(s) por falso."];
            } elseif ($rechazos->count()) {
                $senales[] = ['nivel' => 'aviso', 'texto' => "Al cliente ya se le rechazaron {$rechazos->count()} comprobante(s)."];
            }
        }

        // ── Lo que revisa la regla de aplicación automática ──
        $motivos = ComprobanteConfiable::revisar($p, false)['motivos'];

        foreach ($motivos as $m) {
            if (str_contains($m, 'referencia ya se usó')) {
                $senales[] = ['nivel' => 'alerta', 'texto' => 'Esa referencia ya se usó en otro comprobante.'];
            } elseif (str_contains($m, 'hace más de') || str_contains($m, 'mucho mayor') || str_contains($m, 'no se pudo confirmar')) {
                $senales[] = ['nivel' => 'aviso', 'texto' => ucfirst($m) . '.'];
            }
        }

        $niveles = array_column($senales, 'nivel');
        $color = in_array('alerta', $niveles, true) ? 'rojo' : (in_array('aviso', $niveles, true) ? 'amarillo' : 'verde');

        return ['color' => $color, 'senales' => $senales];
    }

    /* ── Lectura del texto ─────────────────────────────────────────────────── */

    /** Minuto del día impreso en el comprobante («02:30 p. m.» → 870). */
    public static function minutoImpreso(string $texto): ?int
    {
        if (!preg_match(self::HORA, $texto, $m)) {
            return null;
        }

        $hora = (int) $m[1] % 12 + (stripos($m[3], 'p') !== false ? 12 : 0);

        return $hora * 60 + (int) $m[2];
    }

    /** La parte numérica de una referencia de Nequi («M13358926» → 13358926). */
    public static function referenciaNequi(?string $ref): ?int
    {
        return preg_match('/^M(\d{8})$/', trim((string) $ref), $m) ? (int) $m[1] : null;
    }

    /**
     * A dónde se mandó el pago: el «Número Nequi» de un envío, o la «Llave» de uno
     * por Bre-B. Null si el comprobante no lo dice (o el lector no lo vio).
     */
    public static function destino(string $texto): ?string
    {
        if (preg_match('/N[uú]mero\s+Nequi\W*(3\d{2}[\s-]?\d{3}[\s-]?\d{4})(?!\d)/iu', $texto, $m)) {
            return preg_replace('/\D/', '', $m[1]);
        }

        // Una llave de verdad tiene números (celular, cédula, código) o es un correo:
        // así no se toma una palabra suelta («llave fue…») como si fuera la cuenta.
        if (preg_match('/\bLlave\b\W*((?=[\w.+-]*\d{5})[\w.+-]{5,}|[\w.+-]+@[\w.-]+)/iu', $texto, $m)) {
            return mb_strtolower($m[1]);
        }

        return null;
    }

    /** La referencia típica a esa hora: la mediana de los aprobados más cercanos en el tiempo. */
    private static function referenciaEsperada(array $puntos, int $minuto): int
    {
        usort($puntos, fn ($a, $b) => abs($a[0] - $minuto) <=> abs($b[0] - $minuto));
        $refs = array_column(array_slice($puntos, 0, self::VECINOS), 1);
        sort($refs);

        return $refs[intdiv(count($refs), 2)];
    }

    private static function clave(int $companyId): string
    {
        return "comprobantes:modelo:{$companyId}";
    }
}
