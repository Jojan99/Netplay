<?php

namespace App\Services\Avisos;

use App\Services\NotificationRouterService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Arma los avisos internos que la plataforma manda al WhatsApp de la empresa.
 *
 * Antes cada lugar escribía el suyo a mano y salía una pila de emojis, uno por
 * renglón. Aquí se arma siempre igual: un título, el nombre de la empresa y los
 * datos en renglones "Etiqueta: valor". Los campos vacíos no se muestran, las
 * fechas van en formato colombiano y la plata con puntos de mil.
 *
 * Se usa así:
 *
 *   MensajeDeAviso::nuevo('Nuevo ticket de soporte', $companyId, '🔧')
 *       ->dato('Ticket', "#{$id}")
 *       ->dato('Cliente', $nombre)
 *       ->telefono('Teléfono', $tel)
 *       ->fecha('Registrado', now())
 *       ->bloque('Observación', $obs)
 *       ->enviar('ticket_support');
 */
final class MensajeDeAviso
{
    /** Nombre de cada empresa ya resuelto, para no ir a la base en cada aviso. */
    private static array $nombres = [];

    private string $titulo;
    private ?int $companyId;
    private string $marcador;

    /** Renglones "Etiqueta: valor" en el orden en que se agregaron. */
    private array $datos = [];

    /** Bloques de texto largo (observaciones, motivos) al final. */
    private array $bloques = [];

    /** Cierre en una línea, sin etiqueta. */
    private ?string $cierre = null;

    private function __construct(string $titulo, ?int $companyId, string $marcador)
    {
        $this->titulo    = trim($titulo);
        $this->companyId = $companyId;
        $this->marcador  = trim($marcador);
    }

    /**
     * @param string   $titulo    Qué pasó, en una línea ("Nuevo ticket de soporte").
     * @param int|null $companyId Empresa del aviso; su nombre va bajo el título.
     * @param string   $marcador  Un solo emoji, opcional, para reconocer el tipo de un vistazo.
     */
    public static function nuevo(string $titulo, ?int $companyId = null, string $marcador = ''): self
    {
        return new self($titulo, $companyId, $marcador);
    }

    /** Fuerza el nombre de empresa cuando ya se tiene y no hace falta consultarlo. */
    public function empresa(?string $nombre): self
    {
        if ($this->companyId !== null && $nombre !== null && trim($nombre) !== '') {
            self::$nombres[$this->companyId] = (string) NotificationRouterService::textoLimpio(trim($nombre));
        }

        return $this;
    }

    /** Un renglón "Etiqueta: valor". Si el valor viene vacío, no se muestra. */
    public function dato(string $etiqueta, mixed $valor): self
    {
        $texto = self::limpiar($valor);

        if ($texto !== '') {
            $this->datos[] = [$etiqueta, $texto];
        }

        return $this;
    }

    /** Plata en pesos: 125000 → "$125.000". */
    public function dinero(string $etiqueta, mixed $valor): self
    {
        if ($valor === null || $valor === '' || !is_numeric($valor)) {
            return $this;
        }

        return $this->dato($etiqueta, '$' . number_format((float) $valor, 0, ',', '.'));
    }

    /** Fecha y hora en colombiano: 17/09/2026 03:45 pm. */
    public function fecha(string $etiqueta, mixed $valor, bool $conHora = true): self
    {
        $texto = self::comoFecha($valor, $conHora);

        return $texto === null ? $this : $this->dato($etiqueta, $texto);
    }

    /** Teléfono legible: 3001234567 → "300 123 4567". */
    public function telefono(string $etiqueta, mixed $valor): self
    {
        $texto = self::limpiar($valor);

        if ($texto === '') {
            return $this;
        }

        $digitos = preg_replace('/\D/', '', $texto);

        if (strlen($digitos) === 10) {
            $texto = substr($digitos, 0, 3) . ' ' . substr($digitos, 3, 3) . ' ' . substr($digitos, 6);
        } elseif (strlen($digitos) === 12 && str_starts_with($digitos, '57')) {
            $texto = '+57 ' . substr($digitos, 2, 3) . ' ' . substr($digitos, 5, 3) . ' ' . substr($digitos, 8);
        }

        return $this->dato($etiqueta, $texto);
    }

    /** Texto largo con su propio subtítulo, al final del mensaje. */
    public function bloque(string $titulo, mixed $texto): self
    {
        $limpio = self::limpiar($texto);

        if ($limpio !== '') {
            $this->bloques[] = [trim($titulo), $limpio];
        }

        return $this;
    }

    /** Una línea de cierre, para decir qué hay que hacer con esto. */
    public function cierre(?string $texto): self
    {
        $limpio = self::limpiar($texto);
        $this->cierre = $limpio !== '' ? $limpio : null;

        return $this;
    }

    /** El mensaje listo para WhatsApp. */
    public function texto(): string
    {
        $encabezado = trim(($this->marcador !== '' ? $this->marcador . ' ' : '') . '*' . mb_strtoupper($this->titulo, 'UTF-8') . '*');

        $empresa = $this->companyId !== null ? self::nombreEmpresa($this->companyId) : '';

        // La empresa va pegada al título, no como un párrafo suelto: es de quién
        // es el aviso, no un dato más.
        $partes = [$empresa !== '' ? $encabezado . "\n" . $empresa : $encabezado];

        if ($this->datos) {
            $renglones = [];

            foreach ($this->datos as [$etiqueta, $valor]) {
                $renglones[] = "*{$etiqueta}:* {$valor}";
            }

            $partes[] = implode("\n", $renglones);
        }

        foreach ($this->bloques as [$titulo, $texto]) {
            $partes[] = "*{$titulo}*\n{$texto}";
        }

        if ($this->cierre !== null) {
            $partes[] = $this->cierre;
        }

        return implode("\n\n", $partes);
    }

    public function __toString(): string
    {
        return $this->texto();
    }

    /** Arma el mensaje y lo manda al destino que la empresa eligió para el evento. */
    public function enviar(string $evento, ?int $companyId = null): void
    {
        $empresa = $companyId ?? $this->companyId;

        if ($empresa === null) {
            return;
        }

        NotificationRouterService::dispatch($empresa, $evento, $this->texto());
    }

    // ── Ayudas ───────────────────────────────────────────────────────────────

    /** Fecha suelta en colombiano, para quien arma el texto por su cuenta. */
    public static function comoFecha(mixed $valor, bool $conHora = true): ?string
    {
        if ($valor === null || $valor === '' || $valor === '0000-00-00') {
            return null;
        }

        try {
            $fecha = $valor instanceof Carbon ? $valor : Carbon::parse((string) $valor);
        } catch (Throwable) {
            return null;
        }

        return $fecha->format($conHora ? 'd/m/Y h:i a' : 'd/m/Y');
    }

    /** Plata suelta, para quien arma el texto por su cuenta. */
    public static function comoDinero(mixed $valor): string
    {
        return '$' . number_format((float) $valor, 0, ',', '.');
    }

    /**
     * Deja el valor listo para mostrar, o vacío si no hay nada que mostrar.
     * Los "N/A", "null" y "0" sueltos que traen los datos viejos no se muestran.
     */
    private static function limpiar(mixed $valor): string
    {
        if ($valor === null || is_bool($valor) || is_array($valor) || is_object($valor)) {
            return is_object($valor) && method_exists($valor, '__toString') ? trim((string) $valor) : '';
        }

        $texto = trim((string) $valor);

        // Espacios raros y renglones en blanco de más: el mensaje se lee en un celular.
        $texto = preg_replace('/[ \t]+/u', ' ', $texto) ?? $texto;
        $texto = preg_replace('/\n{3,}/u', "\n\n", $texto) ?? $texto;
        $texto = trim($texto);

        if (in_array(mb_strtolower($texto, 'UTF-8'), ['', '0', 'n/a', 'na', 'null', 'none', 'sin dirección', 'sin direccion', '-'], true)) {
            return '';
        }

        return (string) NotificationRouterService::textoLimpio($texto);
    }

    private static function nombreEmpresa(int $companyId): string
    {
        if (array_key_exists($companyId, self::$nombres)) {
            return self::$nombres[$companyId];
        }

        $nombre = '';

        try {
            $nombre = trim((string) (DB::table('companies')->where('id', $companyId)->value('name') ?? ''));
        } catch (Throwable) {
            $nombre = '';
        }

        return self::$nombres[$companyId] = (string) NotificationRouterService::textoLimpio($nombre);
    }
}
