<?php

namespace App\Services\Tv;

use App\Models\TvLinea;
use App\Models\TvPanel;
use App\Services\WhatsAppService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Las líneas de TV de los clientes: se crean al dar de alta (o al pasar a un plan con TV), se
 * traen las que ya existían en el panel y se ligan a su cliente, y se suspenden y reactivan con
 * la cartera cuando la empresa lo prende.
 *
 * Estados de una línea: pendiente (falta crearla en el panel; se reintenta sola), activa,
 * suspendida (la apagó la plataforma por mora: la vuelve a prender al pagar), desactivada (la
 * apagó alguien en el panel: la plataforma no la toca) y retirada (cliente retirado).
 */
class LineasDeTv
{
    /**
     * Los formatos de lista que entrega el XUI (get.php?type=), probados contra el panel el
     * 21-10-2026: todos devuelven los canales del paquete de la línea.
     */
    public const FORMATOS = [
        'm3u_plus'        => ['M3U Plus', 'Smart TV, TV Box, VLC: con logos y grupos'],
        'm3u'             => ['M3U', 'Reproductores sencillos'],
        'simple'          => ['Lista simple', 'Solo los enlaces'],
        'enigma22_script' => ['Enigma2 OE 2.0 (script)', 'Decodificadores Enigma2: Vu+, Dreambox nuevos'],
        'enigma16'        => ['Enigma 1.6', 'Decodificadores Enigma viejos'],
        'dreambox'        => ['Dreambox', 'Decodificadores Dreambox'],
        'gigablue'        => ['GigaBlue', 'Decodificadores GigaBlue'],
        'octagon'         => ['Octagon', 'Decodificadores Octagon'],
        'octagon_script'  => ['Octagon (script)', 'Instalación automática en Octagon'],
        'starlivev3'      => ['Starlive v3', 'Decodificadores Starlive'],
        'mediastar'       => ['Mediastar', 'Decodificadores Mediastar'],
        'webtvlist'       => ['WebTV list', 'Listas WebTV'],
        'ariva'           => ['Ariva', 'Decodificadores Ariva'],
    ];

    public const SALIDAS = ['ts' => 'MPEG-TS', 'm3u8' => 'HLS (m3u8)', 'rtmp' => 'RTMP'];

    public function __construct(private TvPanel $panel) {}

    public static function deLaEmpresa(int $companyId): ?self
    {
        $panel = TvPanel::deLaEmpresa($companyId);

        return $panel ? new self($panel) : null;
    }

    private function xui(): PanelXui { return new PanelXui($this->panel); }

    private function empresa(): int { return (int) $this->panel->company_id; }

    // ── Clientes con TV ──────────────────────────────────────────────────────

    /** El plan del cliente lleva TV: con qué bouquets y cuántas pantallas. */
    public function tvDelPlan(int $userId): ?array
    {
        $plan = DB::table('user_data as u')->join('internet_plans as p', 'p.id', '=', 'u.internet_plans_id')
            ->where('u.user_id', $userId)->where('u.company_id', $this->empresa())
            ->first(['p.tv_incluida', 'p.tv_bouquets', 'p.tv_pantallas']);

        if (!$plan || !$plan->tv_incluida) {
            return null;
        }

        return [
            'bouquets' => json_decode((string) $plan->tv_bouquets, true) ?: ($this->panel->bouquets ?: []),
            'pantallas' => (int) ($plan->tv_pantallas ?: $this->panel->pantallas ?: 1),
        ];
    }

    /**
     * Al dar de alta un cliente o cambiarle el plan: si el plan lleva TV y no tiene línea, se le
     * crea. Nunca rompe el alta: si el panel no responde, la línea queda pendiente y la crea
     * la tarea programada.
     */
    public static function alGuardarCliente(int $companyId, int $userId): void
    {
        try {
            $s = self::deLaEmpresa($companyId);
            if ($s && $s->tvDelPlan($userId) && !TvLinea::where('company_id', $companyId)->where('user_id', $userId)->where('estado', '!=', 'retirada')->exists()) {
                $s->crearPara($userId);
            }
        } catch (\Throwable $e) {
            Log::warning('[TV] No se pudo crear la línea al guardar el cliente', ['empresa' => $companyId, 'cliente' => $userId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * En la instalación: si el plan del cliente lleva TV, su línea (la que ya tenga, o una nueva).
     * Nunca frena la instalación: devuelve la línea o el motivo por el que no se pudo.
     *
     * @return array{linea: ?TvLinea, error: ?string}|null  null si el plan no lleva TV
     */
    public static function paraLaInstalacion(int $companyId, int $userId): ?array
    {
        $s = self::deLaEmpresa($companyId);
        if (!$s || !$s->tvDelPlan($userId)) {
            return null;
        }

        $ya = TvLinea::where('company_id', $companyId)->where('user_id', $userId)->whereIn('estado', ['activa', 'suspendida', 'desactivada'])->orderByDesc('id')->first();
        if ($ya) {
            return ['linea' => $ya, 'error' => null];
        }

        try {
            return ['linea' => $s->crearPara($userId), 'error' => null];
        } catch (\Throwable $e) {
            Log::warning('[TV] No se pudo crear la línea en la instalación', ['empresa' => $companyId, 'cliente' => $userId, 'error' => $e->getMessage()]);
            $pendiente = TvLinea::where('company_id', $companyId)->where('user_id', $userId)->where('estado', 'pendiente')->first();

            return ['linea' => $pendiente, 'error' => $e->getMessage()];
        }
    }

    /** Crea la línea del cliente (o la intenta de nuevo si quedó pendiente). */
    public function crearPara(int $userId, ?array $tv = null): TvLinea
    {
        $tv ??= $this->tvDelPlan($userId) ?? ['bouquets' => $this->panel->bouquets ?: [], 'pantallas' => (int) ($this->panel->pantallas ?: 1)];
        $cliente = DB::table('user_data')->where('user_id', $userId)->where('company_id', $this->empresa())->first(['names', 'lastname']);

        if (!$cliente) {
            throw new \DomainException('Ese cliente no es de esta empresa.');
        }

        $linea = TvLinea::where('company_id', $this->empresa())->where('user_id', $userId)->whereIn('estado', ['pendiente'])->first()
            ?? TvLinea::create([
                'company_id' => $this->empresa(), 'user_id' => $userId, 'usuario' => $this->usuarioLibre($cliente->names, $cliente->lastname),
                'clave' => $this->claveNueva(), 'bouquets' => $tv['bouquets'], 'pantallas' => $tv['pantallas'],
                'estado' => 'pendiente', 'origen' => 'plataforma', 'vinculo' => 'manual',
            ]);

        return $this->aplicarPendiente($linea);
    }

    /** Lleva al panel una línea pendiente. Si falla, queda pendiente con el motivo. */
    public function aplicarPendiente(TvLinea $linea): TvLinea
    {
        try {
            $id = $this->xui()->crear($linea->usuario, $linea->clave, (int) $linea->pantallas, $linea->bouquets ?: [], $this->nota($linea->user_id));
            $linea->update(['xui_id' => $id, 'estado' => 'activa', 'error' => null]);
        } catch (\Throwable $e) {
            $linea->update(['error' => Str::limit($e->getMessage(), 250)]);
            throw $e;
        }

        return $linea;
    }

    /** El nombre de usuario: nombre y apellido sin tildes ni espacios (como las que ya hay). */
    private function usuarioLibre(?string $nombres, ?string $apellidos): string
    {
        $base = Str::of(Str::ascii(strtok(trim((string) $nombres), ' ') . strtok(trim((string) $apellidos), ' ')))
            ->lower()->replaceMatches('/[^a-z0-9]/', '')->limit(20, '')->toString() ?: 'cliente';
        $base = strlen($base) < 4 ? $base . 'tv' : $base;

        $ocupados = TvLinea::where('company_id', $this->empresa())->where('usuario', 'like', $base . '%')->pluck('usuario')->map(fn ($u) => strtolower($u))->all();
        $usuario = $base;
        for ($i = 2; in_array($usuario, $ocupados, true); $i++) {
            $usuario = $base . $i;
        }

        return $usuario;
    }

    /**
     * La clave de una línea: la fija de la empresa (1234) si la tiene, o cinco dígitos al azar,
     * fáciles de escribir con el control del televisor. Al cambiarla siempre va al azar: poner
     * otra vez la fija no le quitaría el acceso a nadie.
     */
    private function claveNueva(bool $cambio = false): string
    {
        $fija = trim((string) $this->panel->clave_fija);
        if ($fija !== '' && !$cambio) {
            return $fija;
        }

        return str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
    }

    private function nota(?int $userId): string
    {
        return 'Plataforma Netplay' . ($userId ? " · cliente {$userId}" : '');
    }

    // ── Acciones desde la ficha ──────────────────────────────────────────────

    public function cambiarClave(TvLinea $linea): TvLinea
    {
        $this->exigirEnElPanel($linea);
        $nueva = $this->claveNueva(true);
        $this->xui()->editar((int) $linea->xui_id, $linea->usuario, $nueva, (int) $linea->pantallas, $linea->bouquets ?: [], $this->nota($linea->user_id));
        // edit_line deja la línea activa: si estaba apagada, se vuelve a apagar.
        if ($linea->estado !== 'activa') {
            $this->xui()->desactivar((int) $linea->xui_id);
        }
        $linea->update(['clave' => $nueva]);

        return $linea;
    }

    public function cambiarPaquete(TvLinea $linea, array $bouquets, int $pantallas): TvLinea
    {
        $this->exigirEnElPanel($linea);
        $this->xui()->editar((int) $linea->xui_id, $linea->usuario, $linea->clave, $pantallas, $bouquets, $this->nota($linea->user_id));
        if ($linea->estado !== 'activa') {
            $this->xui()->desactivar((int) $linea->xui_id);
        }
        $linea->update(['bouquets' => $bouquets, 'pantallas' => $pantallas]);

        return $linea;
    }

    /** Apaga o prende la línea a mano. */
    public function encender(TvLinea $linea, bool $prender): TvLinea
    {
        $this->exigirEnElPanel($linea);
        $prender ? $this->xui()->activar((int) $linea->xui_id) : $this->xui()->desactivar((int) $linea->xui_id);
        $linea->update(['estado' => $prender ? 'activa' : 'desactivada']);

        return $linea;
    }

    /** Liga una línea (traída del panel) a un cliente, o la suelta. */
    public function vincular(TvLinea $linea, ?int $userId): TvLinea
    {
        if ($userId && !DB::table('user_data')->where('user_id', $userId)->where('company_id', $this->empresa())->exists()) {
            throw new \DomainException('Ese cliente no es de esta empresa.');
        }
        $linea->update(['user_id' => $userId, 'vinculo' => $userId ? 'manual' : null, 'sugerido_user_id' => null, 'sugerido_motivo' => null]);

        return $linea;
    }

    /** Usuario, clave, servidor y lista M3U por la línea de WhatsApp Web principal. */
    public function enviarPorWhatsApp(TvLinea $linea, string $tipo = 'm3u_plus', string $salida = 'ts'): string
    {
        $tel = preg_replace('/\D/', '', (string) DB::table('user_data')->where('user_id', $linea->user_id)->where('company_id', $this->empresa())->value('phone'));
        if (strlen($tel) === 10) {
            $tel = '57' . $tel;
        }
        if (strlen($tel) < 12) {
            throw new \DomainException('El cliente no tiene un celular válido en la ficha.');
        }

        $wl = DB::table('wa_lineas')->where('company_id', $this->empresa())->where('activa', 1)->where('estado', 'connected')
            ->orderByDesc('principal')->orderBy('id')->first();
        if (!$wl) {
            throw new \DomainException('No hay una línea de WhatsApp Web conectada para enviar.');
        }

        $r = (new WhatsAppService($this->empresa(), false, 'netplay', (string) $wl->instance_id))->mensajeInformativo($tel, $this->mensajeParaElCliente($linea, $tipo, $salida));
        if (isset($r['error']) || ($r['success'] ?? true) === false) {
            throw new \RuntimeException('WhatsApp no lo envió: ' . ($r['message'] ?? $r['error'] ?? 'sin detalle'));
        }

        return $tel;
    }

    public function mensajeParaElCliente(TvLinea $linea, string $tipo = 'm3u_plus', string $salida = 'ts'): string
    {
        $u = $this->urls($linea, $tipo, $salida);

        return "📺 *Su televisión ya está lista*\n\n"
            . "*Usuario:* {$linea->usuario}\n*Clave:* {$linea->clave}\n*Servidor:* {$u['servidor']}\n\n"
            . "En *IPTV Smarters* o *TiviMate* elija «Xtream Codes» y escriba esos tres datos.\n\n"
            . 'Lista ' . (self::FORMATOS[$tipo][0] ?? 'M3U Plus') . ' (' . (self::FORMATOS[$tipo][1] ?? '') . "):\n{$u['m3u']}\n\n"
            . 'Puede verla en ' . ((int) $linea->pantallas === 1 ? '1 pantalla' : "{$linea->pantallas} pantallas a la vez") . '.';
    }

    /** @return array{servidor:string, m3u:string} */
    public function urls(TvLinea $linea, string $tipo = 'm3u_plus', string $salida = 'ts'): array
    {
        $base = $this->panel->urlClientes();
        $tipo = isset(self::FORMATOS[$tipo]) ? $tipo : 'm3u_plus';
        $salida = isset(self::SALIDAS[$salida]) ? $salida : 'ts';

        return [
            'servidor' => $base,
            // El enlace corto del XUI, el que usa la empresa: /playlist/usuario/clave/formato.
            'm3u' => "{$base}/playlist/" . rawurlencode($linea->usuario) . '/' . rawurlencode($linea->clave) . "/{$tipo}" . ($salida !== 'ts' ? "?output={$salida}" : ''),
        ];
    }

    private function exigirEnElPanel(TvLinea $linea): void
    {
        if (!$linea->xui_id) {
            throw new \DomainException('La línea todavía no está creada en el panel.');
        }
    }

    // ── Sincronización (tarea programada) ────────────────────────────────────

    /**
     * Trae las líneas del panel, liga a su cliente las que se reconocen sin duda y deja las
     * dudosas para revisar. No cambia nada en el panel.
     *
     * @return array{lineas:int, nuevas:int, vinculadas:int, por_revisar:int}
     */
    public function traerDelPanel(): array
    {
        $empresa = $this->empresa();
        $nuevas = 0;
        $vistos = [];

        foreach ($this->xui()->lineas() as $l) {
            $id = (int) $l['id'];
            if ($id === (int) $this->panel->monitor_xui_id) {
                continue; // La línea de monitoreo de las previas no es de ningún cliente.
            }
            $vistos[] = $id;
            $linea = TvLinea::firstOrNew(['company_id' => $empresa, 'xui_id' => $id]);
            $nuevas += $linea->exists ? 0 : 1;
            $apagada = (string) $l['enabled'] !== '1' || (string) ($l['admin_enabled'] ?? '1') !== '1';

            $estado = match (true) {
                !$apagada => 'activa',
                in_array($linea->estado, ['suspendida', 'retirada'], true) => $linea->estado,
                default => 'desactivada',
            };

            $actividad = json_decode((string) ($l['last_activity_array'] ?? ''), true)['date_end'] ?? null;
            $linea->fill([
                'usuario' => (string) $l['username'], 'clave' => (string) $l['password'],
                'pantallas' => (int) ($l['max_connections'] ?? 1), 'estado' => $estado,
                'ultima_actividad_en' => $actividad ? Carbon::createFromTimestamp((int) $actividad) : $linea->ultima_actividad_en,
            ]);
            if (!$linea->exists) {
                $linea->origen = 'importada';
            }
            $linea->save();
        }

        // Las que se borraron en el panel: se quedan como historia, sin id.
        TvLinea::where('company_id', $empresa)->whereNotNull('xui_id')->whereNotIn('xui_id', $vistos ?: [0])
            ->update(['xui_id' => null, 'estado' => 'retirada', 'error' => 'Ya no está en el panel.']);

        $this->completarBouquets();
        [$vinculadas, $porRevisar] = $this->vincularAutomaticamente();

        return ['lineas' => count($vistos), 'nuevas' => $nuevas, 'vinculadas' => $vinculadas, 'por_revisar' => $porRevisar];
    }

    /** get_lines no trae el bouquet: se pide línea por línea, solo para las que no lo tienen. */
    private function completarBouquets(): void
    {
        TvLinea::where('company_id', $this->empresa())->whereNotNull('xui_id')->whereNull('bouquets')->limit(400)->get()
            ->each(function (TvLinea $l) {
                try {
                    $l->update(['bouquets' => json_decode((string) ($this->xui()->linea((int) $l->xui_id)['bouquet'] ?? '[]'), true) ?: []]);
                } catch (\Throwable) {
                    // Se completa en la próxima pasada.
                }
            });
    }

    /**
     * Liga cada línea sin cliente con el que le corresponde: el usuario es el nombre y el
     * apellido del cliente (erikapalacio), su usuario PPPoE, su cédula o su celular. Si hay
     * exactamente un cliente que encaja, se liga; si hay varios o solo se parece, queda para
     * revisar con la sugerencia.
     */
    private function vincularAutomaticamente(): array
    {
        $empresa = $this->empresa();
        $sueltas = TvLinea::where('company_id', $empresa)->whereNull('user_id')->where(fn ($q) => $q->whereNull('vinculo')->orWhere('vinculo', 'revisar'))->get();
        if ($sueltas->isEmpty()) {
            return [0, 0];
        }

        $yaTienen = TvLinea::where('company_id', $empresa)->whereNotNull('user_id')->where('estado', '!=', 'retirada')->pluck('user_id')->flip();
        $indice = [];
        $agregar = function (string $clave, int $userId, string $motivo) use (&$indice) {
            if (strlen($clave) >= 5) {
                $indice[$clave][$userId] = $motivo;
            }
        };

        DB::table('user_data')->where('company_id', $empresa)->where('active', 1)
            ->get(['user_id', 'names', 'lastname', 'pppoe_user', 'dni', 'phone'])
            ->each(function ($c) use ($agregar) {
                $n = fn ($s) => preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii((string) $s)));
                $nombres = preg_split('/\s+/', trim((string) $c->names)) ?: [];
                $apellidos = preg_split('/\s+/', trim((string) $c->lastname)) ?: [];
                $agregar($n(($nombres[0] ?? '') . ($apellidos[0] ?? '')), (int) $c->user_id, 'nombre y apellido');
                $agregar($n($c->names . $c->lastname), (int) $c->user_id, 'nombre completo');
                $agregar($n(($nombres[0] ?? '') . ($apellidos[0] ?? '') . ($apellidos[1] ?? '')), (int) $c->user_id, 'nombre y apellidos');
                $agregar($n($c->pppoe_user), (int) $c->user_id, 'usuario PPPoE');
                $agregar($n($c->dni), (int) $c->user_id, 'cédula');
                $agregar(substr(preg_replace('/\D/', '', (string) $c->phone), -10), (int) $c->user_id, 'celular');
            });

        $vinculadas = 0;
        $porRevisar = 0;

        foreach ($sueltas as $l) {
            $u = preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii($l->usuario)));
            $candidatos = array_diff_key($indice[$u] ?? [], $yaTienen->all());

            if (count($candidatos) === 1) {
                $userId = (int) array_key_first($candidatos);
                $l->update(['user_id' => $userId, 'vinculo' => 'auto', 'sugerido_user_id' => null, 'sugerido_motivo' => 'Por ' . $candidatos[$userId]]);
                $yaTienen[$userId] = true;
                $vinculadas++;
                continue;
            }

            // Varios con el mismo nombre, o uno cuyo usuario empieza igual: se sugiere y se revisa.
            $parecido = $candidatos ?: collect($indice)->filter(fn ($v, $k) => strlen($u) >= 6 && (str_starts_with($k, $u) || str_starts_with($u, $k)))->first() ?? [];
            $parecido = array_diff_key($parecido, $yaTienen->all());
            if ($parecido) {
                $l->update(['vinculo' => 'revisar', 'sugerido_user_id' => (int) array_key_first($parecido), 'sugerido_motivo' => count($parecido) > 1 ? count($parecido) . ' clientes con ese nombre' : 'Se parece al ' . reset($parecido)]);
                $porRevisar++;
            } elseif ($l->vinculo === 'revisar') {
                $l->update(['vinculo' => null, 'sugerido_user_id' => null, 'sugerido_motivo' => null]);
            }
        }

        return [$vinculadas, $porRevisar];
    }

    /** Reintenta las líneas que quedaron pendientes porque el panel no respondió. */
    public function crearPendientes(): int
    {
        $hechas = 0;
        foreach (TvLinea::where('company_id', $this->empresa())->where('estado', 'pendiente')->get() as $l) {
            try {
                $this->aplicarPendiente($l);
                $hechas++;
            } catch (\Throwable) {
                // Queda con el motivo para la ficha; se intenta en la próxima pasada.
            }
        }

        return $hechas;
    }

    /**
     * Con la cartera prendida: el cliente suspendido o retirado ve la TV apagada, y al quedar al
     * día vuelve. Solo se reactiva lo que apagó la plataforma; lo que alguien apagó a mano en el
     * panel se respeta.
     *
     * @return array{suspendidas:int, reactivadas:int, retiradas:int}
     */
    public function seguirLaCartera(): array
    {
        $r = ['suspendidas' => 0, 'reactivadas' => 0, 'retiradas' => 0];
        if (!$this->panel->sincronizar_cartera) {
            return $r;
        }

        $lineas = TvLinea::where('tv_lineas.company_id', $this->empresa())->whereNotNull('tv_lineas.xui_id')->whereNotNull('tv_lineas.user_id')
            ->whereIn('tv_lineas.estado', ['activa', 'suspendida', 'retirada'])
            ->join('user_data as u', fn ($j) => $j->on('u.user_id', '=', 'tv_lineas.user_id')->on('u.company_id', '=', 'tv_lineas.company_id'))
            ->get(['tv_lineas.*', 'u.active as cliente_activo', 'u.status_internet_id']);

        foreach ($lineas as $l) {
            $retirado = (int) $l->cliente_activo !== 1;
            $suspendido = (int) $l->status_internet_id === 2;

            try {
                if (($retirado || $suspendido) && $l->estado === 'activa') {
                    $this->xui()->desactivar((int) $l->xui_id);
                    TvLinea::whereKey($l->id)->update(['estado' => $retirado ? 'retirada' : 'suspendida']);
                    $r[$retirado ? 'retiradas' : 'suspendidas']++;
                } elseif (!$retirado && !$suspendido && in_array($l->estado, ['suspendida', 'retirada'], true)) {
                    // Pagó, o lo reinstalaron.
                    $this->xui()->activar((int) $l->xui_id);
                    TvLinea::whereKey($l->id)->update(['estado' => 'activa']);
                    $r['reactivadas']++;
                }
            } catch (\Throwable $e) {
                Log::warning('[TV] No se pudo seguir la cartera', ['linea' => $l->id, 'error' => $e->getMessage()]);
            }
        }

        return $r;
    }

    /** Lo que haría seguirLaCartera() hoy, sin hacerlo: para mostrarlo antes de prenderla. */
    public function previaDeCartera(): array
    {
        $q = TvLinea::where('tv_lineas.company_id', $this->empresa())->whereNotNull('tv_lineas.xui_id')->whereNotNull('tv_lineas.user_id')
            ->join('user_data as u', fn ($j) => $j->on('u.user_id', '=', 'tv_lineas.user_id')->on('u.company_id', '=', 'tv_lineas.company_id'));

        return [
            'se_apagarian' => (clone $q)->where('tv_lineas.estado', 'activa')->where(fn ($w) => $w->where('u.active', '!=', 1)->orWhere('u.status_internet_id', 2))->count(),
            'se_prenderian' => (clone $q)->whereIn('tv_lineas.estado', ['suspendida', 'retirada'])->where('u.active', 1)->where('u.status_internet_id', '!=', 2)->count(),
        ];
    }
}
