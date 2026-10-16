<?php

namespace App\Services\Red;

use App\Models\GestionRemota;
use App\Services\Acs\EquiposDelAcs;
use App\Services\Acs\GenieAcs;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Abre la página web de la ONT de un cliente desde el panel, a través del servidor.
 *
 * Los ZTE con firmware de Claro (F680 V6) no dejan entrar al administrador
 * («admin@claro») desde la red local —«Current user is forbidden to login»— y
 * por la WAN sólo atienden si la petición dice ir a 192.168.1.1: con la IP de la
 * WAN en la barra contestan «400 Bad Request». Un navegador no puede cambiar eso;
 * el servidor sí.
 *
 * El recorrido:
 *  1. El operador pide abrir la página en la ficha (abrir): se le da un enlace a
 *     equipos.netvula.com con un código de un solo uso que vence en 2 minutos.
 *  2. Al seguirlo (entrar) el código se cambia por una cookie de sesión del
 *     puente y, en los ZTE, el servidor inicia sesión en el equipo con la clave
 *     de la empresa y le pasa al navegador la cookie de esa sesión.
 *  3. Cada petición a equipos.netvula.com la valida nginx contra destino() y la
 *     reenvía a la ONT con el Host que el equipo espera.
 *
 * El destino sale siempre de la plataforma (la IP WAN que informa el ACS) y sólo
 * puede ser una IP privada: nadie puede usar el puente para llegar a otra parte.
 */
class PaginaDelEquipo
{
    private const CODIGO_VENCE = 120;
    private const SESION_DURA = 1800;
    public const COOKIE = 'np_equipo';

    public function __construct(private int $companyId) {}

    public static function dominio(): string
    {
        return (string) config('services.pagina_equipo.dominio', 'equipos.netvula.com');
    }

    /**
     * El enlace para abrir la página del equipo del cliente.
     *
     * @return array{url: string, equipo: string, ip: string, entra_solo: bool}
     */
    public function abrir(int $userId, int $operador): array
    {
        $acs = GenieAcs::deEmpresa($this->companyId);
        // Si cambiaron el equipo, el viejo sigue en el ACS a nombre del cliente: el que
        // reportó último es el que está instalado.
        $equipo = collect((new EquiposDelAcs($this->companyId, $acs))->lista())
            ->filter(fn ($f) => ($f['cliente']['user_id'] ?? null) === $userId)
            ->sortByDesc(fn ($f) => (string) ($f['ultimo_reporte'] ?? ''))
            ->first();

        if (!$equipo) {
            throw new \InvalidArgumentException('El cliente no tiene un equipo conectado al TR-069.');
        }

        $ip = (string) ($equipo['ip_wan'] ?? '');

        if (!self::esPrivada($ip)) {
            throw new \InvalidArgumentException($ip === ''
                ? 'El equipo no informó la IP de su conexión a internet: no se puede abrir su página.'
                : "La IP del equipo ({$ip}) no es de la red de la empresa: no se abre por el puente.");
        }

        $d = $acs->dispositivo((string) $equipo['id']) ?? [];
        $fabricante = strtoupper((string) ($d['_deviceId']['_Manufacturer'] ?? $equipo['fabricante'] ?? ''));
        $marca = str_contains($fabricante, 'ZTE') ? 'zte' : (str_contains($fabricante, 'HUAWEI') ? 'huawei' : null);

        if ($marca === 'huawei') {
            $this->abrirWanHuawei($acs, (string) $equipo['id']);
        }

        $lan = (string) AprovisionamientoDeOnt::v($d, 'InternetGatewayDevice.LANDevice.1.LANHostConfigManagement.IPInterface.1.IPInterfaceIPAddress');
        $host = filter_var($lan, FILTER_VALIDATE_IP) ? $lan : '192.168.1.1';
        [$esquema, $puerto, $ingreso] = $marca === 'huawei' ? self::comoAtiendeHuawei($ip, $host) : ['http', 80, '/'];

        $codigo = Str::random(48);
        Cache::put("pagina-equipo:codigo:{$codigo}", [
            'company_id' => $this->companyId,
            'user_id'    => $userId,
            'operador'   => $operador,
            'ip'         => $ip,
            'host'       => $host,
            'esquema'    => $esquema,
            'puerto'     => $puerto,
            'ingreso'    => $ingreso,
            'marca'      => $marca,
            'usuario'    => match ($marca) {
                'zte'    => self::administradorZte($d),
                // La que deja el aprovisionamiento (adminisp en Netplay).
                'huawei' => (string) GestionRemota::where('company_id', $this->companyId)->value('onu_admin_usuario'),
                default  => null,
            },
        ], self::CODIGO_VENCE);

        DB::table('user_audit_logs')->insert([
            'user_id' => $userId, 'changed_by' => $operador, 'company_id' => $this->companyId,
            'field_changed' => 'equipo', 'old_value' => null, 'new_value' => $ip,
            'description' => 'Abrió la página web del equipo del cliente desde el panel',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [
            'url'        => 'https://' . self::dominio() . '/__abrir?t=' . $codigo,
            'equipo'     => trim(($equipo['fabricante'] ?? '') . ' ' . ($equipo['modelo'] ?? '')),
            'ip'         => $ip,
            'entra_solo' => $marca !== null,
        ];
    }

    /**
     * Cambia el código por una sesión del puente.
     *
     * @return array{sesion: string, cookies: array<string,string>, ingreso: ?bool, inicio: string}|null
     *         ingreso: true si entró al equipo, false si la clave no sirvió, null si no lo intentó.
     *         inicio: la página del equipo con la que se abre la pestaña.
     */
    public static function entrar(string $codigo): ?array
    {
        $datos = $codigo !== '' ? Cache::pull("pagina-equipo:codigo:{$codigo}") : null;

        // Un solo uso aunque el borrado falle (en la caché de archivos, uno que no es
        // del usuario del servidor web se lee pero no se borra): la marca lo cierra.
        if (!$datos || !Cache::add("pagina-equipo:usado:{$codigo}", 1, self::CODIGO_VENCE)) {
            return null;
        }

        $sesion = Str::random(64);
        Cache::put("pagina-equipo:sesion:{$sesion}", $datos, self::SESION_DURA);

        [$cookies, $ingreso] = match ($datos['marca'] ?? null) {
            'zte'    => self::iniciarSesionZte($datos),
            'huawei' => self::iniciarSesionHuawei($datos),
            default  => [[], null],
        };

        // Por http la raíz de una Huawei manda al navegador a https://…:8080 —su página
        // cifrada—, que no pasa por el puente: se entra directo a la página principal o
        // al ingreso. Por el 8080 (el servidor ya va cifrado) el ingreso es la raíz.
        $inicio = match (true) {
            ($datos['marca'] ?? null) !== 'huawei' => '/',
            (bool) $ingreso                        => '/index.asp',
            default                                => $datos['ingreso'] ?? '/login.asp',
        };

        return ['sesion' => $sesion, 'cookies' => $cookies, 'ingreso' => $ingreso, 'inicio' => $inicio];
    }

    /**
     * A dónde va una petición del puente; null si la sesión no existe o venció.
     * Cada uso la extiende: vence tras media hora sin actividad.
     *
     * @return array{ip: string, host: string, esquema: string, puerto: int}|null
     */
    public static function destino(string $sesion): ?array
    {
        $datos = $sesion !== '' ? Cache::get("pagina-equipo:sesion:{$sesion}") : null;

        if (!$datos || !self::esPrivada((string) $datos['ip'])) {
            return null;
        }

        Cache::put("pagina-equipo:sesion:{$sesion}", $datos, self::SESION_DURA);

        return [
            'ip'      => $datos['ip'],
            'host'    => $datos['host'],
            'esquema' => ($datos['esquema'] ?? 'http') === 'https' ? 'https' : 'http',
            'puerto'  => (int) ($datos['puerto'] ?? 80),
        ];
    }

    /** Sólo redes privadas (RFC 1918 y CGNAT): el puente no sale a internet. */
    public static function esPrivada(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        $n = ip2long($ip);

        foreach ([['10.0.0.0', 8], ['172.16.0.0', 12], ['192.168.0.0', 16], ['100.64.0.0', 10]] as [$red, $bits]) {
            $mascara = -1 << (32 - $bits);

            if (($n & $mascara) === (ip2long($red) & $mascara)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Las Huawei (HG8145V5 de Claro) traen apagada la página web por la WAN
     * (AclServices.HTTPWanEnable) y una lista blanca de orígenes con rangos de Claro:
     * el puente se quedaba esperando y Cloudflare mostraba «504». Se enciende y se
     * agrega a la lista sólo la red del servidor (la VPN por la que llega a las ONT),
     * así que la página sigue cerrada para cualquier otro.
     */
    private function abrirWanHuawei(GenieAcs $acs, string $id): void
    {
        $red = (string) config('services.pagina_equipo.red_servidor', '10.200.200.0/24');
        $acl = 'InternetGatewayDevice.X_HW_Security.AclServices.HTTPWanEnable';
        $lista = 'InternetGatewayDevice.X_HW_Security.WANSrcWhiteList';

        $acs->tarea($id, ['name' => 'refreshObject', 'objectName' => 'InternetGatewayDevice.X_HW_Security'], 60);
        $d = $acs->dispositivo($id) ?? [];
        $habilitada = AprovisionamientoDeOnt::v($d, $acl);

        if ($habilitada === null) {
            return;
        }

        $prefijos = fn (array $d) => collect(AprovisionamientoDeOnt::hijos($d, "{$lista}.List"))
            ->mapWithKeys(fn ($i) => [$i => (string) AprovisionamientoDeOnt::v($d, "{$lista}.List.{$i}.SrcIPPrefix")]);
        $conLista = in_array(AprovisionamientoDeOnt::v($d, "{$lista}.WANSrcWhiteListEnable"), [true, 'true', 1, '1'], true);
        $valores = in_array($habilitada, [true, 'true', 1, '1'], true) ? [] : [[$acl, 'true', 'xsd:boolean']];

        if ($conLista && !$prefijos($d)->contains($red)) {
            $libre = $prefijos($d)->search('');

            if ($libre === false) {
                $acs->tarea($id, ['name' => 'addObject', 'objectName' => "{$lista}.List"], 60);
                $acs->tarea($id, ['name' => 'refreshObject', 'objectName' => $lista], 60);
                $libre = $prefijos($acs->dispositivo($id) ?? [])->search('');
            }

            if ($libre === false) {
                throw new \InvalidArgumentException('La Huawei no dejó agregar el servidor a su lista de acceso por la WAN: no se puede abrir su página.');
            }

            $valores[] = ["{$lista}.List.{$libre}.SrcIPPrefix", $red, 'xsd:string'];
        }

        if (!$valores) {
            return;
        }

        $r = $acs->tarea($id, ['name' => 'setParameterValues', 'parameterValues' => $valores], 60);

        if (!($r['hecha'] ?? false)) {
            throw new \InvalidArgumentException('La Huawei no respondió al habilitar su página por la WAN. Intente de nuevo en un momento.');
        }
    }

    /**
     * Por dónde atiende una Huawei. Hay firmwares que muestran el ingreso por http
     * (puerto 80) y otros que por http sólo devuelven un script que manda al
     * navegador a https://…:8080, que no pasa por el puente: a esos se va cifrado
     * desde el servidor, por el 8080 (HG8145V5) o por el 443 (HG8145X7b, que no
     * abre ni el 80 ni el 8080). Cifrado, el ingreso está en la raíz (login.asp
     * devuelve otra cosa). Se prefiere http: por el 8080 hay firmwares que piden una
     * renegociación TLS insegura que el servidor rechaza.
     *
     * @return array{0: string, 1: int, 2: string} esquema, puerto y página de ingreso
     */
    private static function comoAtiendeHuawei(string $ip, string $host): array
    {
        foreach ([['http', 80, '/login.asp'], ['https', 8080, '/'], ['https', 443, '/']] as [$esquema, $puerto, $ingreso]) {
            $conexion = @fsockopen($ip, $puerto, $errno, $error, 3);

            if (!$conexion) {
                continue;
            }

            fclose($conexion);

            try {
                $html = Http::timeout(8)->withoutVerifying()->withHeaders(['Host' => $host, 'User-Agent' => 'Mozilla/5.0'])
                    ->get("{$esquema}://{$ip}:{$puerto}{$ingreso}")->body();

                if (str_contains($html, 'txt_Username')) {
                    return [$esquema, $puerto, $ingreso];
                }
            } catch (\Throwable) {
            }
        }

        return ['http', 80, '/login.asp'];
    }

    /**
     * Inicia sesión en una Huawei como lo hace su página: pide un número
     * (GetRandCount), manda la clave en base64 y el equipo devuelve la cookie
     * «Cookie=sid=…» si entró. Va por donde atienda el equipo (comoAtiendeHuawei).
     * El equipo bloquea un minuto tras 3 intentos fallidos: se intenta una sola vez.
     *
     * @return array{0: array<string,string>, 1: ?bool}
     */
    private static function iniciarSesionHuawei(array $datos): array
    {
        $clave = (string) GestionRemota::where('company_id', $datos['company_id'])->first()?->onu_admin_clave;

        if ($clave === '' || !($datos['usuario'] ?? '')) {
            return [[], null];
        }

        try {
            $jar = new CookieJar();
            $http = fn () => Http::timeout(10)->withoutVerifying()->withOptions(['cookies' => $jar])
                ->withHeaders(['Host' => $datos['host'], 'User-Agent' => 'Mozilla/5.0']);
            $base = ($datos['esquema'] ?? 'http') . "://{$datos['ip']}:" . ($datos['puerto'] ?? 80);

            $http()->get($base . ($datos['ingreso'] ?? '/login.asp'));
            $numero = trim(str_replace("\xEF\xBB\xBF", '', $http()->post("{$base}/asp/GetRandCount.asp")->body()));
            $jar->setCookie(new \GuzzleHttp\Cookie\SetCookie([
                'Name' => 'Cookie', 'Value' => 'body:Language:english:id=-1', 'Domain' => $datos['ip'], 'Path' => '/',
            ]));

            $http()->asForm()->post("{$base}/login.cgi", [
                'UserName'     => $datos['usuario'],
                'PassWord'     => base64_encode($clave),
                'Language'     => 'english',
                'x.X_HW_Token' => $numero,
            ]);

            // Por http la sesión llega en «Cookie»; cifrado (HG8145X7b), en «CookieHttps».
            $sesion = collect($jar->toArray())
                ->filter(fn ($c) => str_contains((string) $c['Value'], 'sid='))
                ->mapWithKeys(fn ($c) => [$c['Name'] => $c['Value']])
                ->all();

            return $sesion ? [$sesion, true] : [[], false];
        } catch (\Throwable) {
            return [[], null];
        }
    }

    /** «admin@claro» en firmware de Claro; «admin» en los demás. */
    private static function administradorZte(array $d): string
    {
        foreach (AprovisionamientoDeOnt::hijos($d, 'InternetGatewayDevice.User') as $i) {
            $usuario = (string) AprovisionamientoDeOnt::v($d, "InternetGatewayDevice.User.{$i}.Username");

            if (preg_match('/^admin(@|$)/i', $usuario)) {
                return $usuario;
            }
        }

        return 'admin';
    }

    /**
     * Inicia sesión en un ZTE como lo hace su página: pide un número al equipo y
     * manda sha256(clave + número). El equipo ata la sesión a su cookie, no a la
     * IP, y todo el tráfico del puente sale del servidor: la cookie le sirve al
     * navegador tal cual.
     *
     * @return array{0: array<string,string>, 1: ?bool}
     */
    private static function iniciarSesionZte(array $datos): array
    {
        $clave = (string) GestionRemota::where('company_id', $datos['company_id'])->first()?->onu_admin_clave;

        if ($clave === '') {
            return [[], null];
        }

        try {
            $jar = new CookieJar();
            $http = fn () => Http::timeout(10)->withOptions(['cookies' => $jar])
                ->withHeaders(['Host' => $datos['host'], 'User-Agent' => 'Mozilla/5.0']);
            $base = "http://{$datos['ip']}";

            $http()->get("{$base}/");
            $sesion = (string) ($http()->get("{$base}/?_type=loginData&_tag=login_entry")->json('sess_token') ?? '');
            $numero = trim(strip_tags($http()->get("{$base}/?_type=loginData&_tag=login_token")->body()));

            $r = $http()->asForm()->post("{$base}/?_type=loginData&_tag=login_entry", [
                'action'        => 'login',
                'Password'      => hash('sha256', $clave . $numero),
                'Username'      => $datos['usuario'] ?? 'admin',
                '_sessionTOKEN' => $sesion,
            ])->json();

            if (!($r['login_need_refresh'] ?? false)) {
                return [[], false];
            }

            $cookies = [];

            foreach ($jar->toArray() as $c) {
                $cookies[$c['Name']] = $c['Value'];
            }

            return [$cookies, true];
        } catch (\Throwable) {
            return [[], null];
        }
    }
}
