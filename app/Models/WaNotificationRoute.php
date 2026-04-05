<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaNotificationRoute extends Model
{
    protected $table    = 'wa_notification_routes';
    protected $fillable = ['company_id', 'event_type', 'destination', 'label', 'enabled'];
    protected $casts    = ['enabled' => 'boolean'];
}
