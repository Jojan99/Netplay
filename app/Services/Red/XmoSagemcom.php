<?php

namespace App\Services\Red;

use Illuminate\Support\Facades\Http;

/**
 * La API de la página de los Sagemcom (Fast 5670): JSON-RPC «XMO» en /cgi/json-req.
 *
 * Cada petición va firmada: auth-key = sha512(credencial:id:cnonce:JSON:/cgi/json-req),
 * con credencial = sha512(usuario:nonce:sha512(clave)). El nonce y la sesión los da el
 * logIn; antes van vacío y 0. La página sólo contesta con el Host de la LAN.
 */
class XmoSagemcom
{
    private string $nonce = '';
    private int $sesion = 0;
    private int $peticion = 0;

    public function __construct(private string $base, private string $usuario, private string $clave) {}

    public function entrar(): bool
    {
        $r = $this->llamar([['id' => 0, 'method' => 'logIn', 'parameters' => [
            'user' => $this->usuario, 'persistent' => true,
            'session-options' => [
                'nss' => [['name' => 'gtw', 'uri' => 'http://sagemcom.com/gateway-data']],
                'language' => 'ident', 'context-flags' => ['get-content-name' => true, 'local-time' => true],
                'capability-depth' => 2,
                'capability-flags' => ['name' => true, 'default-value' => false, 'restriction' => true, 'description' => false],
                'time-format' => 'ISO_8601',
            ],
        ]]]);

        $accion = $r['actions'][0] ?? [];
        $datos = $accion['callbacks'][0]['parameters'] ?? [];

        if (($accion['error']['description'] ?? '') !== 'XMO_NO_ERR' || !isset($datos['id'], $datos['nonce'])) {
            return false;
        }

        $this->sesion = (int) $datos['id'];
        $this->nonce = (string) $datos['nonce'];

        return true;
    }

    public function poner(string $xpath, mixed $valor): bool
    {
        $r = $this->llamar([['id' => 0, 'method' => 'setValue', 'xpath' => $xpath, 'parameters' => ['value' => $valor]]]);

        return ($r['actions'][0]['error']['description'] ?? '') === 'XMO_NO_ERR';
    }

    public function leer(string $xpath): mixed
    {
        $r = $this->llamar([['id' => 0, 'method' => 'getValue', 'xpath' => $xpath]]);

        return $r['actions'][0]['callbacks'][0]['parameters']['value'] ?? null;
    }

    public function salir(): void
    {
        try {
            $this->llamar([['id' => 0, 'method' => 'logOut']]);
        } catch (\Throwable) {
        }
    }

    /** @return array<string,mixed> el «reply» */
    private function llamar(array $acciones): array
    {
        $h = fn (string $s) => hash('sha512', $s);
        $cnonce = (string) random_int(100000000, 999999999);
        $credencial = $h("{$this->usuario}:{$this->nonce}:" . $h($this->clave));

        $cuerpo = ['request' => [
            'id' => $this->peticion, 'session-id' => $this->sesion, 'priority' => false, 'actions' => $acciones,
            'cnonce' => $cnonce, 'auth-key' => $h("{$credencial}:{$this->peticion}:{$cnonce}:JSON:/cgi/json-req"),
        ]];
        $this->peticion++;

        $r = Http::withOptions(['verify' => false])->timeout(20)
            ->withHeaders(['Host' => '192.168.1.254'])
            ->asForm()->post("{$this->base}/cgi/json-req", ['req' => json_encode($cuerpo)]);

        return (array) ($r->json('reply') ?? []);
    }
}
