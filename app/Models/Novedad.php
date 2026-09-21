<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Una novedad de la plataforma: lo nuevo, lo mejorado o lo arreglado. */
class Novedad extends Model
{
    protected $table = 'novedades';

    protected $fillable = ['titulo', 'detalle', 'tipo', 'modulo', 'ruta', 'publicada_en', 'escrita_por'];

    protected $casts = ['publicada_en' => 'datetime'];

    public function scopePublicadas(Builder $q): Builder
    {
        return $q->whereNotNull('publicada_en')->where('publicada_en', '<=', now());
    }

    /**
     * Las que puede ver quien tiene estos módulos: las generales siempre, y
     * las de un módulo sólo si lo tiene contratado.
     *
     * @param list<string> $modulos
     */
    public function scopeParaModulos(Builder $q, array $modulos): Builder
    {
        return $q->where(fn ($w) => $w->whereNull('modulo')->orWhere('modulo', '')->orWhereIn('modulo', $modulos));
    }
}
