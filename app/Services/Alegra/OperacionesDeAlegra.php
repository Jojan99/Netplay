<?php

namespace App\Services\Alegra;

use App\Models\FacturaElectronicaConfig;
use App\Services\FacturaElectronica\FacturacionElectronica;
use App\Services\FacturaElectronica\Proveedores\Alegra;
use App\Services\FacturaElectronica\Proveedores\ErrorDelProveedor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * La bandeja de aprobación: lo único que escribe en Alegra.
 *
 * Tres reglas, las tres por plata y DIAN de verdad:
 *
 *  1. Nada sale sin aprobación. Cada cosa a escribir es primero una propuesta que alguien mira.
 *  2. Cada tipo se estrena de a uno. Mientras un tipo esté «sin probar» sólo se deja aprobar UNA
 *     operación; cuando sale bien queda «probada» y no se aprueba ninguna otra hasta que el
 *     usuario confirme, mirando en Alegra, que quedó como debía. Recién ahí pasa a «verificada»
 *     y se habilitan los lotes y el automático. Es la red para un envío mal armado: se equivoca
 *     en un cliente, no en setecientos.
 *  3. El automático es por tipo, apagado de fábrica, y sólo existe para tipos verificados.
 */
class OperacionesDeAlegra
{
    public const TIPOS = [
        'registrar_pago'    => 'Registrar el pago en Alegra',
        'nota_credito'      => 'Anular con nota crédito',
        'quitar_recurrente' => 'Quitar la factura recurrente',
        'crear_recurrente'  => 'Crear la factura recurrente',
        'crear_contacto'    => 'Crear el contacto en Alegra',
    ];

    public const MEDIOS = ['transfer' => 'Transferencia', 'cash' => 'Efectivo', 'deposit' => 'Consignación', 'debit-card' => 'Tarjeta débito', 'credit-card' => 'Tarjeta de crédito'];

    /** Entre operación y operación: Alegra corta por exceso de consultas (ver EspejoDeAlegra). */
    private const PAUSA_US = 1_200_000;

    public function __construct(private int $companyId) {}

    // ── Ajustes ──────────────────────────────────────────────────────────────

    private function config(): ?FacturaElectronicaConfig
    {
        return (new FacturacionElectronica($this->companyId))->config();
    }

    /** @return array{banco_id:?string, medio_pago:string, tipos:array<string,string>, auto:array<string,bool>} */
    public function ajustes(): array
    {
        $a = (array) ($this->config()?->ajuste('alegra') ?? []);
        $tipos = [];
        $auto  = [];

        foreach (array_keys(self::TIPOS) as $t) {
            $tipos[$t] = in_array($a['tipos'][$t] ?? '', ['probada', 'verificada'], true) ? $a['tipos'][$t] : 'sin_probar';
            // El automático sólo vale para un tipo verificado.
            $auto[$t]  = $tipos[$t] === 'verificada' && !empty($a['auto'][$t]);
        }

        return [
            'banco_id'   => isset($a['banco_id']) && $a['banco_id'] !== '' ? (string) $a['banco_id'] : null,
            'medio_pago' => isset(self::MEDIOS[$a['medio_pago'] ?? '']) ? $a['medio_pago'] : 'transfer',
            'tipos'      => $tipos,
            'auto'       => $auto,
        ];
    }

    private function guardarAjustes(array $cambios): void
    {
        $config = $this->config();

        if (!$config) {
            throw new ErrorDelProveedor('Alegra no está conectado.');
        }

        $ajustes = (array) ($config->ajustes ?? []);
        $ajustes['alegra'] = array_replace_recursive((array) ($ajustes['alegra'] ?? []), $cambios);
        $config->ajustes = $ajustes;
        $config->save();
    }

    /** Banco, medio de pago y qué tipos van en automático (sólo los verificados). */
    public function configurar(?string $bancoId, ?string $medio, array $auto): array
    {
        $actual = $this->ajustes();
        $cambios = [];

        if ($bancoId !== null) {
            $cambios['banco_id'] = $bancoId;
        }
        if ($medio !== null && isset(self::MEDIOS[$medio])) {
            $cambios['medio_pago'] = $medio;
        }
        foreach ($auto as $tipo => $valor) {
            if (isset(self::TIPOS[$tipo])) {
                $cambios['auto'][$tipo] = (bool) $valor && $actual['tipos'][$tipo] === 'verificada';
            }
        }

        if ($cambios) {
            $this->guardarAjustes($cambios);
        }

        return $this->ajustes();
    }

    /** El usuario miró en Alegra la primera operación del tipo y confirma que quedó bien. */
    public function verificar(string $tipo): array
    {
        if (!isset(self::TIPOS[$tipo]) || $this->ajustes()['tipos'][$tipo] !== 'probada') {
            throw new ErrorDelProveedor('Ese tipo todavía no tiene una operación hecha que confirmar.');
        }

        $this->guardarAjustes(['tipos' => [$tipo => 'verificada']]);

        return $this->ajustes();
    }

    // ── Proponer ─────────────────────────────────────────────────────────────

    private function crearPropuesta(string $tipo, string $referencia, array $campos): bool
    {
        if (DB::table('alegra_operaciones')->where('company_id', $this->companyId)->where('referencia', $referencia)->exists()) {
            return false;
        }

        DB::table('alegra_operaciones')->insert($campos + [
            'company_id' => $this->companyId, 'tipo' => $tipo, 'referencia' => $referencia, 'estado' => 'propuesta',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return true;
    }

    /**
     * Mira la copia de Alegra y propone lo que haría falta escribir allá. No escribe nada.
     *
     * @return array<string,int>  cuántas propuestas nuevas por tipo
     */
    public function proponer(): array
    {
        $nuevas = array_fill_keys(array_keys(self::TIPOS), 0);

        $base = fn () => DB::table('alegra_facturas as f')
            ->join('det_facturations as d', 'd.id', '=', 'f.det_facturation_id')
            ->where('f.company_id', $this->companyId)->where('f.estado', 'open')->where('f.saldo', '>', 0);

        // Pagada en Netvula y abierta en Alegra → registrar el pago allá.
        foreach ($base()->where('d.paid', 1)->whereNull('d.anulada_en')
            ->get(['f.alegra_id', 'f.numero', 'f.saldo', 'f.user_id', 'f.cliente_nombre', 'f.cliente_identificacion', 'd.id as det_id', 'd.number_facture', 'd.paid_at', 'f.fecha']) as $f) {
            // El pago no puede llevar fecha anterior a la factura que paga.
            $fecha = max(substr((string) ($f->paid_at ?: now()), 0, 10), (string) $f->fecha);

            $nuevas['registrar_pago'] += (int) $this->crearPropuesta('registrar_pago', 'pago:' . $f->alegra_id, [
                'user_id' => $f->user_id, 'cliente_nombre' => $f->cliente_nombre, 'cliente_identificacion' => $f->cliente_identificacion,
                'det_facturation_id' => $f->det_id, 'alegra_factura_id' => $f->alegra_id, 'monto' => $f->saldo,
                'datos' => json_encode(['factura' => $f->numero, 'cuenta_de_cobro' => $f->number_facture, 'fecha' => $fecha]),
            ]);
        }

        // Anulada en Netvula y viva en Alegra → nota crédito.
        foreach ($base()->whereNotNull('d.anulada_en')->where('f.pagado', '<=', 0)
            ->get(['f.alegra_id', 'f.numero', 'f.total', 'f.user_id', 'f.cliente_nombre', 'f.cliente_identificacion', 'd.id as det_id', 'd.number_facture', 'd.anulada_motivo']) as $f) {
            $nuevas['nota_credito'] += (int) $this->crearPropuesta('nota_credito', 'nc:' . $f->alegra_id, [
                'user_id' => $f->user_id, 'cliente_nombre' => $f->cliente_nombre, 'cliente_identificacion' => $f->cliente_identificacion,
                'det_facturation_id' => $f->det_id, 'alegra_factura_id' => $f->alegra_id, 'monto' => $f->total,
                'datos' => json_encode(['factura' => $f->numero, 'cuenta_de_cobro' => $f->number_facture, 'motivo' => mb_substr((string) $f->anulada_motivo, 0, 200)], JSON_UNESCAPED_UNICODE),
            ]);
        }

        // Cliente retirado en Netvula que sigue con recurrente → quitarla.
        foreach (DB::table('alegra_recurrentes as r')
            ->join('user_data as u', fn ($j) => $j->on('u.user_id', '=', 'r.user_id')->where('u.company_id', $this->companyId))
            ->where('r.company_id', $this->companyId)->where('u.active', 0)
            ->get(['r.alegra_id', 'r.cliente_nombre', 'r.total', 'r.user_id', 'r.proxima', 'u.dni']) as $r) {
            $nuevas['quitar_recurrente'] += (int) $this->crearPropuesta('quitar_recurrente', 'quitar:' . $r->alegra_id, [
                'user_id' => $r->user_id, 'cliente_nombre' => $r->cliente_nombre, 'cliente_identificacion' => $r->dni,
                'alegra_recurrente_id' => $r->alegra_id, 'monto' => $r->total,
                'datos' => json_encode(['motivo' => 'El cliente está retirado en Netvula', 'proxima' => $r->proxima], JSON_UNESCAPED_UNICODE),
            ]);
        }

        $this->cerrarLasQueYaNoAplican();

        return $nuevas;
    }

    /** Una propuesta cuyo caso ya se resolvió por otro lado deja de pedirse. */
    private function cerrarLasQueYaNoAplican(): void
    {
        $abiertas = DB::table('alegra_facturas')->where('company_id', $this->companyId)->where('estado', 'open')->where('saldo', '>', 0)->pluck('alegra_id')->flip();

        foreach (DB::table('alegra_operaciones')->where('company_id', $this->companyId)->where('estado', 'propuesta')
            ->whereIn('tipo', ['registrar_pago', 'nota_credito'])->get(['id', 'alegra_factura_id']) as $op) {
            if (!isset($abiertas[$op->alegra_factura_id])) {
                DB::table('alegra_operaciones')->where('id', $op->id)->update(['estado' => 'descartada', 'error' => 'Ya no aplica: la factura dejó de estar abierta en Alegra.', 'updated_at' => now()]);
            }
        }
    }

    /** El usuario pide quitar la recurrente de un cliente (queda como propuesta). */
    public function proponerQuitar(string $recurrenteId, string $motivo = ''): bool
    {
        $r = DB::table('alegra_recurrentes as r')->leftJoin('user_data as u', fn ($j) => $j->on('u.user_id', '=', 'r.user_id')->where('u.company_id', $this->companyId))
            ->where('r.company_id', $this->companyId)->where('r.alegra_id', $recurrenteId)->first(['r.*', 'u.dni']);

        if (!$r) {
            throw new ErrorDelProveedor('Esa factura recurrente ya no está en Alegra.');
        }

        // Una descartada o fallida anterior no impide volver a pedirlo.
        DB::table('alegra_operaciones')->where('company_id', $this->companyId)->where('referencia', 'quitar:' . $recurrenteId)->whereIn('estado', ['descartada', 'fallida'])->delete();

        return $this->crearPropuesta('quitar_recurrente', 'quitar:' . $recurrenteId, [
            'user_id' => $r->user_id, 'cliente_nombre' => $r->cliente_nombre, 'cliente_identificacion' => $r->dni,
            'alegra_recurrente_id' => $recurrenteId, 'monto' => $r->total,
            'datos' => json_encode(['motivo' => $motivo !== '' ? mb_substr($motivo, 0, 200) : 'Pedido desde el panel', 'proxima' => $r->proxima], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** El usuario pide que Alegra le empiece a facturar a un cliente (queda como propuesta). */
    public function proponerCrear(int $userId, ?string $inicio = null): bool
    {
        $c = DB::table('user_data as u')->join('users as us', 'us.id', '=', 'u.user_id')->leftJoin('internet_plans as p', 'p.id', '=', 'u.internet_plans_id')
            ->where('us.company_id', $this->companyId)->where('u.user_id', $userId)
            ->first(['u.user_id', 'u.names', 'u.lastname', 'u.dni', 'u.active', 'p.monthly_price', 'p.plan_name']);

        if (!$c || !(int) $c->active) {
            throw new ErrorDelProveedor('Ese cliente no está vigente en Netvula.');
        }
        if (DB::table('alegra_recurrentes')->where('company_id', $this->companyId)->where('user_id', $userId)->exists()) {
            throw new ErrorDelProveedor('Ese cliente ya tiene una factura recurrente en Alegra.');
        }

        $contacto = DB::table('alegra_contactos')->where('company_id', $this->companyId)->where('user_id', $userId)->value('alegra_id');

        if (!$contacto) {
            throw new ErrorDelProveedor('Ese cliente todavía no existe como contacto en Alegra: créelo allá primero y actualice los datos.');
        }
        if ((float) $c->monthly_price <= 0) {
            throw new ErrorDelProveedor('El plan del cliente no tiene valor.');
        }

        // Por defecto, el mismo día en que salen las demás: así todas quedan en una sola tanda.
        $inicio = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $inicio) && $inicio >= now()->toDateString()
            ? $inicio
            : (DB::table('alegra_recurrentes')->where('company_id', $this->companyId)->where('proxima', '>=', now()->toDateString())
                ->selectRaw('proxima, COUNT(*) n')->groupBy('proxima')->orderByDesc('n')->value('proxima') ?: now()->addMonth()->startOfMonth()->toDateString());

        DB::table('alegra_operaciones')->where('company_id', $this->companyId)->where('referencia', 'crear:' . $userId)->whereIn('estado', ['descartada', 'fallida'])->delete();

        return $this->crearPropuesta('crear_recurrente', 'crear:' . $userId, [
            'user_id' => $userId, 'cliente_nombre' => trim($c->names . ' ' . $c->lastname), 'cliente_identificacion' => $c->dni, 'monto' => $c->monthly_price,
            'datos' => json_encode(['contacto' => (string) $contacto, 'inicio' => $inicio, 'plan' => $c->plan_name], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** El cliente como lo necesita Alegra, leído de su ficha. Con $problemas si le falta algo. */
    private function clienteParaAlegra(int $userId): array
    {
        $u = DB::table('user_data as u')->join('users as us', 'us.id', '=', 'u.user_id')
            ->where('us.company_id', $this->companyId)->where('u.user_id', $userId)
            ->first(['u.user_id', 'u.names', 'u.lastname', 'u.dni', 'u.address', 'u.phone', 'u.email', 'u.active', 'u.fiscal_tipo_documento', 'u.fiscal_dv', 'u.fiscal_tipo_persona', 'u.barrio', 'u.ciudad', 'u.departamento']);

        if (!$u) {
            throw new ErrorDelProveedor('Ese cliente no existe en Netvula.');
        }

        $numero   = preg_replace('/[^0-9A-Za-z]/', '', (string) $u->dni);
        $tipo     = strtoupper((string) ($u->fiscal_tipo_documento ?: 'CC'));
        $telefono = substr(preg_replace('/\D/', '', (string) $u->phone), -10);
        $problemas = [];

        if (!(int) $u->active) $problemas[] = 'no está vigente';
        if ($numero === '') $problemas[] = 'no tiene documento';
        if (trim((string) $u->names) === '') $problemas[] = 'no tiene nombre';
        if (trim((string) $u->address) === '') $problemas[] = 'no tiene dirección';
        if (strlen($telefono) < 7) $problemas[] = 'no tiene un teléfono válido';
        if ($tipo === 'NIT' && ($u->fiscal_dv === null || $u->fiscal_dv === '')) $problemas[] = 'tiene NIT sin dígito de verificación';

        return [
            'problemas' => $problemas,
            'nombre' => trim($u->names . ' ' . $u->lastname),
            'cliente' => [
                'tipo_documento' => $tipo, 'numero' => $numero, 'dv' => $u->fiscal_dv,
                'persona' => $u->fiscal_tipo_persona ?: ($tipo === 'NIT' ? 'juridica' : 'natural'),
                'nombres' => trim((string) $u->names), 'apellidos' => trim((string) $u->lastname),
                // Alegra no tiene campo de barrio: va al final de la dirección.
                'direccion' => trim((string) $u->address) . ($u->barrio && stripos((string) $u->address, (string) $u->barrio) === false ? ', ' . trim((string) $u->barrio) : ''),
                'ciudad_ficha' => $u->ciudad ?: null, 'departamento_ficha' => $u->departamento ?: null,
                'telefono' => $telefono,
                'email' => filter_var(trim((string) $u->email), FILTER_VALIDATE_EMAIL) ?: null,
            ],
        ];
    }

    /** El usuario pide crear en Alegra el contacto de un cliente (queda como propuesta). */
    public function proponerContacto(int $userId): bool
    {
        if (DB::table('alegra_contactos')->where('company_id', $this->companyId)->where('user_id', $userId)->exists()) {
            throw new ErrorDelProveedor('Ese cliente ya existe como contacto en Alegra.');
        }

        $c = $this->clienteParaAlegra($userId);

        if ($c['problemas']) {
            throw new ErrorDelProveedor($c['nombre'] . ': ' . implode(', ', $c['problemas']) . '. Complete su ficha en Netvula.');
        }

        DB::table('alegra_operaciones')->where('company_id', $this->companyId)->where('referencia', 'contacto:' . $userId)->whereIn('estado', ['descartada', 'fallida'])->delete();

        return $this->crearPropuesta('crear_contacto', 'contacto:' . $userId, [
            'user_id' => $userId, 'cliente_nombre' => $c['nombre'], 'cliente_identificacion' => $c['cliente']['numero'],
            'datos' => json_encode(['tipo_documento' => $c['cliente']['tipo_documento'], 'direccion' => $c['cliente']['direccion'], 'telefono' => $c['cliente']['telefono'], 'email' => $c['cliente']['email']], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Propone el contacto de todos los clientes vigentes que no están en Alegra.
     *
     * @return array{nuevas:int, incompletos:list<string>}
     */
    public function proponerContactos(): array
    {
        $nuevas = 0;
        $incompletos = [];

        $faltan = DB::table('user_data as u')->join('users as us', 'us.id', '=', 'u.user_id')
            ->where('us.company_id', $this->companyId)->where('u.active', 1)
            ->whereNotIn('us.profile_id', fn ($q) => $q->select('id')->from('profiles')->where('company_id', $this->companyId)->whereIn('name', ['ADMIN', 'TECNICO', 'CONTADOR']))
            ->whereNotExists(fn ($x) => $x->from('alegra_contactos as k')->where('k.company_id', $this->companyId)->whereColumn('k.user_id', 'u.user_id'))
            ->pluck('u.user_id');

        foreach ($faltan as $userId) {
            try {
                $nuevas += (int) $this->proponerContacto((int) $userId);
            } catch (ErrorDelProveedor $e) {
                $incompletos[] = $e->getMessage();
            }
        }

        return ['nuevas' => $nuevas, 'incompletos' => $incompletos];
    }

    /**
     * Ciudad y departamento como los escribe esta cuenta en sus contactos («Soledad» /
     * «Atlántico»): Alegra los valida contra su propia lista, así que se copian de los que ya
     * existen en vez de adivinar la escritura.
     *
     * @return array{0:?string, 1:?string}
     */
    private function ciudadDeLaCuenta(Alegra $alegra): array
    {
        $config = $this->config();

        if ($config?->ajuste('ciudad') && $config->ajuste('departamento')) {
            return [(string) $config->ajuste('ciudad'), (string) $config->ajuste('departamento')];
        }

        return Cache::remember("alegra:ciudad:{$this->companyId}", now()->addDay(), function () use ($alegra) {
            $veces = [];

            foreach ($alegra->leer('contacts', ['start' => 0, 'limit' => 30]) as $k) {
                if (is_array($k) && !empty($k['address']['city']) && !empty($k['address']['department'])) {
                    $clave = $k['address']['city'] . '|' . $k['address']['department'];
                    $veces[$clave] = ($veces[$clave] ?? 0) + 1;
                }
            }

            arsort($veces);

            return $veces ? explode('|', (string) array_key_first($veces)) : [null, null];
        });
    }

    private function hacerContacto(Alegra $alegra, object $op): array
    {
        $c = $this->clienteParaAlegra((int) $op->user_id);

        if ($c['problemas']) {
            throw new ErrorDelProveedor('El cliente ' . implode(', ', $c['problemas']) . '.');
        }

        // Los documentos extranjeros van sin ciudad, como los que ya tiene la cuenta.
        [$ciudad, $departamento] = in_array($c['cliente']['tipo_documento'], ['DIE', 'PPT', 'PEP', 'PP'], true) ? [null, null] : $this->ciudadDeLaCuenta($alegra);

        // Manda la ciudad de la ficha del cliente; la de la cuenta es sólo para quien no la tiene.
        if ($ciudad !== null && $c['cliente']['ciudad_ficha'] && $c['cliente']['departamento_ficha']) {
            [$ciudad, $departamento] = [$c['cliente']['ciudad_ficha'], $c['cliente']['departamento_ficha']];
        }

        $r = $alegra->crearContacto($c['cliente'] + ['ciudad' => $ciudad, 'departamento' => $departamento]);

        DB::table('alegra_contactos')->updateOrInsert(['company_id' => $this->companyId, 'alegra_id' => $r['id']], [
            'nombre' => $c['nombre'], 'identificacion' => $c['cliente']['numero'], 'email' => $c['cliente']['email'], 'telefono' => $c['cliente']['telefono'],
            'estado' => 'active', 'user_id' => $op->user_id, 'sincronizada_en' => now(), 'updated_at' => now(),
        ]);

        return $r;
    }

    // ── Aprobar y descartar ──────────────────────────────────────────────────

    /**
     * Aprueba propuestas. No las ejecuta: las deja «aprobadas» para que las tome `aplicarAprobadas()`.
     *
     * @param  list<int> $ids
     * @return array{aprobadas:int, rechazadas:list<string>}
     */
    public function aprobar(array $ids, ?int $usuarioId, bool $automatica = false): array
    {
        $ajustes = $this->ajustes();
        $aprobadas = 0;
        $rechazadas = [];
        // Cuántas hay ya en camino por tipo: para el candado de «de a una» mientras no esté verificado.
        $enCamino = DB::table('alegra_operaciones')->where('company_id', $this->companyId)->whereIn('estado', ['aprobada', 'aplicando'])
            ->selectRaw('tipo, COUNT(*) n')->groupBy('tipo')->pluck('n', 'tipo');

        foreach (DB::table('alegra_operaciones')->where('company_id', $this->companyId)->whereIn('id', $ids)->where('estado', 'propuesta')->orderBy('id')->get() as $op) {
            $estadoTipo = $ajustes['tipos'][$op->tipo] ?? 'sin_probar';

            if ($estadoTipo === 'probada') {
                $rechazadas[] = self::TIPOS[$op->tipo] . ': primero confirme que la que ya se hizo quedó bien en Alegra.';
                continue;
            }
            if ($estadoTipo === 'sin_probar' && (int) ($enCamino[$op->tipo] ?? 0) >= 1) {
                $rechazadas[] = self::TIPOS[$op->tipo] . ': es la primera vez; se aprueba de a una hasta comprobar que queda bien.';
                continue;
            }
            if ($op->tipo === 'registrar_pago' && !$ajustes['banco_id']) {
                $rechazadas[] = 'Falta elegir el banco donde entran los pagos.';
                continue;
            }

            DB::table('alegra_operaciones')->where('id', $op->id)->where('estado', 'propuesta')->update([
                'estado' => 'aprobada', 'aprobada_por' => $usuarioId, 'aprobada_en' => now(), 'automatica' => $automatica, 'error' => null, 'updated_at' => now(),
            ]);
            $enCamino[$op->tipo] = (int) ($enCamino[$op->tipo] ?? 0) + 1;
            $aprobadas++;
        }

        return ['aprobadas' => $aprobadas, 'rechazadas' => array_values(array_unique($rechazadas))];
    }

    /** @param list<int> $ids */
    public function descartar(array $ids): int
    {
        return DB::table('alegra_operaciones')->where('company_id', $this->companyId)->whereIn('id', $ids)->whereIn('estado', ['propuesta', 'fallida'])
            ->update(['estado' => 'descartada', 'updated_at' => now()]);
    }

    /** Una descartada o fallida vuelve a quedar como propuesta. */
    public function restaurar(array $ids): int
    {
        return DB::table('alegra_operaciones')->where('company_id', $this->companyId)->whereIn('id', $ids)->whereIn('estado', ['descartada', 'fallida'])
            // Una nota crédito que Alegra alcanzó a crear no se repite desde aquí.
            ->where(fn ($q) => $q->whereNull('resultado')->orWhere('resultado', 'not like', '%nota_credito%'))
            ->update(['estado' => 'propuesta', 'error' => null, 'updated_at' => now()]);
    }

    /** Aprueba solo lo que el administrador dejó en automático. */
    public function aprobarAutomaticas(): int
    {
        $auto = array_keys(array_filter($this->ajustes()['auto']));

        if (!$auto) {
            return 0;
        }

        $ids = DB::table('alegra_operaciones')->where('company_id', $this->companyId)->where('estado', 'propuesta')->whereIn('tipo', $auto)->pluck('id')->all();

        return $ids ? $this->aprobar($ids, null, true)['aprobadas'] : 0;
    }

    // ── Aplicar: lo único que escribe en Alegra ──────────────────────────────

    /**
     * Ejecuta en Alegra todo lo aprobado, de a una y con pausa.
     *
     * @return array{hechas:int, fallidas:int}
     */
    public function aplicarAprobadas(int $tope = 2000): array
    {
        $r = ['hechas' => 0, 'fallidas' => 0];
        $candado = Cache::lock("alegra:aplicar:{$this->companyId}", 3600);

        if (!$candado->get()) {
            return $r;
        }

        try {
            $alegra = $this->alegra();

            for ($i = 0; $i < $tope; $i++) {
                $op = DB::table('alegra_operaciones')->where('company_id', $this->companyId)->where('estado', 'aprobada')->orderBy('id')->first();

                if (!$op) {
                    break;
                }

                // El candado de estreno también vale aquí, por si dos se aprobaron a la vez.
                if (($this->ajustes()['tipos'][$op->tipo] ?? '') === 'probada') {
                    DB::table('alegra_operaciones')->where('id', $op->id)->update(['estado' => 'propuesta', 'aprobada_en' => null, 'updated_at' => now()]);
                    continue;
                }

                $this->aplicar($alegra, $op) ? $r['hechas']++ : $r['fallidas']++;
                usleep(self::PAUSA_US);
            }
        } finally {
            $candado->release();
        }

        return $r;
    }

    private function alegra(): Alegra
    {
        $config = $this->config();

        if (!$config || $config->proveedor !== 'alegra' || empty($config->credenciales['token'])) {
            throw new ErrorDelProveedor('Alegra no está conectado.');
        }

        /** @var Alegra $a */
        $a = (new FacturacionElectronica($this->companyId))->proveedor($config);

        return $a;
    }

    private function aplicar(Alegra $alegra, object $op): bool
    {
        if (!DB::table('alegra_operaciones')->where('id', $op->id)->where('estado', 'aprobada')->update(['estado' => 'aplicando', 'updated_at' => now()])) {
            return false;
        }

        $datos = (array) json_decode((string) $op->datos, true);

        try {
            $hecho = $this->conPaciencia(fn () => match ($op->tipo) {
                'registrar_pago'    => $this->hacerPago($alegra, $op, $datos),
                'nota_credito'      => $this->hacerNotaCredito($alegra, $op, $datos),
                'quitar_recurrente' => $this->hacerQuitar($alegra, $op),
                'crear_recurrente'  => $this->hacerCrear($alegra, $op, $datos),
                'crear_contacto'    => $this->hacerContacto($alegra, $op),
                default             => throw new ErrorDelProveedor('Tipo de operación desconocido.'),
            });

            DB::table('alegra_operaciones')->where('id', $op->id)->update([
                'estado' => 'hecha', 'externo_id' => $hecho['id'] ?? null, 'resultado' => json_encode($hecho, JSON_UNESCAPED_UNICODE),
                'error' => null, 'aplicada_en' => now(), 'updated_at' => now(),
            ]);

            // El estreno salió: el tipo queda esperando que el usuario lo confirme en Alegra.
            if (($this->ajustes()['tipos'][$op->tipo] ?? '') === 'sin_probar') {
                $this->guardarAjustes(['tipos' => [$op->tipo => 'probada']]);
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('[Alegra] Operación fallida', ['op' => $op->id, 'tipo' => $op->tipo, 'error' => $e->getMessage()]);

            DB::table('alegra_operaciones')->where('id', $op->id)->update([
                'estado' => 'fallida', 'error' => mb_substr($e->getMessage(), 0, 500),
                'resultado' => $e instanceof ErrorDelProveedor && $e->respuesta ? json_encode($e->respuesta, JSON_UNESCAPED_UNICODE) : null, 'updated_at' => now(),
            ]);

            return false;
        }
    }

    /** «Too many requests» no es un fallo de la operación: se espera y se insiste. */
    private function conPaciencia(callable $fn): array
    {
        for ($intento = 1; ; $intento++) {
            try {
                return $fn();
            } catch (ErrorDelProveedor $e) {
                // Sólo se insiste con el límite de consultas: una caída a mitad de un POST puede
                // haber dejado el documento creado, y repetirlo lo duplicaría.
                if ($intento >= 5 || stripos($e->getMessage(), 'too many requests') === false) {
                    throw $e;
                }

                sleep(min(60, 20 * $intento));
            }
        }
    }

    private function hacerPago(Alegra $alegra, object $op, array $datos): array
    {
        $ajustes = $this->ajustes();

        if (!$ajustes['banco_id']) {
            throw new ErrorDelProveedor('Falta elegir el banco donde entran los pagos.');
        }

        // Entre la propuesta y este momento pudieron revertir el pago en Netvula.
        $det = DB::table('det_facturations')->where('id', $op->det_facturation_id)->first(['paid', 'anulada_en']);

        if (!$det || (int) $det->paid !== 1 || $det->anulada_en) {
            throw new ErrorDelProveedor('La cuenta de cobro ya no figura pagada en Netvula: no se registró el pago.');
        }

        $r = $alegra->registrarPago((string) $op->alegra_factura_id, (float) $op->monto, (string) ($datos['fecha'] ?? now()->toDateString()),
            $ajustes['banco_id'], $ajustes['medio_pago'], 'Pago registrado en Netvula · ' . ($datos['cuenta_de_cobro'] ?? ''));

        // La copia local queda al día sin esperar la próxima sincronización.
        DB::table('alegra_facturas')->where('company_id', $this->companyId)->where('alegra_id', $op->alegra_factura_id)
            ->update(['estado' => 'closed', 'pagado' => DB::raw('total'), 'saldo' => 0, 'updated_at' => now()]);

        return $r;
    }

    private function hacerNotaCredito(Alegra $alegra, object $op, array $datos): array
    {
        $det = DB::table('det_facturations')->where('id', $op->det_facturation_id)->first(['anulada_en']);

        if (!$det || !$det->anulada_en) {
            throw new ErrorDelProveedor('La cuenta de cobro ya no está anulada en Netvula: no se hizo la nota crédito.');
        }

        // La numeración electrónica de notas crédito de la cuenta, si hay una sola.
        $numeraciones = array_values(array_filter((new EspejoDeAlegra($this->companyId))->cuenta()['numeraciones'] ?? [],
            fn ($n) => ($n['tipo'] ?? '') === 'creditNote' && !empty($n['electronica']) && ($n['estado'] ?? 'active') === 'active'));

        $r = $alegra->anularFactura((string) $op->alegra_factura_id,
            'Anulación de la factura ' . ($datos['factura'] ?? '') . (!empty($datos['motivo']) ? ': ' . $datos['motivo'] : ''),
            count($numeraciones) === 1 ? (string) $numeraciones[0]['id'] : null);

        // Rechazada por la DIAN: la nota quedó creada en Alegra. No se da por hecha, y tampoco se
        // deja reintentar desde aquí (ver restaurar()): otra pasada crearía una segunda nota.
        if (($r['estado'] ?? '') === 'rechazada') {
            throw new ErrorDelProveedor('Alegra creó la nota crédito pero la DIAN la rechazó: ' . ($r['error'] ?? 'sin detalle') . ' Corríjala en Alegra.', false, ['nota_credito' => $r['externo_id'] ?? null]);
        }

        DB::table('alegra_facturas')->where('company_id', $this->companyId)->where('alegra_id', $op->alegra_factura_id)
            ->update(['estado' => 'closed', 'saldo' => 0, 'updated_at' => now()]);

        // «pendiente»: creada y enviada, la DIAN todavía no contesta. Cuenta como hecha.
        return ['id' => $r['externo_id'] ?? null, 'numero' => $r['numero'] ?? null, 'cufe' => $r['cufe'] ?? null, 'esperando_dian' => ($r['estado'] ?? '') !== 'emitida'];
    }

    private function hacerQuitar(Alegra $alegra, object $op): array
    {
        $antes = $alegra->quitarRecurrente((string) $op->alegra_recurrente_id);

        DB::table('alegra_recurrentes')->where('company_id', $this->companyId)->where('alegra_id', $op->alegra_recurrente_id)->delete();

        // Se guarda entera: es lo que permite volver a crearla igual.
        return ['id' => (string) $op->alegra_recurrente_id, 'recurrente' => $antes];
    }

    private function hacerCrear(Alegra $alegra, object $op, array $datos): array
    {
        $modeloId = DB::table('alegra_recurrentes')->where('company_id', $this->companyId)->orderByRaw('ABS(total - ?)', [(float) $op->monto])->value('alegra_id');

        if (!$modeloId) {
            throw new ErrorDelProveedor('No hay ninguna factura recurrente en la cuenta de la cual copiar la configuración.');
        }

        $r = $alegra->crearRecurrente((string) ($datos['contacto'] ?? ''), $alegra->leer('recurring-invoices/' . $modeloId), (float) $op->monto, (string) ($datos['inicio'] ?? now()->toDateString()));

        if ($r['id']) {
            DB::table('alegra_recurrentes')->updateOrInsert(['company_id' => $this->companyId, 'alegra_id' => $r['id']], [
                'cliente_alegra_id' => (string) ($datos['contacto'] ?? ''), 'cliente_nombre' => $op->cliente_nombre, 'inicio' => $datos['inicio'] ?? null,
                'proxima' => $datos['inicio'] ?? null, 'cada_meses' => 1, 'total' => $op->monto, 'user_id' => $op->user_id,
                'sincronizada_en' => now(), 'updated_at' => now(),
            ]);
        }

        return $r;
    }
}
