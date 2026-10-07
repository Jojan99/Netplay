<?php

namespace App\Services;

use App\Repositories\Interfaces\RouterRepositoryInterface;
use Illuminate\Support\Facades\DB;
use App\Services\Red\IdentidadEnElRouter;
use RouterOS\Query;

class AutoSuspendService
{
    public function __construct(
        private RouterRepositoryInterface $routerRepo
    ) {}

    // ── Log dedicado ─────────────────────────────────────────────────────────

    private function logPath(): string
    {
        $path = storage_path('logs/auto-suspend-' . now()->format('Y-m-d') . '.log');

        if (!file_exists($path)) {
            touch($path);
            @chmod($path, 0664);
            @chown($path, 'www-data');
        }

        return $path;
    }

    private function writelog(string $line): void
    {
        try {
            file_put_contents($this->logPath(), '[' . now()->format('Y-m-d H:i:s') . '] ' . $line . PHP_EOL, FILE_APPEND);
        } catch (\Throwable) {
            \Illuminate\Support\Facades\Log::info('[auto-suspend] ' . $line);
        }
    }

    public function logRun(string $job, int $companies): void
    {
        $this->writelog("════════════════════════════════════════════════════");
        $this->writelog("INICIO JOB: {$job} | empresas configuradas: {$companies}");
        $this->writelog("════════════════════════════════════════════════════");
    }

    // ── Helpers MikroTik ─────────────────────────────────────────────────────

    /**
     * Abre una conexión con el MikroTik de la empresa.
     * Retorna null si no hay router configurado o si la conexión falla.
     *
     * Protegido a propósito: así las pruebas pueden correr toda la suspensión
     * contra un router simulado, sin tocar el de la empresa.
     */
    protected function mikrotikClient(int $companyId): mixed
    {
        try {
            $router = $this->routerRepo->getRouterByCompany($companyId);
            if (!$router) return null;

            $parsed = \App\Helpers\RouterHostParser::parse($router->host, $router->port ?? null);

            return new \RouterOS\Client([
                'host'    => $parsed['host'],
                'user'    => $router->user,
                'pass'    => $router->pass,
                'port'    => $parsed['port'],
                'timeout' => 30,
            ]);
        } catch (\Throwable $e) {
            $this->writelog("ERROR conexión MikroTik empresa {$companyId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Desactiva o activa entradas ARP de una lista de usuarios.
     * $cmd  = '/ip/arp/disable' | '/ip/arp/enable'
     * $verb = 'SUSPENDIDO'      | 'REACTIVADO'
     */
    /**
     * Retorna array de user_ids que fueron aplicados exitosamente en MikroTik.
     * Si no hay conexión retorna null (fallo total).
     */
    private function applyArpStatus(int $companyId, array $userIds, string $cmd, string $verb): ?array
    {
        if (empty($userIds)) return [];

        $action = ($cmd === '/ip/arp/disable') ? 'SUSPENDER' : 'REACTIVAR';

        $this->writelog("─────────────────────────────────────────────────────");
        $this->writelog("EMPRESA {$companyId} │ ACCIÓN: {$action} │ Usuarios a procesar: " . count($userIds));

        $api = $this->mikrotikClient($companyId);
        if (!$api) {
            $this->writelog("  ✗ Sin conexión MikroTik — operación ARP omitida. Se reintentará en el próximo ciclo.");
            $this->writelog("─────────────────────────────────────────────────────");
            return null; // null = fallo de conexión total
        }

        $applied = []; // user_ids que sí se procesaron en MikroTik

        $found = 0; $done = 0; $notFound = 0; $errors = 0;

        try {
            // Se lee el ARP una sola vez y se reconoce a cada cliente con el
            // criterio común: documento, nombre en la plataforma de origen o su
            // IP fija. Los clientes importados de WispHub tienen en el comment
            // el nombre de servicio de allá, no la cédula.
            $arpTodos = $api->query(new Query('/ip/arp/print'))->read();

            foreach ($userIds as $userId) {
                $identidad = IdentidadEnElRouter::deUsuario((int) $userId, $companyId);
                $label = $identidad
                    ? "ID:{$userId} | {$identidad['nombre']} | DNI:{$identidad['dni']} | user:{$identidad['username']}"
                    : "ID:{$userId} (sin datos)";

                if (!$identidad) {
                    $this->writelog("  ✗ {$label} — sin ficha, omitido");
                    $notFound++;
                    continue;
                }

                try {
                    $arpEntries = IdentidadEnElRouter::suyas($arpTodos, $identidad, $companyId);

                    if (empty($arpEntries)) {
                        $this->writelog("  ✗ {$label}");
                        $this->writelog("     NO encontrado en ARP (se buscó por " . IdentidadEnElRouter::explicar($identidad) . ")");
                        $notFound++;
                        continue;
                    }

                    $foundBy = $arpEntries[0]['via'];
                    $found++;

                    foreach ($arpEntries as $arp) {
                        $disabledState = ($arp['disabled'] ?? 'false') === 'true' ? 'YA-DESACTIVADO' : 'activo';
                        $this->writelog("  ✓ {$label}");
                        $this->writelog("     Encontrado por {$foundBy} | IP:{$arp['address']} | MAC:" . ($arp['mac-address'] ?? '') . " | Estado-ARP:{$disabledState} | .id:{$arp['.id']}");

                        if (empty($arp['.id'])) continue;
                        $api->query(
                            (new Query($cmd))->equal('.id', $arp['.id'])
                        )->read();
                        $this->writelog("     → Comando '{$cmd}' ejecutado sobre .id:{$arp['.id']}");
                    }

                    $this->writelog("     ✔ {$verb} correctamente en MikroTik");
                    $applied[] = $userId;
                    $done++;

                } catch (\Throwable $e) {
                    $this->writelog("  ! {$label} — ERROR ARP: " . $e->getMessage());
                    $errors++;
                }
            }
        } finally {
            if (method_exists($api, 'disconnect')) {
                $api->disconnect();
            }
        }

        $this->writelog("RESUMEN {$action}: encontrados={$found} | {$verb}s={$done} | no encontrados={$notFound} | errores={$errors}");
        $this->writelog("─────────────────────────────────────────────────────");
        return $applied;
    }

    /**
     * Corta o devuelve el servicio en el router, según cómo se conecte cada cliente.
     *
     * Con IP fija se apaga o se enciende su entrada del ARP. Un cliente PPPoE no tiene
     * entrada ahí: lo que se corta es su credencial (el «secret») y además se le baja la
     * sesión, o sigue navegando hasta que reconecte. Antes este proceso sólo sabía de ARP,
     * así que a los PPPoE ni los suspendía ni los reactivaba: salían como «no encontrado».
     *
     * @param  array<int,int> $userIds  ids de «users»
     * @return ?array<int,int>  los que el router confirmó; null si no hubo conexión con ninguno
     */
    /**
     * Deja al cliente en el router como está en la plataforma: cortado si está
     * suspendido, habilitado si está activo. Es lo que corrige un descuadre de la
     * revisión «plataforma contra router» (ver PlataformaContraRouter).
     *
     * @return bool|null true si el router quedó como la plataforma; null si no hubo conexión.
     */
    public function aplicarEstadoDeLaPlataforma(int $companyId, int $userId): ?bool
    {
        $estado = DB::table('user_data')->where('company_id', $companyId)->where('user_id', $userId)->where('active', 1)->value('status_internet_id');

        if ($estado === null) {
            return false;
        }

        $hechos = $this->aplicarEnElRouter($companyId, [$userId], (int) $estado === 2);

        return $hechos === null ? null : in_array($userId, array_map('intval', $hechos), true);
    }

    private function aplicarEnElRouter(int $companyId, array $userIds, bool $suspender): ?array
    {
        if (empty($userIds)) return [];

        $pppoe = DB::table('user_data')
            ->whereIn('user_id', $userIds)
            ->where('company_id', $companyId)
            ->where('connection_type', 'pppoe')
            ->get(['user_id', 'names', 'lastname', 'dni', 'pppoe_user', 'router_id']);

        $fijos = array_values(array_diff(array_map('intval', $userIds), $pppoe->pluck('user_id')->map(fn ($i) => (int) $i)->all()));

        $hechos = [];
        $sinConexion = false;

        if ($fijos) {
            $r = $this->applyArpStatus($companyId, $fijos, $suspender ? '/ip/arp/disable' : '/ip/arp/enable', $suspender ? 'SUSPENDIDO' : 'REACTIVADO');
            $r === null ? $sinConexion = true : $hechos = $r;
        }

        if ($pppoe->isEmpty()) {
            return $sinConexion && !$hechos ? null : $hechos;
        }

        $verbo = $suspender ? 'SUSPENDIDO' : 'REACTIVADO';
        $this->writelog("EMPRESA {$companyId} │ PPPoE │ " . ($suspender ? 'SUSPENDER' : 'REACTIVAR') . ' │ Usuarios a procesar: ' . $pppoe->count());

        $conexion = app(\App\Managers\Interfaces\ConectionRouterManagerInterface::class);
        $caidos = 0;

        foreach ($pppoe as $c) {
            $label = "ID:{$c->user_id} | " . trim("{$c->names} {$c->lastname}") . " | DNI:{$c->dni} | pppoe:{$c->pppoe_user}";
            $usuario = trim((string) $c->pppoe_user);

            if ($usuario === '') {
                $this->writelog("  ✗ {$label} — es PPPoE pero no tiene usuario configurado, omitido");
                continue;
            }

            try {
                // El router del cliente; si no tiene uno asignado, el de la empresa.
                $router = $c->router_id ? $this->routerRepo->getRouterById((int) $c->router_id, $companyId) : null;
                $token  = $router->token ?? $this->routerRepo->getTokenByCompany($companyId);

                if (!$token) {
                    $this->writelog("  ✗ {$label} — la empresa no tiene router configurado");
                    $caidos++;
                    continue;
                }

                $servicio = new \App\Services\Red\ServicioPppoe($conexion, $token);
                $suspender ? $servicio->suspender($usuario) : $servicio->reactivar($usuario);

                // suspender()/reactivar() no avisan si la credencial no existe: se comprueba
                // cómo quedó antes de darlo por hecho y tocar la plataforma.
                $secret = $servicio->secret($usuario);

                if (!$secret) {
                    $this->writelog("  ✗ {$label} — la credencial no existe en el router");
                    continue;
                }

                if ((($secret['disabled'] ?? 'false') === 'true') !== $suspender) {
                    $this->writelog("  ! {$label} — el router no aplicó el cambio");
                    continue;
                }

                $this->writelog("  ✓ {$label} — {$verbo} en el router (PPPoE)");
                $hechos[] = (int) $c->user_id;
            } catch (\Throwable $e) {
                $this->writelog("  ! {$label} — ERROR PPPoE: " . $e->getMessage());
                $caidos++;
            }
        }

        // Nada aplicado y todo fue falla de conexión: se trata como router caído, para reintentar.
        if (!$hechos && ($sinConexion || $caidos === $pppoe->count()) && ($sinConexion || !$fijos)) {
            return null;
        }

        return $hechos;
    }

    // ── Lógica principal ─────────────────────────────────────────────────────

    /**
     * Suspende clientes con >= $minInvoices cabs pendientes de pago.
     * Actualiza user_data.STATUS = 1 y desactiva ARP en MikroTik.
     */
    public function suspendOverdue(int $companyId, int $minInvoices): int
    {
        $overdueUsers = DB::table('cab_facturations as cb')
            ->join('user_data as ud', 'ud.user_id', '=', 'cb.user_id')
            ->join('users', 'users.id', '=', 'cb.user_id')
            ->where('users.company_id', $companyId)
            // Sólo clientes vigentes que hoy tienen servicio: ni eliminados ni ya suspendidos.
            ->where('ud.active', 1)
            ->where('ud.status_internet_id', 1)
            ->whereNotIn('users.profile_id', function ($q) use ($companyId) {
                $q->select('id')->from('profiles')
                    ->where('company_id', $companyId)
                    ->whereIn('name', ['ADMIN', 'TECNICO', 'CONTADOR']);
            })
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('det_facturations as df')
                    ->whereColumn('df.cab_id', 'cb.id')
                    ->where('df.paid', 0)->whereNull('df.anulada_en')
                    ->where('df.abone', '!=', 1);
            })
            ->select('cb.user_id', DB::raw('COUNT(DISTINCT cb.id) as cab_count'))
            ->groupBy('cb.user_id')
            ->havingRaw('COUNT(DISTINCT cb.id) >= ?', [$minInvoices])
            ->get();

        if ($overdueUsers->isEmpty()) {
            $this->writelog("suspendOverdue empresa={$companyId} → 0 clientes con deuda >= {$minInvoices} factura(s)");
            return 0;
        }

        $userIds = $overdueUsers->pluck('user_id')->toArray();
        $total   = count($userIds);

        // Cargar detalle de deuda por cliente
        $debtDetail = DB::table('det_facturations as df')
            ->join('cab_facturations as cb', 'cb.id', '=', 'df.cab_id')
            ->join('user_data as ud', 'ud.user_id', '=', 'cb.user_id')
            ->whereIn('cb.user_id', $userIds)
            ->where('df.paid', 0)->whereNull('df.anulada_en')
            ->where('df.abone', '!=', 1)
            ->select(
                'cb.user_id',
                'ud.names', 'ud.lastname', 'ud.dni',
                DB::raw('COUNT(df.id) as det_count'),
                DB::raw('SUM(df.price_total - df.price_discount - df.price_abone) as total_deuda')
            )
            ->groupBy('cb.user_id', 'ud.names', 'ud.lastname', 'ud.dni')
            ->get()
            ->keyBy('user_id');

        $this->writelog("suspendOverdue empresa={$companyId} minFacturas={$minInvoices} → {$total} cliente(s) elegibles para suspensión:");
        foreach ($overdueUsers as $row) {
            $d = $debtDetail->get($row->user_id);
            $nombre  = $d ? "{$d->names} {$d->lastname}" : '?';
            $dni     = $d->dni ?? '?';
            $deuda   = $d ? number_format((float)$d->total_deuda, 2) : '?';
            $dets    = $d->det_count ?? '?';
            $cabs    = $row->cab_count;
            $this->writelog("  Cliente ID:{$row->user_id} | {$nombre} | DNI:{$dni} | Cabs:{$cabs} | Dets pendientes:{$dets} | Deuda total:\${$deuda}");
        }

        // 1. Primero aplicar en MikroTik
        $applied = $this->aplicarEnElRouter($companyId, $userIds, true);

        if ($applied === null) {
            $this->writelog("suspendOverdue empresa={$companyId} → MikroTik sin conexión, BD sin cambios.");
            return 0;
        }

        if (empty($applied)) {
            $this->writelog("suspendOverdue empresa={$companyId} → ningún cliente encontrado en el router (ARP ni PPPoE), BD sin cambios.");
            return 0;
        }

        // 2. Actualizar BD solo con los confirmados por MikroTik
        DB::table('user_data')->whereIn('user_id', $applied)->update(['STATUS' => 1, 'status_internet_id' => 2]);

        // 3. Registrar logs solo de los confirmados
        $now  = now();
        $appliedSet = array_flip($applied);
        $logs = $overdueUsers
            ->filter(fn($r) => isset($appliedSet[$r->user_id]))
            ->map(fn($r) => [
                'company_id'     => $companyId,
                'user_id'        => $r->user_id,
                'action'         => 'suspended',
                'motivo'         => 'mora',
                'detalle'        => "Corte automático: {$r->cab_count} factura(s) pendiente(s)",
                'invoices_count' => $r->cab_count,
                'created_at'     => $now,
            ])->toArray();
        DB::table('auto_suspend_logs')->insert($logs);
        $this->alHistorial($companyId, $applied, false, 'Suspensión automática por mora');

        $total = count($applied);
        $this->writelog("suspendOverdue empresa={$companyId} → {$total} cliente(s) suspendido(s) en BD y MikroTik");
        return $total;
    }

    /**
     * Suspende los clientes que el operador eligió (suspensión masiva).
     *
     * Hace lo mismo que el corte automático —primero el router, después la plataforma, y
     * sólo para los que el router confirmó—, pero con la lista que se le pasa en vez de
     * calcularla, y deja escrito quién lo ordenó.
     *
     * @param  list<int> $userIds
     * @return array{suspendidos: list<int>, router_caido: bool}
     */
    public function suspenderSeleccion(int $companyId, array $userIds, string $detalle, ?int $hechoPor): array
    {
        // Sólo clientes vigentes de la empresa que hoy tienen servicio.
        $validos = DB::table('user_data')->where('company_id', $companyId)->where('active', 1)->where('status_internet_id', 1)
            ->whereIn('user_id', array_map('intval', $userIds))->pluck('user_id')->map(fn ($i) => (int) $i)->all();

        if (!$validos) {
            return ['suspendidos' => [], 'router_caido' => false];
        }

        $this->writelog("suspensionMasiva empresa={$companyId} por usuario " . ($hechoPor ?? 'sistema') . ' → ' . count($validos) . ' cliente(s) elegidos');
        $aplicados = $this->aplicarEnElRouter($companyId, $validos, true);

        if ($aplicados === null) {
            $this->writelog("suspensionMasiva empresa={$companyId} → MikroTik sin conexión, BD sin cambios.");

            return ['suspendidos' => [], 'router_caido' => true];
        }

        if (!$aplicados) {
            return ['suspendidos' => [], 'router_caido' => false];
        }

        DB::table('user_data')->where('company_id', $companyId)->whereIn('user_id', $aplicados)->update(['STATUS' => 1, 'status_internet_id' => 2]);

        $facturas = DB::table('det_facturations as df')->join('cab_facturations as cb', 'cb.id', '=', 'df.cab_id')
            ->where('cb.company_id', $companyId)->whereIn('cb.user_id', $aplicados)->where('df.paid', 0)->whereNull('df.anulada_en')
            ->groupBy('cb.user_id')->selectRaw('cb.user_id, COUNT(*) n')->pluck('n', 'user_id');
        $ahora = now();

        DB::table('auto_suspend_logs')->insert(array_map(fn ($id) => [
            'company_id' => $companyId, 'user_id' => $id, 'action' => 'suspended', 'motivo' => 'mora',
            'detalle' => mb_substr($detalle, 0, 250), 'hecho_por' => $hechoPor, 'invoices_count' => (int) ($facturas[$id] ?? 0), 'created_at' => $ahora,
        ], $aplicados));
        $this->alHistorial($companyId, $aplicados, false, mb_substr($detalle, 0, 200));
        $this->writelog("suspensionMasiva empresa={$companyId} → " . count($aplicados) . ' cliente(s) suspendido(s) en BD y MikroTik');

        return ['suspendidos' => array_values($aplicados), 'router_caido' => false];
    }

    /**
     * Importa al log los usuarios que ya están STATUS=1 pero no tienen entrada
     * en auto_suspend_logs. Permite que el sistema los tome en cuenta para reactivar.
     * Solo corre una vez por usuario (no duplica si ya existe registro).
     */
    public function importExistingSuspended(int $companyId): int
    {
        // Ya no hace falta: la reactivación mira el estado real del cliente, no este registro
        // (ver suspendidosQueVuelvenSolos). Y tal como estaba era peligroso al encender el
        // corte: tomaba «STATUS = 1», que tienen sobre todo los clientes ELIMINADOS, y les
        // apagaba el ARP buscándolos por documento o por IP — una IP que hoy puede ser de
        // otro cliente. Se deja el método porque lo llama la pantalla de configuración.
        return 0;

        // Usuarios suspendidos (STATUS=1) sin ningún log en auto_suspend_logs
        $suspended = DB::table('user_data as ud')
            ->join('users', 'users.id', '=', 'ud.user_id')
            ->where('users.company_id', $companyId)
            ->where('ud.STATUS', 1)
            ->whereNotIn('users.profile_id', function ($q) use ($companyId) {
                $q->select('id')->from('profiles')
                    ->where('company_id', $companyId)
                    ->whereIn('name', ['ADMIN', 'TECNICO', 'CONTADOR']);
            })
            ->whereNotExists(function ($q) use ($companyId) {
                $q->from('auto_suspend_logs')
                    ->whereColumn('auto_suspend_logs.user_id', 'ud.user_id')
                    ->where('auto_suspend_logs.company_id', $companyId);
            })
            ->pluck('ud.user_id')
            ->toArray();

        if (empty($suspended)) return 0;

        // Fecha antigua para que no aparezcan en las estadísticas del día
        $importedAt = '2000-01-01 00:00:00';
        $logs = array_map(fn($uid) => [
            'company_id'     => $companyId,
            'user_id'        => $uid,
            'action'         => 'suspended',
            'motivo'         => 'importado',
            'detalle'        => 'Ya estaba suspendido al encender el corte automático',
            'invoices_count' => 0,
            'created_at'     => $importedAt,
        ], $suspended);

        DB::table('auto_suspend_logs')->insert($logs);
        $this->writelog("importExistingSuspended empresa={$companyId} → " . count($suspended) . " usuario(s) importados al log");

        // Verificar y aplicar desactivación ARP en MikroTik para cada usuario importado
        $this->applyArpStatus($companyId, $suspended, '/ip/arp/disable', 'SUSPENDIDO');

        return count($suspended);
    }

    /**
     * Tras un pago, reactiva al cliente si ya no tiene dets pendientes.
     */
    public function reactivateIfClear(int $userId, int $companyId): bool
    {
        $config = DB::table('auto_suspend_configs')
            ->where('company_id', $companyId)
            ->where('enabled', true)
            ->first();

        if (!$config) return false;

        // ¿Aún tiene algún det pendiente?
        $hasUnpaid = DB::table('det_facturations as df')
            ->join('cab_facturations as cb', 'cb.id', '=', 'df.cab_id')
            ->where('cb.user_id', $userId)
            ->where('df.paid', 0)->whereNull('df.anulada_en')
            ->where('df.abone', '!=', 1)
            ->exists();

        if ($hasUnpaid) return false;

        // Suspendido de verdad: cliente vigente con el internet en INACTIVE (ver
        // suspendidosQueVuelvenSolos). Antes se miraba STATUS = 1, que la suspensión desde el
        // panel no escribe: quien pagaba después de un corte manual no volvía al registrar el pago.
        $userData = DB::table('user_data')->where('user_id', $userId)->where('company_id', $companyId)->first();
        if (!$userData || (int) $userData->active !== 1 || (int) $userData->status_internet_id !== 2) return false;

        // Ya no depende de que exista una fila en el registro: basta con que esté suspendido
        // y al día. Lo único que lo frena es que un operador lo haya suspendido por algo que
        // no es la mora (ver UpdateStatusUserUseCase).
        if (!empty($userData->no_reactivar_auto)) {
            $this->writelog("reactivateIfClear usuario={$userId} → al día, pero marcado «no reactivar automáticamente»: lo reactiva un operador.");
            return false;
        }

        // 1. Primero MikroTik
        $applied = $this->aplicarEnElRouter($companyId, [$userId], false);

        if (empty($applied)) return false; // MikroTik falló o no encontró al cliente

        // 2. Actualizar BD solo si MikroTik confirmó
        DB::table('user_data')->where('user_id', $userId)->update(['STATUS' => 0, 'status_internet_id' => 1, 'no_reactivar_auto' => 0]);

        // 3. Log
        DB::table('auto_suspend_logs')->insert([
            'company_id'     => $companyId,
            'user_id'        => $userId,
            'action'         => 'reactivated',
            'motivo'         => 'al_dia',
            'detalle'        => 'Reactivación automática: quedó sin facturas pendientes',
            'invoices_count' => 0,
            'created_at'     => now(),
        ]);

        $this->alHistorial($companyId, [$userId], true, 'Reactivación automática: quedó sin facturas pendientes');

        // 4. Avisarle. Sin esto el cliente que pagó vuelve a llamar preguntando
        // por su internet aunque ya lo tenga de vuelta.
        $this->avisarReactivacion($companyId, [$userId]);

        return true;
    }

    /**
     * Le avisa por WhatsApp a quien recuperó el servicio.
     *
     * Se traga cualquier error a propósito: reactivar es lo importante y no
     * puede quedarse a medias porque WhatsApp no responda.
     *
     * @param array<int, int> $userIds
     */
    private function avisarReactivacion(int $companyId, array $userIds): void
    {
        try {
            $enviados = app(\App\Services\WhatsApp\ServiceNotifier::class)
                ->reactivados($companyId, $userIds);

            if ($enviados > 0) {
                $this->writelog("avisarReactivacion empresa={$companyId} → {$enviados} aviso(s) por WhatsApp.");
            }
        } catch (\Throwable $e) {
            $this->writelog("avisarReactivacion empresa={$companyId} → falló el aviso: {$e->getMessage()}");
        }
    }

    /**
     * Deja el cambio en el historial de la ficha del cliente, igual que el cambio manual.
     * Sin esto el operador veía al cliente suspendido y no sabía que había sido el sistema.
     *
     * @param array<int,int> $userIds
     */
    private function alHistorial(int $companyId, array $userIds, bool $reactiva, string $descripcion): void
    {
        try {
            $ahora = now();

            DB::table('user_audit_logs')->insert(array_map(fn ($id) => [
                'user_id'       => $id,
                // La columna no admite nulo: 0 es «el sistema».
                'changed_by'    => 0,
                'company_id'    => $companyId,
                'field_changed' => 'estado_internet',
                'old_value'     => $reactiva ? 'INACTIVE' : 'ACTIVE',
                'new_value'     => $reactiva ? 'ACTIVE' : 'INACTIVE',
                'description'   => $descripcion,
                'created_at'    => $ahora,
                'updated_at'    => $ahora,
            ], array_values($userIds)));
        } catch (\Throwable $e) {
            $this->writelog("alHistorial empresa={$companyId} → no se pudo anotar: {$e->getMessage()}");
        }
    }

    /**
     * Los suspendidos a los que el sistema les puede devolver el servicio cuando queden al día.
     *
     * @return array<int,int>  ids de «users»
     */
    public function suspendidosQueVuelvenSolos(int $companyId): array
    {
        return DB::table('user_data as ud')
            ->join('users', 'users.id', '=', 'ud.user_id')
            ->where('users.company_id', $companyId)
            // Suspendido de verdad = cliente vigente con el internet en INACTIVE. «STATUS = 1»
            // no sirve para esto: lo tienen sobre todo los clientes eliminados, y la
            // suspensión hecha desde el panel ni lo toca.
            ->where('ud.active', 1)
            ->where('ud.status_internet_id', 2)
            ->where('ud.no_reactivar_auto', 0)
            ->whereNotIn('users.profile_id', function ($q) use ($companyId) {
                $q->select('id')->from('profiles')
                    ->where('company_id', $companyId)
                    ->whereIn('name', ['ADMIN', 'TECNICO', 'CONTADOR']);
            })
            ->pluck('ud.user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Reactiva en bloque todos los auto-suspendidos que ya saldaron su deuda.
     * Corre en el job cada 4 min y en el día de corte mensual.
     */
    public function reactivateAllClear(int $companyId, int $minInvoices): int
    {
        // Todos los suspendidos de la empresa, tengan o no fila en el registro. Antes sólo se
        // miraba a quien tuviera un «suspended» anotado: el que se suspendía por otro camino no
        // volvía nunca aunque pagara. Quedan fuera el personal, los clientes dados de baja y los
        // que un operador suspendió por algo que no es la mora.
        $autoSuspended = $this->suspendidosQueVuelvenSolos($companyId);

        if (empty($autoSuspended)) {
            $this->writelog("reactivateAllClear empresa={$companyId} → sin clientes auto-suspendidos pendientes");
            return 0;
        }

        // Quiénes AÚN tienen algún det sin pagar
        $stillOwing = DB::table('det_facturations as df')
            ->join('cab_facturations as cb', 'cb.id', '=', 'df.cab_id')
            ->whereIn('cb.user_id', $autoSuspended)
            ->where('df.paid', 0)->whereNull('df.anulada_en')
            ->where('df.abone', '!=', 1)
            ->select('cb.user_id')
            ->distinct()
            ->pluck('user_id')
            ->toArray();

        $toReactivate = array_values(array_diff($autoSuspended, $stillOwing));
        if (empty($toReactivate)) {
            $this->writelog("reactivateAllClear empresa={$companyId} → " . count($autoSuspended) . " suspendido(s), todos aún con deuda pendiente");
            return 0;
        }

        // 1. Primero aplicar en MikroTik — solo actualizamos BD con los que respondieron ok
        $applied = $this->aplicarEnElRouter($companyId, $toReactivate, false);

        if ($applied === null) {
            // Fallo total de conexión — no tocar BD ni logs, se reintenta en 4 min
            $this->writelog("reactivateAllClear empresa={$companyId} → MikroTik sin conexión, BD sin cambios. Reintento en 4 min.");
            return 0;
        }

        if (empty($applied)) {
            $this->writelog("reactivateAllClear empresa={$companyId} → ningún cliente encontrado en el router (ARP ni PPPoE), BD sin cambios.");
            return 0;
        }

        // 2. Actualizar BD solo con los confirmados por MikroTik
        DB::table('user_data')->whereIn('user_id', $applied)->update(['STATUS' => 0, 'status_internet_id' => 1]);

        // 3. Logs solo de los confirmados
        $now  = now();
        $logs = array_map(fn($uid) => [
            'company_id'     => $companyId,
            'user_id'        => $uid,
            'action'         => 'reactivated',
            'motivo'         => 'al_dia',
            'detalle'        => 'Reactivación automática: quedó sin facturas pendientes',
            'invoices_count' => 0,
            'created_at'     => $now,
        ], $applied);
        DB::table('auto_suspend_logs')->insert($logs);
        $this->alHistorial($companyId, $applied, true, 'Reactivación automática: quedó sin facturas pendientes');

        // Avisarle a cada uno que ya tiene servicio.
        $this->avisarReactivacion($companyId, $applied);

        $total = count($applied);
        $this->writelog("reactivateAllClear empresa={$companyId} → {$total} cliente(s) reactivado(s) en BD y MikroTik");
        return $total;
    }

    /**
     * Sincroniza el ARP del MikroTik con el estado del servicio en la plataforma.
     * - servicio activo     pero ARP desactivado → habilita ARP
     * - servicio suspendido pero ARP activado    → desactiva ARP
     *
     * El estado es «status_internet_id» (2 = suspendido), que es lo que escribe tanto el corte
     * automático como la suspensión desde el panel. Antes se guiaba por «STATUS», que el panel
     * no toca: a un cliente suspendido a mano lo veía «activo con el ARP apagado» y se lo volvía
     * a encender, además de pisarle el estado. Sólo clientes vigentes y de IP fija: los
     * eliminados pueden tener su IP ya en manos de otro, y los PPPoE no viven en el ARP.
     */
    public function syncArpWithPlatform(int $companyId): array
    {
        $result = ['enabled' => 0, 'disabled' => 0, 'not_found' => 0, 'errors' => 0];

        $this->writelog("═════════════════════════════════════════════════════");
        $this->writelog("SYNC ARP empresa={$companyId} — iniciando");

        $api = $this->mikrotikClient($companyId);
        if (!$api) {
            $this->writelog("  ✗ Sin conexión MikroTik — sync abortado");
            $this->writelog("═════════════════════════════════════════════════════");
            return $result;
        }

        try {
            // 1. Traer todos los ARP del MikroTik de una sola vez
            $allArp = $api->query(new Query('/ip/arp/print'))->read();


            // 2. Traer todos los usuarios de la empresa con su STATUS
            $users = DB::table('user_data as ud')
                ->join('users', 'users.id', '=', 'ud.user_id')
                ->where('users.company_id', $companyId)
                ->whereNotIn('users.profile_id', function ($q) use ($companyId) {
                    $q->select('id')->from('profiles')
                        ->where('company_id', $companyId)
                        ->whereIn('name', ['ADMIN', 'TECNICO', 'CONTADOR']);
                })
                ->where('ud.active', 1)
                ->where(fn ($q) => $q->whereNull('ud.connection_type')->orWhere('ud.connection_type', '!=', 'pppoe'))
                ->select('ud.user_id', 'ud.dni', 'ud.names', 'ud.lastname', 'ud.status_internet_id', 'users.username')
                ->get()
                ->each(fn ($u) => $u->STATUS = (int) $u->status_internet_id === 2 ? 1 : 0);

            $this->writelog("  Usuarios en plataforma: " . $users->count() . " | Entradas ARP cargadas: " . count($allArp));
            $this->writelog("─────────────────────────────────────────────────────");

            foreach ($users as $u) {
                $label = "ID:{$u->user_id} DNI:{$u->dni} ({$u->names} {$u->lastname}) user:{$u->username}";

                // Se lo busca con el criterio común: documento, nombre en la
                // plataforma de origen o su IP fija.
                $identidad = IdentidadEnElRouter::deUsuario((int) $u->user_id, $companyId);
                $arpEntries = $identidad ? IdentidadEnElRouter::suyas($allArp, $identidad, $companyId) : [];
                $foundBy    = $arpEntries ? $arpEntries[0]['via'] : null;

                if (empty($arpEntries)) {
                    $this->writelog("  ? {$label} │ STATUS=" . ($u->STATUS ? 'SUSPENDIDO' : 'ACTIVO') . " — NO encontrado en ARP");
                    $result['not_found']++;
                    continue;
                }

                $ips        = implode(', ', array_column($arpEntries, 'address'));
                $isDisabled = ($arpEntries[0]['disabled'] ?? 'false') === 'true';
                $platformOk = $u->STATUS == 1; // 1=suspendido, 0=activo

                $statusLabel   = $u->STATUS ? 'SUSPENDIDO' : 'ACTIVO';
                $arpLabel      = $isDisabled ? 'DESACTIVADO' : 'ACTIVADO';

                if ($isDisabled === $platformOk) {
                    $this->writelog("  ✓ {$label} │ plataforma={$statusLabel} arp={$arpLabel} por {$foundBy} │ IPs: {$ips} — OK");
                    continue;
                }

                // Hay desync — corregir
                $this->writelog("  ! DESYNC {$label} │ plataforma={$statusLabel} pero arp={$arpLabel} por {$foundBy} │ IPs: {$ips} — CORRIGIENDO...");

                try {
                    if ($u->STATUS == 0 && $isDisabled) {
                        // Activo en plataforma pero desactivado en ARP → habilitar
                        foreach ($arpEntries as $arp) {
                            if (empty($arp['.id'])) continue;
                            $api->query((new Query('/ip/arp/enable'))->equal('.id', $arp['.id']))->read();
                        }
                        $this->writelog("    → ARP HABILITADO (cliente activo en plataforma)");
                        $result['enabled']++;
                    } else {
                        // Suspendido en plataforma pero ARP activo → deshabilitar
                        foreach ($arpEntries as $arp) {
                            if (empty($arp['.id'])) continue;
                            $api->query((new Query('/ip/arp/disable'))->equal('.id', $arp['.id']))->read();
                        }
                        $this->writelog("    → ARP DESACTIVADO (cliente suspendido en plataforma)");
                        $result['disabled']++;
                    }
                } catch (\Throwable $e) {
                    $this->writelog("    ✗ ERROR al corregir: " . $e->getMessage());
                    $result['errors']++;
                }
            }
        } catch (\Throwable $e) {
            $this->writelog("  ✗ ERROR general sync: " . $e->getMessage());
        } finally {
            if (method_exists($api, 'disconnect')) $api->disconnect();
        }

        $this->writelog("─────────────────────────────────────────────────────");
        $this->writelog("SYNC ARP empresa={$companyId} RESUMEN → habilitados={$result['enabled']} | desactivados={$result['disabled']} | no encontrados={$result['not_found']} | errores={$result['errors']}");
        $this->writelog("═════════════════════════════════════════════════════");

        return $result;
    }

    /**
     * Estadísticas para la UI.
     */
    public function getStats(int $companyId): array
    {
        // Los que hoy están suspendidos de verdad, no los que tienen una fila «suspended»
        // sin su «reactivated»: esa cuenta se inflaba con cada suspensión repetida.
        $suspended = DB::table('user_data as ud')
            ->join('users', 'users.id', '=', 'ud.user_id')
            ->where('users.company_id', $companyId)
            ->where('ud.active', 1)
            ->where('ud.status_internet_id', 2)
            ->count();

        $reactivatedToday = DB::table('auto_suspend_logs')
            ->where('company_id', $companyId)
            ->where('action', 'reactivated')
            ->whereDate('created_at', today())
            ->count();

        $suspendedToday = DB::table('auto_suspend_logs')
            ->where('company_id', $companyId)
            ->where('action', 'suspended')
            ->whereDate('created_at', today())
            ->count();

        return [
            'currently_suspended' => $suspended,
            'suspended_today'     => $suspendedToday,
            'reactivated_today'   => $reactivatedToday,
        ];
    }
}
