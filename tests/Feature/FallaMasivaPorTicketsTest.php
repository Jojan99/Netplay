<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\Red\FallasDeSector;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Falla masiva por tickets, contra la base de pruebas.
 *
 * El 22-10-2026 la OLT dejó de responder y Kloe abrió un ticket por cliente sin que nada
 * dijera que era una masiva. Aquí: tres clientes de la misma OLT reportan en pocos minutos →
 * se abre la masiva de toda la OLT con los tres tickets; el cuarto que llega se ata solo; al
 * resolverla, los cuatro se cierran con su nota. Sin aviso al grupo (no hay WhatsApp).
 */
class FallaMasivaPorTicketsTest extends TestCase
{
    use DatabaseTransactions;

    private int $empresa;
    private int $olt;
    private int $tecnico;
    /** @var list<int> */
    private array $clientes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();

        $empresa = new Company();
        $empresa->forceFill(['name' => 'Empresa de pruebas', 'slug' => 'pruebas-' . uniqid(), 'invoice_prefix' => 'PR'])->save();
        $this->empresa = (int) $empresa->id;

        DB::table('fallas_sector_config')->insert([
            'company_id' => $this->empresa, 'activo' => 1, 'modo' => 'aprobar', 'avisar_grupo' => 0, 'avisar_inicio' => 0, 'avisar_fin' => 0,
            'tickets_para_masiva' => 3, 'minutos_ventana_tickets' => 30, 'cerrar_tickets' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Los tres estados de ticket de Netplay (la base de pruebas viene sin ellos).
        foreach ([1 => 'Por hacer', 2 => 'En curso', 3 => 'Finalizado'] as $id => $nombre) {
            DB::table('ticket_status')->insertOrIgnore(['id' => $id, 'company_id' => $this->empresa, 'name' => $nombre, 'active' => 1]);
        }

        $this->olt = DB::table('olt_admins')->insertGetId(['company_id' => $this->empresa, 'name' => 'OLT PRUEBA', 'host' => '10.0.0.1', 'username' => 'x', 'password' => 'x']);
        // El técnico del ticket apunta a user_data.user_id: necesita su ficha.
        $this->tecnico = DB::table('users')->insertGetId(['company_id' => $this->empresa, 'email' => uniqid() . '@pruebas.test', 'password' => 'x', 'username' => uniqid()]);
        DB::table('user_data')->insert([
            'user_id' => $this->tecnico, 'company_id' => $this->empresa, 'names' => 'TECNICO', 'lastname' => 'PRUEBA', 'dni' => '999',
            'phone' => '3009999999', 'email' => uniqid() . '@pruebas.test', 'address' => 'Calle 1', 'birthday' => '1990-01-01', 'active' => 1, 'role_id' => null,
        ]);

        foreach (range(1, 4) as $n) {
            $uid = DB::table('users')->insertGetId(['company_id' => $this->empresa, 'email' => uniqid() . '@pruebas.test', 'password' => 'x', 'username' => uniqid()]);
            $ficha = DB::table('user_data')->insertGetId([
                'user_id' => $uid, 'company_id' => $this->empresa, 'names' => "CLIENTE {$n}", 'lastname' => 'PRUEBA', 'dni' => "10{$n}", 'phone' => "300000000{$n}",
                'email' => uniqid() . '@pruebas.test', 'address' => 'Calle 1', 'birthday' => '1990-01-01', 'active' => 1, 'role_id' => null, 'status_internet_id' => 1,
            ]);
            DB::table('olt_onts')->insert(['olt_id' => $this->olt, 'fsp' => '0/0/' . $n, 'ont_id' => $n, 'user_data_id' => $ficha, 'status' => 'online']);
            $this->clientes[] = $uid;
        }
    }

    private function ticket(int $cliente): int
    {
        $id = DB::table('tickets')->insertGetId([
            'company_id' => $this->empresa, 'user_id' => $cliente, 'address' => 'Calle 1', 'date' => now()->toDateString(), 'status_id' => 1,
            'technical_id' => $this->tecnico, 'observation' => 'Sin internet', 'source' => 'asistente', 'reopened_count' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        FallasDeSector::alCrearTicket($this->empresa, $id);

        return $id;
    }

    public function test_una_racha_de_tickets_abre_la_masiva_y_al_resolverse_los_cierra(): void
    {
        $t1 = $this->ticket($this->clientes[0]);
        $t2 = $this->ticket($this->clientes[1]);
        $this->assertSame(0, DB::table('fallas_sector')->where('company_id', $this->empresa)->count(), 'Dos reportes todavía no son una masiva.');

        $t3 = $this->ticket($this->clientes[2]);
        $falla = DB::table('fallas_sector')->where('company_id', $this->empresa)->first();
        $this->assertNotNull($falla, 'El tercer reporte de la misma OLT abre la masiva.');
        $this->assertSame(FallasDeSector::TODA_LA_OLT, $falla->fsp);
        $this->assertSame('tickets', $falla->origen);
        $this->assertSame('por_aprobar', $falla->estado);
        $this->assertSame(3, DB::table('tickets')->where('falla_id', $falla->id)->count());

        // El que llega después, de la misma OLT, se ata a la falla abierta.
        $t4 = $this->ticket($this->clientes[3]);
        $this->assertSame((int) $falla->id, (int) DB::table('tickets')->where('id', $t4)->value('falla_id'));

        $this->assertTrue((new FallasDeSector($this->empresa))->resolverAMano((int) $falla->id));

        foreach ([$t1, $t2, $t3, $t4] as $t) {
            $this->assertSame(3, (int) DB::table('tickets')->where('id', $t)->value('status_id'), "El ticket #{$t} queda cerrado.");
            $this->assertTrue(DB::table('ticket_notes')->where('ticket_id', $t)->where('note', 'like', '✅ Cierre: se resolvió la falla masiva%')->exists());
        }
    }

    public function test_los_tickets_del_panel_no_cuentan_para_la_racha(): void
    {
        foreach ($this->clientes as $c) {
            $id = DB::table('tickets')->insertGetId([
                'company_id' => $this->empresa, 'user_id' => $c, 'address' => 'Calle 1', 'date' => now()->toDateString(), 'status_id' => 1,
                'technical_id' => $this->tecnico, 'observation' => 'Señal baja', 'source' => 'admin', 'reopened_count' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            FallasDeSector::alCrearTicket($this->empresa, $id);
        }

        // Los de «señal baja» que crea la red salen varios juntos: no son una masiva.
        $this->assertSame(0, DB::table('fallas_sector')->where('company_id', $this->empresa)->count());
    }
}
