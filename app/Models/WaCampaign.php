<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un envío de plantilla a muchos clientes.
 *
 * Cada mensaje se le cobra a la empresa, así que un envío no se dispara y se
 * olvida: nace como borrador, exige una prueba vista y solo entonces se manda.
 */
class WaCampaign extends Model
{
    protected $table = 'wa_campaigns';

    protected $fillable = [
        'company_id', 'created_by', 'name', 'template_name', 'language',
        'params', 'audience', 'excluded_user_ids',
        'recipients_count', 'sent_count', 'failed_count', 'status',
        'test_phone', 'test_sent_at', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'params'            => 'array',
        'audience'          => 'array',
        'excluded_user_ids' => 'array',
        'test_sent_at'      => 'datetime',
        'started_at'        => 'datetime',
        'finished_at'       => 'datetime',
    ];

    public const BORRADOR  = 'draft';
    public const PROBADO   = 'tested';
    public const ENVIANDO  = 'sending';
    public const TERMINADO = 'done';
    public const CANCELADO = 'cancelled';

    public function recipients(): HasMany
    {
        return $this->hasMany(WaCampaignRecipient::class, 'campaign_id');
    }

    /**
     * ¿Se puede mandar ya?
     *
     * Exige una prueba vista: es la única forma de que nadie descubra una
     * variable mal puesta después de haberle escrito a novecientas personas.
     */
    public function listoParaEnviar(): bool
    {
        return in_array($this->status, [self::BORRADOR, self::PROBADO], true)
            && $this->test_sent_at !== null;
    }

    public function enCurso(): bool
    {
        return $this->status === self::ENVIANDO;
    }
}
