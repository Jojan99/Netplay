<?php

namespace App\Console\Commands;

use App\UseCases\Crm\ReceiveConversationMessageUseCase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Suelta lo que quedó retenido en la puerta de identificación.
 *
 * La puerta le pide la cédula al cliente y guarda sus mensajes hasta que la dé. Si no la da
 * nunca (o la pregunta no le llegó porque la línea estaba caída), esos mensajes no los veía
 * nadie: el 22-10-2026 había 55 clientes así, algunos desde el 9 de septiembre. Pasado el
 * plazo, la conversación entra a la vista del asesor sin identificar, con los mensajes en su
 * hora de verdad y sin respuestas automáticas.
 */
class CrmSoltarRetenidos extends Command
{
    protected $signature = 'crm:soltar-retenidos {--minutos=10 : Sin dar la cédula durante este tiempo, se suelta}';

    protected $description = 'Pasa al asesor las conversaciones que quedaron esperando la cédula del cliente';

    public function handle(ReceiveConversationMessageUseCase $recibir): int
    {
        $limite = now()->subMinutes(max(1, (int) $this->option('minutos')));
        $soltadas = 0;

        foreach (DB::table('crm_identificaciones')->where('estado', 'preguntado')->where('updated_at', '<', $limite)->orderBy('updated_at')->limit(200)->get() as $r) {
            // Se toma de una vez: si el cliente contesta justo ahora, gana el que llegue primero.
            $tomada = DB::table('crm_identificaciones')->where('id', $r->id)->where('estado', 'preguntado')
                ->update(['estado' => 'sin_registro', 'retenidos' => null, 'updated_at' => now()]);

            if (!$tomada) {
                continue;
            }

            foreach (json_decode($r->retenidos ?: '[]', true) ?: [] as $payload) {
                try {
                    $payload['_saltar_identificacion'] = true;
                    $payload['_soltado'] = true;
                    $recibir->execute($payload);
                } catch (\Throwable $e) {
                    Log::warning('[Identificación] No se pudo soltar un retenido', ['id' => $r->id, 'error' => $e->getMessage()]);
                }
            }

            $soltadas++;
        }

        $this->info("Listo: {$soltadas} conversación(es) pasaron al asesor sin identificar.");

        return self::SUCCESS;
    }
}
