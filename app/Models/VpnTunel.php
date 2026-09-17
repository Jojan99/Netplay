<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VpnTunel extends Model
{
    protected $table = 'vpn_tuneles';

    protected $fillable = [
        'company_id', 'nombre', 'router_id',
        'clave_privada', 'clave_publica', 'clave_compartida',
        'ip_tunel', 'redes_remotas', 'traducciones', 'puerto_router', 'keepalive',
        'activo', 'ultimo_saludo', 'bytes_rx', 'bytes_tx', 'aplicado_en', 'notas',
    ];

    protected $casts = [
        'redes_remotas'    => 'array',
        // [{real, virtual}]: redes de este router que chocan con las de otra
        // empresa y se publican en la VPN con otra dirección (NETMAP en el router).
        'traducciones'     => 'array',
        'clave_privada'    => 'encrypted',
        'clave_compartida' => 'encrypted',
        'activo'           => 'boolean',
        'ultimo_saludo'    => 'datetime',
        'aplicado_en'      => 'datetime',
        'puerto_router'    => 'integer',
        'keepalive'        => 'integer',
    ];

    /**
     * Las claves del router no se devuelven en los listados: sólo al momento
     * de entregar el script, que es la única vez que el operador las necesita.
     */
    protected $hidden = ['clave_privada', 'clave_compartida'];

    /** ¿Saludó hace poco? WireGuard renueva cada dos minutos como máximo. */
    public function getConectadoAttribute(): bool
    {
        return $this->ultimo_saludo !== null
            && $this->ultimo_saludo->gt(now()->subMinutes(3));
    }

    protected $appends = ['conectado'];

    /** La red virtual con la que se publica esa red real, o null si no se traduce. */
    public function virtualDe(string $real): ?string
    {
        foreach ($this->traducciones ?? [] as $t) {
            if (($t['real'] ?? null) === $real) {
                return $t['virtual'];
            }
        }

        return null;
    }

    /** La red real detrás de una red virtual, o la misma red si no se traduce. */
    public function realDe(string $red): string
    {
        foreach ($this->traducciones ?? [] as $t) {
            if (($t['virtual'] ?? null) === $red) {
                return $t['real'];
            }
        }

        return $red;
    }

    /**
     * La IP con la que la plataforma llega a un equipo de este router.
     *
     * Si el equipo está en una red traducida, la virtual que le corresponde
     * (192.168.5.5 → 10.250.0.5); si no, la misma IP.
     */
    public function ipAlcanzable(string $ip): string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $ip;
        }

        foreach ($this->traducciones ?? [] as $t) {
            [$base, $bits] = explode('/', $t['real']);
            $mascara = (int) $bits === 0 ? 0 : (-1 << (32 - (int) $bits)) & 0xFFFFFFFF;

            if ((ip2long($ip) & $mascara) === (ip2long($base) & $mascara)) {
                [$virtual] = explode('/', $t['virtual']);

                return long2ip(ip2long($virtual) | (ip2long($ip) & ~$mascara & 0xFFFFFFFF));
            }
        }

        return $ip;
    }
}
