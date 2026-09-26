<?php

namespace App\Services\Plataforma;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Qué empresa corresponde al dominio desde el que se abre la plataforma.
 *
 *  netvula.com, www.netvula.com  → la plataforma (página pública y registro)
 *  netplay.netvula.com           → la empresa con subdomain = 'netplay'
 *  cualquier otro dominio        → la plataforma (netplay.com.co, localhost)
 *
 * El frontend y la API se sirven desde el mismo dominio, así que el Host de la
 * petición a la API es el de la página que la hizo.
 */
class EmpresaDelDominio
{
    /** 3 a 40 caracteres, letras minúsculas, números y guiones que no queden en las puntas. */
    public const FORMATO = '/^[a-z0-9](?:[a-z0-9-]{1,38})[a-z0-9]$/';

    /** @var array<string, Company|null> por host, para no consultar dos veces en la misma petición */
    private array $resueltas = [];

    public function dominioBase(): string
    {
        return strtolower((string) config('plataforma.dominio', 'netvula.com'));
    }

    public function activos(): bool
    {
        return (bool) config('plataforma.subdominios_activos', false);
    }

    /** El subdominio del host (sin puerto), o null si es la raíz, www u otro dominio. */
    public function subdominioDe(string $host): ?string
    {
        $host = strtolower(preg_replace('/:\d+$/', '', trim($host)));
        $sufijo = '.' . $this->dominioBase();

        if (!str_ends_with($host, $sufijo)) {
            return null;
        }

        $sub = substr($host, 0, -strlen($sufijo));

        // Un solo nivel: a.b.netvula.com no es de ninguna empresa.
        if ($sub === '' || $sub === 'www' || str_contains($sub, '.')) {
            return null;
        }

        return $sub;
    }

    /** El subdominio pedido por esta petición, aunque no exista la empresa. */
    public function subdominioPedido(Request $request): ?string
    {
        return $this->subdominioDe($request->getHost());
    }

    /**
     * El subdominio de la empresa dueña de un dominio propio (netplay.com.co),
     * según el mapa de config/plataforma.php. Null si el dominio no es de nadie.
     */
    public function porDominioPropio(string $host): ?string
    {
        $host = strtolower(preg_replace('/:\d+$/', '', trim($host)));
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        $sub  = config('plataforma.dominios_propios', [])[$host] ?? null;

        return is_string($sub) && $sub !== '' ? strtolower($sub) : null;
    }

    /**
     * La empresa de la petición: por su subdominio o por su dominio propio.
     * Null en la raíz de la plataforma o si no existe.
     *
     * Sin el dominio propio, el portal abierto en netplay.com.co no sabía de
     * qué empresa era, y un cliente de otra empresa podía entrar ahí: veía sus
     * propios datos, pero con la marca que no era.
     */
    public function empresa(Request $request): ?Company
    {
        $sub = $this->subdominioPedido($request) ?? $this->porDominioPropio($request->getHost());

        if ($sub === null) {
            return null;
        }

        if (!array_key_exists($sub, $this->resueltas)) {
            $this->resueltas[$sub] = Company::where('subdomain', $sub)->first();
        }

        return $this->resueltas[$sub];
    }

    /**
     * La dirección de la empresa: https://sub.netvula.com mientras los
     * subdominios estén activos; si no, APP_URL.
     */
    public function urlDe(Company|int|null $empresa): string
    {
        $raiz = rtrim((string) config('app.url'), '/');

        if (!$this->activos() || !$empresa) {
            return $raiz;
        }

        $sub = $empresa instanceof Company
            ? $empresa->subdomain
            : Company::where('id', $empresa)->value('subdomain');

        return $sub ? 'https://' . $sub . '.' . $this->dominioBase() : $raiz;
    }

    /** Por qué no sirve un subdominio, o null si está libre. */
    public function problemaCon(string $sub, ?int $salvoEmpresaId = null): ?string
    {
        if (!preg_match(self::FORMATO, $sub)) {
            return 'Use de 3 a 40 letras minúsculas, números o guiones (sin guion al principio ni al final).';
        }

        if (str_contains($sub, '--')) {
            return 'No uses dos guiones seguidos.';
        }

        if (in_array($sub, config('plataforma.reservados', []), true)) {
            return 'Esa dirección está reservada. Pruebe con otra.';
        }

        $tomado = Company::where('subdomain', $sub)
            ->when($salvoEmpresaId, fn ($q) => $q->where('id', '!=', $salvoEmpresaId))
            ->exists();

        return $tomado ? 'Esa dirección ya la usa otra empresa.' : null;
    }

    /** Un subdominio libre a partir del nombre de la empresa. */
    public function libreDesde(string $nombre): string
    {
        $base = self::sugerirDesde($nombre);
        $sub  = $base;
        $i    = 2;

        while ($this->problemaCon($sub) !== null) {
            $sub = Str::limit($base, 36, '') . '-' . $i++;
        }

        return $sub;
    }

    /** "Netplay S.A.S." → "netplay". Sin consultar la base. */
    public static function sugerirDesde(string $nombre): string
    {
        $slug = Str::slug($nombre);

        // La razón social del final no aporta: netplay-sas → netplay.
        $slug = preg_replace('/-(s-a-s|sas|s-a|sa|ltda|limitada|e-u|eu|s-en-c|y-cia)$/', '', $slug);
        $slug = trim(Str::limit($slug, 40, ''), '-');

        return strlen($slug) >= 3 ? $slug : 'empresa-' . Str::lower(Str::random(4));
    }

    /**
     * Dónde ver el logo de la empresa, o null si no tiene. El de la factura es
     * una imagen en base64 de hasta 60 KB: se sirve aparte y con caché en vez
     * de viajar en cada respuesta.
     */
    public function logoDe(Company $empresa): ?string
    {
        if ($empresa->logo) {
            return str_starts_with($empresa->logo, 'http') ? $empresa->logo : url('/storage/' . ltrim($empresa->logo, '/'));
        }

        if (!$empresa->invoice_logo_base64 && !$empresa->invoice_logo_url) {
            return null;
        }

        return url('/api/plataforma/logo/' . $empresa->slug) . '?v=' . optional($empresa->updated_at)->timestamp;
    }
}
