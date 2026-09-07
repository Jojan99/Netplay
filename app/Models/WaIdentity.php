<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * El WhatsApp de un cliente, ya comprobado.
 *
 * Guardarlo evita pedirle la cédula —y, a quien escribe sin teléfono, el
 * celular registrado— en cada conversación. La comprobación se hace una vez y
 * vale por un mes.
 */
class WaIdentity extends Model
{
    /**
     * Cuánto dura el reconocimiento.
     *
     * Ni tan corto que vuelva a molestar cada semana, ni tan largo que un
     * número reasignado siga abriendo las facturas de quien lo tenía antes.
     */
    public const DIAS_VIGENCIA = 30;

    protected $fillable = [
        'company_id',
        'sender',
        'user_id',
        'dni',
        'verified_phone',
        'verified_at',
        'expires_at',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
        'expires_at'  => 'datetime',
    ];

    /** El cliente reconocido para este WhatsApp, o null si no hay o ya venció. */
    public static function lookup(int $companyId, string $sender): ?self
    {
        return static::where('company_id', $companyId)
            ->where('sender', $sender)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();
    }

    /** Deja constancia de que este WhatsApp es de este cliente. */
    public static function remember(
        int $companyId,
        string $sender,
        int $userId,
        string $dni,
        ?string $verifiedPhone = null
    ): self {
        return static::updateOrCreate(
            ['company_id' => $companyId, 'sender' => $sender],
            [
                'user_id'        => $userId,
                'dni'            => $dni,
                'verified_phone' => $verifiedPhone,
                'verified_at'    => now(),
                'expires_at'     => now()->addDays(self::DIAS_VIGENCIA),
            ]
        );
    }

    /** Olvida el reconocimiento: el cliente quiere consultar otra cédula. */
    public static function forget(int $companyId, string $sender): void
    {
        static::where('company_id', $companyId)->where('sender', $sender)->delete();
    }

    /** Cédula parcialmente oculta, para poder nombrarla sin exponerla entera. */
    public function maskedDni(): string
    {
        $dni = (string) $this->dni;

        return strlen($dni) <= 4
            ? $dni
            : substr($dni, 0, 2) . str_repeat('*', max(0, strlen($dni) - 4)) . substr($dni, -2);
    }
}
