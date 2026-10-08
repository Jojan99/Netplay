<?php

namespace Tests\Feature;

use App\Http\Requests\Facturation\CreateFacturationRequest;
use App\Models\Company;
use App\Services\Clientes\SuspensionTemporal;
use App\UseCases\Facturation\CreateDetFacturationUseCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Suspensión temporal de punta a punta, contra la base de pruebas.
 *
 * Cliente con corte el 15 y plan de $60.000. Se suspende el 5 de octubre hasta el
 * 5 de noviembre. En la base de pruebas no hay MikroTik: no se toca ningún router.
 */
class SuspensionTemporalTest extends TestCase
{
    use DatabaseTransactions;

    private Company $empresa;
    private int $clienteId;
    private int $cabId;
    private int $grupo;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Event::fake();
        Carbon::setTestNow('2026-10-05 08:00:00');

        $this->empresa = new Company();
        $this->empresa->forceFill(['name' => 'Empresa de pruebas', 'slug' => 'pruebas-' . uniqid(), 'invoice_prefix' => 'PR'])->save();
        // El grupo del cliente es el id del horario (llave foránea) y el proceso de facturación
        // lo compara con el número de grupo: aquí coinciden, como en Netplay.
        $horario = DB::table('company_billing_schedules')->insertGetId(['company_id' => $this->empresa->id, 'grupo' => 0, 'billing_day' => 15, 'billing_hour' => 1, 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('company_billing_schedules')->where('id', $horario)->update(['grupo' => $horario]);
        $this->grupo = $horario;

        $plan = DB::table('internet_plans')->insertGetId(['company_id' => $this->empresa->id, 'plan_name' => 'Plan', 'description' => 'Plan', 'download_speed' => '10', 'upload_speed' => '10', 'monthly_price' => 60000]);
        $this->clienteId = DB::table('users')->insertGetId(['company_id' => $this->empresa->id, 'email' => uniqid() . '@pruebas.test', 'password' => 'x', 'username' => uniqid()]);
        DB::table('user_data')->insert([
            'user_id' => $this->clienteId, 'company_id' => $this->empresa->id, 'names' => 'ANA', 'lastname' => 'VIAJERA', 'dni' => '1122334455',
            'phone' => '3001112233', 'email' => uniqid() . '@pruebas.test', 'address' => 'Calle 1', 'birthday' => '1990-01-01',
            'active' => 1, 'role_id' => null, 'status_internet_id' => 1, 'internet_plans_id' => $plan,
        ]);
        $this->cabId = DB::table('cab_facturations')->insertGetId([
            'company_id' => $this->empresa->id, 'user_id' => $this->clienteId, 'group' => $this->grupo, 'date_init_facturation' => '2026-01-15',
            'created_at' => '2026-01-15 00:00:00',
        ]);
        // La factura del corte de septiembre ya salió (y está pagada).
        DB::table('det_facturations')->insert([
            'cab_id' => $this->cabId, 'number_facture' => 'PR-SEP', 'date_facturation' => '2026-09-15', 'date_create_facturation' => '2026-09-15',
            'paid' => 1, 'price_total' => 60000, 'total' => 1, 'create_facture_manual' => 0,
        ]);

        session(['user' => (object) ['company_id' => $this->empresa->id, 'id' => null]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function suspension(): object
    {
        return DB::table('suspensiones_temporales')->where('user_id', $this->clienteId)->orderByDesc('id')->first();
    }

    private function estadoInternet(): int
    {
        return (int) DB::table('user_data')->where('user_id', $this->clienteId)->value('status_internet_id');
    }

    private function facturarCorte(int $mes): void
    {
        app(CreateDetFacturationUseCase::class)->createProcesoDetFacturation(new CreateFacturationRequest(), $this->grupo, $this->empresa->id, 15, $mes, 2026);
    }

    public function test_el_ciclo_completo_de_una_suspension_temporal(): void
    {
        $s = app(SuspensionTemporal::class);

        // 5 de octubre: se suspende hasta el 5 de noviembre.
        $r = $s->programar($this->empresa->id, $this->clienteId, '2026-10-05', '2026-11-05', 'Viaje', null);
        $this->assertTrue($r['ok'], $r['mensaje']);
        $this->assertSame('activa', $this->suspension()->estado);
        $this->assertSame(2, $this->estadoInternet());
        $this->assertSame(1, (int) DB::table('user_data')->where('user_id', $this->clienteId)->value('no_reactivar_auto'));

        // Se le cobran los 20 días usados (15 sep → 5 oct): 60.000 ÷ 30 × 20.
        $prorrateo = DB::table('det_facturations')->where('id', $this->suspension()->factura_id)->first();
        $this->assertSame(20, (int) $this->suspension()->dias_cobrados);
        $this->assertEquals(40000, (float) $prorrateo->price_total);
        $this->assertSame(0, (int) $prorrateo->paid);

        // 15 de octubre: el corte no le factura (está suspendido).
        Carbon::setTestNow('2026-10-15 01:00:00');
        $this->facturarCorte(10);
        $this->assertFalse(DB::table('det_facturations')->where('cab_id', $this->cabId)->where('date_facturation', '2026-10-15')->exists());

        // 5 de noviembre: vuelve debiendo la factura de los días usados → no se reactiva.
        Carbon::setTestNow('2026-11-05 08:20:00');
        $s->revisar();
        $this->assertSame('requiere_atencion', $this->suspension()->estado);
        $this->assertSame(2, $this->estadoInternet());

        // Y se abre la alerta crítica para que alguien lo atienda.
        $revisor = new \App\Services\Alertas\RevisorDeRed($this->empresa->id);
        $m = new \ReflectionMethod($revisor, 'suspensionesConDeuda');
        $m->setAccessible(true);
        $m->invoke($revisor);
        $this->assertTrue(\App\Models\Alerta::where('company_id', $this->empresa->id)->where('tipo', 'clientes')->where('nivel', 'critico')->whereNull('cerrada_en')->exists());

        // Paga y se le termina la suspensión: se reactiva.
        DB::table('det_facturations')->where('id', $prorrateo->id)->update(['paid' => 1]);
        $this->assertTrue($s->cancelar((int) $this->suspension()->id, null)['ok']);
        $this->assertSame('terminada', $this->suspension()->estado);
        $this->assertSame(1, $this->estadoInternet());
        $this->assertSame(0, (int) DB::table('user_data')->where('user_id', $this->clienteId)->value('no_reactivar_auto'));

        // 15 de noviembre: el corte le cobra solo desde que volvió (5 → 15 nov = 10 días).
        Carbon::setTestNow('2026-11-15 01:00:00');
        $this->facturarCorte(11);
        $noviembre = DB::table('det_facturations')->where('cab_id', $this->cabId)->where('date_facturation', '2026-11-15')->first();
        $this->assertNotNull($noviembre);
        $this->assertEquals(20000, (float) $noviembre->price_total);
    }

    public function test_si_vuelve_al_dia_se_reactiva_solo(): void
    {
        $s = app(SuspensionTemporal::class);
        $s->programar($this->empresa->id, $this->clienteId, '2026-10-05', '2026-10-20', 'Temporada', null);
        DB::table('det_facturations')->where('id', $this->suspension()->factura_id)->update(['paid' => 1]);

        Carbon::setTestNow('2026-10-20 08:20:00');
        $s->revisar();

        $this->assertSame('terminada', $this->suspension()->estado);
        $this->assertSame(1, $this->estadoInternet());
    }

    public function test_una_programada_empieza_el_dia_que_toca(): void
    {
        $s = app(SuspensionTemporal::class);
        $this->assertTrue($s->programar($this->empresa->id, $this->clienteId, '2026-10-10', '2026-10-25', 'Viaje', null)['ok']);
        $this->assertSame('programada', $this->suspension()->estado);
        $this->assertSame(1, $this->estadoInternet());

        Carbon::setTestNow('2026-10-10 08:20:00');
        $s->revisar();
        $this->assertSame('activa', $this->suspension()->estado);
        $this->assertSame(25, (int) $this->suspension()->dias_cobrados, '15 sep → 10 oct');
    }

    public function test_la_que_empieza_el_dia_del_corte_no_cobra_dos_veces(): void
    {
        // El corte del 15 ya facturó el mes (la 1 a. m.) y la suspensión empieza ese día.
        Carbon::setTestNow('2026-10-15 01:00:00');
        $this->facturarCorte(10);
        $this->assertTrue(DB::table('det_facturations')->where('cab_id', $this->cabId)->where('date_facturation', '2026-10-15')->exists());

        Carbon::setTestNow('2026-10-15 08:20:00');
        app(SuspensionTemporal::class)->programar($this->empresa->id, $this->clienteId, '2026-10-15', '2026-11-15', 'Viaje', null);

        $this->assertSame(0, (int) $this->suspension()->dias_cobrados);
        $this->assertNull($this->suspension()->factura_id);
    }

    public function test_valida_las_fechas(): void
    {
        $s = app(SuspensionTemporal::class);

        $this->assertFalse($s->programar($this->empresa->id, $this->clienteId, '2026-10-01', '2026-10-20', 'Viaje', null)['ok'], 'Fecha pasada.');
        $this->assertFalse($s->programar($this->empresa->id, $this->clienteId, '2026-10-20', '2026-10-10', 'Viaje', null)['ok'], 'Vuelve antes de irse.');
        $this->assertFalse($s->programar($this->empresa->id, $this->clienteId, '2026-10-06', '2026-10-20', '  ', null)['ok'], 'Sin motivo.');
        $this->assertTrue($s->programar($this->empresa->id, $this->clienteId, '2026-10-06', '2026-10-20', 'Viaje', null)['ok']);
        $this->assertFalse($s->programar($this->empresa->id, $this->clienteId, '2026-10-21', '2026-10-30', 'Otra', null)['ok'], 'Ya tiene una.');
    }
}
