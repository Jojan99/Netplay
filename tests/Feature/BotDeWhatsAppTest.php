<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\WaBotConfig;
use App\Models\WaBotSession;
use App\Services\WaBotService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El bot de Meta de punta a punta, contra la base de pruebas.
 *
 * Fija los casos reales que se corrigieron: el cliente que escribió «ya la
 * factura está paga y no tenemos internet» y terminó en un cierre por
 * inactividad, y el pago que se registraba con solo escribir el monto.
 *
 * Nada sale a Meta (Http::fake) y cada prueba se deshace al terminar.
 */
class BotDeWhatsAppTest extends TestCase
{
    use DatabaseTransactions;

    private const TELEFONO = '573009999901';
    private const NUMERO_DE_META = 'pruebas-phone-number-id';

    private Company $empresa;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.PRUEBA']]], 200)]);
        Event::fake();

        $this->empresa = new Company();
        $this->empresa->forceFill([
            'name' => 'Empresa de pruebas',
            'slug' => 'pruebas-' . uniqid(),
            'wa_provider' => 'meta',
            'whatsapp_enabled' => 1,
            'wa_phone_number_id' => self::NUMERO_DE_META,
            'wa_access_token' => 'token-de-pruebas',
        ])->save();

        WaBotConfig::create([
            'company_id' => $this->empresa->id,
            'enabled' => true,
            'trigger_word' => 'hola',
            'menu_type' => 'buttons',
            'options' => [['key' => '1', 'label' => 'Consultar factura', 'flow' => 'consultar_factura']],
        ]);
    }

    private function escribe(string $texto): bool
    {
        return app(WaBotService::class)->handleIncomingMessage([
            'from' => self::TELEFONO,
            'id' => 'wamid.' . uniqid(),
            'type' => 'text',
            'text' => ['body' => $texto],
        ], self::NUMERO_DE_META);
    }

    private function loUltimoQueDijoElBot(): string
    {
        return (string) DB::table('crm_messages as m')
            ->join('crm_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->join('crm_customers as k', 'k.id', '=', 'c.customer_id')
            ->where('c.company_id', $this->empresa->id)
            ->where('k.phone', self::TELEFONO)
            ->where('m.sender_type', 'system')
            ->orderByDesc('m.id')
            ->value('m.content');
    }

    private function botEnPausa(): bool
    {
        return DB::table('wa_bot_pauses')
            ->where(['company_id' => $this->empresa->id, 'provider' => 'meta', 'phone' => self::TELEFONO])
            ->exists();
    }

    public function test_pago_y_sin_internet_pasa_a_un_asesor(): void
    {
        $this->assertTrue($this->escribe('Buenas tardes ya la factura está paga y no tenemos internet todavía'));

        $this->assertStringContainsString('Le paso con un asesor', $this->loUltimoQueDijoElBot());
        $this->assertTrue($this->botEnPausa(), 'El bot tiene que quedar en pausa para no hablarle encima al asesor.');
        $this->assertSame('high', DB::table('crm_conversations')->where('company_id', $this->empresa->id)->value('priority'));
    }

    public function test_con_el_bot_en_pausa_no_contesta(): void
    {
        $this->escribe('no tenemos internet');

        $this->assertFalse($this->escribe('hola?'), 'Con el bot en pausa, el mensaje es del asesor.');
    }

    public function test_un_saludo_abre_el_menu_y_no_pasa_a_un_asesor(): void
    {
        $this->assertTrue($this->escribe('hola'));

        $this->assertFalse($this->botEnPausa());
        $this->assertSame('menu', WaBotSession::where('company_id', $this->empresa->id)->where('phone', self::TELEFONO)->value('current_flow'));
    }

    public function test_escribir_el_monto_no_registra_un_pago(): void
    {
        WaBotSession::create([
            'company_id' => $this->empresa->id,
            'phone' => self::TELEFONO,
            'current_flow' => 'reportar_pago',
            'current_step' => 'awaiting_payment_proof',
            'data' => ['client_id' => 1, 'selected_invoice_id' => 1],
            'expires_at' => now()->addMinutes(5),
        ]);

        $antes = DB::table('payment_proofs')->count();

        $this->escribe('Monto: 55000 Fecha: 06/10/2026 Referencia: 123456789');

        $this->assertSame($antes, DB::table('payment_proofs')->count(), 'Un texto no es un comprobante.');
        $this->assertStringContainsString('necesito la foto o el documento del comprobante', $this->loUltimoQueDijoElBot());
    }
}
