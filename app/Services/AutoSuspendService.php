<?php

namespace App\Services;

use App\Repositories\Interfaces\RouterRepositoryInterface;
use Illuminate\Support\Facades\DB;
use RouterOS\Query;

class AutoSuspendService
{
    public function __construct(
        private RouterRepositoryInterface $routerRepo
    ) {}

    // ── Log dedicado ─────────────────────────────────────────────────────────

    private function logPath(): string
    {
        return storage_path('logs/auto-suspend-' . now()->format('Y-m-d') . '.log');
    }

    private function writelog(string $line): void
    {
        file_put_contents($this->logPath(), '[' . now()->format('H:i:s') . '] ' . $line . PHP_EOL, FILE_APPEND);
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
     */
    private function mikrotikClient(int $companyId): mixed
    {
        try {
            $router = $this->routerRepo->getRouterByCompany($companyId);
            if (!$router) return null;

            return new \RouterOS\Client([
                'host'    => $router->host,
                'user'    => $router->user,
                'pass'    => $router->pass,
                'port'    => (int) ($router->port ?? 8728),
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
            // DNI = campo que MikroTik guarda como comment en ARP
            $users = DB::table('user_data as ud')
                ->join('users', 'users.id', '=', 'ud.user_id')
                ->whereIn('ud.user_id', $userIds)
                ->select('ud.user_id', 'ud.dni', 'users.username', 'ud.names', 'ud.lastname')
                ->get()
                ->keyBy('user_id');

            foreach ($userIds as $userId) {
                $u   = $users->get($userId);
                $dni = $u?->dni ?? null;
                $label = $u
                    ? "ID:{$userId} DNI:{$dni} ({$u->names} {$u->lastname}) user:{$u->username}"
                    : "ID:{$userId} (sin datos)";

                if (!$dni) {
                    $this->writelog("  ✗ {$label} — sin DNI, omitido");
                    $notFound++;
                    continue;
                }

                try {
                    // Intento 1: buscar por DNI
                    $arpEntries = $api->query(
                        (new Query('/ip/arp/print'))->where('comment', $dni)
                    )->read();

                    $foundBy = 'DNI';

                    // Intento 2: buscar por username si no encontró por DNI
                    if (empty($arpEntries) && !empty($u?->username)) {
                        $this->writelog("  ~ {$label} — no encontrado por DNI:{$dni}, buscando por username:{$u->username}");
                        $arpEntries = $api->query(
                            (new Query('/ip/arp/print'))->where('comment', $u->username)
                        )->read();
                        $foundBy = 'USERNAME';
                    }

                    if (empty($arpEntries)) {
                        $this->writelog("  ✗ {$label} — NO encontrado en ARP (buscado por DNI y USERNAME)");
                        $notFound++;
                        continue;
                    }

                    $found++;
                    $ips = implode(', ', array_column($arpEntries, 'address'));
                    $this->writelog("  ✓ {$label} — encontrado en ARP por {$foundBy} │ IPs: {$ips}");

                    foreach ($arpEntries as $arp) {
                        if (empty($arp['.id'])) continue;
                        $api->query(
                            (new Query($cmd))->equal('.id', $arp['.id'])
                        )->read();
                    }

                    $this->writelog("    → {$verb} correctamente");
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
            ->where('ud.STATUS', 0)
            ->whereNotIn('users.profile_id', function ($q) use ($companyId) {
                $q->select('id')->from('profiles')
                    ->where('company_id', $companyId)
                    ->whereIn('name', ['ADMIN', 'TECNICO', 'CONTADOR']);
            })
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('det_facturations as df')
                    ->whereColumn('df.cab_id', 'cb.id')
                    ->where('df.paid', 0)
                    ->where('df.abone', '!=', 1);
            })
            ->select('cb.user_id', DB::raw('COUNT(DISTINCT cb.id) as cab_count'))
            ->groupBy('cb.user_id')
            ->havingRaw('COUNT(DISTINCT cb.id) >= ?', [$minInvoices])
            ->get();

        if ($overdueUsers->isEmpty()) return 0;

        $userIds = $overdueUsers->pluck('user_id')->toArray();
        $total   = count($userIds);

        $this->writelog("suspendOverdue empresa={$companyId} minFacturas={$minInvoices} → {$total} cliente(s) a suspender");

        // 1. Primero aplicar en MikroTik
        $applied = $this->applyArpStatus($companyId, $userIds, '/ip/arp/disable', 'SUSPENDIDO');

        if ($applied === null) {
            $this->writelog("suspendOverdue empresa={$companyId} → MikroTik sin conexión, BD sin cambios.");
            return 0;
        }

        if (empty($applied)) {
            $this->writelog("suspendOverdue empresa={$companyId} → ningún cliente encontrado en ARP, BD sin cambios.");
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
                'invoices_count' => $r->cab_count,
                'created_at'     => $now,
            ])->toArray();
        DB::table('auto_suspend_logs')->insert($logs);

        $total = count($applied);
        $this->writelog("suspendOverdue empresa={$companyId} → {$total} cliente(s) suspendido(s) en BD y MikroTik");
        return $total;
    }

    /**
     * Importa al log los usuarios que ya están STATUS=1 pero no tienen entrada
     * en auto_suspend_logs. Permite que el sistema los tome en cuenta para reactivar.
     * Solo corre una vez por usuario (no duplica si ya existe registro).
     */
    public function importExistingSuspended(int $companyId): int
    {
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
            ->where('df.paid', 0)
            ->where('df.abone', '!=', 1)
            ->exists();

        if ($hasUnpaid) return false;

        $userData = DB::table('user_data')->where('user_id', $userId)->first();
        $currentStatus = $userData ? ($userData->STATUS ?? $userData->status ?? null) : null;
        if (!$userData || $currentStatus != 1) return false;

        $lastLog = DB::table('auto_suspend_logs')
            ->where('user_id', $userId)
            ->where('company_id', $companyId)
            ->orderByDesc('created_at')
            ->first();

        if (!$lastLog || $lastLog->action !== 'suspended') return false;

        // 1. Primero MikroTik
        $applied = $this->applyArpStatus($companyId, [$userId], '/ip/arp/enable', 'REACTIVADO');

        if (empty($applied)) return false; // MikroTik falló o no encontró al cliente

        // 2. Actualizar BD solo si MikroTik confirmó
        DB::table('user_data')->where('user_id', $userId)->update(['STATUS' => 0, 'status_internet_id' => 1]);

        // 3. Log
        DB::table('auto_suspend_logs')->insert([
            'company_id'     => $companyId,
            'user_id'        => $userId,
            'action'         => 'reactivated',
            'invoices_count' => 0,
            'created_at'     => now(),
        ]);

        return true;
    }

    /**
     * Reactiva en bloque todos los auto-suspendidos que ya saldaron su deuda.
     * Corre en el job cada 4 min y en el día de corte mensual.
     */
    public function reactivateAllClear(int $companyId, int $minInvoices): int
    {
        // Usuarios cuyo último log es 'suspended'
        $autoSuspended = DB::table('auto_suspend_logs as asl')
            ->where('asl.company_id', $companyId)
            ->where('asl.action', 'suspended')
            ->whereNotExists(function ($q) use ($companyId) {
                $q->from('auto_suspend_logs as asl2')
                    ->whereColumn('asl2.user_id', 'asl.user_id')
                    ->where('asl2.company_id', $companyId)
                    ->where('asl2.action', 'reactivated')
                    ->whereColumn('asl2.created_at', '>', 'asl.created_at');
            })
            ->select('asl.user_id')
            ->distinct()
            ->pluck('user_id')
            ->toArray();

        if (empty($autoSuspended)) {
            $this->writelog("reactivateAllClear empresa={$companyId} → sin clientes auto-suspendidos pendientes");
            return 0;
        }

        // Quiénes AÚN tienen algún det sin pagar
        $stillOwing = DB::table('det_facturations as df')
            ->join('cab_facturations as cb', 'cb.id', '=', 'df.cab_id')
            ->whereIn('cb.user_id', $autoSuspended)
            ->where('df.paid', 0)
            //->where('df.abone', '!=', 1)
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
        $applied = $this->applyArpStatus($companyId, $toReactivate, '/ip/arp/enable', 'REACTIVADO');

        if ($applied === null) {
            // Fallo total de conexión — no tocar BD ni logs, se reintenta en 4 min
            $this->writelog("reactivateAllClear empresa={$companyId} → MikroTik sin conexión, BD sin cambios. Reintento en 4 min.");
            return 0;
        }

        if (empty($applied)) {
            $this->writelog("reactivateAllClear empresa={$companyId} → ningún cliente encontrado en ARP, BD sin cambios.");
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
            'invoices_count' => 0,
            'created_at'     => $now,
        ], $applied);
        DB::table('auto_suspend_logs')->insert($logs);

        $total = count($applied);
        $this->writelog("reactivateAllClear empresa={$companyId} → {$total} cliente(s) reactivado(s) en BD y MikroTik");
        return $total;
    }

    /**
     * Sincroniza el estado ARP del MikroTik con el STATUS de la plataforma.
     * - STATUS=0 (activo)    pero ARP desactivado → habilita ARP
     * - STATUS=1 (suspendido) pero ARP activado   → desactiva ARP
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

            // Indexar por comment → puede haber varios (múltiples IPs por cliente)
            $arpByComment = [];
            foreach ($allArp as $entry) {
                $comment = trim($entry['comment'] ?? '');
                if ($comment === '') continue;
                $arpByComment[$comment][] = $entry;
            }

            // 2. Traer todos los usuarios de la empresa con su STATUS
            $users = DB::table('user_data as ud')
                ->join('users', 'users.id', '=', 'ud.user_id')
                ->where('users.company_id', $companyId)
                ->whereNotIn('users.profile_id', function ($q) use ($companyId) {
                    $q->select('id')->from('profiles')
                        ->where('company_id', $companyId)
                        ->whereIn('name', ['ADMIN', 'TECNICO', 'CONTADOR']);
                })
                ->select('ud.user_id', 'ud.dni', 'ud.names', 'ud.lastname', 'ud.STATUS', 'ud.status_internet_id', 'users.username')
                ->get();

            $this->writelog("  Usuarios en plataforma: " . $users->count() . " | Entradas ARP cargadas: " . count($allArp));
            $this->writelog("─────────────────────────────────────────────────────");

            foreach ($users as $u) {
                $label = "ID:{$u->user_id} DNI:{$u->dni} ({$u->names} {$u->lastname}) user:{$u->username}";

                // Buscar en ARP por DNI, luego por username
                $arpEntries = $arpByComment[$u->dni] ?? $arpByComment[$u->username] ?? [];
                $foundBy    = isset($arpByComment[$u->dni]) ? 'DNI' : (isset($arpByComment[$u->username]) ? 'USERNAME' : null);

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
                    // ARP y STATUS en sync — verificar también status_internet_id
                    $expectedInternetId = $u->STATUS == 1 ? 2 : 1;
                    if ($u->status_internet_id != $expectedInternetId) {
                        DB::table('user_data')->where('user_id', $u->user_id)->update(['status_internet_id' => $expectedInternetId]);
                        $this->writelog("  ~ {$label} │ plataforma={$statusLabel} arp={$arpLabel} — corregido status_internet_id → {$expectedInternetId}");
                    } else {
                        $this->writelog("  ✓ {$label} │ plataforma={$statusLabel} arp={$arpLabel} por {$foundBy} │ IPs: {$ips} — OK");
                    }
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
                        DB::table('user_data')->where('user_id', $u->user_id)->update(['status_internet_id' => 1]);
                        $this->writelog("    → ARP HABILITADO (cliente activo en plataforma)");
                        $result['enabled']++;
                    } else {
                        // Suspendido en plataforma pero ARP activo → deshabilitar
                        foreach ($arpEntries as $arp) {
                            if (empty($arp['.id'])) continue;
                            $api->query((new Query('/ip/arp/disable'))->equal('.id', $arp['.id']))->read();
                        }
                        DB::table('user_data')->where('user_id', $u->user_id)->update(['status_internet_id' => 2]);
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
        $suspended = DB::table('auto_suspend_logs as asl')
            ->where('asl.company_id', $companyId)
            ->where('asl.action', 'suspended')
            ->whereNotExists(function ($q) use ($companyId) {
                $q->from('auto_suspend_logs as asl2')
                    ->whereColumn('asl2.user_id', 'asl.user_id')
                    ->where('asl2.company_id', $companyId)
                    ->where('asl2.action', 'reactivated')
                    ->whereColumn('asl2.created_at', '>', 'asl.created_at');
            })
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
