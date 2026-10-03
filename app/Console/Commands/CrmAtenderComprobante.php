<?php

namespace App\Console\Commands;

use App\Events\InboxUpdatedEvent;
use App\Repositories\Interfaces\ConversationRepositoryInterface;
use App\Services\Crm\ComprobanteWhatsAppWeb;
use App\Services\WhatsAppService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Atiende a mano un comprobante que el bot de la línea de WhatsApp Web dejó sin procesar.
 *
 * Hace lo mismo que el bot cuando el cliente contesta la cédula del titular: registra el
 * comprobante a nombre de ese cliente, lo deja en autorización de pagos, avisa al grupo, le
 * confirma al cliente por la misma línea y deja la conversación ligada a su ficha. La única
 * diferencia: nunca aplica el pago solo; queda esperando que una persona lo autorice.
 *
 * Sin --enviar sólo muestra lo que haría.
 */
class CrmAtenderComprobante extends Command
{
    protected $signature = 'crm:atender-comprobante {empresa : Id de la empresa}
        {telefono : Número desde el que el cliente mandó el comprobante}
        {cedula : Cédula del titular del servicio}
        {--enviar : Registrar el comprobante y responderle al cliente (sin esto, sólo simula)}';

    protected $description = 'Registra un comprobante que el bot de WhatsApp Web no atendió y le responde al cliente';

    public function handle(ComprobanteWhatsAppWeb $comprobantes, ConversationRepositoryInterface $crm): int
    {
        $empresa  = (int) $this->argument('empresa');
        $telefono = substr(preg_replace('/\D/', '', (string) $this->argument('telefono')), -10);
        $cedula   = preg_replace('/\D/', '', (string) $this->argument('cedula'));

        $conversacion = DB::table('crm_conversations as v')->join('crm_customers as c', 'c.id', '=', 'v.customer_id')
            ->where('v.company_id', $empresa)->where('v.provider', 'netplay')->where('c.is_group', 0)
            ->where('c.phone', 'like', '%' . $telefono)
            ->orderByDesc('v.last_message_at')
            ->first(['v.id', 'v.wa_linea_id', 'v.status', 'c.id as contacto', 'c.phone', 'c.name', 'c.user_id']);

        if (!$conversacion) {
            $this->error('No hay conversación de WhatsApp Web con ese número.');

            return self::FAILURE;
        }

        $cliente = DB::table('user_data as ud')->join('users as u', 'u.id', '=', 'ud.user_id')
            ->where('u.company_id', $empresa)->where('ud.dni', $cedula)
            ->first(['ud.user_id', 'ud.dni', 'ud.names', 'ud.lastname']);

        if (!$cliente) {
            $this->error('No hay cliente con la cédula ' . $cedula . '.');

            return self::FAILURE;
        }

        $imagen = DB::table('crm_messages')->where('conversation_id', $conversacion->id)->where('sender_type', 'customer')
            ->whereIn('message_type', ['image', 'document'])->whereNotNull('media_url')
            ->where('created_at', '>=', now()->subDays(2))
            ->orderByDesc('id')->first(['id', 'media_url', 'content', 'wa_linea_id', 'created_at']);

        if (!$imagen) {
            $this->error('El cliente no mandó ninguna imagen ni documento en los últimos dos días.');

            return self::FAILURE;
        }

        $nombre = trim($cliente->names . ' ' . $cliente->lastname);
        $linea  = DB::table('wa_lineas')->where('id', $imagen->wa_linea_id ?: $conversacion->wa_linea_id)->first(['id', 'instance_id', 'nombre']);

        $this->line(($this->option('enviar') ? 'SE ATIENDE' : 'SIMULACIÓN') . ' · conversación ' . $conversacion->id . ' («' . $conversacion->name . '», ' . $conversacion->phone . ')');
        $this->line('Titular: ' . $nombre . ' · cédula ' . $cliente->dni);
        $this->line('Comprobante: mensaje ' . $imagen->id . ' del ' . $imagen->created_at . ' · línea ' . ($linea->nombre ?? '?'));
        $this->line($imagen->media_url);

        if (!$this->option('enviar')) {
            $this->line('Nada se registró ni se envió. Para hacerlo, repita el comando con --enviar.');

            return self::SUCCESS;
        }
        if (!$linea) {
            $this->error('No se sabe por qué línea escribió: no se le puede responder.');

            return self::FAILURE;
        }

        $r = $comprobantes->registrar([
            'company_id'    => $empresa,
            'phone'         => $conversacion->phone,
            'dni'           => $cliente->dni,
            'media_url'     => $imagen->media_url,
            'caption'       => $imagen->content,
            'wa_linea_id'   => $linea->id,
            'solo_revision' => true,
        ]);

        if (!($r['ok'] ?? false)) {
            $this->error('No se pudo registrar: ' . ($r['motivo'] ?? 'sin motivo'));

            return self::FAILURE;
        }

        // La conversación queda ligada a la ficha, como cuando el cliente se identifica.
        DB::table('crm_customers')->where('id', $conversacion->contacto)
            ->update(['user_id' => $cliente->user_id, 'dni' => $cliente->dni, 'name' => $nombre, 'updated_at' => now()]);
        DB::table('crm_identificaciones')->where('company_id', $empresa)->where('provider', 'netplay')->where('phone', $conversacion->phone)
            ->update(['estado' => 'identificado', 'dni' => $cliente->dni, 'user_id' => $cliente->user_id, 'nombre' => $nombre, 'retenidos' => null, 'updated_at' => now()]);

        // Los mismos textos del bot. Con un comprobante viejo no se le dice nada.
        $texto = match (true) {
            (bool) ($r['viejo'] ?? false)                => null,
            ($r['motivo'] ?? null) === 'ya_registrado'   => "Ese comprobante ya lo teníamos registrado a nombre de *{$nombre}*.\n\nUn asesor lo está verificando, no hace falta que lo mandes de nuevo.",
            default                                      => "✅ ¡Listo! Registramos tu comprobante a nombre de *{$nombre}*.\n\nUn asesor lo va a verificar.",
        };

        $this->info('Comprobante #' . $r['proof_id'] . ' en autorización de pagos' . (($r['motivo'] ?? null) === 'ya_registrado' ? ' (ya estaba registrado)' : '') . (($r['viejo'] ?? false) ? ' · tiene más de tres días: no se le responde al cliente' : ''));

        if (!$texto) {
            return self::SUCCESS;
        }

        (new WhatsAppService($empresa, false, 'netplay', $linea->instance_id))->mensajeInformativo($conversacion->phone, $texto);

        $crm->storeMessage([
            'conversation_id' => $conversacion->id,
            'wa_linea_id'     => $linea->id,
            'sender_type'     => 'system',
            'agent_signature' => 'Bot',
            'message_type'    => 'text',
            'content'         => $texto,
            'created_at'      => now(),
        ]);
        broadcast(new InboxUpdatedEvent((int) $conversacion->id, (string) $conversacion->status, 'system', 'netplay'));

        $this->info('Respuesta enviada al cliente por la línea ' . $linea->nombre . '.');

        return self::SUCCESS;
    }
}
