<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Lectura de la configuración del CRM con valores por defecto y utilidades de horario. */
class CrmSettings
{
    public const DEFAULT_WELCOME = "👋 Hola, gracias por contactar a *Netplay*.\n\nEn breve uno de nuestros asesores continuará la conversación contigo.";
    public const DEFAULT_OFF_HOURS = "🕒 Gracias por escribirnos. En este momento estamos fuera del horario de atención; te responderemos apenas retomemos. Si es una emergencia del servicio, dejanos el detalle y lo priorizamos.";

    public static function for(int $companyId): array
    {
        $row = DB::table('crm_settings')->where('company_id', $companyId)->first();
        return [
            'auto_assign'        => (bool)($row->auto_assign ?? false),
            'off_hours_enabled'  => (bool)($row->off_hours_enabled ?? false),
            'business_days'      => $row && $row->business_days ? json_decode($row->business_days, true) : [1, 2, 3, 4, 5, 6],
            'open_time'          => $row->open_time ?? '08:00',
            'close_time'         => $row->close_time ?? '18:00',
            'off_hours_message'  => $row->off_hours_message ?? null,
            'welcome_message'    => $row->welcome_message ?? null,
            'wait_alert_minutes' => (int)($row->wait_alert_minutes ?? 15),
            'timezone'           => $row->timezone ?? 'America/Bogota',
        ];
    }

    /** ¿Estamos dentro del horario de atención? */
    public static function isOpenNow(array $s, ?Carbon $now = null): bool
    {
        $now = ($now ?? Carbon::now())->copy()->setTimezone($s['timezone'] ?: 'America/Bogota');
        if (!in_array($now->isoWeekday(), $s['business_days'] ?? [], true) && !in_array((string)$now->isoWeekday(), $s['business_days'] ?? [], true)) return false;
        $hm = $now->format('H:i');
        $open = $s['open_time'] ?: '08:00'; $close = $s['close_time'] ?: '18:00';
        return $open <= $close ? ($hm >= $open && $hm < $close) : ($hm >= $open || $hm < $close); // soporta horarios que cruzan medianoche
    }
}
