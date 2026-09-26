<?php

namespace App\Services\WaBot;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Ejecuta los flujos que se dibujan en el constructor.
 *
 * Hasta ahora el constructor guardaba los bloques en `wa_bot_configs.flows` y
 * nadie los leía: el bot sólo sabía hacer los cuatro flujos escritos a mano en
 * PHP (factura, revisión, reportar pago, pagar). Se podía dibujar una
 * conversación entera, guardarla, y al cliente no le pasaba nada.
 *
 * Aquí se recorre el grafo de bloques. Cada bloque hace lo suyo y dice a cuál
 * seguir; cuando un bloque necesita que el cliente conteste —una pregunta, unos
 * botones, una lista— el recorrido se detiene y queda anotado en la sesión.
 *
 * Nada de esto envía por su cuenta: todo sale por un Canal, que en producción
 * es WhatsApp y en el constructor es la pantalla de prueba.
 */
class MotorDeFlujos
{
    /**
     * Tope de bloques que se corren de una vez.
     *
     * Un flujo mal conectado puede volver sobre sí mismo. Sin tope, el webhook
     * de Meta se quedaría dando vueltas hasta que PHP lo corte, y Meta reintenta
     * el mensaje: el cliente recibiría la misma conversación varias veces.
     */
    private const MAX_BLOQUES = 30;

    /** Lo que espera una petición antes de darla por perdida. */
    private const ESPERA_HTTP = 8;

    /** @param array<int,array<string,mixed>> $flujos tal como los guarda el constructor */
    public function __construct(
        private Company $empresa,
        private array $flujos,
        private Canal $canal,
    ) {
    }

    /** ¿Hay un flujo dibujado con bloques para este id? */
    public function tiene(string $flujoId): bool
    {
        $flujo = $this->flujo($flujoId);

        return $flujo !== null
            && ($flujo['is_active'] ?? true) !== false
            && !empty($flujo['steps']);
    }

    /**
     * Arranca el flujo desde su primer bloque.
     *
     * @param  array<string,mixed>  $datos  variables que ya se saben (teléfono, cliente…)
     */
    public function arrancar(string $flujoId, array $datos = []): Parada
    {
        $flujo = $this->flujo($flujoId);

        if (!$flujo) {
            return Parada::terminada($datos, $flujoId);
        }

        return $this->correr($flujo, $this->primerBloque($flujo), $datos);
    }

    /**
     * Sigue un flujo detenido, con lo que el cliente acaba de contestar.
     *
     * @param  array<string,mixed>  $datos
     */
    public function seguir(string $flujoId, ?string $bloqueId, array $datos, string $respuesta): Parada
    {
        $flujo = $this->flujo($flujoId);

        if (!$flujo) {
            return Parada::terminada($datos, $flujoId);
        }

        $bloque = $this->bloque($flujo, $bloqueId);

        if (!$bloque) {
            return $this->correr($flujo, $this->primerBloque($flujo), $datos);
        }

        // El bloque que estaba esperando resuelve la respuesta y dice por dónde
        // sigue. Si no la acepta, se queda donde está y vuelve a preguntar.
        $resuelto = $this->recibir($flujo, $bloque, $datos, $respuesta);

        if ($resuelto === null) {
            return Parada::esperando((string) $bloque['id'], $datos, $flujoId);
        }

        [$siguiente, $datos] = $resuelto;

        return $this->correr($flujo, $this->bloque($flujo, $siguiente), $datos);
    }

    // ── El recorrido ────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $flujo
     * @param  array<string,mixed>|null  $bloque
     * @param  array<string,mixed>  $datos
     */
    private function correr(array $flujo, ?array $bloque, array $datos): Parada
    {
        $vueltas = 0;

        while ($bloque !== null) {
            if (++$vueltas > self::MAX_BLOQUES) {
                Log::warning('[BotFlujos] Flujo con demasiados bloques seguidos, se corta', [
                    'empresa' => $this->empresa->id,
                    'flujo'   => $flujo['id'] ?? null,
                ]);

                return Parada::terminada($datos, (string) ($flujo['id'] ?? ''));
            }

            // Salto a otro flujo: es lo que permite armar el bot por piezas
            // —un flujo para identificar al cliente, otro para cobrar— en vez
            // de un solo dibujo gigante.
            if ((string) ($bloque['type'] ?? '') === 'goto') {
                $destino = $this->flujo((string) ($bloque['flow_id'] ?? ''));

                if (!$destino || ($destino['id'] ?? null) === ($flujo['id'] ?? null)) {
                    Log::info('[BotFlujos] «Ir a otro flujo» sin destino válido', ['flujo' => $bloque['flow_id'] ?? null]);

                    return Parada::terminada($datos, (string) ($flujo['id'] ?? ''));
                }

                $flujo = $destino;
                $bloque = $this->primerBloque($destino);

                continue;
            }

            $paso = $this->ejecutar($flujo, $bloque, $datos);
            $datos = $paso->datos;
            $idFlujo = (string) ($flujo['id'] ?? '');

            if ($paso->espera || $paso->termina || $paso->transferir) {
                return $paso->espera
                    ? Parada::esperando((string) $bloque['id'], $datos, $idFlujo)
                    : ($paso->transferir
                        ? Parada::transferida($datos, $paso->transferir, $idFlujo)
                        : Parada::terminada($datos, $idFlujo));
            }

            $bloque = $this->bloque($flujo, $paso->siguiente);
        }

        return Parada::terminada($datos, (string) ($flujo['id'] ?? ''));
    }

    /**
     * Corre un bloque. Devuelve qué hacer después.
     *
     * @param  array<string,mixed>  $flujo
     * @param  array<string,mixed>  $bloque
     * @param  array<string,mixed>  $datos
     */
    private function ejecutar(array $flujo, array $bloque, array $datos): Resultado
    {
        $tipo = (string) ($bloque['type'] ?? 'message');
        $sigue = $bloque['next_step'] ?? null;

        switch ($tipo) {
            case 'message':
                $this->canal->texto($this->rellenar((string) ($bloque['message'] ?? ''), $datos));

                return Resultado::sigue($sigue, $datos);

            case 'buttons':
                $this->canal->botones(
                    $this->rellenar((string) ($bloque['message'] ?? '¿Qué quiere hacer?'), $datos),
                    $this->botonesDe($bloque, $datos),
                    $this->rellenar((string) ($bloque['header'] ?? ''), $datos),
                );

                return Resultado::espera($datos);

            case 'list':
                $this->canal->lista(
                    $this->rellenar((string) ($bloque['message'] ?? 'Seleccione una opción'), $datos),
                    $this->seccionesDe($bloque, $datos),
                    $this->rellenar((string) ($bloque['button_text'] ?? 'Ver opciones'), $datos),
                );

                return Resultado::espera($datos);

            case 'input':
                $this->canal->texto($this->rellenar((string) ($bloque['message'] ?? '¿Me lo escribe?'), $datos));

                return Resultado::espera($datos);

            case 'link':
                $this->canal->enlace(
                    $this->rellenar((string) ($bloque['message'] ?? ''), $datos),
                    $this->rellenar((string) ($bloque['url'] ?? ''), $datos),
                    $this->rellenar((string) ($bloque['button_text'] ?? 'Abrir'), $datos),
                );

                return Resultado::sigue($sigue, $datos);

            case 'image':
                $this->canal->imagen(
                    $this->rellenar((string) ($bloque['media_url'] ?? ''), $datos),
                    $this->rellenar((string) ($bloque['media_caption'] ?? ''), $datos),
                );

                return Resultado::sigue($sigue, $datos);

            case 'document':
                $this->canal->documento(
                    $this->rellenar((string) ($bloque['media_url'] ?? ''), $datos),
                    $this->rellenar((string) ($bloque['media_name'] ?? 'archivo.pdf'), $datos),
                    $this->rellenar((string) ($bloque['media_caption'] ?? ''), $datos),
                );

                return Resultado::sigue($sigue, $datos);

            case 'api_call':
            case 'webhook':
                return $this->pedirAlServidor($bloque, $datos);

            case 'cliente':
                return $this->buscarCliente($bloque, $datos);

            case 'condition':
                return $this->decidir($bloque, $datos);

            case 'delay':
                // Un webhook no puede dormir: Meta lo reintenta si tarda. Se
                // respeta hasta 3 s, que alcanza para que no lleguen dos
                // mensajes pegados, y se ignora lo que pase de ahí.
                $segundos = max(0, min(3, (int) ($bloque['delay_seconds'] ?? 0)));

                if ($segundos > 0) {
                    sleep($segundos);
                }

                return Resultado::sigue($sigue, $datos);

            case 'transfer_agent':
                if ($texto = trim($this->rellenar((string) ($bloque['message'] ?? ''), $datos))) {
                    $this->canal->texto($texto);
                }

                return Resultado::transfiere($datos, (string) ($bloque['agent_department'] ?? 'soporte'));

            case 'end':
                if ($texto = trim($this->rellenar((string) ($bloque['message'] ?? ''), $datos))) {
                    $this->canal->texto($texto);
                }

                return Resultado::termina($datos);

            default:
                Log::info('[BotFlujos] Bloque desconocido, se salta', ['tipo' => $tipo]);

                return Resultado::sigue($sigue, $datos);
        }
    }

    /**
     * Le da al bloque que esperaba la respuesta del cliente.
     *
     * Devuelve [siguienteBloque, datos] o null si la respuesta no sirve y hay
     * que volver a preguntar.
     *
     * @param  array<string,mixed>  $flujo
     * @param  array<string,mixed>  $bloque
     * @param  array<string,mixed>  $datos
     * @return array{0: ?string, 1: array<string,mixed>}|null  el id del bloque que sigue y las variables
     */
    private function recibir(array $flujo, array $bloque, array $datos, string $respuesta): ?array
    {
        $tipo = (string) ($bloque['type'] ?? 'message');
        $limpia = trim($respuesta);

        if ($tipo === 'buttons' || $tipo === 'list') {
            $opciones = $tipo === 'buttons'
                ? $this->botonesDe($bloque, $datos, true)
                : $this->filasDe($bloque, $datos, true);

            foreach ($opciones as $i => $opcion) {
                $id = (string) ($opcion['id'] ?? '');
                $titulo = mb_strtolower(trim((string) ($opcion['title'] ?? '')));

                // Vale el id del botón, el número de la opción o su texto: el
                // cliente puede escribir en vez de tocar.
                $coincide = $limpia !== '' && (
                    mb_strtolower($limpia) === mb_strtolower($id)
                    || $limpia === (string) ($i + 1)
                    || ($titulo !== '' && mb_strtolower($limpia) === $titulo)
                );

                if ($coincide) {
                    $datos[(string) ($bloque['variable_name'] ?? '' ?: 'opcion')] = $opcion['title'] ?? $id;

                    return [$opcion['next_step'] ?? ($bloque['next_step'] ?? null), $datos];
                }
            }

            $this->canal->texto($this->rellenar(
                (string) ($bloque['error_message'] ?? 'No entendí esa opción. Toque uno de los botones, por favor.'),
                $datos,
            ));

            return null;
        }

        if ($tipo === 'input') {
            $problema = $this->problemaDelDato((string) ($bloque['input_type'] ?? 'text'), $limpia);

            if ($problema !== null) {
                $this->canal->texto($this->rellenar(
                    (string) ($bloque['validation_message'] ?? '' ?: $problema),
                    $datos,
                ));

                return null;
            }

            $datos[(string) ($bloque['variable_name'] ?? '' ?: 'respuesta')] = $limpia;

            return [$bloque['next_step'] ?? null, $datos];
        }

        // Cualquier otro bloque no esperaba nada: se sigue de largo.
        return [$bloque['next_step'] ?? null, $datos];
    }

    // ── Bloques con lógica propia ───────────────────────────────────────────

    /**
     * Pide algo por HTTP y guarda la respuesta en una variable.
     *
     * @param  array<string,mixed>  $bloque
     * @param  array<string,mixed>  $datos
     */
    private function pedirAlServidor(array $bloque, array $datos): Resultado
    {
        $url = trim($this->rellenar((string) ($bloque['endpoint'] ?? ''), $datos));
        $metodo = strtoupper((string) ($bloque['method'] ?? 'GET'));

        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            Log::warning('[BotFlujos] Petición sin dirección válida', ['url' => $url]);

            return $this->fallaDePeticion($bloque, $datos, 'La dirección de la petición no es válida.');
        }

        $parametros = [];

        foreach (($bloque['params'] ?? []) as $p) {
            $clave = trim((string) ($p['key'] ?? ''));

            if ($clave !== '') {
                $parametros[$clave] = $this->rellenar((string) ($p['value'] ?? ''), $datos);
            }
        }

        $cabeceras = [];

        foreach (($bloque['headers'] ?? []) as $h) {
            $clave = trim((string) ($h['key'] ?? ''));

            if ($clave !== '') {
                $cabeceras[$clave] = $this->rellenar((string) ($h['value'] ?? ''), $datos);
            }
        }

        try {
            $peticion = Http::timeout(self::ESPERA_HTTP)->withHeaders($cabeceras)->acceptJson();

            $respuesta = match ($metodo) {
                'POST'  => $peticion->post($url, $parametros),
                'PUT'   => $peticion->put($url, $parametros),
                'PATCH' => $peticion->patch($url, $parametros),
                default => $peticion->get($url, $parametros),
            };

            if ($respuesta->failed()) {
                return $this->fallaDePeticion($bloque, $datos, 'El servidor respondió ' . $respuesta->status() . '.');
            }

            $cuerpo = $respuesta->json();

            if (!is_array($cuerpo)) {
                $cuerpo = ['respuesta' => $respuesta->body()];
            }

            $valor = $this->porRuta($cuerpo, (string) ($bloque['response_path'] ?? ''));
            $guardar = trim((string) ($bloque['save_response_to'] ?? 'respuesta'));

            if ($guardar !== '') {
                $datos[$guardar] = $valor;
            }

            return Resultado::sigue($bloque['next_step'] ?? null, $datos);
        } catch (\Throwable $e) {
            Log::warning('[BotFlujos] La petición falló', ['url' => $url, 'error' => $e->getMessage()]);

            return $this->fallaDePeticion($bloque, $datos, 'No se pudo consultar ahora mismo.');
        }
    }

    /** @param array<string,mixed> $bloque @param array<string,mixed> $datos */
    private function fallaDePeticion(array $bloque, array $datos, string $porque): Resultado
    {
        $datos['error'] = $porque;

        // Si el flujo tiene una rama para el error, se usa; si no, se le avisa
        // al cliente y se corta, que es mejor que dejarlo esperando.
        if (!empty($bloque['error_step'])) {
            return Resultado::sigue((string) $bloque['error_step'], $datos);
        }

        $this->canal->texto($this->rellenar(
            (string) ($bloque['error_message'] ?? '' ?: 'No pude consultar esa información ahora. Pruebe en un rato.'),
            $datos,
        ));

        return Resultado::termina($datos);
    }

    /**
     * Carga en las variables los datos del cliente de la plataforma.
     *
     * Es el bloque que hace útil al bot para un ISP: sin esto habría que armar
     * una petición HTTP a la propia plataforma para saber el nombre o el saldo
     * de quien está escribiendo.
     *
     * @param  array<string,mixed>  $bloque
     * @param  array<string,mixed>  $datos
     */
    private function buscarCliente(array $bloque, array $datos): Resultado
    {
        $por = (string) ($bloque['lookup_by'] ?? 'phone');
        $valor = trim($this->rellenar((string) ($bloque['lookup_value'] ?? ($por === 'phone' ? '{{telefono}}' : '{{cedula}}')), $datos));

        $ficha = null;

        if ($valor !== '') {
            $q = DB::table('user_data as u')
                ->join('users as us', 'us.id', '=', 'u.user_id')
                ->where('us.company_id', $this->empresa->id);

            $ficha = $por === 'dni'
                ? $q->where('u.dni', $valor)->first(['u.user_id', 'u.names', 'u.lastname', 'u.dni', 'u.address', 'u.phone'])
                : $q->whereRaw("REPLACE(REPLACE(REPLACE(u.phone,' ',''),'+',''),'-','') LIKE ?", ['%' . substr(preg_replace('/\D/', '', $valor), -10)])
                    ->first(['u.user_id', 'u.names', 'u.lastname', 'u.dni', 'u.address', 'u.phone']);
        }

        if (!$ficha) {
            if (!empty($bloque['error_step'])) {
                return Resultado::sigue((string) $bloque['error_step'], $datos);
            }

            $this->canal->texto($this->rellenar(
                (string) ($bloque['error_message'] ?? '' ?: 'No encontré ese dato en nuestros registros.'),
                $datos,
            ));

            return Resultado::termina($datos);
        }

        $datos['cliente_id'] = (int) $ficha->user_id;
        $datos['nombre'] = trim(($ficha->names ?? '') . ' ' . ($ficha->lastname ?? ''));
        $datos['cedula'] = $ficha->dni;
        $datos['direccion'] = $ficha->address;

        // El saldo es price_total menos descuento y abonos, igual que
        // DetFacturation::outstanding(): «total» es una bandera, no el monto, y
        // las anuladas no se deben.
        $saldo = DB::table('det_facturations as d')
            ->join('cab_facturations as c', 'c.id', '=', 'd.cab_id')
            ->where('c.user_id', $ficha->user_id)
            ->where('d.paid', 0)
            ->whereNull('d.anulada_en')
            ->selectRaw('COUNT(*) n, COALESCE(SUM(GREATEST(d.price_total - COALESCE(d.price_discount, 0) - COALESCE(d.price_abone, 0), 0)), 0) saldo')
            ->first();

        $datos['facturas_pendientes'] = (int) ($saldo->n ?? 0);
        $datos['saldo'] = round((float) ($saldo->saldo ?? 0), 2);
        $datos['saldo_texto'] = '$ ' . number_format((float) ($saldo->saldo ?? 0), 0, ',', '.');

        return Resultado::sigue($bloque['next_step'] ?? null, $datos);
    }

    /** @param array<string,mixed> $bloque @param array<string,mixed> $datos */
    private function decidir(array $bloque, array $datos): Resultado
    {
        $izq = $this->rellenar('{{' . (string) ($bloque['condition_variable'] ?? '') . '}}', $datos);
        $der = $this->rellenar((string) ($bloque['condition_value'] ?? ''), $datos);

        $cumple = match ((string) ($bloque['condition_operator'] ?? 'eq')) {
            'neq'      => mb_strtolower($izq) !== mb_strtolower($der),
            'contains' => $der !== '' && mb_stripos($izq, $der) !== false,
            'empty'    => trim($izq) === '',
            'not_empty' => trim($izq) !== '',
            'gt'       => (float) $izq > (float) $der,
            'lt'       => (float) $izq < (float) $der,
            default    => mb_strtolower($izq) === mb_strtolower($der),
        };

        return Resultado::sigue(
            $cumple ? ($bloque['true_step'] ?? null) : ($bloque['false_step'] ?? null),
            $datos,
        );
    }

    // ── Ayudas ──────────────────────────────────────────────────────────────

    /**
     * Cambia {{variable}} por su valor.
     *
     * Lo que no existe queda en blanco y no como «{{saldo}}»: un cliente no
     * tiene por qué ver las tripas del flujo.
     *
     * @param  array<string,mixed>  $datos
     */
    public function rellenar(string $texto, array $datos): string
    {
        $datos['telefono'] ??= '';
        $datos['empresa'] ??= $this->empresa->name;

        return (string) preg_replace_callback('/\{\{\s*([\w.]+)\s*\}\}/u', function (array $m) use ($datos) {
            $valor = $this->porRuta($datos, $m[1]);

            if (is_array($valor)) {
                return json_encode($valor, JSON_UNESCAPED_UNICODE);
            }

            if (is_bool($valor)) {
                return $valor ? 'sí' : 'no';
            }

            return $valor === null ? '' : (string) $valor;
        }, $texto);
    }

    /**
     * Saca un valor anidado con una ruta tipo «data.0.total».
     *
     * @param  array<string,mixed>  $arreglo
     */
    private function porRuta(array $arreglo, string $ruta): mixed
    {
        $ruta = trim($ruta);

        if ($ruta === '') {
            return $arreglo;
        }

        $actual = $arreglo;

        foreach (explode('.', $ruta) as $parte) {
            if (is_array($actual) && array_key_exists($parte, $actual)) {
                $actual = $actual[$parte];
                continue;
            }

            return null;
        }

        return $actual;
    }

    /**
     * @param  array<string,mixed>  $bloque
     * @param  array<string,mixed>  $datos
     * @return list<array<string,mixed>>
     */
    private function botonesDe(array $bloque, array $datos, bool $conRuta = false): array
    {
        $salida = [];

        foreach (array_slice(array_values($bloque['buttons'] ?? []), 0, CanalDeWhatsapp::MAX_BOTONES) as $i => $b) {
            $fila = [
                'id'    => (string) ($b['id'] ?? ('op' . ($i + 1))),
                'title' => $this->rellenar((string) ($b['title'] ?? ('Opción ' . ($i + 1))), $datos),
            ];

            if ($conRuta) {
                $fila['next_step'] = $b['next_step'] ?? null;
            }

            $salida[] = $fila;
        }

        return $salida;
    }

    /**
     * @param  array<string,mixed>  $bloque
     * @param  array<string,mixed>  $datos
     * @return list<array<string,mixed>>
     */
    private function filasDe(array $bloque, array $datos, bool $conRuta = false): array
    {
        $salida = [];

        foreach (($bloque['sections'] ?? []) as $seccion) {
            foreach (($seccion['rows'] ?? []) as $i => $fila) {
                $item = [
                    'id'          => (string) ($fila['id'] ?? ('fila' . ($i + 1))),
                    'title'       => $this->rellenar((string) ($fila['title'] ?? ''), $datos),
                    'description' => $this->rellenar((string) ($fila['description'] ?? ''), $datos),
                ];

                if ($conRuta) {
                    $item['next_step'] = $fila['next_step'] ?? null;
                }

                $salida[] = $item;
            }
        }

        return array_slice($salida, 0, CanalDeWhatsapp::MAX_FILAS);
    }

    /**
     * @param  array<string,mixed>  $bloque
     * @param  array<string,mixed>  $datos
     * @return list<array<string,mixed>>
     */
    private function seccionesDe(array $bloque, array $datos): array
    {
        $salida = [];

        foreach (($bloque['sections'] ?? []) as $seccion) {
            $filas = [];

            foreach (($seccion['rows'] ?? []) as $i => $fila) {
                $filas[] = [
                    'id'          => (string) ($fila['id'] ?? ('fila' . ($i + 1))),
                    'title'       => $this->rellenar((string) ($fila['title'] ?? ''), $datos),
                    'description' => $this->rellenar((string) ($fila['description'] ?? ''), $datos),
                ];
            }

            if ($filas) {
                $salida[] = ['title' => $this->rellenar((string) ($seccion['title'] ?? 'Opciones'), $datos), 'rows' => $filas];
            }
        }

        return $salida;
    }

    /** El reclamo si el dato no sirve, o null si está bien. */
    private function problemaDelDato(string $tipo, string $valor): ?string
    {
        if ($valor === '') {
            return 'No me llegó nada. ¿Lo escribe de nuevo?';
        }

        return match ($tipo) {
            'number' => ctype_digit(preg_replace('/\D/', '', $valor)) && preg_replace('/\D/', '', $valor) !== ''
                ? null : 'Necesito sólo números.',
            'email' => filter_var($valor, FILTER_VALIDATE_EMAIL) ? null : 'Ese correo no parece válido.',
            'phone' => strlen(preg_replace('/\D/', '', $valor)) >= 7 ? null : 'Ese teléfono no parece completo.',
            'date'  => strtotime($valor) !== false ? null : 'No entendí la fecha. Pruebe con día/mes/año.',
            default => null,
        };
    }

    /** @return array<string,mixed>|null */
    private function flujo(string $id): ?array
    {
        foreach ($this->flujos as $f) {
            if ((string) ($f['id'] ?? '') === $id) {
                return $f;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $flujo
     * @return array<string,mixed>|null
     */
    private function bloque(array $flujo, ?string $id): ?array
    {
        if (!$id) {
            return null;
        }

        foreach (($flujo['steps'] ?? []) as $b) {
            if ((string) ($b['id'] ?? '') === $id) {
                return $b;
            }
        }

        return null;
    }

    /**
     * Por dónde empieza.
     *
     * El bloque marcado como inicio, o el primero al que nadie apunta —que es
     * lo que uno espera al dibujar— y si todos están apuntados, el primero de
     * la lista.
     *
     * @param  array<string,mixed>  $flujo
     * @return array<string,mixed>|null
     */
    private function primerBloque(array $flujo): ?array
    {
        $bloques = $flujo['steps'] ?? [];

        foreach ($bloques as $b) {
            if (!empty($b['is_start'])) {
                return $b;
            }
        }

        $apuntados = [];

        foreach ($bloques as $b) {
            foreach (['next_step', 'true_step', 'false_step', 'error_step'] as $campo) {
                if (!empty($b[$campo])) {
                    $apuntados[(string) $b[$campo]] = true;
                }
            }

            foreach (($b['buttons'] ?? []) as $x) {
                if (!empty($x['next_step'])) {
                    $apuntados[(string) $x['next_step']] = true;
                }
            }

            foreach (($b['sections'] ?? []) as $s) {
                foreach (($s['rows'] ?? []) as $x) {
                    if (!empty($x['next_step'])) {
                        $apuntados[(string) $x['next_step']] = true;
                    }
                }
            }
        }

        foreach ($bloques as $b) {
            if (!isset($apuntados[(string) ($b['id'] ?? '')])) {
                return $b;
            }
        }

        return $bloques[0] ?? null;
    }
}
