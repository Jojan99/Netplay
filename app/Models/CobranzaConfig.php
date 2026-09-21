<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Cómo cobra el asistente de cartera de una empresa. */
class CobranzaConfig extends Model
{
    protected $table = 'cobranza_configs';

    protected $fillable = [
        'company_id', 'activa', 'modo',
        'min_facturas', 'min_dias_mora', 'max_dias_mora', 'min_monto',
        'descuento_max_pct', 'descuento_dias', 'cuotas_max', 'plazo_max_dias', 'compromiso_suspende',
        'hora_desde', 'hora_hasta', 'dias', 'max_contactos_dia', 'recordatorios', 'horas_entre_recordatorios',
        'nombre_asistente', 'instrucciones', 'wa_linea_id',
        'pago_link', 'pago_qr', 'pago_texto',
        'ia_clave', 'ia_modelos',
    ];

    /** La clave de Google de la empresa nunca sale en una respuesta. */
    protected $hidden = ['ia_clave'];

    protected $casts = [
        'ia_clave'            => 'encrypted',
        'activa'              => 'boolean',
        'compromiso_suspende' => 'boolean',
        'min_monto'           => 'float',
        'pago_link'           => 'boolean',
    ];

    /**
     * Cómo puede pagar el cliente, con lo que tenga cargado la empresa.
     *
     * @return array{link:bool, qr:?string, texto:?string, hay:bool}
     */
    public function mediosDePago(bool $conPasarela): array
    {
        $texto = trim((string) $this->pago_texto) ?: null;
        $qr = trim((string) $this->pago_qr) ?: null;
        $link = $conPasarela && (bool) ($this->pago_link ?? true);

        return ['link' => $link, 'qr' => $qr, 'texto' => $texto, 'hay' => $link || $qr || $texto];
    }

    /** ¿La empresa usa su propia clave de Google? */
    public function tieneClavePropia(): bool
    {
        return trim((string) $this->ia_clave) !== '';
    }

    public static function deEmpresa(int $companyId): self
    {
        return self::firstOrNew(['company_id' => $companyId]);
    }

    /** ¿Ahora es un momento en que se le puede escribir a un cliente? */
    public function enHorario(?\Carbon\Carbon $cuando = null): bool
    {
        $ahora = ($cuando ?? now())->copy()->timezone('America/Bogota');
        $dias = array_filter(array_map('intval', explode(',', (string) $this->dias)));

        if ($dias && !in_array((int) $ahora->dayOfWeekIso, $dias, true)) {
            return false;
        }

        $hora = $ahora->format('H:i');

        return $hora >= (string) $this->hora_desde && $hora < (string) $this->hora_hasta;
    }
}
