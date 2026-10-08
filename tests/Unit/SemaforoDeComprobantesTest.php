<?php

namespace Tests\Unit;

use App\Services\Comprobantes\SemaforoDeComprobantes;
use PHPUnit\Framework\TestCase;

/**
 * Cómo lee el semáforo un comprobante de Nequi. Sin base de datos.
 */
class SemaforoDeComprobantesTest extends TestCase
{
    public function test_lee_la_hora_impresa(): void
    {
        $this->assertSame(14 * 60 + 30, SemaforoDeComprobantes::minutoImpreso('06 de octubre de 2026 a las 02:30 p. m.'));
        $this->assertSame(9 * 60 + 5, SemaforoDeComprobantes::minutoImpreso('a las 09:05 a.m.'));
        $this->assertSame(5, SemaforoDeComprobantes::minutoImpreso('a las 12:05 a. m.'));
        $this->assertNull(SemaforoDeComprobantes::minutoImpreso('sin hora'));
    }

    public function test_solo_acepta_referencias_de_nequi(): void
    {
        $this->assertSame(13358926, SemaforoDeComprobantes::referenciaNequi('M13358926'));
        $this->assertNull(SemaforoDeComprobantes::referenciaNequi('99986291506578140729'));
        $this->assertNull(SemaforoDeComprobantes::referenciaNequi(null));
    }

    public function test_el_destino_es_el_numero_nequi_no_el_de_quien_paga(): void
    {
        $envio = "Para\nJojanny Pombo\n¿Cuánto?\n$ 55.000,00\nNúmero Nequi\n324 512 7869\nFecha";
        $this->assertSame('3245127869', SemaforoDeComprobantes::destino($envio));

        // Envío por llave: el número que aparece es el de quien paga, no el destino.
        $porLlave = "Para\nNet**** SAS\nLlave\n0012345678\nBanco destino\n¿Desde dónde se hizo el envío?\n324 588 1299";
        $this->assertSame('0012345678', SemaforoDeComprobantes::destino($porLlave));

        $soloQuienPaga = "¿Desde dónde se hizo el envío?\n324 588 1299";
        $this->assertNull(SemaforoDeComprobantes::destino($soloQuienPaga));
    }

    public function test_una_palabra_suelta_no_es_una_llave(): void
    {
        $this->assertNull(SemaforoDeComprobantes::destino('Tu llave fue exitosa'));
    }
}
