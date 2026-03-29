<?php
namespace App\Models;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CrmAgent extends Model
{
    protected $table = 'crm_agents';

    protected $fillable = [
        'user_id',
        'active',
        'max_chats'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
