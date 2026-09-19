<?php

namespace App\Repositories;

use App\Http\Requests\Egresos\CreateEgresosRequest;
use App\Models\Egresses;
use App\Repositories\Interfaces\EgresosRepositoryInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EgresosRepository implements EgresosRepositoryInterface
{
    /** Categorías con las que arranca una empresa nueva (gastos típicos de un ISP). */
    private const CATEGORIAS_BASE = [
        'General', 'Ancho de banda', 'Nómina', 'Arriendo', 'Energía',
        'Mantenimiento de red', 'Equipos e insumos', 'Transporte',
        'Impuestos', 'Publicidad', 'Otros',
    ];

    /** Campos nuevos del registro; se ignoran mientras la migración no corra. */
    private const CAMPOS_NUEVOS = [
        'expense_date', 'supplier', 'document_number', 'notes',
        'attachment_path', 'attachment_name', 'recurrence',
    ];

    private static ?bool $hayCamposNuevos = null;
    private static ?bool $hayCatalogo     = null;

    // ── Compatibilidad con el módulo viejo ──────────────────────────────────

    public function createEgresos(CreateEgresosRequest $data): mixed
    {
        return $this->createEgresoV2([
            'concept'      => $data['concept'],
            'value'        => $data['value'],
            'user_id'      => $data['user_id'] ?? null,
            'expense_date' => now()->toDateString(),
        ]);
    }

    public function getEgresosAll(): mixed
    {
        return Egresses::where('company_id', getSessionCompanyId())->get();
    }

    public function getPriceEgresseAll(): mixed
    {
        return $this->getPriceEgresseByRange(null, null);
    }

    /**
     * Los números que comparten el Resumen Financiero y el tablero de Egresos.
     * Los egresos se filtran por la fecha real del gasto (expense_date) y, si
     * no la tienen, por created_at: así las dos pantallas nunca se contradicen.
     */
    public function getPriceEgresseByRange(?string $from, ?string $to): mixed
    {
        $companyId = getSessionCompanyId();

        $incomeQuery = DB::table('det_facturations as df')
            ->join('cab_facturations as cb', 'cb.id', '=', 'df.cab_id')
            ->join('users', 'users.id', '=', 'cb.user_id')
            ->where('users.company_id', $companyId)
            ->selectRaw('
                SUM(CASE WHEN df.abone != 1 AND df.paid = 1 THEN (df.price_total - df.price_discount) ELSE 0 END) AS valor_ingreso,
                SUM(CASE WHEN df.abone = 1  AND df.paid != 1 THEN df.price_abone ELSE 0 END) AS valor_abone
            ');

        if ($from) {
            $incomeQuery->whereDate('df.created_at', '>=', $from);
        }
        if ($to) {
            $incomeQuery->whereDate('df.created_at', '<=', $to);
        }

        $income = $incomeQuery->first();

        $egresosQuery = DB::table('egresses as e')->where('e.company_id', $companyId);
        $this->entreFechas($egresosQuery, $from, $to);
        $totalEgresses = (float) $egresosQuery->sum('e.value');

        // Egresos por compras de inventario (entradas)
        $inventoryEgresses = DB::table('inventory_movements')
            ->where('company_id', $companyId)
            ->where('type', 'entrada');
        if ($from) {
            $inventoryEgresses->whereDate('created_at', '>=', $from);
        }
        if ($to) {
            $inventoryEgresses->whereDate('created_at', '<=', $to);
        }
        $totalInventoryEgresses = $inventoryEgresses->selectRaw('SUM(quantity * unit_price) as total')->value('total') ?? 0;

        $valorIngreso = $income->valor_ingreso ?? 0;
        $valorAbone   = $income->valor_abone   ?? 0;
        $totalSum     = $valorIngreso + $valorAbone;
        $totalGastos  = $totalEgresses + $totalInventoryEgresses;

        return (object)[
            'valor_ingreso'           => $valorIngreso,
            'valor_abone'             => $valorAbone,
            'total_sum'               => $totalSum,
            'total_egresses'          => $totalEgresses,
            'total_inventory_egresses'=> $totalInventoryEgresses,
            'total_gastos'            => $totalGastos,
            'net_value'               => $totalSum - $totalGastos,
        ];
    }

    public function getIngresosDetailed(?string $from, ?string $to): mixed
    {
        $companyId = getSessionCompanyId();

        $query = DB::table('det_facturations as df')
            ->join('cab_facturations as cb', 'cb.id', '=', 'df.cab_id')
            ->join('users', 'users.id', '=', 'cb.user_id')
            ->where('users.company_id', $companyId)
            ->where(function ($q) {
                $q->where(function ($q2) {
                    $q2->where('df.abone', '!=', 1)->where('df.paid', 1);
                })->orWhere(function ($q2) {
                    $q2->where('df.abone', 1)->where('df.paid', '!=', 1);
                });
            })
            ->select(
                'df.id',
                'df.number_facture',
                'df.date_facturation',
                'df.price_total',
                'df.price_abone',
                'df.price_discount',
                'df.abone',
                'df.paid',
                'df.created_at',
                'users.id as user_id',
                DB::raw("CONCAT(users.username) as cliente")
            )
            ->orderByDesc('df.created_at');

        if ($from) {
            $query->whereDate('df.created_at', '>=', $from);
        }
        if ($to) {
            $query->whereDate('df.created_at', '<=', $to);
        }

        return $query->get();
    }

    // ── Esquema: el módulo funciona igual antes y después de la migración ───

    /** ¿Ya existen fecha real del gasto, proveedor, comprobante, adjunto…? */
    private function camposNuevos(): bool
    {
        return self::$hayCamposNuevos ??= Schema::hasColumn('egresses', 'expense_date');
    }

    /** ¿El catálogo de categorías ya tiene `active`/`color`? */
    private function catalogoListo(): bool
    {
        return self::$hayCatalogo ??= (Schema::hasTable('category_egresses')
            && Schema::hasColumn('category_egresses', 'active'));
    }

    /**
     * Expresión de "la fecha del egreso": la real si está, si no la de
     * registro. Los egresos viejos sin ninguna de las dos quedan fuera de todo
     * período y salen avisados en el tablero.
     */
    private function fecha(string $alias = 'e'): string
    {
        return $this->camposNuevos()
            ? "COALESCE({$alias}.expense_date, DATE({$alias}.created_at))"
            : "DATE({$alias}.created_at)";
    }

    private function entreFechas($query, ?string $from, ?string $to, string $alias = 'e'): void
    {
        $fecha = $this->fecha($alias);
        if ($from) {
            $query->whereRaw("{$fecha} >= ?", [$from]);
        }
        if ($to) {
            $query->whereRaw("{$fecha} <= ?", [$to]);
        }
    }

    // ── Categorías por empresa ──────────────────────────────────────────────

    /**
     * Catálogo de la empresa en sesión con cuántos movimientos tiene cada
     * categoría (se cruzan por nombre, que es lo que guarda el egreso).
     */
    public function getCategorias(): array
    {
        $companyId = (int) getSessionCompanyId();
        $this->sembrarCategorias($companyId);

        $usos = DB::table('egresses as e')
            ->where('e.company_id', $companyId)
            ->selectRaw('LOWER(TRIM(e.category)) as clave, COUNT(*) as movimientos, COALESCE(SUM(e.value), 0) as total')
            ->groupBy('clave')
            ->get()
            ->keyBy('clave');

        // Los egresos que apuntan a la categoría por id (historial viejo).
        $porId = DB::table('egresses')->where('company_id', $companyId)
            ->selectRaw('id_category_egresses as cid, COUNT(*) as n')
            ->groupBy('cid')->pluck('n', 'cid');

        $columnas = ['id', 'name'];
        if ($this->catalogoListo()) {
            $columnas = array_merge($columnas, ['active', 'color']);
        }

        return DB::table('category_egresses')
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get($columnas)
            ->map(function ($c) use ($usos, $porId) {
                $uso = $usos->get(mb_strtolower(trim((string) $c->name)));

                return [
                    'id'          => (int) $c->id,
                    'name'        => $c->name,
                    'active'      => property_exists($c, 'active') ? (bool) $c->active : true,
                    'color'       => $c->color ?? null,
                    'movimientos' => (int) ($uso->movimientos ?? 0),
                    // Ligada = tiene egresos apuntándola, aunque el texto ya no coincida.
                    'ligada'      => (int) ($porId[$c->id] ?? 0) > 0,
                    'total'       => round((float) ($uso->total ?? 0), 2),
                ];
            })->all();
    }

    /**
     * La empresa que no tiene catálogo propio arranca con lo que ya usa en sus
     * egresos, más la lista base. Funciona también antes de la migración: sólo
     * escribe las columnas que existen.
     */
    private function sembrarCategorias(int $companyId): void
    {
        $existentes = DB::table('category_egresses')->where('company_id', $companyId)
            ->pluck('name')->map(fn ($n) => mb_strtolower(trim((string) $n)))->all();

        $usadas = DB::table('egresses')->where('company_id', $companyId)
            ->whereNotNull('category')->where('category', '!=', '')
            ->distinct()->pluck('category')->all();

        // Ya sembrada: sólo se completa si aparecieron categorías nuevas en los egresos.
        $faltan = $existentes ? $usadas : array_merge($usadas, self::CATEGORIAS_BASE);

        $filas = [];
        foreach ($faltan as $nombre) {
            $nombre = trim((string) $nombre);
            $clave  = mb_strtolower($nombre);
            if ($nombre === '' || in_array($clave, $existentes, true)) {
                continue;
            }
            $existentes[] = $clave;
            $filas[]      = $this->filaCategoria($companyId, $nombre);
        }

        if ($filas) {
            DB::table('category_egresses')->insertOrIgnore($filas);
        }
    }

    /** Fila del catálogo con las columnas que existen hoy en la tabla. */
    private function filaCategoria(int $companyId, string $nombre, ?string $color = null): array
    {
        $fila = ['company_id' => $companyId, 'name' => mb_substr($nombre, 0, 120)];
        if ($this->catalogoListo()) {
            $fila['active']     = 1;
            $fila['color']      = $color;
            $fila['created_at'] = now();
            $fila['updated_at'] = now();
        }

        return $fila;
    }

    public function crearCategoria(string $nombre, ?string $color): array
    {
        $companyId = (int) getSessionCompanyId();
        $nombre    = mb_substr(trim($nombre), 0, 120);
        if ($nombre === '') {
            return ['ok' => false, 'mensaje' => 'El nombre no puede ir vacío.'];
        }
        $this->sembrarCategorias($companyId);

        if ($this->existeNombre($companyId, $nombre)) {
            return ['ok' => false, 'mensaje' => "Ya existe una categoría llamada «{$nombre}»."];
        }

        $id = DB::table('category_egresses')->insertGetId($this->filaCategoria($companyId, $nombre, $color));

        return ['ok' => true, 'mensaje' => 'Categoría creada.', 'id' => $id];
    }

    /**
     * Renombrar arrastra el texto guardado en los egresos de la empresa: si no,
     * los movimientos viejos quedarían huérfanos de la categoría renombrada.
     */
    public function actualizarCategoria(int $id, ?string $nombre, ?string $color): array
    {
        $companyId = (int) getSessionCompanyId();
        $categoria = DB::table('category_egresses')->where('id', $id)->where('company_id', $companyId)->first();
        if (!$categoria) {
            return ['ok' => false, 'mensaje' => 'Categoría no encontrada.'];
        }

        $cambios = [];
        if ($this->catalogoListo()) {
            $cambios['updated_at'] = now();
            if ($color !== null) {
                $cambios['color'] = $color !== '' ? $color : null;
            }
        }

        $nombre = $nombre !== null ? mb_substr(trim($nombre), 0, 120) : null;
        if ($nombre !== null && $nombre !== '' && mb_strtolower($nombre) !== mb_strtolower((string) $categoria->name)) {
            if ($this->existeNombre($companyId, $nombre, $id)) {
                return ['ok' => false, 'mensaje' => "Ya existe una categoría llamada «{$nombre}»."];
            }
            $cambios['name'] = $nombre;
        }

        if (!$cambios) {
            return ['ok' => true, 'mensaje' => 'Sin cambios.'];
        }

        DB::transaction(function () use ($id, $companyId, $cambios, $categoria) {
            DB::table('category_egresses')->where('id', $id)->where('company_id', $companyId)->update($cambios);
            if (isset($cambios['name'])) {
                DB::table('egresses')
                    ->where('company_id', $companyId)
                    ->whereRaw('LOWER(TRIM(category)) = ?', [mb_strtolower(trim((string) $categoria->name))])
                    ->update(['category' => $cambios['name']]);
            }
        });

        return ['ok' => true, 'mensaje' => 'Categoría actualizada.'];
    }

    public function alternarCategoria(int $id): array
    {
        if (!$this->catalogoListo()) {
            return ['ok' => false, 'mensaje' => 'Falta correr la migración de egresos para activar o desactivar categorías.'];
        }

        $companyId = (int) getSessionCompanyId();
        $categoria = DB::table('category_egresses')->where('id', $id)->where('company_id', $companyId)->first();
        if (!$categoria) {
            return ['ok' => false, 'mensaje' => 'Categoría no encontrada.'];
        }

        $activa = !((bool) $categoria->active);
        DB::table('category_egresses')->where('id', $id)->where('company_id', $companyId)
            ->update(['active' => $activa, 'updated_at' => now()]);

        return ['ok' => true, 'mensaje' => $activa ? 'Categoría activada.' : 'Categoría desactivada.', 'active' => $activa];
    }

    /** Sólo se borra la que no tiene ningún movimiento; la usada se desactiva. */
    public function eliminarCategoria(int $id): array
    {
        $companyId = (int) getSessionCompanyId();
        $categoria = DB::table('category_egresses')->where('id', $id)->where('company_id', $companyId)->first();
        if (!$categoria) {
            return ['ok' => false, 'mensaje' => 'Categoría no encontrada.'];
        }

        // Por texto y por id: hay egresos viejos que la apuntan sin que el texto coincida.
        $movimientos = DB::table('egresses')->where('company_id', $companyId)
            ->where(function ($w) use ($categoria, $id) {
                $w->whereRaw('LOWER(TRIM(category)) = ?', [mb_strtolower(trim((string) $categoria->name))])
                  ->orWhere('id_category_egresses', $id);
            })
            ->count();

        if ($movimientos > 0) {
            $extra = $this->catalogoListo()
                ? ' Desactivala para que deje de aparecer al registrar.'
                : '';

            return [
                'ok'      => false,
                'mensaje' => "«{$categoria->name}» tiene {$movimientos} movimiento(s): no se puede borrar sin perder el historial.{$extra}",
            ];
        }

        DB::table('category_egresses')->where('id', $id)->where('company_id', $companyId)->delete();

        return ['ok' => true, 'mensaje' => 'Categoría eliminada.'];
    }

    private function existeNombre(int $companyId, string $nombre, ?int $excepto = null): bool
    {
        $q = DB::table('category_egresses')->where('company_id', $companyId)
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($nombre)]);
        if ($excepto) {
            $q->where('id', '!=', $excepto);
        }

        return $q->exists();
    }

    /**
     * id del catálogo para el texto de la categoría. Nunca puede dar 0: la
     * tabla `egresses` tiene clave foránea contra `category_egresses`, y por
     * eso el módulo viejo (que grababa siempre id 1, inexistente) no dejaba
     * crear ningún egreso. Si la categoría no está en el catálogo, se crea.
     */
    private function asegurarCategoria(int $companyId, ?string $nombre): int
    {
        foreach ([trim((string) $nombre), 'General'] as $candidato) {
            if ($candidato === '') {
                continue;
            }
            if ($id = $this->idPorNombre($companyId, $candidato)) {
                return $id;
            }
            DB::table('category_egresses')->insertOrIgnore([$this->filaCategoria($companyId, $candidato)]);
            if ($id = $this->idPorNombre($companyId, $candidato)) {
                return $id;
            }
        }

        // Último recurso: cualquier categoría que ya tenga la empresa.
        return (int) DB::table('category_egresses')->where('company_id', $companyId)->min('id');
    }

    private function idPorNombre(int $companyId, string $nombre): int
    {
        return (int) DB::table('category_egresses')->where('company_id', $companyId)
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($nombre))])
            ->value('id');
    }

    // ── Movimientos ─────────────────────────────────────────────────────────

    /** Arma la consulta base con todos los filtros de la pantalla. */
    private function consulta(array $f)
    {
        $companyId = (int) getSessionCompanyId();
        $fecha     = $this->fecha();

        $q = DB::table('egresses as e')
            // El método se cruza también por empresa: nunca se muestra el de otra.
            ->leftJoin('payment_methods as pm', function ($j) {
                $j->on('pm.id', '=', 'e.payment_method_id')->on('pm.company_id', '=', 'e.company_id');
            })
            ->where('e.company_id', $companyId);

        if (!empty($f['search'])) {
            $t = '%' . $f['search'] . '%';
            $q->where(function ($w) use ($t) {
                $w->where('e.concept', 'like', $t);
                if ($this->camposNuevos()) {
                    $w->orWhere('e.supplier', 'like', $t)->orWhere('e.document_number', 'like', $t);
                }
            });
        }

        // "Sin fecha" son los egresos viejos que no caen en ningún período.
        if (!empty($f['sin_fecha'])) {
            $q->whereRaw("{$fecha} IS NULL");
        } else {
            $this->entreFechas($q, $f['from'] ?? null, $f['to'] ?? null);
        }

        if (!empty($f['category'])) {
            $q->where('e.category', $f['category']);
        }
        if (!empty($f['payment_method_id'])) {
            $q->where('e.payment_method_id', (int) $f['payment_method_id']);
        }
        if (!empty($f['sin_categoria'])) {
            $q->where(function ($w) {
                $w->whereNull('e.category')->orWhere('e.category', '');
            });
        }
        if (!empty($f['sin_metodo'])) {
            $q->whereNull('e.payment_method_id');
        }
        if (!empty($f['ids']) && is_array($f['ids'])) {
            $q->whereIn('e.id', array_map('intval', $f['ids']));
        }

        return $q;
    }

    private function columnas(): array
    {
        $cols = ['e.id', 'e.concept', 'e.category', 'e.value', 'e.user_id', 'e.payment_method_id', 'e.created_at'];
        if ($this->camposNuevos()) {
            $cols = array_merge($cols, [
                'e.expense_date', 'e.supplier', 'e.document_number', 'e.notes', 'e.recurrence',
                'e.attachment_name', DB::raw('(e.attachment_path IS NOT NULL) as tiene_adjunto'),
            ]);
        }
        $cols[] = DB::raw('pm.name as payment_method_name');
        $cols[] = DB::raw($this->fecha() . ' as fecha');

        return $cols;
    }

    public function getEgresosPaginated(array $filtros): object
    {
        $page    = max(1, (int) ($filtros['page'] ?? 1));
        $perPage = min(200, max(1, (int) ($filtros['per_page'] ?? 15)));

        $base  = $this->consulta($filtros);
        $total = (clone $base)->count();
        $suma  = (float) (clone $base)->sum('e.value');

        $items = (clone $base)
            ->select($this->columnas())
            ->orderByRaw($this->fecha() . ' DESC')
            ->orderByDesc('e.id')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return (object)[
            'items'        => $items,
            'total'        => (int) $total,
            'suma'         => round($suma, 2),
            'per_page'     => $perPage,
            'current_page' => $page,
            'last_page'    => max(1, (int) ceil($total / $perPage)),
        ];
    }

    public function exportEgresos(array $filtros): array
    {
        return $this->consulta($filtros)
            ->select($this->columnas())
            ->orderByRaw($this->fecha() . ' DESC')
            ->orderByDesc('e.id')
            ->limit(50000)
            ->get()->toArray();
    }

    public function buscarEgreso(int $id): ?object
    {
        return DB::table('egresses as e')
            // El método se cruza también por empresa: nunca se muestra el de otra.
            ->leftJoin('payment_methods as pm', function ($j) {
                $j->on('pm.id', '=', 'e.payment_method_id')->on('pm.company_id', '=', 'e.company_id');
            })
            ->where('e.id', $id)->where('e.company_id', getSessionCompanyId())
            ->select($this->columnas())
            ->first();
    }

    /** Ruta del adjunto (relativa a storage/app) sólo si el egreso es de la empresa. */
    public function rutaAdjunto(int $id): ?object
    {
        if (!$this->camposNuevos()) {
            return null;
        }

        return DB::table('egresses')->where('id', $id)->where('company_id', getSessionCompanyId())
            ->whereNotNull('attachment_path')
            ->first(['attachment_path', 'attachment_name']);
    }

    /** Sólo las columnas que existen hoy: así crear/editar no revienta sin migración. */
    private function camposDelRegistro(array $data, bool $creando): array
    {
        $companyId = (int) getSessionCompanyId();
        $campos    = [];

        if ($creando || array_key_exists('concept', $data)) {
            $campos['concept'] = mb_substr(trim((string) ($data['concept'] ?? '')), 0, 200);
        }
        if ($creando || array_key_exists('value', $data)) {
            $campos['value'] = (float) ($data['value'] ?? 0);
        }
        if ($creando || array_key_exists('category', $data)) {
            $categoria = trim((string) ($data['category'] ?? '')) ?: 'General';
            $campos['category']             = $categoria;
            $campos['id_category_egresses'] = $this->asegurarCategoria($companyId, $categoria);
        }
        if ($creando || array_key_exists('payment_method_id', $data)) {
            $campos['payment_method_id'] = $this->metodoDeLaEmpresa($companyId, $data['payment_method_id'] ?? null);
        }

        if ($this->camposNuevos()) {
            foreach (self::CAMPOS_NUEVOS as $c) {
                if (!array_key_exists($c, $data)) {
                    continue;
                }
                $valor = $data[$c];
                if (is_string($valor)) {
                    $valor = trim($valor);
                }
                $campos[$c] = ($valor === '' || $valor === null) ? null : $valor;
            }
            if ($creando && empty($campos['expense_date'])) {
                $campos['expense_date'] = now()->toDateString();
            }
            if (array_key_exists('recurrence', $campos) && !in_array($campos['recurrence'], ['mensual', 'quincenal', null], true)) {
                $campos['recurrence'] = null;
            }
        }

        return $campos;
    }

    /** Un método de pago de otra empresa no se guarda nunca. */
    private function metodoDeLaEmpresa(int $companyId, $id): ?int
    {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        $existe = DB::table('payment_methods')->where('id', $id)->where('company_id', $companyId)->exists();

        return $existe ? $id : null;
    }

    public function createEgresoV2(array $data): mixed
    {
        $campos = $this->camposDelRegistro($data, true);
        $campos['company_id'] = getSessionCompanyId();
        $campos['user_id']    = $this->usuarioValido($data['user_id'] ?? getSessionUserId());

        return Egresses::create($campos);
    }

    /**
     * `egresses.user_id` tiene clave foránea contra `user_data.user_id`, que
     * sólo tiene clientes: si quien registra no está ahí, va NULL en vez de
     * romper el alta (es lo mismo que traen los 436 egresos que ya existen).
     */
    private function usuarioValido($id): ?int
    {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }

        return DB::table('user_data')->where('user_id', $id)->exists() ? $id : null;
    }

    public function updateEgreso(int $id, array $data): bool
    {
        $egreso = Egresses::where('id', $id)->where('company_id', getSessionCompanyId())->first();
        if (!$egreso) {
            return false;
        }
        $egreso->update($this->camposDelRegistro($data, false));

        return true;
    }

    public function deleteEgreso(int $id): bool
    {
        return (bool) Egresses::where('id', $id)->where('company_id', getSessionCompanyId())->delete();
    }

    /**
     * Crea el siguiente egreso de uno recurrente. Nunca se dispara solo: lo
     * pide el usuario desde el panel de recurrentes.
     */
    public function repetirEgreso(int $id): array
    {
        if (!$this->camposNuevos()) {
            return ['ok' => false, 'mensaje' => 'Falta correr la migración de egresos para usar recurrentes.'];
        }

        $egreso = Egresses::where('id', $id)->where('company_id', getSessionCompanyId())->first();
        if (!$egreso) {
            return ['ok' => false, 'mensaje' => 'Egreso no encontrado.'];
        }

        $base = $egreso->expense_date
            ?: ($egreso->created_at ? Carbon::parse($egreso->created_at)->toDateString() : now()->toDateString());

        $siguiente = $egreso->recurrence === 'quincenal'
            ? Carbon::parse($base)->addDays(15)
            : Carbon::parse($base)->addMonthNoOverflow();

        $nuevo = $this->createEgresoV2([
            'concept'           => $egreso->concept,
            'category'          => $egreso->category,
            'value'             => $egreso->value,
            'payment_method_id' => $egreso->payment_method_id,
            'supplier'          => $egreso->supplier,
            'notes'             => $egreso->notes,
            'recurrence'        => $egreso->recurrence,
            'expense_date'      => $siguiente->toDateString(),
            // El comprobante y el número de factura son del gasto anterior.
            'document_number'   => null,
        ]);

        return [
            'ok'      => true,
            'mensaje' => 'Se creó el siguiente egreso con fecha ' . $siguiente->format('d/m/Y') . '.',
            'id'      => $nuevo->id,
        ];
    }

    // ── Tablero ─────────────────────────────────────────────────────────────

    /**
     * Todo lo que se ve arriba de la pantalla: totales, comparaciones contra el
     * período anterior y el año pasado, peso por categoría, evolución mensual,
     * los movimientos más grandes, el contraste con ingresos y los avisos.
     */
    public function getTablero(array $f): array
    {
        $companyId = (int) getSessionCompanyId();
        $desde     = $f['from'] ?: now()->startOfMonth()->toDateString();
        $hasta     = $f['to']   ?: now()->endOfMonth()->toDateString();

        $dIni = Carbon::parse($desde)->startOfDay();
        $dFin = Carbon::parse($hasta)->startOfDay();
        if ($dFin->lt($dIni)) {
            [$dIni, $dFin]   = [$dFin, $dIni];
            [$desde, $hasta] = [$hasta, $desde];
        }
        $dias = $dIni->diffInDays($dFin) + 1;

        // Período anterior de igual largo, pegado al actual, y el mismo del año pasado.
        $antFin = $dIni->copy()->subDay();
        $antIni = $antFin->copy()->subDays($dias - 1);
        $ayIni  = $dIni->copy()->subYear();
        $ayFin  = $dFin->copy()->subYear();

        $actual   = $this->totalDelRango($f, $desde, $hasta);
        $anterior = $this->totalDelRango($f, $antIni->toDateString(), $antFin->toDateString());
        $anioPas  = $this->totalDelRango($f, $ayIni->toDateString(), $ayFin->toDateString());

        $total = (float) $actual->total;

        $porCategoria = $this->porCategoria($f, $desde, $hasta, $antIni->toDateString(), $antFin->toDateString(), $total);
        $mensual      = $this->mensual($f, $dFin);
        $sinFecha     = $this->sinFecha($companyId);

        return [
            'periodo'     => ['desde' => $desde, 'hasta' => $hasta, 'dias' => $dias],
            'total'       => round($total, 2),
            'movimientos' => (int) $actual->movimientos,
            'promedio'    => $actual->movimientos ? round($total / (int) $actual->movimientos, 2) : 0,
            'por_dia'     => $dias ? round($total / $dias, 2) : 0,
            'anterior' => [
                'desde'     => $antIni->toDateString(),
                'hasta'     => $antFin->toDateString(),
                'total'     => round((float) $anterior->total, 2),
                'variacion' => $this->variacion($total, (float) $anterior->total),
            ],
            'anio_pasado' => [
                'desde'     => $ayIni->toDateString(),
                'hasta'     => $ayFin->toDateString(),
                'total'     => round((float) $anioPas->total, 2),
                'variacion' => $this->variacion($total, (float) $anioPas->total),
            ],
            'por_categoria'        => $porCategoria,
            'por_metodo'           => $this->porMetodo($f, $desde, $hasta),
            'mensual'              => $mensual,
            'mayores'              => $this->mayores($f, $desde, $hasta),
            'recurrentes'          => $this->recurrentes($companyId),
            'contra_ingresos'      => $this->contraIngresos($companyId, $desde, $hasta, $total),
            'sin_fecha'            => $sinFecha,
            'ultimo_mes_con_datos' => $this->ultimoMesConDatos($companyId),
            'alertas'              => $this->alertas($f, $desde, $hasta, $dias, $porCategoria, $total, $sinFecha, $mensual),
            'migracion_pendiente'  => !$this->camposNuevos(),
        ];
    }

    /** Variación porcentual honesta: sin base anterior no hay porcentaje. */
    private function variacion(float $actual, float $anterior): ?float
    {
        if ($anterior <= 0) {
            return null;
        }

        return round((($actual - $anterior) / $anterior) * 100, 1);
    }

    private function totalDelRango(array $f, string $desde, string $hasta): object
    {
        return $this->consulta(array_merge($f, ['from' => $desde, 'to' => $hasta, 'sin_fecha' => false]))
            ->selectRaw('COALESCE(SUM(e.value), 0) as total, COUNT(*) as movimientos')
            ->first();
    }

    private function porCategoria(array $f, string $desde, string $hasta, string $antDesde, string $antHasta, float $total): array
    {
        $etiqueta = "COALESCE(NULLIF(TRIM(e.category), ''), 'Sin categoría')";

        $ahora = $this->consulta(array_merge($f, ['from' => $desde, 'to' => $hasta, 'sin_fecha' => false]))
            ->selectRaw("{$etiqueta} as categoria, COALESCE(SUM(e.value), 0) as total, COUNT(*) as movimientos")
            ->groupBy('categoria')->orderByDesc('total')->get();

        $antes = $this->consulta(array_merge($f, ['from' => $antDesde, 'to' => $antHasta, 'sin_fecha' => false]))
            ->selectRaw("{$etiqueta} as categoria, COALESCE(SUM(e.value), 0) as total")
            ->groupBy('categoria')->pluck('total', 'categoria');

        return $ahora->map(function ($c) use ($antes, $total) {
            $ant = (float) ($antes[$c->categoria] ?? 0);

            return [
                'categoria'   => $c->categoria,
                'total'       => round((float) $c->total, 2),
                'movimientos' => (int) $c->movimientos,
                'peso'        => $total > 0 ? round((float) $c->total * 100 / $total, 1) : 0,
                'anterior'    => round($ant, 2),
                'variacion'   => $this->variacion((float) $c->total, $ant),
            ];
        })->all();
    }

    private function porMetodo(array $f, string $desde, string $hasta): array
    {
        return $this->consulta(array_merge($f, ['from' => $desde, 'to' => $hasta, 'sin_fecha' => false]))
            ->selectRaw("COALESCE(pm.name, 'Sin método') as metodo, COALESCE(SUM(e.value), 0) as total, COUNT(*) as movimientos")
            ->groupBy('metodo')->orderByDesc('total')->get()
            ->map(fn ($m) => [
                'metodo'      => $m->metodo,
                'total'       => round((float) $m->total, 2),
                'movimientos' => (int) $m->movimientos,
            ])->all();
    }

    /** Últimos 12 meses terminando en el mes del "hasta", con los meses vacíos en 0. */
    private function mensual(array $f, Carbon $fin): array
    {
        $desde = $fin->copy()->startOfMonth()->subMonths(11);
        $hasta = $fin->copy()->endOfMonth();
        $fecha = $this->fecha();

        $filas = $this->consulta(array_merge($f, [
            'from' => $desde->toDateString(), 'to' => $hasta->toDateString(), 'sin_fecha' => false,
        ]))
            ->selectRaw("DATE_FORMAT({$fecha}, '%Y-%m') as mes, COALESCE(SUM(e.value), 0) as total, COUNT(*) as movimientos")
            ->groupBy('mes')->get()->keyBy('mes');

        $meses = [];
        for ($i = 0; $i < 12; $i++) {
            $m = $desde->copy()->addMonths($i);
            $k = $m->format('Y-m');
            $meses[] = [
                'mes'         => $k,
                'etiqueta'    => $this->mesCorto($m),
                'total'       => round((float) ($filas[$k]->total ?? 0), 2),
                'movimientos' => (int) ($filas[$k]->movimientos ?? 0),
            ];
        }

        return $meses;
    }

    private function mesCorto(Carbon $m): string
    {
        $meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

        return $meses[$m->month - 1] . ' ' . $m->format('y');
    }

    private function mayores(array $f, string $desde, string $hasta): array
    {
        return $this->consulta(array_merge($f, ['from' => $desde, 'to' => $hasta, 'sin_fecha' => false]))
            ->select($this->columnas())
            ->orderByDesc('e.value')->limit(6)->get()->toArray();
    }

    /**
     * Egresos marcados como recurrentes: el último de cada concepto y cuándo
     * tocaría el siguiente. No se crea nada solo; se ofrece el botón.
     */
    private function recurrentes(int $companyId): array
    {
        if (!$this->camposNuevos()) {
            return [];
        }

        $fecha = $this->fecha();
        $filas = DB::table('egresses as e')
            ->where('e.company_id', $companyId)
            ->whereIn('e.recurrence', ['mensual', 'quincenal'])
            ->selectRaw("e.id, e.concept, e.category, e.value, e.recurrence, e.supplier, {$fecha} as fecha")
            ->orderByRaw("{$fecha} DESC")
            ->limit(200)->get();

        $ultimos = [];
        foreach ($filas as $r) {
            $clave = mb_strtolower(trim((string) $r->concept)) . '|' . $r->recurrence;
            if (isset($ultimos[$clave]) || !$r->fecha) {
                continue;
            }
            $siguiente = $r->recurrence === 'quincenal'
                ? Carbon::parse($r->fecha)->addDays(15)
                : Carbon::parse($r->fecha)->addMonthNoOverflow();

            $ultimos[$clave] = [
                'id'         => (int) $r->id,
                'concept'    => $r->concept,
                'category'   => $r->category,
                'supplier'   => $r->supplier,
                'value'      => round((float) $r->value, 2),
                'recurrence' => $r->recurrence,
                'ultima'     => $r->fecha,
                'siguiente'  => $siguiente->toDateString(),
                'vencida'    => $siguiente->lte(now()->startOfDay()),
            ];
        }

        $lista = array_values($ultimos);
        usort($lista, fn ($a, $b) => strcmp($a['siguiente'], $b['siguiente']));

        return array_slice($lista, 0, 12);
    }

    /**
     * Contraste con ingresos usando exactamente la misma fuente del Resumen
     * Financiero (getPriceEgresseByRange), para que los dos nunca discrepen.
     */
    private function contraIngresos(int $companyId, string $desde, string $hasta, float $totalFiltrado): array
    {
        $r = $this->getPriceEgresseByRange($desde, $hasta);

        $ingresos = (float) $r->total_sum;
        $gastos   = (float) $r->total_gastos;

        $activos = DB::table('user_data as ud')
            ->join('users as u', 'u.id', '=', 'ud.user_id')
            ->where('u.company_id', $companyId)
            ->where('ud.active', 1)
            ->count();

        return [
            'ingresos'            => round($ingresos, 2),
            'egresos_modulo'      => round((float) $r->total_egresses, 2),
            'compras_inventario'  => round((float) $r->total_inventory_egresses, 2),
            'gastos_totales'      => round($gastos, 2),
            'margen'              => round($ingresos - $gastos, 2),
            'peso_sobre_ingresos' => $ingresos > 0 ? round($gastos * 100 / $ingresos, 1) : null,
            'clientes_activos'    => (int) $activos,
            'egreso_por_cliente'  => $activos > 0 ? round($gastos / $activos, 2) : null,
            // El total del tablero difiere de éste si hay filtro de categoría o método.
            'total_filtrado'      => round($totalFiltrado, 2),
        ];
    }

    private function sinFecha(int $companyId): int
    {
        $fecha = $this->fecha();

        return (int) DB::table('egresses as e')->where('e.company_id', $companyId)
            ->whereRaw("{$fecha} IS NULL")->count();
    }

    private function ultimoMesConDatos(int $companyId): ?string
    {
        $fecha = $this->fecha();
        $max   = DB::table('egresses as e')->where('e.company_id', $companyId)
            ->whereRaw("{$fecha} IS NOT NULL")
            ->selectRaw("MAX({$fecha}) as f")->value('f');

        return $max ? Carbon::parse($max)->format('Y-m') : null;
    }

    // ── Avisos ──────────────────────────────────────────────────────────────

    /**
     * Sólo avisos que los datos sostienen. Cada uno dice por qué salió y trae
     * el filtro con el que la lista muestra exactamente esos movimientos.
     */
    private function alertas(array $f, string $desde, string $hasta, int $dias, array $porCategoria, float $total, int $sinFecha, array $mensual): array
    {
        $avisos = array_merge(
            $this->avisoCategoriasDisparadas($f, $desde, $dias, $porCategoria, $total),
            $this->avisoDuplicados($f, $desde, $hasta),
        );

        $sinCat = $this->consulta(array_merge($f, ['from' => $desde, 'to' => $hasta, 'sin_fecha' => false, 'sin_categoria' => true]))
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(e.value), 0) as v')->first();
        if ((int) $sinCat->n > 0) {
            $avisos[] = [
                'tipo'      => 'sin_categoria',
                'severidad' => 'media',
                'titulo'    => (int) $sinCat->n . ' egreso(s) sin categoría',
                'detalle'   => 'Suman ' . $this->pesos((float) $sinCat->v) . ' en el período y no entran en el reparto por categoría, así que el análisis del gasto queda incompleto.',
                'filtro'    => ['sin_categoria' => 1],
            ];
        }

        $sinMet = $this->consulta(array_merge($f, ['from' => $desde, 'to' => $hasta, 'sin_fecha' => false, 'sin_metodo' => true]))
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(e.value), 0) as v')->first();
        if ((int) $sinMet->n > 0) {
            $avisos[] = [
                'tipo'      => 'sin_metodo',
                'severidad' => 'baja',
                'titulo'    => (int) $sinMet->n . ' egreso(s) sin método de pago',
                'detalle'   => 'Suman ' . $this->pesos((float) $sinMet->v) . '. Sin el método no se puede cuadrar la salida contra la cuenta o la caja de donde salió la plata.',
                'filtro'    => ['sin_metodo' => 1],
            ];
        }

        if ($sinFecha > 0) {
            $avisos[] = [
                'tipo'      => 'sin_fecha',
                'severidad' => 'alta',
                'titulo'    => $sinFecha . ' egreso(s) sin fecha',
                'detalle'   => 'Se registraron sin fecha del gasto y sin fecha de creación, así que no caen en ningún período ni en ninguna comparación. Abrilos y ponéles la fecha real.',
                'filtro'    => ['sin_fecha' => 1],
            ];
        }

        $avisos = array_merge($avisos, $this->avisoMesesVacios($mensual));

        return $avisos;
    }

    private function avisoMesesVacios(array $mensual): array
    {
        $conDatos = array_values(array_filter($mensual, fn ($m) => $m['movimientos'] > 0));
        if (count($conDatos) < 2) {
            return [];
        }

        $primero = $conDatos[0];
        $ultimo  = $conDatos[count($conDatos) - 1];
        $vacios  = array_values(array_filter(
            $mensual,
            fn ($m) => $m['movimientos'] === 0 && $m['mes'] > $primero['mes'] && $m['mes'] < $ultimo['mes']
        ));

        if (!$vacios) {
            return [];
        }

        $etiquetas = implode(', ', array_map(fn ($m) => $m['etiqueta'], array_slice($vacios, 0, 6)));

        return [[
            'tipo'      => 'meses_vacios',
            'severidad' => 'media',
            'titulo'    => count($vacios) . ' mes(es) sin ningún egreso',
            'detalle'   => "Entre {$primero['etiqueta']} y {$ultimo['etiqueta']} no hay nada cargado en: {$etiquetas}. O no se registró el gasto de esos meses, o quedó con otra fecha.",
            'filtro'    => ['mes' => $vacios[0]['mes']],
        ]];
    }

    private function avisoCategoriasDisparadas(array $f, string $desde, int $dias, array $porCategoria, float $total): array
    {
        if (!$porCategoria || $total <= 0) {
            return [];
        }

        // Promedio por categoría de los 3 períodos de igual largo anteriores.
        $fin      = Carbon::parse($desde)->subDay();
        $ini      = $fin->copy()->subDays($dias * 3 - 1);
        $etiqueta = "COALESCE(NULLIF(TRIM(e.category), ''), 'Sin categoría')";

        $previos = $this->consulta(array_merge($f, [
            'from' => $ini->toDateString(), 'to' => $fin->toDateString(), 'sin_fecha' => false,
        ]))
            ->selectRaw("{$etiqueta} as categoria, COALESCE(SUM(e.value), 0) as total")
            ->groupBy('categoria')->pluck('total', 'categoria');

        $avisos = [];
        foreach ($porCategoria as $c) {
            $promedio = ((float) ($previos[$c['categoria']] ?? 0)) / 3;
            // Ruido fuera: sin historial con qué comparar, o si pesa menos del 5% del período.
            if ($promedio <= 0 || $c['peso'] < 5) {
                continue;
            }
            $subida = ($c['total'] - $promedio) / $promedio * 100;
            if ($subida < 40) {
                continue;
            }

            $esSinCategoria = $c['categoria'] === 'Sin categoría';
            $avisos[] = [
                'tipo'      => 'categoria_disparada',
                'severidad' => $subida >= 100 ? 'alta' : 'media',
                'titulo'    => $c['categoria'] . ' subió ' . round($subida) . '%',
                'detalle'   => 'Gastaste ' . $this->pesos($c['total']) . ' en ' . $c['categoria']
                    . ', contra un promedio de ' . $this->pesos($promedio)
                    . ' en los 3 períodos anteriores de igual duración (' . $ini->format('d/m/Y') . ' a ' . $fin->format('d/m/Y') . ').',
                'filtro'    => $esSinCategoria ? ['sin_categoria' => 1] : ['category' => $c['categoria']],
            ];

            if (count($avisos) >= 3) {
                break;
            }
        }

        return $avisos;
    }

    /** Mismo valor y mismo concepto con 3 días o menos entre uno y otro. */
    private function avisoDuplicados(array $f, string $desde, string $hasta): array
    {
        $fecha = $this->fecha();

        $grupos = $this->consulta(array_merge($f, ['from' => $desde, 'to' => $hasta, 'sin_fecha' => false]))
            ->selectRaw("LOWER(TRIM(e.concept)) as clave, e.value, COUNT(*) as n,
                         MIN({$fecha}) as primera, MAX({$fecha}) as ultima,
                         GROUP_CONCAT(e.id) as ids, MIN(e.concept) as concepto")
            ->groupBy('clave', 'e.value')
            ->havingRaw('COUNT(*) > 1')
            ->havingRaw("DATEDIFF(MAX({$fecha}), MIN({$fecha})) <= 3")
            ->orderByDesc('e.value')
            ->limit(3)->get();

        $avisos = [];
        foreach ($grupos as $g) {
            $avisos[] = [
                'tipo'      => 'duplicado',
                'severidad' => 'alta',
                'titulo'    => 'Posible gasto duplicado: ' . $g->concepto,
                'detalle'   => (int) $g->n . ' egresos con el mismo concepto y el mismo valor ('
                    . $this->pesos((float) $g->value) . ') entre el '
                    . Carbon::parse($g->primera)->format('d/m/Y') . ' y el ' . Carbon::parse($g->ultima)->format('d/m/Y')
                    . '. Puede ser un pago cargado dos veces.',
                'filtro'    => ['ids' => array_map('intval', explode(',', (string) $g->ids))],
            ];
        }

        return $avisos;
    }

    private function pesos(float $v): string
    {
        return '$ ' . number_format($v, 0, ',', '.');
    }
}
