<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** El panel XUI.one de una empresa: a dónde se le piden las líneas y los canales. */
class TvPanel extends Model
{
    protected $table = 'tv_paneles';

    protected $fillable = [
        'company_id', 'url', 'codigo_api', 'api_key', 'url_clientes', 'bouquets', 'pantallas', 'clave_fija', 'monitor_xui_id', 'monitor_usuario', 'monitor_clave',
        'sincronizar_cartera', 'ultima_conexion_en', 'ultimo_error',
    ];

    protected $hidden = ['api_key', 'monitor_clave'];

    protected $casts = [
        'api_key'             => 'encrypted',
        'monitor_clave'       => 'encrypted',
        'bouquets'            => 'array',
        'sincronizar_cartera' => 'boolean',
        'ultima_conexion_en'  => 'datetime',
    ];

    /**
     * ¿La empresa tiene televisión? Sin panel conectado no aparece nada de TV: ni el menú, ni la
     * tarjeta de la ficha, ni el permiso, ni el aviso de canales, ni la TV en las instalaciones.
     */
    public static function activa(?int $companyId): bool
    {
        return $companyId && self::where('company_id', $companyId)->exists();
    }

    /** Quita «television» de una lista de módulos si la empresa no tiene panel. */
    public static function filtrarModulos(?int $companyId, iterable $modulos): array
    {
        $lista = collect($modulos)->values()->all();

        return self::activa($companyId) ? $lista : array_values(array_filter($lista, fn ($m) => (is_array($m) ? ($m['module'] ?? null) : $m) !== 'television'));
    }

    public static function deLaEmpresa(int $companyId): ?self
    {
        return self::where('company_id', $companyId)->first();
    }

    /** La dirección que va en la lista M3U y en el código Xtream del cliente. */
    public function urlClientes(): string
    {
        $url = rtrim($this->url_clientes ?: $this->url, '/');

        // Como las arma el XUI: con el puerto aunque sea el 80 (algunas apps lo exigen).
        if (!parse_url($url, PHP_URL_PORT)) {
            $url .= parse_url($url, PHP_URL_SCHEME) === 'https' ? ':443' : ':80';
        }

        return $url;
    }
}
