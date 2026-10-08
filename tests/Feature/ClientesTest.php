<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\Clientes\CambioDeDocumento;
use App\Services\Clientes\ListaDeClientes;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cambio de documento y búsqueda de clientes, contra la base de pruebas.
 *
 * En la base de pruebas no hay MikroTik: el cambio de documento no toca ningún
 * router, avisa que no lo pudo actualizar y sigue con lo demás.
 */
class ClientesTest extends TestCase
{
    use DatabaseTransactions;

    private Company $empresa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa = new Company();
        $this->empresa->forceFill(['name' => 'Empresa de pruebas', 'slug' => 'pruebas-' . uniqid()])->save();
        session(['user' => (object) ['company_id' => $this->empresa->id, 'id' => null]]);
    }

    /** Un cliente completo, como lo ve la lista: con plan, estado y factura. */
    private function cliente(string $nombres, string $apellidos, string $dni, string $telefono): int
    {
        $plan = DB::table('internet_plans')->insertGetId(['company_id' => $this->empresa->id, 'plan_name' => 'Plan de pruebas', 'download_speed' => '10', 'upload_speed' => '10', 'monthly_price' => 50000, 'description' => 'Plan de pruebas']);
        $id = DB::table('users')->insertGetId([
            'company_id' => $this->empresa->id, 'email' => uniqid() . '@pruebas.test', 'password' => 'x', 'username' => $dni,
        ]);
        DB::table('user_data')->insert([
            'user_id' => $id, 'company_id' => $this->empresa->id, 'names' => $nombres, 'lastname' => $apellidos,
            'dni' => $dni, 'phone' => $telefono, 'email' => uniqid() . '@pruebas.test', 'address' => 'Calle 1', 'birthday' => '1990-01-01',
            'active' => 1, 'role_id' => null, 'status_internet_id' => 1, 'internet_plans_id' => $plan,
        ]);
        DB::table('cab_facturations')->insert(['company_id' => $this->empresa->id, 'user_id' => $id, 'date_init_facturation' => '2026-10-01']);

        return $id;
    }

    private function buscar(string $q): array
    {
        $r = app(ListaDeClientes::class)->pagina(['q' => $q, 'per_page' => 20]);

        return collect($r['items'])->map(fn ($i) => trim("{$i->names} {$i->lastname}"))->all();
    }

    public function test_busca_por_palabras_en_cualquier_orden_y_el_celular_por_digitos(): void
    {
        $this->cliente('GABRIEL DE JESUS', 'GIRALDO SUESCUN', '1041850072', '+573116290588');
        $this->cliente('MARIA', 'LOPEZ', '22334455', '3009998877');

        $gabriel = ['GABRIEL DE JESUS GIRALDO SUESCUN'];

        $this->assertSame($gabriel, $this->buscar('gabriel giraldo'));
        $this->assertSame($gabriel, $this->buscar('giraldo gabriel'));
        $this->assertSame($gabriel, $this->buscar('jesús giraldo'));
        $this->assertSame($gabriel, $this->buscar('gabriel 1041'));
        $this->assertSame($gabriel, $this->buscar('311 629 0588'));
        $this->assertSame($gabriel, $this->buscar('+57 311-629-0588'));
        $this->assertSame([], $this->buscar('zzzz giraldo'));
    }

    public function test_cambiar_el_documento_lo_corrige_en_todos_lados(): void
    {
        $id = $this->cliente('ANA', 'PRUEBA', '1122334455', '3001112233');
        DB::table('crm_customers')->insert(['company_id' => $this->empresa->id, 'phone' => '573001112233', 'name' => 'ANA', 'user_id' => $id, 'dni' => '1122334455']);

        $r = (new CambioDeDocumento())->cambiar($this->empresa->id, $id, '1.122.334.466', null);

        $this->assertTrue($r['ok']);
        $this->assertSame('1122334466', DB::table('user_data')->where('user_id', $id)->value('dni'));
        $this->assertSame('1122334466', DB::table('users')->where('id', $id)->value('username'), 'El usuario de acceso era la cédula: cambia con ella.');
        $this->assertSame('1122334466', DB::table('crm_customers')->where('user_id', $id)->value('dni'));
        $this->assertTrue(DB::table('user_audit_logs')->where('user_id', $id)->where('field_changed', 'dni')
            ->where('old_value', '1122334455')->where('new_value', '1122334466')->exists(), 'Tiene que quedar en el historial.');
        $this->assertNotEmpty($r['avisos'], 'Sin MikroTik, avisa que el router hay que corregirlo a mano.');
    }

    public function test_no_deja_poner_un_documento_que_ya_tiene_otro_cliente(): void
    {
        $ana = $this->cliente('ANA', 'PRUEBA', '1122334455', '3001112233');
        $this->cliente('LUIS', 'OTRO', '5566778899', '3004445566');

        $r = (new CambioDeDocumento())->cambiar($this->empresa->id, $ana, '5566778899', null);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('LUIS OTRO', $r['mensaje']);
        $this->assertSame('1122334455', DB::table('user_data')->where('user_id', $ana)->value('dni'));
    }

    public function test_rechaza_un_documento_invalido(): void
    {
        $id = $this->cliente('ANA', 'PRUEBA', '1122334455', '3001112233');

        $this->assertFalse((new CambioDeDocumento())->cambiar($this->empresa->id, $id, '12', null)['ok']);
        $this->assertFalse((new CambioDeDocumento())->cambiar($this->empresa->id, $id, '1122334455', null)['ok'], 'Ese ya es su documento.');
    }
}
