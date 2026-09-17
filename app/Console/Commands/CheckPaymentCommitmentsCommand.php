<?php

namespace App\Console\Commands;

use App\Managers\ConectionRouterManager;
use App\Models\ConectionRouter;
use App\Models\PaymentCommitment;
use App\Models\UserData;
use App\Repositories\ManagementRouterRepository;
use App\Repositories\RouterRepository;
use App\Services\Red\ClienteEnElRouter;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RouterOS\Query;
use Throwable;

class CheckPaymentCommitmentsCommand extends Command
{
    protected $signature   = 'commitments:check';
    protected $description = 'Verifica compromisos de pago vencidos y suspende el servicio si no se registró pago.';

    public function handle(): int
    {
        $today   = Carbon::today();
        $logFile = storage_path('logs/commitments_' . $today->format('Y-m-d') . '.log');

        $this->log($logFile, 'INICIO CHECK COMPROMISOS');

        // 1. Buscar compromisos pendientes cuyo plazo ya venció
        $overdue = PaymentCommitment::where('status', 'pending')
            ->whereDate('commitment_date', '<=', $today)
            ->get();

        if ($overdue->isEmpty()) {
            $this->info('✅ No hay compromisos vencidos.');
            $this->log($logFile, 'Sin compromisos vencidos.');
            return Command::SUCCESS;
        }

        $this->info("🔍 Compromisos vencidos: {$overdue->count()}");

        $routerRepository = new RouterRepository();
        $connection       = new ConectionRouterManager($routerRepository);
        $managementRepo   = new ManagementRouterRepository();

        foreach ($overdue as $commitment) {
            try {
                // 2. ¿Se pagaron las facturas vinculadas al compromiso?
                $linkedInvoiceIds = DB::table('payment_commitment_invoices')
                    ->where('commitment_id', $commitment->id)
                    ->pluck('det_facturation_id');

                if ($linkedInvoiceIds->isEmpty()) {
                    // Sin facturas vinculadas: fallback a verificar cualquier pago reciente
                    $hasPaid = DB::table('payment_logs')
                        ->where('cab_id', $commitment->cab_id)
                        ->where('company_id', $commitment->company_id)
                        ->where('created_at', '>=', $commitment->created_at)
                        ->exists();
                } else {
                    // Verificar si TODAS las facturas vinculadas están pagadas
                    $unpaidCount = DB::table('det_facturations')
                        ->whereIn('id', $linkedInvoiceIds)
                        ->where('paid', '!=', 1)
                        ->count();
                    $hasPaid = ($unpaidCount === 0);
                }

                if ($hasPaid) {
                    $commitment->update([
                        'status'       => 'fulfilled',
                        'fulfilled_at' => now(),
                    ]);
                    $this->info("✅ Compromiso #{$commitment->id} cumplido.");
                    $this->log($logFile, "FULFILLED | commitment_id={$commitment->id} | cab_id={$commitment->cab_id}");
                    continue;
                }

                // 3. No pagó
                if (!$commitment->auto_suspend) {
                    // Sin auto-suspensión: solo marcar como broken
                    $commitment->update(['status' => 'broken']);
                    $this->warn("⚠️  Compromiso #{$commitment->id} incumplido (sin auto-suspensión).");
                    $this->log($logFile, "BROKEN (no suspend) | commitment_id={$commitment->id}");
                    continue;
                }

                // 4. Suspender en MikroTik
                $suspended = $this->suspendUser(
                    $commitment,
                    $routerRepository,
                    $connection,
                    $managementRepo,
                    $logFile
                );

                $commitment->update([
                    'status'       => 'broken',
                    'suspended_at' => $suspended ? now() : null,
                ]);

                $this->warn("🚫 Compromiso #{$commitment->id} incumplido — " . ($suspended ? 'servicio suspendido.' : 'no se pudo suspender.'));

            } catch (Throwable $e) {
                $this->error("❌ Error en compromiso #{$commitment->id}: {$e->getMessage()}");
                $this->log($logFile, "ERROR | commitment_id={$commitment->id} | {$e->getMessage()}");
            }
        }

        $this->log($logFile, 'FIN CHECK COMPROMISOS');
        return Command::SUCCESS;
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function suspendUser(
        PaymentCommitment $commitment,
        RouterRepository $routerRepo,
        ConectionRouterManager $connection,
        ManagementRouterRepository $mgmtRepo,
        string $logFile
    ): bool {
        // Usuario de la misma empresa del compromiso
        $user = DB::table('users')
            ->where('id', $commitment->user_id)
            ->where('company_id', $commitment->company_id)
            ->select('username', 'id')
            ->first();

        if (!$user) {
            $this->log($logFile, "SIN USUARIO | commitment_id={$commitment->id}");
            return false;
        }

        try {
            // Misma lógica que al suspender desde el panel: el router del propio
            // cliente (no el primero de la empresa), PPPoE o ARP por su documento.
            $enRouter  = new ClienteEnElRouter($connection, (int) $commitment->company_id);
            $resultado = $enRouter->suspender((int) $user->id);

            if (!$resultado['ok']) {
                $this->log($logFile, "SIN ROUTER / ERROR MIKROTIK | commitment_id={$commitment->id} | " . implode('; ', $resultado['errores']));
                return false;
            }

            // Compatibilidad: entradas ARP viejas con el username como comment
            if (empty($resultado['quitado'])) {
                $resultado['quitado'] = $this->suspenderArpPorUsername($commitment, $user, $connection);
            }

            if (empty($resultado['quitado'])) {
                $this->log($logFile, "NO ENCONTRADO EN ROUTER | user={$user->username}");
                return false;
            }

            // Actualizar estado en BD (sin sesión — escribir directamente)
            UserData::where('user_id', $user->id)
                ->where('company_id', $commitment->company_id)
                ->update(['status_internet_id' => 2]);

            DB::table('user_audit_logs')->insert([
                'user_id'       => $user->id,
                'changed_by'    => null,
                'company_id'    => $commitment->company_id,
                'field_changed' => 'estado_internet',
                'old_value'     => 'ACTIVE',
                'new_value'     => 'INACTIVE',
                'description'   => "Suspensión automática por compromiso de pago #{$commitment->id}",
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            $this->log($logFile, "SUSPENDIDO | commitment_id={$commitment->id} | user={$user->username} | " . implode('; ', $resultado['quitado']));
            return true;

        } catch (Throwable $e) {
            $this->log($logFile, "ERROR MIKROTIK | commitment_id={$commitment->id} | {$e->getMessage()}");
            return false;
        }
    }

    /** ARP con el username como comment, en el router del cliente (o el de la empresa). */
    private function suspenderArpPorUsername(PaymentCommitment $commitment, object $user, ConectionRouterManager $connection): array
    {
        if (empty($user->username)) {
            return [];
        }

        $routerId = UserData::where('user_id', $user->id)
            ->where('company_id', $commitment->company_id)
            ->value('router_id');

        $routers = ConectionRouter::where('company_id', $commitment->company_id);
        $router  = ($routerId ? (clone $routers)->where('id', $routerId)->first() : null)
            ?? $routers->orderBy('id')->first();

        if (!$router) {
            return [];
        }

        $client = $connection->conection($router->token);
        $arp    = $client->query((new Query('/ip/arp/print'))->where('comment', $user->username))->read();

        if (empty($arp[0]['.id'])) {
            return [];
        }

        $client->query((new Query('/ip/arp/disable'))->equal('.id', $arp[0]['.id']))->read();

        return ['ARP ' . ($arp[0]['address'] ?? '') . ' deshabilitado (por username)'];
    }

    private function log(string $file, string $msg): void
    {
        file_put_contents(
            $file,
            '[' . Carbon::now()->toDateTimeString() . '] ' . $msg . "\n",
            FILE_APPEND
        );
    }
}
