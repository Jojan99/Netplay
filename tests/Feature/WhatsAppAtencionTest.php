<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Repositories\ConversationRepository;
use App\Services\Crm\Reparto;
use App\Services\WhatsApp\BandejaDeSalida;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * La atención por WhatsApp (23-10-2026): el reparto sólo le da chats a quien está en el panel y
 * tiene cupo, los no leídos se cuentan en el servidor (compartidos entre asesores) y la bandeja
 * de salida sólo espera por errores de la línea, no por errores del mensaje.
 */
class WhatsAppAtencionTest extends TestCase
{
    use DatabaseTransactions;

    private int $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        config(['cache.default' => 'array']);

        $empresa = new Company();
        $empresa->forceFill(['name' => 'Empresa de pruebas', 'slug' => 'pruebas-' . uniqid(), 'invoice_prefix' => 'PR'])->save();
        $this->empresa = (int) $empresa->id;
    }

    private function asesor(?int $maxChats = null, ?string $actividad = 'now'): int
    {
        $id = DB::table('users')->insertGetId(['company_id' => $this->empresa, 'email' => uniqid() . '@pruebas.test', 'password' => 'x', 'username' => uniqid()]);
        DB::table('crm_agents')->insert([
            'company_id' => $this->empresa, 'user_id' => $id, 'active' => 1, 'max_chats' => $maxChats ?? 0,
            'ultima_actividad' => $actividad ? now()->modify($actividad === 'now' ? '+0 minutes' : $actividad) : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function chat(?int $asesor = null): int
    {
        $cliente = DB::table('crm_customers')->insertGetId(['company_id' => $this->empresa, 'name' => 'Cliente', 'phone' => '57300' . random_int(1000000, 9999999), 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('crm_conversations')->insertGetId([
            'company_id' => $this->empresa, 'customer_id' => $cliente, 'status' => 'new', 'provider' => 'netplay',
            'assigned_user_id' => $asesor, 'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_el_reparto_es_para_quien_esta_y_tiene_cupo(): void
    {
        $ausente = $this->asesor(null, '-2 hours');
        $lleno = $this->asesor(1);
        $this->chat($lleno);   // ya tiene su único chat
        $libre = $this->asesor();

        $chat = $this->chat();
        $this->assertTrue(Reparto::asignar($chat, $this->empresa));
        $this->assertSame($libre, (int) DB::table('crm_conversations')->where('id', $chat)->value('assigned_user_id'));

        // Sin nadie presente, el chat queda sin dueño (lo cubre el aviso de espera).
        DB::table('crm_agents')->where('user_id', $libre)->update(['ultima_actividad' => now()->subHour()]);
        $otro = $this->chat();
        $this->assertFalse(Reparto::asignar($otro, $this->empresa));
        $this->assertNull(DB::table('crm_conversations')->where('id', $otro)->value('assigned_user_id'));
        $this->assertNotSame($ausente, $libre);
    }

    public function test_los_no_leidos_se_cuentan_en_el_servidor(): void
    {
        $chat = $this->chat();
        $repo = app(ConversationRepository::class);

        $repo->storeMessage(['conversation_id' => $chat, 'sender_type' => 'customer', 'message_type' => 'text', 'content' => 'hola']);
        $repo->storeMessage(['conversation_id' => $chat, 'sender_type' => 'customer', 'message_type' => 'text', 'content' => 'sin internet']);
        $this->assertSame(2, (int) DB::table('crm_conversations')->where('id', $chat)->value('sin_leer'));

        // Un asesor contesta: queda en cero para todos.
        $repo->storeMessage(['conversation_id' => $chat, 'sender_type' => 'agent', 'message_type' => 'text', 'content' => 'ya le reviso']);
        $this->assertSame(0, (int) DB::table('crm_conversations')->where('id', $chat)->value('sin_leer'));
    }

    public function test_la_bandeja_de_salida_espera_solo_por_la_linea(): void
    {
        $this->assertTrue(BandejaDeSalida::esDeLaLinea('Error WA 503: Instancia no conectada'));
        $this->assertTrue(BandejaDeSalida::esDeLaLinea('Error de conexión WA: Connection timed out'));
        $this->assertFalse(BandejaDeSalida::esDeLaLinea('Error WA API: número inválido'));
    }
}
