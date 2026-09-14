<?php

namespace App\Services\Tickets;

use App\Managers\Interfaces\ConectionRouterManagerInterface;
use App\Models\Alerta;
use App\Models\OltOnt;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Acs\EquiposDelAcs;
use App\Services\HuaweiSnmpReader;
use App\Services\Olt\EstadoDeUnaOnt;
use App\Services\Red\ClienteEnElRouter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Lo que se puede saber del cliente antes de mandar a alguien.
 *
 * Al abrir un ticket se mira la cuenta, la ONT en la OLT (y por qué se cayó),
 * si hay una falla en su puerto, el MikroTik y el TR-069, y se deja todo como
 * novedad del ticket con una conclusión. El técnico sale sabiendo qué va a
 * encontrar, y varios casos (mora, corte de luz, falla general) ni necesitan
 * visita.
 */
class DiagnosticoDeTicket
{
    public const MARCA = '🩺 Diagnóstico automático';

    /** Causas de caída de Huawei que son de la parte óptica (fibra). */
    private const CAUSAS_DE_FIBRA = [1, 2, 3, 4, 5, 6];
    private const CAUSA_SIN_ENERGIA = 13;

    public function __construct(private int $ticketId) {}

    /** Corre el diagnóstico en otro proceso: consultar OLT y MikroTik tarda. */
    public static function lanzar(int $ticketId): void
    {
        try {
            $php = (new PhpExecutableFinder())->find() ?: 'php';

            exec(sprintf(
                'nohup %s %s tickets:diagnosticar %d >> %s 2>&1 &',
                escapeshellarg($php),
                escapeshellarg(base_path('artisan')),
                $ticketId,
                escapeshellarg(storage_path('logs/diagnosticos-tickets.log'))
            ));
        } catch (\Throwable $e) {
            // Crear el ticket es lo importante: sin diagnóstico igual sirve.
            Log::warning('[Diagnóstico] No se pudo lanzar', ['ticket' => $ticketId, 'error' => $e->getMessage()]);
        }
    }

    /** Diagnostica y guarda la novedad. Devuelve el texto, o null si no aplica. */
    public function ejecutar(): ?string
    {
        $ticket = DB::table('tickets')->where('id', $this->ticketId)->first();

        if (!$ticket || !$ticket->user_id) {
            return null;
        }

        $empresa = (int) $ticket->company_id;

        $cliente = DB::table('users as u')
            ->join('user_data as ud', 'ud.user_id', '=', 'u.id')
            ->leftJoin('internet_status as ist', 'ist.id', '=', 'ud.status_internet_id')
            ->where('u.id', $ticket->user_id)
            ->where('u.company_id', $empresa)
            ->first(['u.id', 'ud.names', 'ud.lastname', 'ist.name as estado_servicio']);

        if (!$cliente) {
            return null;
        }

        // Los servicios de red toman la empresa de la sesión, que en un proceso
        // de consola no existe.
        $autor = User::find($ticket->user_created_id ?: $ticket->technical_id);
        session(['user' => ($autor && (int) $autor->company_id === $empresa) ? $autor : (object) ['company_id' => $empresa]]);

        $cuenta = $this->cuenta($empresa, (int) $cliente->id, (string) $cliente->estado_servicio);
        $ont    = $this->ont($empresa, (int) $cliente->id);
        $router = $this->router($empresa, (int) $cliente->id);
        $acs    = $this->tr069($empresa, (int) $cliente->id);

        $texto = implode("\n", array_merge(
            [self::MARCA, ''],
            array_filter([$cuenta['linea'], ...$ont['lineas'], ...$router['lineas'], $acs['linea']]),
            ['', '👉 ' . $this->conclusion($cuenta, $ont, $router)]
        ));

        TicketNote::create([
            'ticket_id'  => $this->ticketId,
            'company_id' => $empresa,
            // La novedad necesita autor: quien creó el ticket, o el cliente.
            'user_id'    => $ticket->user_created_id ?: ($ticket->technical_id ?: $ticket->user_id),
            'note'       => $texto,
        ]);

        return $texto;
    }

    // ── Revisiones ────────────────────────────────────────────────────────

    private function cuenta(int $empresa, int $userId, string $estado): array
    {
        $vencidas = DB::table('det_facturations as d')
            ->join('cab_facturations as cab', 'cab.id', '=', 'd.cab_id')
            ->where('cab.user_id', $userId)
            ->where('cab.company_id', $empresa)
            ->where('d.paid', 0)
            ->whereDate('d.date_facturation', '<', now()->toDateString())
            ->count();

        $activo = str_starts_with(strtoupper($estado), 'ACT');

        return [
            'activo'   => $activo,
            'vencidas' => $vencidas,
            'linea'    => ($activo ? '✅' : '⛔') . ' Servicio: ' . ($estado ?: 'sin estado')
                . ($vencidas ? " · {$vencidas} factura(s) vencida(s)" : ' · al día'),
        ];
    }

    private function ont(int $empresa, int $userId): array
    {
        $resultado = ['lineas' => [], 'hay' => false, 'error' => false, 'en_linea' => null, 'causa' => null, 'estado' => null, 'potencia' => null, 'corte' => null];

        // olt_onts.user_data_id guarda users.id, pese al nombre.
        $asignada = OltOnt::where('user_data_id', $userId)
            ->whereHas('olt', fn ($q) => $q->where('company_id', $empresa))
            ->with('olt')
            ->first();

        if (!$asignada) {
            $resultado['lineas'][] = '➖ ONT: el cliente no tiene equipo asignado en el sistema';
            return $resultado;
        }

        $resultado['hay'] = true;
        $olt = $asignada->olt;
        $donde = "{$olt->name} · puerto {$asignada->fsp} ONT {$asignada->ont_id}";

        $corte = Alerta::where('company_id', $empresa)->abiertas()
            ->where('clave', "pon:{$olt->id}:{$asignada->fsp}")
            ->first();

        if ($corte) {
            $resultado['corte'] = $asignada->fsp;
            $resultado['lineas'][] = "🚨 Falla abierta en su puerto: {$corte->titulo}";
        }

        $vivo = EstadoDeUnaOnt::de($olt, (string) $asignada->fsp, (int) $asignada->ont_id, true);

        if (!empty($vivo['error'])) {
            $resultado['error'] = true;
            $resultado['lineas'][] = "⚠️ ONT ({$donde}): {$vivo['error']}";
            return $resultado;
        }

        $resultado['en_linea'] = ($vivo['status'] ?? null) === 'online';

        if ($resultado['en_linea']) {
            $resultado['estado']   = $vivo['estado'] ?? null;
            $resultado['potencia'] = $vivo['potencia'] ?? null;
            $problema = in_array($resultado['estado'], ['baja', 'critica', 'saturada'], true);
            $dbm = $resultado['potencia'] !== null ? $resultado['potencia'] . ' dBm' : 'sin medición';

            $resultado['lineas'][] = ($problema ? '📉' : '✅') . " ONT en línea ({$donde}) · señal {$this->enCastellano($resultado['estado'])} ({$dbm})";

            return $resultado;
        }

        $resultado['causa'] = $this->causaDeCaida($olt, (string) $asignada->fsp, (int) $asignada->ont_id);
        $porque = match (true) {
            in_array($resultado['causa'], self::CAUSAS_DE_FIBRA, true) => 'perdió la señal óptica (fibra)',
            $resultado['causa'] === self::CAUSA_SIN_ENERGIA          => 'se quedó sin energía',
            default                                                  => 'causa desconocida',
        };

        $resultado['lineas'][] = "❌ ONT apagada ({$donde}) · {$porque}";

        return $resultado;
    }

    private function router(int $empresa, int $userId): array
    {
        $resultado = ['lineas' => [], 'leido' => false, 'tipo' => null, 'sesion' => false, 'arp' => false, 'moroso' => false];

        try {
            $hay = (new ClienteEnElRouter(app(ConectionRouterManagerInterface::class), $empresa))->queHay($userId);
        } catch (\Throwable $e) {
            $hay = ['ok' => false, 'error' => $e->getMessage()];
        }

        if (!($hay['ok'] ?? false)) {
            $resultado['lineas'][] = '⚠️ MikroTik: ' . ($hay['error'] ?? 'no se pudo leer');
            return $resultado;
        }

        $resultado['leido']  = true;
        $resultado['tipo']   = $hay['tipo'];
        $resultado['moroso'] = collect($hay['listas'])->contains(fn ($l) => strtolower((string) $l['lista']) === 'morosos');

        if ($hay['tipo'] === 'pppoe') {
            $conSesion = collect($hay['pppoe'])->first(fn ($p) => !empty($p['sesion']));
            $resultado['sesion'] = (bool) $conSesion;

            $resultado['lineas'][] = match (true) {
                (bool) $conSesion        => "✅ PPPoE conectado · IP {$conSesion['sesion']['ip']} · hace {$conSesion['sesion']['desde']}",
                empty($hay['pppoe'])     => '❌ PPPoE: no hay usuario creado en el MikroTik',
                collect($hay['pppoe'])->every(fn ($p) => !$p['habilitado']) => '⛔ PPPoE: el usuario está deshabilitado',
                default                  => '❌ PPPoE: el usuario existe pero no hay sesión abierta',
            };
        } else {
            $arp = collect($hay['arp'])->first(fn ($a) => $a['habilitado']);
            $resultado['arp'] = (bool) $arp;
            $resultado['lineas'][] = $arp
                ? "✅ IP fija {$arp['ip']} cargada en el ARP"
                : '❌ IP fija: no tiene entrada activa en el ARP del MikroTik';
        }

        if ($resultado['moroso']) {
            $resultado['lineas'][] = '⛔ Está en la lista de morosos del MikroTik (sin navegación)';
        }

        return $resultado;
    }

    private function tr069(int $empresa, int $userId): array
    {
        try {
            $equipo = (new EquiposDelAcs($empresa))->deCliente($userId);
        } catch (\Throwable $e) {
            return ['linea' => '➖ TR-069: no se pudo consultar'];
        }

        if (!$equipo) {
            return ['linea' => '➖ TR-069: el equipo no reporta (sin gestión remota)'];
        }

        $ultimo = $equipo['ultimo_reporte'] ?? null;
        $dispositivos = count($equipo['equipos'] ?? []);

        return ['linea' => '📡 TR-069: reporta' . ($ultimo ? ' · último reporte ' . \Carbon\Carbon::parse($ultimo)->setTimezone(config('app.timezone'))->format('d/m H:i') : '')
            . " · {$dispositivos} dispositivo(s) conectados"];
    }

    private function conclusion(array $cuenta, array $ont, array $router): string
    {
        return match (true) {
            !$cuenta['activo'] || $router['moroso']
                => 'Servicio suspendido' . ($cuenta['vencidas'] ? " por mora ({$cuenta['vencidas']} factura/s vencida/s)" : '') . ': se resuelve con el pago o la reactivación, no hace falta técnico.',
            $ont['corte'] !== null
                => "Falla general en el puerto {$ont['corte']}: ya está en alertas; no hace falta visita individual.",
            $ont['en_linea'] === false && in_array($ont['causa'], self::CAUSAS_DE_FIBRA, true)
                => 'Caída por fibra: hay que enviar técnico (fibra, conector o roseta).',
            $ont['en_linea'] === false && $ont['causa'] === self::CAUSA_SIN_ENERGIA
                => 'El equipo se quedó sin energía: pedirle al cliente que revise el enchufe y la luz antes de enviar técnico.',
            $ont['en_linea'] === false
                => 'La ONT está apagada y no se sabe por qué: confirmar con el cliente si el equipo tiene luces.',
            in_array($ont['estado'], ['critica', 'saturada', 'baja'], true)
                => "Señal óptica {$this->enCastellano($ont['estado'])}: revisar empalmes, conector o roseta.",
            $router['leido'] && $router['tipo'] === 'pppoe' && !$router['sesion']
                => 'La ONT está en línea pero no hay sesión PPPoE: revisar el usuario y la clave cargados en el equipo.',
            $router['leido'] && $router['tipo'] === 'static' && !$router['arp']
                => 'Sin entrada en el ARP del MikroTik: revisar la IP asignada al cliente.',
            $ont['error'] || !$ont['hay'] || !$router['leido']
                => 'No se pudo revisar todo: completar el diagnóstico a mano.',
            default
                => 'La red llega bien hasta el equipo: probablemente es el WiFi o un dispositivo del cliente.',
        };
    }

    // ── Interno ───────────────────────────────────────────────────────────

    private function causaDeCaida($olt, string $fsp, int $ontId): ?int
    {
        if (strtolower((string) $olt->brand) !== 'huawei') {
            return null;
        }

        try {
            return (new HuaweiSnmpReader($olt))->causasDeCaida()["{$fsp}:{$ontId}"] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function enCastellano(?string $estado): string
    {
        return [
            'buena' => 'buena', 'regular' => 'regular', 'baja' => 'baja', 'critica' => 'crítica',
            'saturada' => 'saturada', 'sin_dato' => 'sin dato', 'sin_senal' => 'sin señal',
        ][$estado ?? ''] ?? ($estado ?: 'sin dato');
    }
}
