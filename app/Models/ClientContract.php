<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientContract extends Model
{
    protected $fillable = [
        'company_id',
        'contract_id',
        'user_id',
        'status',
        'token',
        'sent_at',
        'sent_channel',
        'opened_at',
        'accepted_at',
        'accept_ip',
        'accept_user_agent',
        'accepted_terms',
        'require_documents',
        'document_front_path',
        'document_back_path',
        'document_number_front',
        'document_number_back',
        'signature',
        'signed_at',
    ];

    protected $casts = [
        'require_documents' => 'boolean',
        'signed_at'         => 'datetime:Y-m-d H:i:s',
        'sent_at'           => 'datetime:Y-m-d H:i:s',
        'opened_at'         => 'datetime:Y-m-d H:i:s',
        'accepted_at'       => 'datetime:Y-m-d H:i:s',
        'created_at'        => 'datetime:Y-m-d H:i:s',
        'updated_at'        => 'datetime:Y-m-d H:i:s',
    ];

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
