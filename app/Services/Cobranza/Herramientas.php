<?php

namespace App\Services\Cobranza;

use App\Models\CobranzaCaso;
use App\Models\CobranzaConfig;
use App\Models\Company;
use App\Models\DetFacturation;
use App\Models\PaymentCommitment;
use App\Services\NotificationRouterService;
use App\Services\PaymentGateways\PaymentLinkService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lo único que el asistente puede HACER. La IA propone; aquí se valida contra
 * los límites de la empresa y, si no cumple, se le devuelve el motivo para que
 * le explique al cliente. Ningún acuerdo sale de la conversación sin pasar por
 * estas reglas.
 */
class Herramientas
{
    public function __construct(private CobranzaCaso $caso, private CobranzaConfig $cfg) {}

    /** Definición para la API (nombre, qué hace, qué recibe). */
    public function definiciones(): array
    {
        $cuotas = max(1, (int) $this->cfg->cuotas_max);
        $desc = (int) $this->cfg->descuento_max_pct;

        return array_values(array_filter([
            [
                'name'        => 'registrar_compromiso',
                'description' => 'Registra el compromiso de pago que el cliente aceptó. Una fecha = pagar todo en esa fecha; '
                    . ($cuotas > 1 ? "varias fechas = cuotas (máximo {$cuotas}), las facturas se reparten de la más vieja a la más nueva. " : 'no se permiten cuotas. ')
                    . "Cada fecha debe ser hoy o posterior y a más tardar {$this->cfg->plazo_max_dias} días desde hoy. "
                    . 'Úsala SÓLO cuando el cliente confirmó explícitamente la(s) fecha(s).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'fechas' => ['type' => 'array', 'items' => ['type' => 'string', 'description' => 'AAAA-MM-DD'], 'minItems' => 1, 'maxItems' => $cuotas],
                        'nota'   => ['type' => 'string', 'description' => 'Lo que dijo el cliente, en una línea.'],
                    ],
                    'required' => ['fechas'],
                ],
            ],
            $this->medios()['hay'] ? [
                'name'        => 'enviar_medios_de_pago',
                'description' => 'Le da al cliente cómo pagar TODA la deuda: ' . $this->comoSePaga() . ' '
                    . ($this->mediosDeLaPasarela()
                        ? 'Puede pagar con ' . implode(', ', $this->mediosDeLaPasarela()) . ': si dice con cuál, pásalo en «metodo» y el link lo lleva derecho ahí. '
                        : '')
                    . ($desc > 0 && $this->medios()['link']
                        ? "Opcional: descuento_pct (máximo {$desc}) si el cliente paga el total dentro de {$this->cfg->descuento_dias} día(s); ofrécelo sólo si hace falta para cerrar el pago, empezando por menos."
                        : 'No hay descuentos autorizados.'),
                'input_schema' => [
                    'type' => 'object',
                    'properties' => array_merge(
                        $desc > 0 && $this->medios()['link'] ? ['descuento_pct' => ['type' => 'integer', 'minimum' => 0, 'maximum' => $desc]] : [],
                        $this->mediosDeLaPasarela() ? ['metodo' => [
                            'type' => 'string',
                            'enum' => array_keys($this->mediosDeLaPasarela()),
                            'description' => 'Con qué quiere pagar. Si el cliente no lo dice, no lo mandes: se le ofrecen todos.',
                        ]] : [],
                    ) ?: (object) [],
                ],
            ] : null,
            [
                'name'        => 'escalar_a_humano',
                'description' => 'Pasa la conversación a una persona del equipo y dejas de responder. Úsala si el cliente lo pide, está molesto, '
                    . 'reclama por el servicio o la factura, dice que ya pagó, pide algo fuera de sus límites, o no sabes qué responder.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['motivo' => ['type' => 'string']],
                    'required' => ['motivo'],
                ],
            ],
            [
                'name'        => 'cerrar_caso',
                'description' => 'Cierra el caso sin acuerdo: numero_equivocado (no es el cliente), no_contactar (pide que no le escriban más) o no_desea (se niega a pagar y no quiere hablar con nadie).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'resultado' => ['type' => 'string', 'enum' => ['numero_equivocado', 'no_contactar', 'no_desea']],
                        'nota'      => ['type' => 'string'],
                    ],
                    'required' => ['resultado'],
                ],
            ],
        ]));
    }

    /** @return array{ok:bool, texto:string, fin?:bool} */
    public function ejecutar(string $nombre, array $entrada): array
    {
        try {
            return match ($nombre) {
                'registrar_compromiso' => $this->registrarCompromiso((array) ($entrada['fechas'] ?? []), (string) ($entrada['nota'] ?? '')),
                'enviar_medios_de_pago',
                'enviar_link_de_pago'  => $this->comoPagar((int) ($entrada['descuento_pct'] ?? 0), $entrada['metodo'] ?? null),
                'escalar_a_humano'     => $this->escalar((string) ($entrada['motivo'] ?? 'El asistente pidió ayuda.')),
                'cerrar_caso'          => $this->cerrar((string) ($entrada['resultado'] ?? ''), (string) ($entrada['nota'] ?? '')),
                default                => ['ok' => false, 'texto' => "No existe la herramienta {$nombre}."],
            };
        } catch (\Throwable $e) {
            Log::error('[Cobranza] Falló una herramienta', ['caso' => $this->caso->id, 'herramienta' => $nombre, 'error' => $e->getMessage()]);

            return ['ok' => false, 'texto' => 'No se pudo hacer ahora por un error interno. Dile al cliente que un asesor lo contactará, y escala el caso.'];
        }
    }

    // ── Compromiso ────────────────────────────────────────────────────────

    private function registrarCompromiso(array $fechas, string $nota): array
    {
        // El descuento era sólo por pagar todo con el link a tiempo: si en
        // cambio acuerda un compromiso, las facturas vuelven a su valor.
        $sinDescuento = false;

        if (!empty($this->caso->descuentos)) {
            self::revertirDescuento($this->caso);
            $sinDescuento = true;
        }

        $deuda = Deuda::de((int) $this->caso->company_id, (int) $this->caso->user_id);

        if (!$deuda->cantidad()) {
            return ['ok' => false, 'texto' => 'El cliente ya no tiene saldo pendiente: no hace falta compromiso.'];
        }

        $max = min(max(1, (int) $this->cfg->cuotas_max), $deuda->cantidad());

        if (count($fechas) < 1 || count($fechas) > $max) {
            return ['ok' => false, 'texto' => $max === 1
                ? 'Sólo se permite una fecha (pago total). ' . ($deuda->cantidad() === 1 && $this->cfg->cuotas_max > 1 ? 'Tiene una sola factura: no se puede dividir en cuotas.' : '')
                : "Se permiten entre 1 y {$max} fechas."];
        }

        $hoy = now('America/Bogota')->startOfDay();
        $limite = $hoy->copy()->addDays((int) $this->cfg->plazo_max_dias);
        $anterior = null;
        $dias = [];

        foreach ($fechas as $f) {
            try {
                $d = \Carbon\Carbon::createFromFormat('Y-m-d', (string) $f, 'America/Bogota')->startOfDay();
            } catch (\Throwable) {
                return ['ok' => false, 'texto' => "La fecha «{$f}» no es válida (formato AAAA-MM-DD)."];
            }

            if ($d->lt($hoy) || $d->gt($limite)) {
                return ['ok' => false, 'texto' => 'Cada fecha debe estar entre hoy (' . $hoy->format('Y-m-d') . ') y el ' . $limite->format('Y-m-d') . '. Propón una fecha dentro de ese rango.'];
            }

            if ($anterior && !$d->gt($anterior)) {
                return ['ok' => false, 'texto' => 'Las fechas de las cuotas deben ir en orden y ser distintas.'];
            }

            $anterior = $d;
            $dias[] = $d;
        }

        if ($vigente = PaymentCommitment::where('company_id', $this->caso->company_id)->where('user_id', $this->caso->user_id)->where('status', 'pending')->first()) {
            return ['ok' => false, 'texto' => 'El cliente ya tiene un compromiso de pago vigente para el ' . $vigente->commitment_date?->format('Y-m-d') . '. No se crea otro: recuérdaselo o escala a una persona si quiere cambiarlo.'];
        }

        // Las facturas se reparten parejo, de la más vieja a la más nueva.
        $grupos = [];
        $facturas = $deuda->facturas->values();
        $n = count($dias);
        $base = intdiv($facturas->count(), $n);
        $sobra = $facturas->count() % $n;
        $desde = 0;

        for ($i = 0; $i < $n; $i++) {
            $tam = $base + ($i < $sobra ? 1 : 0);
            $grupos[] = $facturas->slice($desde, $tam)->values();
            $desde += $tam;
        }

        $creados = [];

        DB::transaction(function () use ($grupos, $dias, $nota, &$creados) {
            foreach ($grupos as $i => $grupo) {
                $monto = round((float) $grupo->sum(fn (DetFacturation $f) => $f->outstanding()), 2);

                $c = PaymentCommitment::create([
                    'company_id'       => $this->caso->company_id,
                    'cab_id'           => $grupo->first()->cab_id,
                    'user_id'          => $this->caso->user_id,
                    'commitment_date'  => $dias[$i]->format('Y-m-d'),
                    'amount_committed' => $monto,
                    'notes'            => trim('Acordado por WhatsApp con el asistente de cobranza. ' . $nota),
                    'auto_suspend'     => (bool) $this->cfg->compromiso_suspende,
                    'status'           => 'pending',
                    'created_by'       => null,
                ]);
                $c->invoices()->attach($grupo->pluck('id')->all());

                $creados[] = ['id' => $c->id, 'fecha' => $dias[$i]->format('Y-m-d'), 'monto' => $monto, 'facturas' => $grupo->count()];
            }
        });

        $this->caso->fill([
            'estado'      => 'acuerdo',
            'resultado'   => 'compromiso',
            'compromisos' => $creados,
            'resumen'     => 'Compromiso: ' . implode(' · ', array_map(fn ($c) => $c['fecha'] . ' por ' . Deuda::pesos($c['monto']), $creados)),
            'visto'       => false,
        ])->save();

        $texto = implode("\n", array_map(fn ($c) => "- {$c['fecha']}: " . Deuda::pesos($c['monto']), $creados));

        return ['ok' => true, 'texto' => "Compromiso registrado:\n{$texto}\n"
            . ($sinDescuento ? 'El descuento que se le había ofrecido ya no aplica (era sólo por pagar todo con el link a tiempo): los valores de arriba son sin descuento. Díselo con claridad. ' : '')
            . ($this->cfg->compromiso_suspende ? 'Si no paga en la fecha, el servicio se suspende automáticamente (díselo con tacto). ' : '')
            . ($this->medios()['hay'] ? 'Puedes pasarle los medios de pago con enviar_medios_de_pago para cuando vaya a pagar.' : '')];
    }

    // ── Link de pago (con descuento opcional) ─────────────────────────────

    /**
     * Cómo paga el cliente, con lo que tenga cargado la empresa: link de pago
     * (si hay pasarela), la imagen del QR —que se le manda al momento— y los
     * datos escritos (cuentas, llaves, oficinas).
     */
    private function comoPagar(int $descuento, ?string $metodo = null): array
    {
        $medios = $this->medios();

        if (!$medios['hay']) {
            return ['ok' => false, 'texto' => 'La empresa no cargó medios de pago. Ofrece un compromiso de pago o escala a una persona para que le den los datos.'];
        }

        $partes = [];
        $link = $medios['link'] ? $this->linkDePago($descuento, $metodo) : null;

        // Si el link falla (descuento inválido, sin saldo…), eso manda: no se
        // le mandan medios de pago encima de un error.
        if ($link && !$link['ok']) {
            return $link;
        }

        if ($link) {
            $partes[] = $link['texto'];
        }

        if ($medios['qr']) {
            $partes[] = $this->mandarQr($medios['qr'])
                ? 'Ya le envié al cliente la imagen del QR de pago: dile que escanee el QR que le acaba de llegar.'
                : 'No se pudo enviar la imagen del QR; continúe los datos escritos de abajo.';
        }

        if ($medios['texto']) {
            $partes[] = "Medios de pago de la empresa (cópialos tal cual, sin cambiar ni un número):\n" . $medios['texto'];
        }

        $partes[] = 'Después pídele que le mande el comprobante cuando pague.';

        return ['ok' => true, 'texto' => implode("\n\n", $partes)];
    }

    /**
     * Lo que este cliente puede usar para pagar. El link y el QR son de la
     * pasarela: sólo para quien tenga factura electrónica activa. Al resto se
     * le dan únicamente los datos de pago escritos (Nequi, Daviplata…).
     *
     * @return array{link:bool, qr:?string, texto:?string, hay:bool}
     */
    private function medios(): array
    {
        $m = $this->cfg->mediosDePago($this->hayPasarela());

        if (!$this->conFacturaElectronica()) {
            $m['link'] = false;
            $m['qr'] = null;
            $m['hay'] = (bool) $m['texto'];
        }

        return $m;
    }

    /**
     * Los medios de la pasarela que esta empresa puede ofrecer de verdad.
     *
     * Se le pregunta a la pasarela: ofrecer un botón que la cuenta no tiene
     * habilitado termina en un error del banco delante del cliente.
     *
     * @return array<string,string>
     */
    private function mediosDeLaPasarela(): array
    {
        if (!$this->medios()['link']) {
            return [];
        }

        $empresa = Company::find($this->caso->company_id);

        if (!$empresa || strtolower((string) $empresa->pg_gateway) !== 'wompi') {
            return [];
        }

        try {
            $acepta = (new \App\Services\PaymentGateways\WompiGateway($empresa))->metodosAceptados();
        } catch (\Throwable $e) {
            return [];
        }

        $equivale = ['bancolombia' => 'BANCOLOMBIA_TRANSFER', 'nequi' => 'NEQUI', 'pse' => 'PSE'];
        $nombres  = ['bancolombia' => 'Bancolombia', 'nequi' => 'Nequi', 'pse' => 'PSE'];

        return array_filter($nombres, fn ($_, $clave) => in_array($equivale[$clave], $acepta, true), ARRAY_FILTER_USE_BOTH);
    }

    /** ¿El cliente cobra por la pasarela (factura electrónica activa)? */
    private function conFacturaElectronica(): bool
    {
        return (bool) DB::table('cab_facturations')
            ->where('company_id', $this->caso->company_id)
            ->where('user_id', $this->caso->user_id)
            ->value('billing_electronic');
    }

    /** En una línea, para que la IA sepa qué va a pasar al usarla. */
    private function comoSePaga(): string
    {
        $m = $this->medios();
        $como = array_filter([
            $m['link'] ? 'le devuelve el link de pago en línea' : null,
            $m['qr'] ? 'le manda al cliente la imagen del QR de pago' : null,
            $m['texto'] ? 'le devuelve los datos de pago escritos para que se los copies' : null,
        ]);

        return implode(', ', $como) . '.';
    }

    /** Manda la imagen del QR por la misma línea de WhatsApp de la conversación. */
    private function mandarQr(string $ruta): bool
    {
        try {
            $linea = DB::table('wa_lineas')->where('company_id', $this->caso->company_id)->where('activa', 1)
                ->when($this->cfg->wa_linea_id, fn ($q) => $q->where('id', $this->cfg->wa_linea_id))
                ->orderByDesc('principal')->first(['id', 'instance_id']);

            $url = asset('storage/' . $ruta);

            $idDeWhatsapp = app(Mensajero::class)->enviarImagen(
                (int) $this->caso->company_id,
                $linea?->instance_id,
                (string) $this->caso->telefono,
                $url,
                'Código QR para pagar'
            );

            // Que quede en la conversación: si no, el equipo ve que el
            // asistente habla de un QR que no aparece por ninguna parte.
            if ($this->caso->conversation_id) {
                DB::table('crm_messages')->insert([
                    'conversation_id' => $this->caso->conversation_id,
                    'wa_linea_id'     => $linea?->id,
                    'sender_type'     => 'agent',
                    'message_type'    => 'image',
                    'media_url'       => $url,
                    'mime_type'       => 'image/jpeg',
                    'content'         => 'Código QR para pagar',
                    'status'          => 'sent',
                    'agent_signature' => mb_substr((string) $this->cfg->nombre_asistente, 0, 200),
                    // Para reconocer su eco cuando WhatsApp lo sincronice.
                    'external_id'     => $idDeWhatsapp,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('[Cobranza] No se pudo mandar el QR de pago', ['caso' => $this->caso->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private function linkDePago(int $descuento, ?string $metodo = null): array
    {

        $companyId = (int) $this->caso->company_id;
        $deuda = Deuda::de($companyId, (int) $this->caso->user_id);

        if (!$deuda->cantidad()) {
            return ['ok' => false, 'texto' => 'El cliente ya no tiene saldo pendiente.'];
        }

        $max = (int) $this->cfg->descuento_max_pct;

        if ($descuento < 0 || $descuento > $max) {
            return ['ok' => false, 'texto' => $max > 0 ? "El descuento máximo autorizado es {$max}%." : 'No hay descuentos autorizados.'];
        }

        // Un descuento por caso: si ya se dio, no se suma otro encima.
        if ($descuento > 0 && !empty($this->caso->descuentos)) {
            return ['ok' => false, 'texto' => 'Ya se le aplicó un descuento en esta conversación: no se puede dar otro. Reenvía el link sin descuento adicional (descuento_pct 0).'];
        }

        $ttl = null;
        $aplicado = null;

        if ($descuento > 0) {
            $aplicado = $this->aplicarDescuento($deuda, $descuento);
            $ttl = max(1, (int) $this->cfg->descuento_dias);
            $deuda = Deuda::de($companyId, (int) $this->caso->user_id);
        }

        $servicio = app(PaymentLinkService::class);
        $link = $servicio->create(Company::findOrFail($companyId), (int) $this->caso->user_id, $deuda->facturas->pluck('id')->all(), 'cobranza', $ttl, (string) $this->caso->telefono);
        $url = $servicio->publicUrl($link);

        // Con un medio elegido, el link no abre la pantalla de "seleccione cómo
        // pagar": lleva derecho al banco, o dispara el cobro de Nequi.
        $deLaPasarela = $this->mediosDeLaPasarela();

        if ($metodo && isset($deLaPasarela[$metodo])) {
            $url .= '?m=' . $metodo;
        }

        $this->caso->fill([
            'resumen' => 'Link de pago enviado por ' . Deuda::pesos($deuda->total()) . ($aplicado ? " (descuento {$descuento}%, vence " . $this->caso->descuento_vence?->format('Y-m-d') . ')' : ''),
        ])->save();

        $comoPaga = $metodo && isset($deLaPasarela[$metodo]) ? $deLaPasarela[$metodo] : null;

        return ['ok' => true, 'texto' => ($comoPaga ? "Link para pagar con {$comoPaga}: {$url}" : "Link de pago: {$url}")
            . "\nTotal a pagar: " . Deuda::pesos($deuda->total())
            . ($aplicado ? "\nIncluye el descuento de {$descuento}% (" . Deuda::pesos($aplicado) . "), válido hasta el " . $this->caso->descuento_vence?->format('Y-m-d') . '. Si no paga antes, el descuento se pierde.' : '')
            . "\nComparte el link tal cual, sin cambiarlo."];
    }

    /**
     * Reparte el descuento entre las facturas (proporcional al saldo) y anota
     * lo que tenían antes: si no paga a tiempo, se devuelve como estaba.
     */
    private function aplicarDescuento(Deuda $deuda, int $pct): float
    {
        $total = $deuda->total();
        $monto = round($total * $pct / 100, 0);
        $restante = $monto;
        $cambios = [];
        $facturas = $deuda->facturas->values();

        DB::transaction(function () use ($facturas, $total, $monto, &$restante, &$cambios) {
            foreach ($facturas as $i => $f) {
                $parte = $i === $facturas->count() - 1 ? $restante : round($monto * $f->outstanding() / max(1, $total), 0);
                $parte = min($parte, $f->outstanding());

                if ($parte <= 0) {
                    continue;
                }

                $antes = (float) ($f->price_discount ?? 0);
                DetFacturation::where('id', $f->id)->update(['price_discount' => $antes + $parte]);
                $cambios[] = ['det_id' => (int) $f->id, 'antes' => $antes, 'sumado' => $parte];
                $restante -= $parte;
            }
        });

        $this->caso->fill([
            'descuentos'      => $cambios,
            'descuento_vence' => now('America/Bogota')->addDays(max(1, (int) $this->cfg->descuento_dias))->endOfDay(),
        ])->save();

        return $monto - $restante;
    }

    /**
     * El descuento venció sin que pagara: cada factura vuelve a su descuento
     * de antes (sólo las que siguen sin pagar).
     */
    public static function revertirDescuento(CobranzaCaso $caso): int
    {
        $n = 0;

        foreach ((array) $caso->descuentos as $c) {
            $n += DetFacturation::where('id', $c['det_id'])->where('paid', 0)
                ->update(['price_discount' => DB::raw('GREATEST(0, price_discount - ' . (float) $c['sumado'] . ')')]);
        }

        $caso->fill(['descuentos' => null, 'descuento_vence' => null])->save();

        return $n;
    }

    // ── A una persona / cierre ────────────────────────────────────────────

    private function escalar(string $motivo): array
    {
        $this->caso->fill(['estado' => 'escalado', 'motivo' => mb_substr($motivo, 0, 250), 'visto' => false])->save();

        $cliente = DB::table('user_data')->where('user_id', $this->caso->user_id)->first(['names', 'lastname']);
        NotificationRouterService::dispatch((int) $this->caso->company_id, 'cobranza',
            '💬 Cobranza: ' . trim(($cliente->names ?? '') . ' ' . ($cliente->lastname ?? '')) . " necesita una persona.\nMotivo: {$motivo}\nDeuda: " . Deuda::pesos((float) $this->caso->deuda));

        return ['ok' => true, 'fin' => true, 'texto' => 'Listo: una persona del equipo sigue la conversación. Despídete brevemente diciendo que un asesor le escribirá pronto, y no ofrezcas nada más.'];
    }

    private function cerrar(string $resultado, string $nota): array
    {
        if (!in_array($resultado, ['numero_equivocado', 'no_contactar', 'no_desea'], true)) {
            return ['ok' => false, 'texto' => 'Resultado no válido.'];
        }

        $this->caso->fill(['estado' => 'cerrado', 'resultado' => $resultado, 'motivo' => mb_substr($nota, 0, 250), 'visto' => false])->save();

        return ['ok' => true, 'fin' => true, 'texto' => 'Caso cerrado. Despídete con respeto en una sola frase.'];
    }

    private function hayPasarela(): bool
    {
        $c = Company::find($this->caso->company_id);

        return (bool) ($c?->pg_active) && !empty($c?->pg_gateway);
    }
}
