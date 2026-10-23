<?php

namespace App\Console\Commands;

use App\Services\Crm\TranscriptorDeAudios;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Transcribe las notas de voz de los clientes que quedaron sin texto: las de chats que atiende
 * una persona (Kloe y cobranza transcriben las suyas en el momento) y las que fallaron antes.
 * Sólo de las últimas 48 horas: lo viejo ya no lo va a leer nadie.
 */
class CrmTranscribirAudios extends Command
{
    protected $signature = 'crm:transcribir-audios {--limite=20}';

    protected $description = 'Pasa a texto las notas de voz recientes de los clientes';

    public function handle(): int
    {
        $pendientes = DB::table('crm_messages as m')->join('crm_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->where('m.sender_type', 'customer')->where('m.message_type', 'audio')->whereNull('m.transcripcion')
            ->where('m.transcripcion_intentos', '<', 3)->where('m.created_at', '>=', now()->subHours(48))
            ->orderByDesc('m.id')->limit(max(1, (int) $this->option('limite')))->get(['m.id', 'c.company_id']);

        $hechas = 0;
        foreach ($pendientes as $p) {
            $hechas += (new TranscriptorDeAudios((int) $p->company_id))->transcribir((int) $p->id) !== null ? 1 : 0;
        }

        $this->info("Transcritas {$hechas} de {$pendientes->count()} notas de voz.");

        return self::SUCCESS;
    }
}
