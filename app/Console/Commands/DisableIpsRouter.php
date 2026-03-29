<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Managers\ConectionRouterManager;
use App\Repositories\RouterRepository;
use App\Repositories\ManagementRouterRepository;
use RouterOS\Query;
use Throwable;
use Carbon\Carbon;

class DisableIpsRouter extends Command
{
    protected $signature = 'post:disable-ips';
    protected $description = 'Desactiva por lote las IPs en MikroTik (ARP) y guarda log en storage';

    public function handle()
    {
        $this->info('🚀 Iniciando proceso de desactivación de IPs');

        // 📅 Archivo de log por fecha
        $fecha = Carbon::now()->format('Y-m-d');
        $logFile = storage_path("logs/disable_ips_{$fecha}.log");

        // 📝 Log de inicio
        file_put_contents(
            $logFile,
            "[" . Carbon::now()->toDateTimeString() . "] INICIO PROCESO\n",
            FILE_APPEND
        );

        // 1️⃣ Repositorio (sin DI)
        $managementRouterRepository = new ManagementRouterRepository();

        // 2️⃣ Obtener usuarios pendientes (username + idUser)
        $ips = $managementRouterRepository->GetUsersPendding();

        if (empty($ips)) {
            $this->warn('⚠️ No hay IPs para procesar');
            file_put_contents(
                $logFile,
                "[" . Carbon::now()->toDateTimeString() . "] SIN REGISTROS PARA PROCESAR\n",
                FILE_APPEND
            );
            return Command::SUCCESS;
        }

        // 3️⃣ Conexión al MikroTik (UNA SOLA VEZ)
        $routerRepository = new RouterRepository();
        $connection = new ConectionRouterManager($routerRepository);
        $client = $connection->conection('b5c2f0e8-7e82-4a5c-a7d7-0ee8b2d7b905');

        $this->info('🔌 Conectado al MikroTik');

        file_put_contents(
            $logFile,
            "[" . Carbon::now()->toDateTimeString() . "] CONECTADO AL MIKROTIK\n",
            FILE_APPEND
        );

        $success = 0;
        $notFound = 0;
        $errors = 0;

        // 4️⃣ Iterar IPs
        foreach ($ips as $ip) {
            try {
                // 🔍 Buscar IP en ARP
                $query = (new Query('/ip/arp/print'))
                    ->where('comment', $ip['username']);

                $arp = $client->query($query)->read();

                if (!empty($arp[0]['.id'])) {

                    $ipAddress = $arp[0]['address'];

                    // 🚫 Desactivar ARP
                    $query = (new Query('/ip/arp/disable'))
                        ->equal('.id', $arp[0]['.id']);

                    $client->query($query)->read();
                    $success++;

                    // ➕ AGREGAR A ADDRESS LIST "morosos"
                    $query = (new Query('/ip/firewall/address-list/set'))
                        ->equal('.id', $ip['username'])
                        ->equal('list', 'morosos');

                    $client->query($query)->read();


                    $this->info("✅ IP desactivada: {$ip['username']}");

                    // 🗄️ Actualizar estado en BD
                    $managementRouterRepository->UpdateStatus([
                        'id_user' => $ip['idUser'],
                        'status'  => 2
                    ]);

                    // 📝 Log éxito
                    file_put_contents(
                        $logFile,
                        sprintf(
                            "[%s] DESACTIVADA | IP: %s | USER_ID: %s\n",
                            Carbon::now()->toDateTimeString(),
                            $ip['username'],
                            $ip['idUser']
                        ),
                        FILE_APPEND
                    );

                } else {
                    $notFound++;
                    $this->warn("⚠️ IP no encontrada en ARP: {$ip['username']}");

                    file_put_contents(
                        $logFile,
                        sprintf(
                            "[%s] NO ENCONTRADA | IP: %s\n",
                            Carbon::now()->toDateTimeString(),
                            $ip['username']
                        ),
                        FILE_APPEND
                    );
                }

            } catch (Throwable $e) {
                $errors++;
                $this->error("❌ Error con IP {$ip['username']}: {$e->getMessage()}");

                file_put_contents(
                    $logFile,
                    sprintf(
                        "[%s] ERROR | IP: %s | %s\n",
                        Carbon::now()->toDateTimeString(),
                        $ip['username'],
                        $e->getMessage()
                    ),
                    FILE_APPEND
                );
            }

            // ⏱️ Pausa para no saturar el router
            usleep(100000); // 0.1s
        }

        // 5️⃣ Resumen final
        $this->info('==========================');
        $this->info("✔ Desactivadas: {$success}");
        $this->info("⚠ No encontradas: {$notFound}");
        $this->info("❌ Errores: {$errors}");
        $this->info('🏁 Proceso finalizado');

        file_put_contents(
            $logFile,
            sprintf(
                "[%s] FIN PROCESO | OK: %d | NO: %d | ERR: %d\n",
                Carbon::now()->toDateTimeString(),
                $success,
                $notFound,
                $errors
            ),
            FILE_APPEND
        );

        return Command::SUCCESS;
    }
}
