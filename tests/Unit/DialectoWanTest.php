<?php

namespace Tests\Unit;

use App\Services\Red\AprovisionamientoDeOnt;
use PHPUnit\Framework\TestCase;

/**
 * Cómo se reconoce, por lo que publica el equipo, dónde va la VLAN de cada conexión.
 * Sin dialecto, el aprovisionamiento no carga la conexión a internet («en esta marca
 * todavía no se configura») y el cliente queda sin navegar.
 */
class DialectoWanTest extends TestCase
{
    /** Un árbol de GenieACS mínimo con una conexión IP que publica $campo. */
    private function equipo(string $campo): array
    {
        return ['InternetGatewayDevice' => ['WANDevice' => ['1' => ['WANConnectionDevice' => ['1' => [
            'WANIPConnection' => ['1' => [$campo => ['_value' => 100]]],
        ]]]]]];
    }

    public function test_reconoce_huawei(): void
    {
        $this->assertSame('X_HW_VLAN', AprovisionamientoDeOnt::dialectoWan($this->equipo('X_HW_VLAN'))['vlan']);
    }

    public function test_reconoce_c_data_y_su_modo_de_etiquetado(): void
    {
        $d = AprovisionamientoDeOnt::dialectoWan($this->equipo('X_CT-COM_VLANIDMark'));
        $this->assertSame('X_CT-COM_VLANMode', $d['modo_vlan']);
    }

    public function test_reconoce_zte_con_el_etiquetado_como_si_o_no(): void
    {
        // ZTE F680 (Prueba_TR, 16 de octubre): antes no se reconocía.
        $d = AprovisionamientoDeOnt::dialectoWan($this->equipo('X_ZTE-COM_VLANID'));

        $this->assertSame('X_ZTE-COM_VLANID', $d['vlan']);
        $this->assertSame('X_ZTE-COM_ServiceList', $d['servicios']);
        $this->assertSame('X_ZTE-COM_VLANEnable', $d['modo_vlan']);
        $this->assertSame('xsd:boolean', $d['modo_vlan_tipo']);
    }

    public function test_sin_dialecto_conocido_devuelve_null(): void
    {
        $this->assertNull(AprovisionamientoDeOnt::dialectoWan($this->equipo('X_OTRA_VLAN')));
    }
}
