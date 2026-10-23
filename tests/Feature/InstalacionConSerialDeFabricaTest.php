<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\Inventario\Bodega;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El 22-10-2026 la orden #31 instaló la ONT ZTEGD1C6F1E8 (la OLT la lee como 5A544547D1C6F1E8)
 * y el inventario dijo «no está»: en la entrada se había escaneado el serial de fábrica de la
 * etiqueta (ZTE0QHBNCV03579), no el GPON. Aquí: el técnico tiene esa ONT cargada con el de
 * fábrica → al instalar se toma esa unidad, queda con el GPON y se descuenta de lo suyo.
 */
class InstalacionConSerialDeFabricaTest extends TestCase
{
    use DatabaseTransactions;

    private int $empresa;
    private int $tecnico;
    private int $item;

    protected function setUp(): void
    {
        parent::setUp();

        $empresa = new Company();
        $empresa->forceFill(['name' => 'Empresa de pruebas', 'slug' => 'pruebas-' . uniqid(), 'invoice_prefix' => 'PR'])->save();
        $this->empresa = (int) $empresa->id;

        $this->tecnico = DB::table('users')->insertGetId(['company_id' => $this->empresa, 'email' => uniqid() . '@pruebas.test', 'password' => 'x', 'username' => uniqid()]);
        DB::table('user_data')->insert([
            'user_id' => $this->tecnico, 'company_id' => $this->empresa, 'names' => 'TECNICO', 'lastname' => 'PRUEBA', 'dni' => '999',
            'phone' => '3009999999', 'email' => uniqid() . '@pruebas.test', 'address' => 'Calle 1', 'birthday' => '1990-01-01', 'active' => 1, 'role_id' => null,
        ]);

        $categoria = DB::table('inventory_categories')->insertGetId(['company_id' => $this->empresa, 'name' => 'ONT', 'created_at' => now(), 'updated_at' => now()]);
        // Cinco en bodega, más las dos que ya salieron para el técnico.
        $this->item = DB::table('inventories')->insertGetId([
            'company_id' => $this->empresa, 'category_id' => $categoria, 'name' => 'ONT ZTE GPON ZXHNF680', 'quantity' => 5, 'usa_serial' => 1,
            'unit_price' => 100000, 'average_cost' => 100000, 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (['ZTEEQHBL3J00239', 'ZTE0QHBNCV03579'] as $i => $deFabrica) {
            DB::table('inventory_units')->insert([
                'company_id' => $this->empresa, 'inventory_id' => $this->item, 'serial' => $deFabrica, 'serial_canonico' => $deFabrica,
                'estado' => 'tecnico', 'tecnico_id' => $this->tecnico, 'entregada_en' => now()->subMinutes(10 - $i), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_la_instalacion_toma_la_onT_del_tecnico_cargada_con_serial_de_fabrica(): void
    {
        $r = (new Bodega($this->empresa))->instalar('5A544547D1C6F1E8', 31, $this->tecnico, $this->tecnico);

        $this->assertTrue($r['ok'], $r['detalle']);
        $this->assertSame($this->item, $r['inventory_id']);

        // La más vieja que tenía el técnico quedó instalada, con su GPON; la otra sigue con él.
        $instalada = DB::table('inventory_units')->where('inventory_id', $this->item)->where('estado', 'instalada')->first();
        $this->assertSame('ZTEGD1C6F1E8', $instalada->serial_canonico);
        $this->assertStringContainsString('ZTEEQHBL3J00239', (string) $instalada->nota);
        $this->assertSame(1, DB::table('inventory_units')->where('inventory_id', $this->item)->where('estado', 'tecnico')->count());

        // Salió de lo del técnico: la bodega no se toca.
        $this->assertSame(5.0, (float) DB::table('inventories')->where('id', $this->item)->value('quantity'));
    }

    public function test_con_el_gpon_de_otra_marca_no_toma_la_zte_del_tecnico(): void
    {
        // Una Huawei (HWTC…) que no está en el inventario: no se le asigna una ZTE del técnico.
        $r = (new Bodega($this->empresa))->instalar('HWTC1234ABCD', 32, $this->tecnico, $this->tecnico);

        $this->assertSame(2, DB::table('inventory_units')->where('inventory_id', $this->item)->where('estado', 'tecnico')->count());
        $this->assertSame(0, DB::table('inventory_units')->where('inventory_id', $this->item)->where('serial_canonico', 'HWTC1234ABCD')->count(), $r['detalle']);
    }
}
