<?php

namespace App\Services\Importador;

/**
 * Lleva un cliente de cualquier origen a una misma forma, la que usa el resto
 * del importador:
 *
 *   external_id, nombres, apellidos, dni, email, telefono, direccion,
 *   plan, plan_bajada, plan_subida, plan_precio, tipo_conexion (pppoe|static),
 *   pppoe_usuario, pppoe_clave, pppoe_perfil, ip, mac, router,
 *   estado (activo|suspendido|retirado), estado_origen, dia_pago, saldo
 *
 * También reconoce las columnas de una exportación por su encabezado, con
 * sinónimos, para proponerle al administrador cómo leer el archivo.
 */
class Normalizador
{
    /** Los campos que se pueden leer de un archivo, con cómo se muestran. */
    public const CAMPOS = [
        'external_id'   => 'ID en la plataforma anterior',
        'nombre'        => 'Nombre (completo o nombres)',
        'apellidos'     => 'Apellidos',
        'dni'           => 'Documento (cédula / NIT)',
        'email'         => 'Correo',
        'telefono'      => 'Teléfono',
        'direccion'     => 'Dirección',
        'plan'          => 'Plan de internet',
        'plan_precio'   => 'Precio del plan',
        'plan_bajada'   => 'Velocidad de bajada',
        'plan_subida'   => 'Velocidad de subida',
        'tipo_conexion' => 'Tipo de conexión',
        'pppoe_usuario' => 'Usuario PPPoE',
        'pppoe_clave'   => 'Contraseña PPPoE',
        'ip'            => 'IP',
        'mac'           => 'MAC',
        'router'        => 'Router / zona',
        'estado'        => 'Estado',
        'dia_pago'      => 'Día de corte / pago',
        'saldo'         => 'Saldo pendiente',
    ];

    /**
     * Encabezados conocidos de cada campo, ya normalizados (minúsculas, sin
     * tildes ni signos). Primero se busca la coincidencia exacta y después la
     * que contiene la palabra.
     */
    private const SINONIMOS = [
        'external_id'   => ['id', 'id servicio', 'idservicio', 'id cliente', 'idcliente', 'codigo cliente', 'codigo', 'servicio id', 'no servicio', 'n servicio', 'numero servicio'],
        'nombre'        => ['nombre', 'nombres', 'cliente', 'nombre cliente', 'nombre completo', 'razon social', 'nombre y apellido', 'nombres y apellidos'],
        'apellidos'     => ['apellidos', 'apellido'],
        'dni'           => ['cedula', 'dni', 'documento', 'dni c c ife', 'dni c i c c facturacion', 'numero documento', 'nro documento', 'no documento', 'identificacion', 'numero identificacion', 'nit', 'rfc ruc nit', 'ruc', 'rut', 'cc', 'c c', 'ci', 'cedula ruc', 'cedula nit'],
        'email'         => ['email', 'e mail', 'correo', 'correo electronico', 'mail'],
        'telefono'      => ['telefono', 'telefono celular', 'celular', 'movil', 'telefono movil', 'whatsapp', 'telefono 1', 'telefonos', 'tel'],
        'direccion'     => ['direccion', 'direccion principal', 'domicilio', 'direccion instalacion'],
        'plan'          => ['plan de internet', 'plan internet', 'plan', 'perfil', 'plan servicio', 'nombre plan'],
        'plan_precio'   => ['plan precio', 'precio plan', 'precio', 'costo', 'mensualidad', 'valor', 'tarifa', 'valor plan', 'costo plan'],
        'plan_bajada'   => ['bajada', 'descarga', 'download', 'velocidad bajada', 'velocidad de bajada'],
        'plan_subida'   => ['subida', 'carga', 'upload', 'velocidad subida', 'velocidad de subida'],
        'tipo_conexion' => ['tipo conexion', 'tipo de conexion', 'conexion', 'tipo', 'protocolo', 'tipo servicio'],
        'pppoe_usuario' => ['usuario pppoe', 'pppoe usuario', 'user pppoe', 'pppoe user', 'ppp user', 'ppp usuario', 'pppuser', 'usuario rb', 'cliente rb', 'usuario', 'usuario servicio', 'secret'],
        'pppoe_clave'   => ['password hotspot pppoe', 'password pppoe', 'contrasena pppoe', 'clave pppoe', 'pppoe pass', 'ppp pass', 'ppp password', 'ppp clave', 'ppppass', 'password servicio', 'password', 'contrasena', 'clave'],
        'ip'            => ['ip', 'direccion ip', 'ip cliente', 'ip address', 'ip asignada', 'ip remota'],
        'mac'           => ['mac', 'mac cpe', 'mac adress', 'mac address', 'direccion mac'],
        'router'        => ['router', 'zona', 'nodo', 'servidor', 'mikrotik', 'torre', 'sector'],
        'estado'        => ['estado', 'status', 'estado servicio', 'estado cliente'],
        'dia_pago'      => ['dia de corte', 'dia corte', 'dia de pago', 'dia pago', 'fecha corte', 'corte', 'dia facturacion'],
        'saldo'         => ['saldo', 'saldo pendiente', 'deuda', 'total pendiente', 'total facturas', 'monto pendiente'],
    ];

    /**
     * Propone qué columna del archivo va a cada campo.
     *
     * @param  string[] $columnas
     * @return array<string, int|null>  campo => índice de columna
     */
    public static function sugerirMapeo(array $columnas, string $origen = ''): array
    {
        $normales = array_map([self::class, 'clave'], $columnas);
        $sinonimosPorCampo = self::SINONIMOS;

        // En WispHub "Usuario" es el usuario del sistema (juan@empresa), no el
        // PPPoE: ése viene como "Cliente RB" o "Usuario RB".
        if ($origen === 'wisphub') {
            $sinonimosPorCampo['pppoe_usuario'] = array_values(array_diff($sinonimosPorCampo['pppoe_usuario'], ['usuario']));
        }
        $mapeo = array_fill_keys(array_keys(self::CAMPOS), null);
        $usadas = [];

        // Dos pasadas: exactas para todos los campos y recién después parciales,
        // así "Plan Precio" no se lo lleva "plan" antes que "precio".
        foreach ([true, false] as $exacta) {
            foreach ($sinonimosPorCampo as $campo => $sinonimos) {
                if ($mapeo[$campo] !== null) {
                    continue;
                }

                foreach ($sinonimos as $s) {
                    foreach ($normales as $i => $n) {
                        if (isset($usadas[$i]) || $n === '') {
                            continue;
                        }

                        // Las parciales sólo con palabras largas y nunca con las
                        // columnas del router wifi o de facturación electrónica.
                        $coincide = $exacta
                            ? $n === $s
                            : (strlen($s) >= 6
                                && !preg_match('/wifi|facturacion|ssid/', $n)
                                && preg_match('/(^| )' . preg_quote($s, '/') . '( |$)/', $n));

                        if ($coincide) {
                            $mapeo[$campo] = $i;
                            $usadas[$i] = true;
                            continue 3;
                        }
                    }
                }
            }
        }

        return $mapeo;
    }

    /**
     * Arma el cliente normalizado a partir de una fila del archivo.
     *
     * @param  array<int,string>        $fila
     * @param  array<string, int|null>  $mapeo
     * @return array<string,mixed>
     */
    public static function desdeFila(array $fila, array $mapeo): array
    {
        $v = fn (string $campo) => isset($mapeo[$campo]) && $mapeo[$campo] !== null && $mapeo[$campo] !== ''
            ? trim((string) ($fila[(int) $mapeo[$campo]] ?? ''))
            : '';

        [$nombres, $apellidos] = self::separarNombre($v('nombre'), $v('apellidos'));

        return self::cliente([
            'external_id'   => $v('external_id'),
            'nombres'       => $nombres,
            'apellidos'     => $apellidos,
            'dni'           => $v('dni'),
            'email'         => $v('email'),
            'telefono'      => $v('telefono'),
            'direccion'     => $v('direccion'),
            'plan'          => $v('plan'),
            'plan_precio'   => $v('plan_precio'),
            'plan_bajada'   => $v('plan_bajada'),
            'plan_subida'   => $v('plan_subida'),
            'tipo_conexion' => $v('tipo_conexion'),
            'pppoe_usuario' => $v('pppoe_usuario'),
            'pppoe_clave'   => $v('pppoe_clave'),
            'ip'            => $v('ip'),
            'mac'           => $v('mac'),
            'router'        => $v('router'),
            'estado'        => $v('estado'),
            'dia_pago'      => $v('dia_pago'),
            'saldo'         => $v('saldo'),
        ]);
    }

    /**
     * Limpia y completa los campos de un cliente, venga de donde venga.
     *
     * @param  array<string,mixed> $c
     * @return array<string,mixed>
     */
    public static function cliente(array $c): array
    {
        $t = fn ($x) => trim(preg_replace('/\s+/u', ' ', (string) ($x ?? '')));

        $pppoeUsuario = $t($c['pppoe_usuario'] ?? '');
        $ip = $t($c['ip'] ?? '');
        $plan = $t($c['plan'] ?? '');

        $nombres = $t($c['nombres'] ?? '');
        $apellidos = $t($c['apellidos'] ?? '');
        if ($apellidos === '' && $nombres !== '') {
            [$nombres, $apellidos] = self::separarNombre($nombres, '');
        }

        return [
            'external_id'   => mb_substr($t($c['external_id'] ?? ''), 0, 100),
            'nombres'       => mb_substr($nombres, 0, 255),
            'apellidos'     => mb_substr($apellidos, 0, 255),
            'dni'           => self::documento((string) ($c['dni'] ?? '')),
            'email'         => mb_strtolower(mb_substr($t($c['email'] ?? ''), 0, 255)),
            'telefono'      => mb_substr(preg_replace('/[^\d+ ]/', '', $t($c['telefono'] ?? '')), 0, 60),
            'direccion'     => mb_substr($t($c['direccion'] ?? ''), 0, 255),
            'plan'          => mb_substr($plan, 0, 255),
            'plan_precio'   => self::dinero($c['plan_precio'] ?? null),
            'plan_bajada'   => self::velocidad($c['plan_bajada'] ?? null) ?? self::velocidadDelNombre($plan),
            'plan_subida'   => self::velocidad($c['plan_subida'] ?? null) ?? self::velocidadDelNombre($plan),
            'tipo_conexion' => self::tipoConexion($t($c['tipo_conexion'] ?? ''), $pppoeUsuario, $plan),
            'pppoe_usuario' => mb_substr($pppoeUsuario, 0, 120),
            'pppoe_clave'   => mb_substr((string) ($c['pppoe_clave'] ?? ''), 0, 120),
            'pppoe_perfil'  => mb_substr($t($c['pppoe_perfil'] ?? ''), 0, 120),
            'ip'            => $ip,
            'mac'           => mb_strtoupper(mb_substr($t($c['mac'] ?? ''), 0, 50)),
            'router'        => mb_substr($t($c['router'] ?? ''), 0, 255),
            'estado'        => self::estado($t($c['estado'] ?? '')),
            'estado_origen' => mb_substr($t($c['estado'] ?? ''), 0, 60),
            'dia_pago'      => self::diaDePago($c['dia_pago'] ?? null),
            'saldo'         => self::dinero($c['saldo'] ?? null),
            'avisos_origen' => array_values(array_filter((array) ($c['avisos_origen'] ?? []))),
        ];
    }

    /** "Nombre Apellido" → clave para comparar: minúsculas, sin tildes ni signos. */
    public static function clave(?string $texto): string
    {
        $s = mb_strtolower(trim((string) $texto));
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u']);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);

        return trim($s);
    }

    /**
     * El documento tal como se guarda: sin puntos, espacios ni comas. Se deja
     * el guion del dígito de verificación de un NIT.
     */
    public static function documento(string $dni): string
    {
        $d = preg_replace('/[\s.,]/u', '', trim($dni));

        // "1098765432.0" de una planilla que lo tomó como número.
        if (preg_match('/^(\d+)\.0+$/', trim($dni), $m)) {
            $d = $m[1];
        }

        return mb_substr((string) $d, 0, 60);
    }

    /** Para comparar documentos: sólo los dígitos (como la sincronización con el router). */
    public static function documentoParaComparar(string $dni): string
    {
        $digitos = preg_replace('/\D/', '', $dni);

        return $digitos !== '' ? $digitos : mb_strtolower($dni);
    }

    /**
     * "JUAN CARLOS PEREZ GOMEZ" → ["JUAN CARLOS", "PEREZ GOMEZ"]. Con tres
     * palabras se toma un nombre y dos apellidos, que es lo más común.
     *
     * @return array{0:string,1:string}
     */
    public static function separarNombre(string $nombre, string $apellidos): array
    {
        $nombre = trim(preg_replace('/\s+/u', ' ', $nombre));
        $apellidos = trim(preg_replace('/\s+/u', ' ', $apellidos));

        if ($apellidos !== '' || $nombre === '') {
            return [$nombre, $apellidos];
        }

        $p = explode(' ', $nombre);

        return match (true) {
            count($p) === 1 => [$p[0], ''],
            count($p) === 2 => [$p[0], $p[1]],
            count($p) === 3 => [$p[0], $p[1] . ' ' . $p[2]],
            default         => [implode(' ', array_slice($p, 0, 2)), implode(' ', array_slice($p, 2))],
        };
    }

    /** activo | suspendido | retirado | null (no se reconoce). */
    public static function estado(string $estado): ?string
    {
        $e = self::clave($estado);

        if ($e === '') {
            return null;
        }

        return match (true) {
            (bool) preg_match('/suspend|cortad|moros|bloque|deshabilit|inhabilit|vencid|^2$/', $e)            => 'suspendido',
            (bool) preg_match('/retirad|cancelad|baja|eliminad|inactiv|desinstal|terminad|anulad|^3$/', $e)         => 'retirado',
            (bool) preg_match('/activ|habilit|gratis|libre|instalad|online|al dia|^1$|^4$|^si$|true/', $e)          => 'activo',
            default => null,
        };
    }

    /** pppoe | static. */
    public static function tipoConexion(string $tipo, string $pppoeUsuario, string $plan = ''): string
    {
        $t = self::clave($tipo);

        if ($t !== '') {
            if (str_contains($t, 'ppp')) {
                return 'pppoe';
            }
            if (preg_match('/queue|estatic|static|fija|dhcp|arp|hotspot|pcq/', $t)) {
                return 'static';
            }
        }

        if (str_contains(self::clave($plan), 'pppoe')) {
            return 'pppoe';
        }

        return $pppoeUsuario !== '' ? 'pppoe' : 'static';
    }

    /** "$ 60.000,00", "60000.00", "60,000" → 60000.0; vacío → null. */
    public static function dinero(mixed $valor): ?float
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        if (is_int($valor) || is_float($valor)) {
            return (float) $valor;
        }

        $s = preg_replace('/[^\d,.\-]/', '', (string) $valor);
        if ($s === '' || $s === '-') {
            return null;
        }

        $coma = strrpos($s, ',');
        $punto = strrpos($s, '.');

        if ($coma !== false && $punto !== false) {
            // El último separador es el decimal.
            $s = $coma > $punto
                ? str_replace(',', '.', str_replace('.', '', $s))
                : str_replace(',', '', $s);
        } elseif ($coma !== false) {
            // "60,000" (miles) o "60,5" (decimal).
            $s = strlen($s) - $coma - 1 === 3 ? str_replace(',', '', $s) : str_replace(',', '.', $s);
        } elseif ($punto !== false && substr_count($s, '.') > 1) {
            $s = str_replace('.', '', $s);
        } elseif ($punto !== false && strlen($s) - $punto - 1 === 3 && strlen($s) > 4) {
            // "60.000": en Colombia el punto es de miles.
            $s = str_replace('.', '', $s);
        }

        return is_numeric($s) ? round((float) $s, 2) : null;
    }

    /**
     * Velocidad en Mbps: "10M" → 10, "10240k" → 10, "20 Mbps" → 20, "512K" → 1.
     * Un número suelto se toma como Mbps salvo que sea enorme (kbps).
     */
    public static function velocidad(mixed $valor): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        $s = mb_strtolower(trim((string) $valor));

        // "10M/5M" de un max-limit: se toma el primero.
        $s = explode('/', $s)[0];

        if (!preg_match('/([\d.,]+)\s*([kmg])?/', $s, $m)) {
            return null;
        }

        $n = (float) str_replace(',', '.', $m[1]);
        $unidad = $m[2] ?? '';

        $mbps = match ($unidad) {
            'k'     => $n / 1024,
            'g'     => $n * 1000,
            'm'     => $n,
            default => $n >= 10000 ? $n / 1024 : $n,
        };

        return $mbps > 0 ? max(1, (int) round($mbps)) : null;
    }

    /** "Plan 20MB", "INTERNET 400MG", "Fibra 300 Megas" → 20 / 400 / 300. */
    public static function velocidadDelNombre(string $plan): ?int
    {
        if (preg_match('/(\d{1,5})\s*(mbps|mb|mg|megas?|m\b)/i', $plan, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /** "05", 15, "2024-03-05" → día del mes (1-31) o null. */
    public static function diaDePago(mixed $valor): ?int
    {
        $s = trim((string) ($valor ?? ''));

        if ($s === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-(\d{2})/', $s, $m)) {
            $d = (int) $m[1];
        } elseif (preg_match('/^(\d{1,2})[\/\-]\d{1,2}[\/\-]\d{2,4}/', $s, $m)) {
            $d = (int) $m[1];
        } elseif (preg_match('/(\d{1,2})/', $s, $m)) {
            $d = (int) $m[1];
        } else {
            return null;
        }

        return $d >= 1 && $d <= 31 ? $d : null;
    }
}
