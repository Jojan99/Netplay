<?php

namespace App\Models\Concerns;

use DateTimeInterface;

/**
 * Entrega «created_at» y «updated_at» en la hora de la empresa, no en UTC.
 *
 * Laravel serializa las fechas del modelo a UTC, y el cast
 * 'datetime:Y-m-d H:i:s' las reescribía después sin la marca de zona: el panel
 * recibía «04:19:01» a secas y lo mostraba como hora de Colombia, cinco horas
 * adelante. Conservando el desfase al serializar, ese mismo cast devuelve la
 * hora local, que es lo que las pantallas siempre asumieron.
 */
trait FechasEnHoraLocal
{
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d\TH:i:sP');
    }
}
