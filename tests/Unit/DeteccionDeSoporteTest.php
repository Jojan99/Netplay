<?php

namespace Tests\Unit;

use App\Services\Soporte\AgenteDeSoporte;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Qué mensajes se reconocen como una falla del servicio.
 *
 * El 6 de octubre de 2026 un cliente escribió «ya la factura está paga y no
 * tenemos internet todavía»: la lista solo conocía «no tengo internet», el
 * mensaje cayó en el bot de facturas por la palabra «factura» y nadie lo
 * atendió. Estos casos fijan lo que tiene que pasar.
 *
 * No toca la base: phpunit.xml apunta a la de producción.
 */
class DeteccionDeSoporteTest extends TestCase
{
    public static function fallas(): array
    {
        return [
            'el caso real'           => ['Buenas tardes ya la factura está paga y no tenemos internet todavía'],
            'primera persona'        => ['no tengo internet'],
            'plural'                 => ['no tenemos servicio desde ayer'],
            'no hay señal'           => ['no hay señal'],
            'no ha vuelto'           => ['pagué y no ha vuelto el internet'],
            'no nos ha llegado'      => ['no nos ha llegado el servicio'],
            'no han conectado'       => ['no han conectado el internet'],
            'sin internet'           => ['estamos sin internet'],
            'lista fija'             => ['el internet esta lento'],
        ];
    }

    public static function noSonFallas(): array
    {
        return [
            'quiere pagar'           => ['quiero pagar mi factura'],
            'no ha pagado'           => ['no he pagado la factura'],
            'sin plata'              => ['no tengo plata para pagar el servicio'],
            'botón de lista'         => ['Factura #NT14905'],
            'botón de confirmación'  => ['Sí, es correcta'],
            'saludo'                 => ['hola buenas tardes'],
            'vacío'                  => [''],
        ];
    }

    #[DataProvider('fallas')]
    public function test_reconoce_una_falla_del_servicio(string $mensaje): void
    {
        $this->assertTrue(AgenteDeSoporte::suena($mensaje), "Debió reconocer: «{$mensaje}»");
    }

    #[DataProvider('noSonFallas')]
    public function test_no_confunde_lo_que_no_es_una_falla(string $mensaje): void
    {
        $this->assertFalse(AgenteDeSoporte::suena($mensaje), "No debió reconocer: «{$mensaje}»");
    }

    public function test_las_palabras_propias_de_la_empresa_tambien_valen(): void
    {
        $this->assertTrue(AgenteDeSoporte::suena('necesito un reinicio de equipo', 'reinicio de equipo'));
    }
}
