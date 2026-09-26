<?php

namespace App\Services\WaBot;

use App\Models\Company;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lo que el bot manda, a WhatsApp de verdad.
 *
 * Aquí viven los límites de Meta —3 botones, 10 filas de lista, cuántos
 * caracteres entran en cada cosa— porque son del canal y no del flujo: quien
 * dibuja el flujo no tiene que saberlos de memoria, y si Meta los cambia se
 * cambian en un solo lugar.
 *
 * Todo lo que sale queda también en la conversación del CRM: sin eso, el
 * agente que atiende después no ve lo que el bot ya le dijo al cliente.
 */
class CanalDeWhatsapp implements Canal
{
    public const MAX_BOTONES = 3;
    public const LARGO_BOTON = 20;
    public const MAX_FILAS = 10;
    public const LARGO_TITULO_FILA = 24;
    public const LARGO_DESC_FILA = 72;
    public const LARGO_CUERPO = 1024;

    public function __construct(
        private Company $empresa,
        private string $telefono,
        /** Para dejar constancia en el CRM; se le pasa el registrador del bot. */
        private ?\Closure $registrar = null,
    ) {
    }

    public function texto(string $texto): void
    {
        $texto = mb_substr(trim($texto), 0, self::LARGO_CUERPO);

        if ($texto === '') {
            return;
        }

        $this->anotar($texto);
        $this->intentar(fn (WhatsAppService $wa) => $wa->mensajeInformativo($this->telefono, $texto));
    }

    public function botones(string $cuerpo, array $botones, string $encabezado = ''): void
    {
        $botones = array_slice(array_values($botones), 0, self::MAX_BOTONES);

        if (!$botones) {
            $this->texto($cuerpo);

            return;
        }

        $listos = array_map(fn (array $b) => [
            'id'    => (string) ($b['id'] ?? ''),
            'title' => mb_substr((string) ($b['title'] ?? 'Opción'), 0, self::LARGO_BOTON),
        ], $botones);

        $this->anotar($cuerpo . "\n" . implode(' · ', array_column($listos, 'title')));
        $this->intentar(fn (WhatsAppService $wa) => $wa->sendInteractiveButtons(
            $this->telefono,
            mb_substr($cuerpo, 0, self::LARGO_CUERPO),
            $listos,
            mb_substr($encabezado, 0, 60),
        ));
    }

    public function lista(string $cuerpo, array $secciones, string $textoBoton = 'Ver opciones'): void
    {
        $restantes = self::MAX_FILAS;
        $listas = [];

        foreach ($secciones as $seccion) {
            $filas = [];

            foreach (($seccion['rows'] ?? []) as $fila) {
                if ($restantes <= 0) {
                    break;
                }

                $filas[] = array_filter([
                    'id'          => (string) ($fila['id'] ?? ''),
                    'title'       => mb_substr((string) ($fila['title'] ?? 'Opción'), 0, self::LARGO_TITULO_FILA),
                    'description' => mb_substr((string) ($fila['description'] ?? ''), 0, self::LARGO_DESC_FILA) ?: null,
                ], fn ($v) => $v !== null);

                $restantes--;
            }

            if ($filas) {
                $listas[] = ['title' => mb_substr((string) ($seccion['title'] ?? 'Opciones'), 0, 24), 'rows' => $filas];
            }
        }

        if (!$listas) {
            $this->texto($cuerpo);

            return;
        }

        $titulos = [];

        foreach ($listas as $l) {
            $titulos = array_merge($titulos, array_column($l['rows'], 'title'));
        }

        $this->anotar($cuerpo . "\n" . implode(' · ', $titulos));
        $this->intentar(fn (WhatsAppService $wa) => $wa->sendInteractiveList(
            $this->telefono,
            mb_substr($cuerpo, 0, self::LARGO_CUERPO),
            $listas,
            mb_substr($textoBoton, 0, self::LARGO_BOTON),
        ));
    }

    public function imagen(string $url, string $pie = ''): void
    {
        if (!$url) {
            return;
        }

        $this->anotar($pie ?: '[imagen]');
        $this->intentar(fn (WhatsAppService $wa) => $wa->sendImage($this->telefono, $url, $pie));
    }

    public function documento(string $url, string $nombre, string $pie = ''): void
    {
        if (!$url) {
            return;
        }

        $this->anotar($pie ?: "[documento: {$nombre}]");
        $this->intentar(fn (WhatsAppService $wa) => $wa->sendDocument($this->telefono, $url, $nombre ?: 'archivo.pdf', $pie));
    }

    public function enlace(string $cuerpo, string $url, string $textoBoton): void
    {
        if (!$url) {
            $this->texto($cuerpo);

            return;
        }

        $this->anotar($cuerpo . "\n" . $url);
        $this->intentar(fn (WhatsAppService $wa) => $wa->sendCtaUrl(
            $this->telefono,
            mb_substr($cuerpo, 0, self::LARGO_CUERPO),
            mb_substr($textoBoton ?: 'Abrir', 0, self::LARGO_BOTON),
            $url,
        ));
    }

    /**
     * Un envío nunca puede tumbar la conversación: si Meta falla, se registra
     * y el flujo sigue. Quedarse a mitad de camino es peor que un mensaje
     * perdido.
     */
    private function intentar(\Closure $envio): void
    {
        try {
            $r = $envio(new WhatsAppService($this->empresa->id, false, 'meta'));

            if (($r['success'] ?? true) === false) {
                Log::warning('[BotFlujos] Meta no aceptó el mensaje', [
                    'empresa' => $this->empresa->id,
                    'error'   => $r['error'] ?? null,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[BotFlujos] No se pudo enviar', ['empresa' => $this->empresa->id, 'error' => $e->getMessage()]);
        }
    }

    private function anotar(string $texto): void
    {
        if ($this->registrar) {
            try {
                ($this->registrar)($texto);
            } catch (\Throwable $e) {
                // Que no se pueda anotar en el CRM no impide contestarle al cliente.
            }
        }
    }
}
