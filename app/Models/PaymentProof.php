<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentProof extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        // De dónde vino: 'whatsapp_web' (los que manda el cliente al número
        // de WhatsApp Web) o 'meta' (los del bot de la API oficial).
        'source',
        'wa_linea_id',
        'user_id',
        'invoice_id',
        'file_path',
        'file_name',
        'file_hash',
        'reported_amount',
        'detected_amount',
        'payment_date',
        'reference_number',
        'bank_name',
        'ocr_text',
        'status',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
        'raw_payload',
    ];

    protected $casts = [
        'reported_amount' => 'float',
        'detected_amount' => 'float',
        'payment_date' => 'date:Y-m-d',
        'raw_payload' => 'array',
        'reviewed_at' => 'datetime:Y-m-d H:i:s',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    /**
     * El titular del comprobante.
     *
     * La clave de user_data es 'user_id', no su propio 'id'. Relacionar contra
     * el id hacía que el panel mostrara un cliente completamente distinto: el
     * comprobante del usuario 2300 aparecía a nombre de quien tuviera
     * user_data.id = 2300, que es otra persona. El dato guardado siempre
     * estuvo bien; lo que estaba mal era cómo se leía.
     */
    public function user()
    {
        return $this->belongsTo(UserData::class, 'user_id', 'user_id');
    }

    public function invoice()
    {
        return $this->belongsTo(DetFacturation::class, 'invoice_id');
    }

    public function audits()
    {
        return $this->hasMany(PaymentProofAudit::class, 'payment_proof_id');
    }
}
