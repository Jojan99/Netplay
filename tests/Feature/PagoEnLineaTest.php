<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\OnlinePaymentTransaction;
use App\Services\PaymentGateways\PaymentAllocationService;
use App\Services\PaymentGateways\WompiGateway;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pagos en línea (Wompi): la verificación del aviso y que un pago no se aplique dos veces.
 *
 * Hasta el 9 de octubre ningún aviso de Wompi pasaba la verificación (se buscaban
 * las propiedades firmadas desde el lugar equivocado) y los pagos se aplicaban solo
 * por pagos:conciliar. Con los avisos funcionando, los dos caminos pueden llegar a la
 * vez por el mismo pago.
 */
class PagoEnLineaTest extends TestCase
{
    use DatabaseTransactions;

    private Company $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Event::fake();

        $this->empresa = new Company();
        $this->empresa->forceFill(['name' => 'Empresa de pruebas', 'slug' => 'pruebas-' . uniqid(), 'pg_gateway' => 'wompi', 'pg_events_secret' => 'prod_events_secreto'])->save();
    }

    private function aviso(string $checksum): Request
    {
        $cuerpo = [
            'event' => 'transaction.updated',
            'data' => ['transaction' => ['id' => 'tx-1', 'status' => 'APPROVED', 'amount_in_cents' => 1000000]],
            'signature' => ['properties' => ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'], 'checksum' => $checksum],
            'timestamp' => 1791556400,
        ];

        return Request::create('/', 'POST', [], [], [], ['HTTP_X_EVENT_CHECKSUM' => $checksum, 'CONTENT_TYPE' => 'application/json'], json_encode($cuerpo));
    }

    public function test_acepta_el_aviso_firmado_como_lo_firma_wompi_y_rechaza_el_falso(): void
    {
        $wompi = new WompiGateway($this->empresa);
        // Wompi firma los valores de las propiedades (rutas desde «data»), el timestamp y el secreto, en mayúsculas.
        $bueno = strtoupper(hash('sha256', 'tx-1' . 'APPROVED' . '1000000' . '1791556400' . 'prod_events_secreto'));

        $this->assertTrue($wompi->verifyWebhook($this->aviso($bueno)));
        $this->assertFalse($wompi->verifyWebhook($this->aviso(strtoupper(hash('sha256', 'cualquier cosa')))));
    }

    public function test_el_mismo_pago_no_se_aplica_dos_veces(): void
    {
        $usuario = DB::table('users')->insertGetId(['company_id' => $this->empresa->id, 'email' => uniqid() . '@pruebas.test', 'password' => 'x', 'username' => uniqid()]);
        $cab = DB::table('cab_facturations')->insertGetId(['company_id' => $this->empresa->id, 'user_id' => $usuario, 'date_init_facturation' => '2026-10-01']);
        $factura = DB::table('det_facturations')->insertGetId([
            'cab_id' => $cab, 'number_facture' => 'PR-1', 'date_facturation' => '2026-10-01', 'date_create_facturation' => '2026-10-01',
            'paid' => 0, 'price_total' => 10000, 'total' => 1,
        ]);

        OnlinePaymentTransaction::create([
            'company_id' => $this->empresa->id, 'det_facturation_id' => $factura, 'invoice_ids' => [$factura],
            'gateway' => 'wompi', 'reference' => 'ref-prueba', 'amount' => 10000, 'status' => 'approved',
        ]);

        $pagos = app(PaymentAllocationService::class);
        $pagos->allocate($this->empresa->id, 'ref-prueba', 10000, 'wompi', 'NEQUI');
        $pagos->allocate($this->empresa->id, 'ref-prueba', 10000, 'wompi', 'NEQUI');

        $f = DB::table('det_facturations')->where('id', $factura)->first();
        $this->assertEquals(10000, (float) $f->price_abone, 'Se abonó una sola vez.');
        $this->assertSame(1, (int) $f->paid);
    }
}
