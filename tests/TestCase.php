<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /** La única base contra la que se pueden correr pruebas. */
    private const BASE_DE_PRUEBAS = 'netplay_pruebas';

    protected function setUp(): void
    {
        parent::setUp();

        // Seguro: las pruebas crean y borran datos. Si por un .env o un phpunit.xml
        // mal puesto apuntaran a producción, se detienen antes de tocar nada.
        $base = (string) config('database.connections.' . config('database.default') . '.database');

        if ($base !== self::BASE_DE_PRUEBAS) {
            $this->fail("Las pruebas solo corren contra «" . self::BASE_DE_PRUEBAS . "», no contra «{$base}».");
        }
    }
}
