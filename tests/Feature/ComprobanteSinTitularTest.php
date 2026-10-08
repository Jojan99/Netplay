<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PaymentProof;
use App\Services\Crm\ComprobanteWhatsAppWeb;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Un comprobante sin titular no se pierde.
 *
 * Antes, si la cédula no estaba en el sistema (un PPT, una cédula mal escrita),
 * el registro devolvía «cliente_no_encontrado» y el comprobante no quedaba en
 * ningún lado. Ahora entra a la auditoría «sin titular», no se puede aprobar
 * hasta asignarle el cliente, y al asignarlo queda enganchado a su factura.
 */
class ComprobanteSinTitularTest extends TestCase
{
    use DatabaseTransactions;

    private Company $empresa;
    private int $clienteId;
    private int $facturaId;

    protected function setUp(): void
    {
        parent::setUp();

        // La foto la «baja» de un servidor falso; el OCR no encuentra nada en ella.
        Http::fake(['*' => Http::response('no-es-una-foto-de-verdad', 200)]);
        // La «foto» va a un disco falso: sin esto quedaba en storage/app/public de verdad.
        \Illuminate\Support\Facades\Storage::fake('public');
        Event::fake();

        $this->empresa = new Company();
        $this->empresa->forceFill(['name' => 'Empresa de pruebas', 'slug' => 'pruebas-' . uniqid()])->save();

        $this->clienteId = DB::table('users')->insertGetId([
            'company_id' => $this->empresa->id, 'email' => uniqid() . '@pruebas.test', 'password' => 'x', 'username' => '1122334455',
        ]);
        DB::table('user_data')->insert([
            'user_id' => $this->clienteId, 'company_id' => $this->empresa->id, 'names' => 'ANA', 'lastname' => 'PRUEBA',
            'dni' => '1122334455', 'phone' => '3001112233', 'email' => 'ana@pruebas.test', 'address' => 'Calle 1', 'birthday' => '1990-01-01', 'active' => 1,
            'role_id' => null, 'country_id' => null, 'dni_id' => null, 'gender_id' => null, 'internet_plans_id' => null, 'router_id' => null, 'status_internet_id' => 1,
        ]);
        $cab = DB::table('cab_facturations')->insertGetId([
            'company_id' => $this->empresa->id, 'user_id' => $this->clienteId, 'date_init_facturation' => '2026-10-01',
        ]);
        $this->facturaId = DB::table('det_facturations')->insertGetId([
            'cab_id' => $cab, 'number_facture' => 'PR-1', 'date_facturation' => '2026-10-01', 'date_create_facturation' => '2026-10-01',
            'paid' => 0, 'price_total' => 55000, 'total' => 0,
        ]);

        session(['user' => (object) ['company_id' => $this->empresa->id, 'id' => $this->clienteId]]);
    }

    private function registrarConCedula(string $dni): array
    {
        return app(ComprobanteWhatsAppWeb::class)->registrar([
            'company_id' => $this->empresa->id, 'phone' => '573009999999', 'dni' => $dni,
            'media_url' => 'https://archivos.pruebas.test/comprobante.jpg', 'caption' => null, 'sin_aviso' => true,
        ]);
    }

    public function test_una_cedula_que_no_existe_no_pierde_el_comprobante(): void
    {
        $r = $this->registrarConCedula('5365699');

        $this->assertTrue($r['ok']);
        $this->assertTrue($r['sin_titular']);

        $proof = PaymentProof::find($r['proof_id']);
        $this->assertNull($proof->user_id);
        $this->assertSame('pending', $proof->status);
        $this->assertSame('5365699', $proof->raw_payload['dni_escrito']);
    }

    public function test_sin_titular_no_se_puede_aprobar_y_al_asignarlo_si(): void
    {
        $id = $this->registrarConCedula('5365699')['proof_id'];
        $controlador = app(\App\Http\Controllers\PaymentProofController::class);

        $aprobar = $controlador->approve($id, \Illuminate\Http\Request::create('/', 'POST', ['amount' => 55000]));
        $this->assertSame(422, $aprobar->getStatusCode(), 'Sin titular no hay factura a la cual aplicar el pago.');
        $this->assertSame('pending', PaymentProof::find($id)->status);

        $asignar = $controlador->asignar($id, \Illuminate\Http\Request::create('/', 'POST', ['user_id' => $this->clienteId]));
        $this->assertSame(200, $asignar->getStatusCode());

        $proof = PaymentProof::find($id);
        $this->assertSame($this->clienteId, (int) $proof->user_id);
        $this->assertSame($this->facturaId, (int) $proof->invoice_id);
    }

    public function test_con_cedula_conocida_queda_con_su_cliente_y_su_factura(): void
    {
        $r = $this->registrarConCedula('1122334455');

        $this->assertFalse($r['sin_titular']);
        $proof = PaymentProof::find($r['proof_id']);
        $this->assertSame($this->clienteId, (int) $proof->user_id);
        $this->assertSame($this->facturaId, (int) $proof->invoice_id);
    }

    public function test_si_despues_da_la_cedula_el_mismo_comprobante_queda_con_su_cliente(): void
    {
        // Primero llega sin poder identificarlo (el caso del 8 de octubre, #437).
        $primero = $this->registrarConCedula('5365699');
        $this->assertTrue($primero['sin_titular']);

        // Un momento después da su cédula: es el mismo archivo.
        $segundo = $this->registrarConCedula('1122334455');

        $this->assertSame($primero['proof_id'], $segundo['proof_id'], 'No se duplica el comprobante.');
        $proof = PaymentProof::find($primero['proof_id']);
        $this->assertSame($this->clienteId, (int) $proof->user_id);
        $this->assertSame($this->facturaId, (int) $proof->invoice_id);
    }
}
