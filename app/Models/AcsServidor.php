<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * El servidor TR-069 de una empresa: el de la plataforma o el suyo propio.
 *
 * `redes` guarda lo detectado en su router con nombre entendible, para no
 * pedirle al operador que escriba rangos en CIDR.
 */
class AcsServidor extends Model
{
    protected $table = 'acs_servidores';

    protected $fillable = [
        'company_id', 'modo', 'host', 'puerto_cwmp', 'url_nbi', 'alcance',
        'vpn_tunel_id', 'router_id', 'redes', 'detectado_en', 'aplicado_en', 'activo', 'notas',
    ];

    protected $casts = [
        'redes'        => 'array',
        'detectado_en' => 'datetime',
        'aplicado_en'  => 'datetime',
        'activo'       => 'boolean',
    ];

    public function esPropio(): bool
    {
        return $this->modo === 'propio';
    }

    /** La URL que se configura en las ONT. */
    public function urlCwmp(): string
    {
        if (!$this->esPropio()) {
            return (string) config('services.genieacs.cwmp_url');
        }

        return 'http://' . $this->host . ':' . ($this->puerto_cwmp ?: 7547);
    }

    /** La API con la que la plataforma habla con ese servidor. */
    public function urlNbi(): string
    {
        if (!$this->esPropio()) {
            return (string) config('services.genieacs.nbi');
        }

        return $this->url_nbi ?: ('http://' . $this->host . ':7557');
    }
}
