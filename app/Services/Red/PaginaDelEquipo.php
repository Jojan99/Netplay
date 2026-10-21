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
    private const SAGEMCOM_ABIERTOS = 'pagina-equipo:sagemcom-abiertos';

    public function __construct(private int $companyId) {}

    public static function dominio(): string
    {
        return (string) config('services.pagina_equipo.dominio', 'equipos.netvula.com');
    }

    /**
     * Con qué usuario y clave se entra a la página del equipo del cliente, para que el
     * técnico no tenga que adivinar: cambia según la marca y el firmware (lo que deja el
     * aprovisionamiento). La clave es la de la empresa (Gestión remota). Queda registrado
     * en el historial del cliente quién la consultó.
     *
     * @return array{equipo: string, usuario: string, clave: string, nota: ?string, local: ?array{usuario: string, nota: string}}
     */
    public function credenciales(int $userId, int $operador): array
    {
        $acs = GenieAcs::deEmpresa($this->companyId);
        $equipo = collect((new EquiposDelAcs($this->companyId, $acs))->lista())
            ->filter(fn ($f) => ($f['cliente']['user_id'] ?? null) === $userId)
            ->sortByDesc(fn ($f) => (string) ($f['ultimo_reporte'] ?? ''))
            ->first();

        if (!$equipo) {
            throw new \InvalidArgumentException('El cliente no tiene un equipo conectado al TR-069.');
        }

        $g = GestionRemota::where('company_id', $this->companyId)->first();

        if (!$g?->onu_admin_clave) {
            throw new \InvalidArgumentException('La empresa no tiene configurada la clave de administración de las ONT (Gestión remota).');
        }

        $d = $acs->dispositivo((string) $equipo['id']) ?? [];
        $fabricante = strtoupper((string) ($d['_deviceId']['_Manufacturer'] ?? $equipo['fabricante'] ?? ''));
        $empresa = (string) ($g->onu_admin_usuario ?: 'adminisp');

        [$usuario, $nota, $local] = match (true) {
            str_contains($fabricante, 'ZTE') => (function () use ($d) {
                $admin = self::administradorZte($d);
                // En firmware de Claro el administrador sólo entra por la WAN; en la casa se
                // usa el básico «Wifi@…» (el aprovisionamiento le pone la misma clave).
                $basico = collect(AprovisionamientoDeOnt::hijos($d, 'InternetGatewayDevice.User'))
                    ->map(fn ($i) => (string) AprovisionamientoDeOnt::v($d, "InternetGatewayDevice.User.{$i}.Username"))
                    ->first(fn ($u) => (bool) preg_match('/^wifi@/i', $u));

                return [$admin, str_contains($admin, '@') ? 'Este usuario sólo entra a distancia (botón «Abrir página del equipo»).' : null,
                    $basico ? ['usuario' => $basico, 'nota' => 'En la casa del cliente (red local), con la misma clave.'] : null];
            })(),
            str_contains($fabricante, 'SAGEM') => ['admin', 'En Sagemcom la página sólo acepta el nombre «admin».', null],
            // Firmware de Claro: admin@XXXX / Wifi@XXXX, con la clave alargada (ver claveLarga).
            str_contains($fabricante, 'SKYWORTH') => (function () use ($d, $empresa) {
                $nombres = collect(AprovisionamientoDeOnt::hijos($d, 'Device.Users.User'))
                    ->map(fn ($u) => (string) AprovisionamientoDeOnt::v($d, "Device.Users.User.{$u}.Username"));
                $admin = $nombres->first(fn ($u) => (bool) preg_match('/^admin@/i', $u));
                $basico = $nombres->first(fn ($u) => (bool) preg_match('/^wifi@/i', $u));

                return [$admin ?: $empresa, null,
                    $basico ? ['usuario' => $basico, 'nota' => 'También entra con la misma clave.'] : null];
            })(),
            default => [$empresa, null, null],
        };

        $clave = str_contains($fabricante, 'SKYWORTH')
            ? AprovisionamientoDeOnt::claveLarga((string) $g->onu_admin_clave)
            : (string) $g->onu_admin_clave;

        DB::table('user_audit_logs')->insert([
            'user_id' => $userId, 'changed_by' => $operador, 'company_id' => $this->companyId,
            'field_changed' => 'equipo', 'old_value' => null, 'new_value' => null,
            'description' => 'Consultó el usuario y la clave de administración del equipo',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [
            'equipo'  => trim(($equipo['fabricante'] ?? '') . ' ' . ($equipo['modelo'] ?? '')),
            'usuario' => $usuario,
            'clave'   => $clave,
            'nota'    => $nota,
            'local'   => $local,
        ];
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
        // Skyworth de Claro (GN630V): con X_SKYW_ACL.WanHTTPS el 443 abre por la WAN, pero
        // rechaza todo saludo TLS (TLS 1.0 a 1.3, cualquier cifrado: «handshake failure»)
        // y por HTTP no atiende (Prueba_TR, 19-10). Sólo se entra desde la casa.
        if (str_contains($fabricante, 'SKYWORTH')) {
            $lan = self::ipLanTr181($d) ?: '192.168.40.1';
            $cred = $this->credenciales($userId, $operador);

            throw new \InvalidArgumentException("Este Skyworth no deja abrir su página a distancia (su firmware lo bloquea). "
                . "En la casa del cliente: https://{$lan} con el usuario {$cred['usuario']} y la clave de la ficha («Usuario y clave»).");
        }

        $marca = match (true) {
            str_contains($fabricante, 'ZTE')    => 'zte',
            str_contains($fabricante, 'HUAWEI') => 'huawei',
            str_contains($fabricante, 'SAGEM')  => 'sagemcom',
            default                             => null,
        };

        if ($marca === 'huawei') {
            $this->abrirWanHuawei($acs, (string) $equipo['id']);
        } elseif ($marca === 'zte') {
            $this->abrirWanZte($acs, (string) $equipo['id'], $ip);
        } elseif ($marca === 'sagemcom') {
            $this->abrirWanSagemcom($acs, (string) $equipo['id']);
        }

        $lan = (string) (AprovisionamientoDeOnt::v($d, 'InternetGatewayDevice.LANDevice.1.LANHostConfigManagement.IPInterface.1.IPInterfaceIPAddress')
            ?? self::ipLanTr181($d));
        $host = filter_var($lan, FILTER_VALIDATE_IP) ? $lan : '192.168.1.1';
        // El Sagemcom manda todo a https://<LAN>/2.0/gui/: el servidor entra cifrado por el 443.
        [$esquema, $puerto, $ingreso] = match ($marca) {
            'huawei'   => self::comoAtiendeHuawei($ip, $host),
            'sagemcom' => ['https', 443, '/2.0/gui/'],
            default    => ['http', 80, '/'],
        };

        // Cada firmware revisa distinto a qué dirección dice ir la petición: los ZTE de
        // Claro (F680) sólo aceptan la de la casa (192.168.1.1) y el ZTE F6601P sólo su
        // propia IP; con la otra contestan «400 Bad Request» (Prueba_TR, 19-10). Se usa
        // la que el equipo acepte.
        $host = self::hostQueAcepta($esquema, $ip, $puerto, $ingreso, [$host, $ip]);

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
            'acs_id'     => (string) $equipo['id'],
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
            // El Sagemcom abre en su pantalla de ingreso: su inicio de sesión todavía no se automatiza.
            'entra_solo' => in_array($marca, ['zte', 'huawei'], true),
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
            ($datos['marca'] ?? null) === 'sagemcom' => $datos['ingreso'] ?? '/2.0/gui/',
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

        if (!empty($datos['acs_id'])) {
            Cache::put("pagina-equipo:uso:{$datos['acs_id']}", now()->timestamp, self::SESION_DURA * 2);
        }

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

    /**
     * Los ZTE F670L sólo dejan entrar a la página por la conexión «Gestion» (regla
     * FWSC1 del control de servicios); por el PPPoE el puerto 80 está cerrado y el puente
     * se quedaba esperando. Se agrega una regla para la conexión que tiene la IP del
     * equipo, sólo desde la red del servidor. Los F680 de Claro ya la traen abierta.
     */
    private function abrirWanZte(GenieAcs $acs, string $id, string $ip): void
    {
        $red = (string) config('services.pagina_equipo.red_servidor', '10.200.200.0/24');
        [$inicio, $fin] = self::extremosDeRed($red);
        $reglas = 'InternetGatewayDevice.Firewall.X_ZTE-COM_ServiceControl.IPV4ServiceControl';

        try {
            $acs->tarea($id, ['name' => 'refreshObject', 'objectName' => 'InternetGatewayDevice.Firewall.X_ZTE-COM_ServiceControl'], 60);
            $d = $acs->dispositivo($id) ?? [];
        } catch (\Throwable) {
            return;
        }

        if (AprovisionamientoDeOnt::v($d, "{$reglas}.1.Mode") === null) {
            return;   // este firmware no publica el control de servicios: nada que abrir
        }

        // La conexión por la que se entra: la que tiene la IP a la que va el puente.
        $conexion = null;
        foreach (AprovisionamientoDeOnt::hijos($d, 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice') as $g) {
            foreach (['WANPPPConnection', 'WANIPConnection'] as $tipo) {
                foreach (AprovisionamientoDeOnt::hijos($d, "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.{$g}.{$tipo}") as $i) {
                    $ruta = "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.{$g}.{$tipo}.{$i}";
                    if ((string) AprovisionamientoDeOnt::v($d, "{$ruta}.ExternalIPAddress") === $ip) {
                        $conexion = $ruta;
                    }
                }
            }
        }

        if (!$conexion) {
            return;
        }

        foreach (AprovisionamientoDeOnt::hijos($d, $reglas) as $i) {
            $tiene = (string) AprovisionamientoDeOnt::v($d, "{$reglas}.{$i}.Ingress") === $conexion
                && str_contains((string) AprovisionamientoDeOnt::v($d, "{$reglas}.{$i}.ServiceType"), 'HTTP')
                && (string) AprovisionamientoDeOnt::v($d, "{$reglas}.{$i}.Mode") === 'Accept'
                && in_array(AprovisionamientoDeOnt::v($d, "{$reglas}.{$i}.Enable"), [true, 'true', 1, '1'], true);

            if ($tiene) {
                return;
            }
        }

        $antes = AprovisionamientoDeOnt::hijos($d, $reglas);
        $acs->tarea($id, ['name' => 'addObject', 'objectName' => $reglas], 60);
        $acs->tarea($id, ['name' => 'refreshObject', 'objectName' => $reglas], 60);
        $nueva = array_values(array_diff(AprovisionamientoDeOnt::hijos($acs->dispositivo($id) ?? [], $reglas), $antes))[0] ?? null;

        if ($nueva === null) {
            throw new \InvalidArgumentException('El ZTE no dejó agregar el acceso del servidor a su página: no se puede abrir.');
        }

        $b = "{$reglas}.{$nueva}";
        $r = $acs->tarea($id, ['name' => 'setParameterValues', 'parameterValues' => [
            ["{$b}.Name", 'Netvula puente', 'xsd:string'],
            ["{$b}.Ingress", $conexion, 'xsd:string'],
            ["{$b}.IPStart", $inicio, 'xsd:string'],
            ["{$b}.IPEnd", $fin, 'xsd:string'],
            ["{$b}.ServiceType", 'HTTP,HTTPS,PING', 'xsd:string'],
            ["{$b}.Mode", 'Accept', 'xsd:string'],
            ["{$b}.Enable", 'true', 'xsd:boolean'],
        ]], 90);

        if (!($r['hecha'] ?? false)) {
            throw new \InvalidArgumentException('El ZTE no respondió al abrir su página por la WAN. Intente de nuevo en un momento.');
        }
    }

    /**
     * Sagemcom (TR-181, Fast 5670): cada usuario tiene su acceso remoto por servicio
     * (Users.User.N.RemoteAccess.M: HTTP, HTTPS…) con WANRestriction. De fábrica está en
     * ACCESS_DISABLE y la página no contesta por el PPPoE.
     *
     * Su lista HostRestriction es de IP BLOQUEADAS, no permitidas (probado en Prueba_TR,
     * 19-10: con 0.0.0.0/0 nadie entra; con la red del servidor, justo el servidor quedaba
     * afuera con XMO_ACCESS_RESTRICTION_ERR), así que no sirve para dejarla sólo al
     * servidor. Se abre la WAN del usuario «admin» sólo mientras se usa el puente y la
     * tarea pagina-equipo:cerrar la vuelve a cerrar tras media hora sin uso.
     */
    private function abrirWanSagemcom(GenieAcs $acs, string $id): void
    {
        try {
            $acs->tarea($id, ['name' => 'refreshObject', 'objectName' => 'Device.Users'], 60);
            $d = $acs->dispositivo($id) ?? [];
        } catch (\Throwable) {
            return;
        }

        $valores = self::accesoWanSagemcom($d, 'ACCESS_ENABLE');

        if ($valores && !($acs->tarea($id, ['name' => 'setParameterValues', 'parameterValues' => $valores], 90)['hecha'] ?? false)) {
            throw new \InvalidArgumentException('El Sagemcom no respondió al abrir su página por la WAN. Intente de nuevo en un momento.');
        }

        // Para cerrarla después (ver cerrarSagemcomSinUso).
        $abiertos = Cache::get(self::SAGEMCOM_ABIERTOS, []);
        $abiertos[$id] = $this->companyId;
        Cache::forever(self::SAGEMCOM_ABIERTOS, $abiertos);
        Cache::put("pagina-equipo:uso:{$id}", now()->timestamp, self::SESION_DURA * 2);
    }

    /**
     * Cambia el nombre de las redes WiFi de un Sagemcom por su página (JSON-RPC «XMO»).
     *
     * El firmware de Tigo (Fast5670_MIL) tiene el WiFi en sólo lectura por TR-069, pero
     * la página sí deja cambiar el nombre a «admin» (SMBS005C3459, 19-10). La clave no:
     * a «admin» le esconde el campo. Se abre la página por la WAN sólo para esto, se
     * verifica leyendo de vuelta y se cierra.
     *
     * @param list<int> $indices los SSID (TR-181) a cambiar; en XMO son el mismo uid
     * @return array{ok:bool, detalle:string}
     */
    public function nombreWifiSagemcom(GenieAcs $acs, string $id, string $ssid, array $indices): array
    {
        $clave = (string) GestionRemota::where('company_id', $this->companyId)->value('onu_admin_clave');

        if ($clave === '' || !$indices) {
            return ['ok' => false, 'detalle' => 'sin clave de administración de la empresa'];
        }

        try {
            $this->abrirWanSagemcom($acs, $id);
            $acs->tarea($id, ['name' => 'refreshObject', 'objectName' => 'Device.PPP.Interface'], 60);
            $d = $acs->dispositivo($id) ?? [];
        } catch (\Throwable $e) {
            return ['ok' => false, 'detalle' => 'no se pudo abrir la página del equipo'];
        }

        $ip = collect(AprovisionamientoDeOnt::hijos($d, 'Device.PPP.Interface'))
            ->map(fn ($i) => (string) AprovisionamientoDeOnt::v($d, "Device.PPP.Interface.{$i}.IPCP.LocalIPAddress"))
            ->first(fn ($v) => $v !== '' && $v !== '0.0.0.0');

        try {
            if (!$ip) {
                return ['ok' => false, 'detalle' => 'el equipo no tiene IP de internet'];
            }

            $xmo = new XmoSagemcom("https://{$ip}", 'admin', $clave);

            if (!$xmo->entrar()) {
                return ['ok' => false, 'detalle' => 'la página no aceptó la cuenta admin de la empresa'];
            }

            foreach ($indices as $n) {
                $xmo->poner("Device/WiFi/SSIDs/SSID[@uid='{$n}']/SSID", $ssid);
            }

            $quedo = collect($indices)->every(fn ($n) => $xmo->leer("Device/WiFi/SSIDs/SSID[@uid='{$n}']/SSID") === $ssid);
            $xmo->salir();

            return $quedo
                ? ['ok' => true, 'detalle' => 'nombre cambiado por la página del equipo']
                : ['ok' => false, 'detalle' => 'la página no guardó el nombre'];
        } catch (\Throwable) {
            return ['ok' => false, 'detalle' => 'la página del equipo no respondió'];
        } finally {
            $this->cerrarWanSagemcom($acs, $id);
        }
    }

    /** Cierra en el acto la página por la WAN que abrió nombreWifiSagemcom. */
    private function cerrarWanSagemcom(GenieAcs $acs, string $id): void
    {
        try {
            $acs->tarea($id, ['name' => 'refreshObject', 'objectName' => 'Device.Users'], 60);
            $valores = self::accesoWanSagemcom($acs->dispositivo($id) ?? [], 'ACCESS_DISABLE');

            if (!$valores || ($acs->tarea($id, ['name' => 'setParameterValues', 'parameterValues' => $valores], 90)['hecha'] ?? false)) {
                $abiertos = Cache::get(self::SAGEMCOM_ABIERTOS, []);
                unset($abiertos[$id]);
                Cache::forever(self::SAGEMCOM_ABIERTOS, $abiertos);
            }
        } catch (\Throwable) {
            // Queda en la lista: la tarea pagina-equipo:cerrar lo cierra.
        }
    }

    /**
     * Los valores que dejan el acceso web por la WAN del usuario «admin» como $estado,
     * sólo los que hacen falta cambiar. No usa HostRestriction: en este firmware bloquea
     * (ver abrirWanSagemcom).
     *
     * @return list<array{0:string,1:string,2:string}>
     */
    private static function accesoWanSagemcom(array $d, string $estado): array
    {
        $admin = collect(AprovisionamientoDeOnt::hijos($d, 'Device.Users.User'))
            ->first(fn ($u) => strtolower((string) AprovisionamientoDeOnt::v($d, "Device.Users.User.{$u}.Username")) === 'admin');

        if ($admin === null) {
            return [];
        }

        $valores = [];
        foreach (AprovisionamientoDeOnt::hijos($d, "Device.Users.User.{$admin}.RemoteAccess") as $r) {
            $b = "Device.Users.User.{$admin}.RemoteAccess.{$r}";
            if (in_array((string) AprovisionamientoDeOnt::v($d, "{$b}.Service"), ['HTTP', 'HTTPS'], true)
                && (string) AprovisionamientoDeOnt::v($d, "{$b}.WANRestriction") !== $estado) {
                $valores[] = ["{$b}.WANRestriction", $estado, 'xsd:string'];
            }
        }

        return $valores;
    }

    /**
     * Cierra la página por la WAN de los Sagemcom que nadie usa por el puente hace media
     * hora. La corre la tarea programada pagina-equipo:cerrar.
     *
     * @return int cuántos cerró
     */
    public static function cerrarSagemcomSinUso(): int
    {
        $abiertos = Cache::get(self::SAGEMCOM_ABIERTOS, []);
        $cerrados = 0;

        foreach ($abiertos as $id => $companyId) {
            $uso = (int) Cache::get("pagina-equipo:uso:{$id}", 0);
            if ($uso && now()->timestamp - $uso < self::SESION_DURA) {
                continue;
            }

            try {
                $acs = GenieAcs::deEmpresa((int) $companyId);
                $acs->tarea($id, ['name' => 'refreshObject', 'objectName' => 'Device.Users'], 60);
                $valores = self::accesoWanSagemcom($acs->dispositivo($id) ?? [], 'ACCESS_DISABLE');

                if (!$valores || ($acs->tarea($id, ['name' => 'setParameterValues', 'parameterValues' => $valores], 90)['hecha'] ?? false)) {
                    unset($abiertos[$id]);
                    $cerrados++;
                }
            } catch (\Throwable) {
                // Se reintenta en la próxima vuelta.
            }
        }

        Cache::forever(self::SAGEMCOM_ABIERTOS, $abiertos);

        return $cerrados;
    }

    /** La IP de la LAN en un equipo TR-181: la de la interfaz IP que no tiene NAT ni PPP. */
    private static function ipLanTr181(array $d): ?string
    {
        foreach (AprovisionamientoDeOnt::hijos($d, 'Device.IP.Interface') as $i) {
            $alias = strtoupper((string) AprovisionamientoDeOnt::v($d, "Device.IP.Interface.{$i}.Alias"));
            if (!str_contains($alias, 'LAN') || str_contains($alias, 'GUEST')) {
                continue;
            }
            $ip = (string) AprovisionamientoDeOnt::v($d, "Device.IP.Interface.{$i}.IPv4Address.1.IPAddress");
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return null;
    }

    /**
     * La primera dirección (cabecera Host) con la que el equipo no contesta 400; si
     * ninguna se puede probar, la primera de la lista.
     *
     * @param list<string> $candidatos
     */
    private static function hostQueAcepta(string $esquema, string $ip, int $puerto, string $ruta, array $candidatos): string
    {
        $candidatos = array_values(array_unique(array_filter($candidatos)));

        foreach ($candidatos as $host) {
            try {
                $codigo = Http::timeout(6)->withoutVerifying()->withoutRedirecting()
                    ->withHeaders(['Host' => $host, 'User-Agent' => 'Mozilla/5.0'])
                    ->get("{$esquema}://{$ip}:{$puerto}{$ruta}")->status();
            } catch (\Throwable) {
                return $candidatos[0];   // el equipo no contesta: da igual cuál, el aviso será el mismo
            }

            if ($codigo !== 400) {
                return $host;
            }
        }

        return $candidatos[0];
    }

    /** «10.200.200.0/24» → ['10.200.200.1', '10.200.200.254']. */
    private static function extremosDeRed(string $red): array
    {
        [$base, $bits] = array_pad(explode('/', $red), 2, '24');
        $mascara = -1 << (32 - (int) $bits);
        $n = ip2long($base) & $mascara;

        return [long2ip($n + 1), long2ip(($n | ~$mascara) - 1)];
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
