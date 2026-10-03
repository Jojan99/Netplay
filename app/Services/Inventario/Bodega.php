<?php

namespace App\Services\Inventario;

use App\Support\Serial;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Todo lo que mueve existencias pasa por aquí.
 *
 * Antes había dos lugares que restaban del inventario (el movimiento del panel y la
 * instalación de una ONT), cada uno con sus reglas, y los dos leían la cantidad, hacían la
 * cuenta y escribían el resultado: dos salidas a la vez se pisaban. Ahora hay uno solo, y
 * cada operación toma el renglón con candado dentro de una transacción.
 *
 * Tres ideas:
 *
 *  - «quantity» es lo que hay EN BODEGA. Lo que se le entrega a un técnico sale de la bodega
 *    pero no se ha gastado: queda a su nombre hasta que lo instale o lo devuelva.
 *  - Un equipo con serial es una fila en inventory_units: se sabe dónde está y desde cuándo.
 *  - El material sin serial (cable, conectores) se lleva por cantidad en inventory_custodias.
 *
 * Los errores de negocio («no hay tantos», «ese serial ya está») salen como \DomainException
 * con un texto para mostrarle al operador.
 */
class Bodega
{
    public const ENTRADA    = 'entrada';
    public const SALIDA     = 'salida';
    public const AJUSTE     = 'ajuste';
    public const ENTREGA    = 'entrega';
    public const DEVOLUCION = 'devolucion';
    /** El técnico gasta lo que ya tenía: no sale otra vez de la bodega. */
    public const CONSUMO    = 'consumo';

    public const TIPOS = [self::ENTRADA, self::SALIDA, self::AJUSTE, self::ENTREGA, self::DEVOLUCION, self::CONSUMO];

    public function __construct(private int $companyId) {}

    // ── Lectura ──────────────────────────────────────────────────────────────

    private function item(int $inventoryId, bool $candado = false): object
    {
        $q = DB::table('inventories')->where('company_id', $this->companyId)->where('id', $inventoryId)->whereNull('deleted_at');
        $item = ($candado ? $q->lockForUpdate() : $q)->first();

        if (!$item) {
            throw new \DomainException('Ese ítem no existe en el inventario.');
        }

        return $item;
    }

    private function tecnico(int $tecnicoId): object
    {
        $t = DB::table('users as u')->join('user_data as d', 'd.user_id', '=', 'u.id')
            ->where('u.company_id', $this->companyId)->where('u.id', $tecnicoId)
            ->first(['u.id', 'd.names', 'd.lastname']);

        if (!$t) {
            throw new \DomainException('Ese técnico no es de la empresa.');
        }

        return $t;
    }

    /**
     * Qué es un código leído: el serial de un equipo, o el código de un producto.
     *
     * @return array{tipo:'unidad'|'item'|'nada', unidad?:array<string,mixed>, item?:array<string,mixed>, serial?:string, es_mac?:bool}
     */
    public function buscar(string $codigo): array
    {
        $crudo = trim($codigo);
        $canonico = Serial::canonico($crudo);

        if ($canonico !== '') {
            $u = DB::table('inventory_units as n')->join('inventories as i', 'i.id', '=', 'n.inventory_id')
                ->leftJoin('user_data as t', 't.user_id', '=', 'n.tecnico_id')
                ->where('n.company_id', $this->companyId)->where('n.serial_canonico', $canonico)
                ->first(['n.*', 'i.name as item', DB::raw("TRIM(CONCAT(COALESCE(t.names,''), ' ', COALESCE(t.lastname,''))) as tecnico")]);

            if ($u) {
                return ['tipo' => 'unidad', 'unidad' => (array) $u, 'serial' => $u->serial];
            }
        }

        $i = DB::table('inventories')->where('company_id', $this->companyId)->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('barcode', $crudo)->orWhere('sku', $crudo)->orWhere('code', $crudo))
            ->first(['id', 'name', 'sku', 'code', 'barcode', 'quantity', 'unit', 'usa_serial', 'unit_price']);

        if ($i) {
            return ['tipo' => 'item', 'item' => (array) $i];
        }

        return ['tipo' => 'nada', 'serial' => Serial::limpio($crudo), 'es_mac' => Serial::esMac($crudo)];
    }

    // ── Movimientos de bodega ────────────────────────────────────────────────

    /**
     * Entra mercancía. Con seriales, la cantidad es la de seriales y cada uno queda como
     * un equipo en bodega.
     *
     * @param  list<string> $seriales
     * @return array{movimiento_id:int, cantidad:float, en_bodega:float, unidades:int}
     */
    public function entrada(int $inventoryId, float $cantidad, float $precio = 0, array $seriales = [], ?string $referencia = null, ?string $descripcion = null, ?int $usuarioId = null): array
    {
        $seriales = $this->serialesLimpios($seriales);

        if ($seriales) {
            $cantidad = (float) count($seriales);
        }
        if ($cantidad <= 0) {
            throw new \DomainException('La cantidad que entra tiene que ser mayor que cero.');
        }

        return DB::transaction(function () use ($inventoryId, $cantidad, $precio, $seriales, $referencia, $descripcion, $usuarioId) {
            $item = $this->item($inventoryId, true);

            if ($seriales) {
                $ya = DB::table('inventory_units')->where('company_id', $this->companyId)
                    ->whereIn('serial_canonico', array_map([Serial::class, 'canonico'], $seriales))->pluck('serial')->all();

                if ($ya) {
                    throw new \DomainException('Ya están en el inventario: ' . implode(', ', array_slice($ya, 0, 6)) . (count($ya) > 6 ? '…' : '') . '.');
                }
            } elseif ((int) $item->usa_serial) {
                throw new \DomainException("«{$item->name}» se lleva por serial: registre cada equipo con el suyo.");
            }

            $antes  = (float) $item->quantity;
            $queda  = $antes + $cantidad;
            $costo  = (float) $item->average_cost;
            // Costo promedio ponderado: lo que había a su costo más lo que entra al suyo.
            $nuevoCosto = $queda > 0 ? round((($antes * $costo) + ($cantidad * $precio)) / $queda, 2) : $precio;

            DB::table('inventories')->where('id', $item->id)->update([
                'quantity' => $queda, 'average_cost' => $nuevoCosto, 'usa_serial' => $seriales ? 1 : $item->usa_serial,
                // Volvió a haber: el próximo faltante se avisa de nuevo.
                'aviso_bajo_en' => $queda > (float) $item->stock_min ? null : $item->aviso_bajo_en,
                'updated_at' => now(),
            ]);

            $movimiento = $this->anotar($item->id, self::ENTRADA, $cantidad, $queda, [
                'unit_price' => $precio, 'cost_before' => $costo, 'cost_after' => $nuevoCosto,
                'description' => $descripcion, 'reference' => $referencia, 'user_id' => $usuarioId,
                'serial_number' => count($seriales) === 1 ? $seriales[0] : null,
            ]);

            foreach ($seriales as $s) {
                DB::table('inventory_units')->insert([
                    'company_id' => $this->companyId, 'inventory_id' => $item->id, 'serial' => $s, 'serial_canonico' => Serial::canonico($s),
                    'estado' => 'bodega', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return ['movimiento_id' => $movimiento, 'cantidad' => $cantidad, 'en_bodega' => $queda, 'unidades' => count($seriales)];
        });
    }

    /**
     * Sale de bodega y se gasta (venta, pérdida, uso en la oficina). Para entregarle a un
     * técnico está entregar(); para lo que se instala, instalar().
     */
    public function salida(int $inventoryId, float $cantidad, ?string $referencia = null, ?string $descripcion = null, ?int $usuarioId = null, array $seriales = [], string $estadoDeLaUnidad = 'baja'): array
    {
        $seriales = $this->serialesLimpios($seriales);

        if ($seriales) {
            $cantidad = (float) count($seriales);
        }
        if ($cantidad <= 0) {
            throw new \DomainException('La cantidad que sale tiene que ser mayor que cero.');
        }

        return DB::transaction(function () use ($inventoryId, $cantidad, $referencia, $descripcion, $usuarioId, $seriales, $estadoDeLaUnidad) {
            $item = $this->item($inventoryId, true);

            if ((int) $item->usa_serial && !$seriales) {
                throw new \DomainException("«{$item->name}» se lleva por serial: indique qué equipos salen.");
            }

            $unidades = $seriales ? $this->unidadesEn($item->id, $seriales, 'bodega') : collect();
            $queda = (float) $item->quantity - $cantidad;

            if ($queda < 0) {
                throw new \DomainException('No hay tantos en bodega: quedan ' . $this->n($item->quantity) . " y salen " . $this->n($cantidad) . '.');
            }

            DB::table('inventories')->where('id', $item->id)->update(['quantity' => $queda, 'updated_at' => now()]);

            if ($unidades->isNotEmpty()) {
                DB::table('inventory_units')->whereIn('id', $unidades->pluck('id'))->update(['estado' => $estadoDeLaUnidad, 'nota' => $descripcion ? mb_substr($descripcion, 0, 255) : null, 'updated_at' => now()]);
            }

            $movimiento = $this->anotar($item->id, self::SALIDA, $cantidad, $queda, [
                'unit_price' => $item->unit_price, 'cost_before' => $item->average_cost, 'cost_after' => $item->average_cost,
                'description' => $descripcion, 'reference' => $referencia, 'user_id' => $usuarioId,
                'serial_number' => count($seriales) === 1 ? $seriales[0] : null,
            ]);

            $this->avisarSiQuedoBajo($item, $queda);

            return ['movimiento_id' => $movimiento, 'cantidad' => $cantidad, 'en_bodega' => $queda];
        });
    }

    /** Corrige lo que hay en bodega al valor contado. Sólo para ítems sin serial. */
    public function ajuste(int $inventoryId, float $contado, ?string $descripcion = null, ?int $usuarioId = null): array
    {
        if ($contado < 0) {
            throw new \DomainException('La cantidad contada no puede ser negativa.');
        }

        return DB::transaction(function () use ($inventoryId, $contado, $descripcion, $usuarioId) {
            $item = $this->item($inventoryId, true);

            if ((int) $item->usa_serial) {
                throw new \DomainException("«{$item->name}» se lleva por serial: en vez de ajustar la cantidad, dé de baja o registre los equipos.");
            }

            DB::table('inventories')->where('id', $item->id)->update([
                'quantity' => $contado, 'aviso_bajo_en' => $contado > (float) $item->stock_min ? null : $item->aviso_bajo_en, 'updated_at' => now(),
            ]);

            // La cantidad del movimiento es la diferencia; el saldo dice a cuánto quedó.
            $movimiento = $this->anotar($item->id, self::AJUSTE, abs($contado - (float) $item->quantity), $contado, [
                'unit_price' => $item->unit_price, 'cost_before' => $item->average_cost, 'cost_after' => $item->average_cost,
                'description' => $descripcion ?: 'Ajuste por conteo: había ' . $this->n($item->quantity) . ' y se contaron ' . $this->n($contado), 'user_id' => $usuarioId,
            ]);

            $this->avisarSiQuedoBajo($item, $contado);

            return ['movimiento_id' => $movimiento, 'antes' => (float) $item->quantity, 'en_bodega' => $contado];
        });
    }

    // ── Técnicos ─────────────────────────────────────────────────────────────

    /**
     * Le entrega material a un técnico: sale de bodega y queda a su nombre, con la fecha.
     *
     * @param list<string> $seriales
     */
    public function entregar(int $tecnicoId, int $inventoryId, float $cantidad, array $seriales = [], ?string $nota = null, ?int $usuarioId = null): array
    {
        $seriales = $this->serialesLimpios($seriales);

        if ($seriales) {
            $cantidad = (float) count($seriales);
        }
        if ($cantidad <= 0) {
            throw new \DomainException('La cantidad que se entrega tiene que ser mayor que cero.');
        }

        return DB::transaction(function () use ($tecnicoId, $inventoryId, $cantidad, $seriales, $nota, $usuarioId) {
            $tecnico = $this->tecnico($tecnicoId);
            $item    = $this->item($inventoryId, true);

            if ((int) $item->usa_serial && !$seriales) {
                throw new \DomainException("«{$item->name}» se lleva por serial: escanee los equipos que se lleva.");
            }

            $unidades = $seriales ? $this->unidadesEn($item->id, $seriales, 'bodega') : collect();
            $queda = (float) $item->quantity - $cantidad;

            if ($queda < 0) {
                throw new \DomainException('No hay tantos en bodega: quedan ' . $this->n($item->quantity) . ' y se entregan ' . $this->n($cantidad) . '.');
            }

            DB::table('inventories')->where('id', $item->id)->update(['quantity' => $queda, 'updated_at' => now()]);

            if ($unidades->isNotEmpty()) {
                DB::table('inventory_units')->whereIn('id', $unidades->pluck('id'))->update(['estado' => 'tecnico', 'tecnico_id' => $tecnicoId, 'entregada_en' => now(), 'updated_at' => now()]);
            } else {
                $c = DB::table('inventory_custodias')->where('company_id', $this->companyId)->where('inventory_id', $item->id)->where('tecnico_id', $tecnicoId)->lockForUpdate()->first();

                if ($c) {
                    // «Desde» sólo se reinicia si no tenía nada: si ya tenía, sigue contando desde lo más viejo.
                    DB::table('inventory_custodias')->where('id', $c->id)->update(['cantidad' => (float) $c->cantidad + $cantidad, 'desde' => (float) $c->cantidad > 0 ? $c->desde : now(), 'updated_at' => now()]);
                } else {
                    DB::table('inventory_custodias')->insert(['company_id' => $this->companyId, 'inventory_id' => $item->id, 'tecnico_id' => $tecnicoId, 'cantidad' => $cantidad, 'desde' => now(), 'created_at' => now(), 'updated_at' => now()]);
                }
            }

            $movimiento = $this->anotar($item->id, self::ENTREGA, $cantidad, $queda, [
                'unit_price' => $item->unit_price, 'cost_before' => $item->average_cost, 'cost_after' => $item->average_cost,
                'description' => 'Entregado a ' . trim($tecnico->names . ' ' . $tecnico->lastname) . ($nota ? ' · ' . $nota : ''),
                'user_id' => $usuarioId, 'tecnico_id' => $tecnicoId, 'serial_number' => count($seriales) === 1 ? $seriales[0] : null,
            ]);

            $this->avisarSiQuedoBajo($item, $queda);

            return ['movimiento_id' => $movimiento, 'cantidad' => $cantidad, 'en_bodega' => $queda, 'tecnico' => trim($tecnico->names . ' ' . $tecnico->lastname)];
        });
    }

    /**
     * El técnico devuelve material: vuelve a bodega, o queda como dañado si no sirve.
     *
     * @param list<string> $seriales
     */
    public function devolver(int $tecnicoId, int $inventoryId, float $cantidad, array $seriales = [], bool $danado = false, ?string $nota = null, ?int $usuarioId = null): array
    {
        $seriales = $this->serialesLimpios($seriales);

        if ($seriales) {
            $cantidad = (float) count($seriales);
        }
        if ($cantidad <= 0) {
            throw new \DomainException('La cantidad que se devuelve tiene que ser mayor que cero.');
        }

        return DB::transaction(function () use ($tecnicoId, $inventoryId, $cantidad, $seriales, $danado, $nota, $usuarioId) {
            $tecnico = $this->tecnico($tecnicoId);
            $item    = $this->item($inventoryId, true);

            if ($seriales) {
                $unidades = $this->unidadesEn($item->id, $seriales, 'tecnico', $tecnicoId);
                DB::table('inventory_units')->whereIn('id', $unidades->pluck('id'))->update([
                    'estado' => $danado ? 'danada' : 'bodega', 'tecnico_id' => null, 'entregada_en' => null,
                    'nota' => $nota ? mb_substr($nota, 0, 255) : null, 'updated_at' => now(),
                ]);
            } else {
                if ((int) $item->usa_serial) {
                    throw new \DomainException("«{$item->name}» se lleva por serial: escanee los equipos que devuelve.");
                }

                $c = DB::table('inventory_custodias')->where('company_id', $this->companyId)->where('inventory_id', $item->id)->where('tecnico_id', $tecnicoId)->lockForUpdate()->first();

                if (!$c || (float) $c->cantidad < $cantidad) {
                    throw new \DomainException('El técnico tiene ' . $this->n($c->cantidad ?? 0) . ' de «' . $item->name . '»: no puede devolver ' . $this->n($cantidad) . '.');
                }

                $le = (float) $c->cantidad - $cantidad;
                DB::table('inventory_custodias')->where('id', $c->id)->update(['cantidad' => $le, 'desde' => $le > 0 ? $c->desde : null, 'updated_at' => now()]);
            }

            // Lo dañado no vuelve a la existencia: queda registrado, pero no se puede entregar.
            $queda = (float) $item->quantity + ($danado ? 0 : $cantidad);
            DB::table('inventories')->where('id', $item->id)->update([
                'quantity' => $queda, 'aviso_bajo_en' => $queda > (float) $item->stock_min ? null : $item->aviso_bajo_en, 'updated_at' => now(),
            ]);

            $movimiento = $this->anotar($item->id, self::DEVOLUCION, $cantidad, $queda, [
                'unit_price' => $item->unit_price, 'cost_before' => $item->average_cost, 'cost_after' => $item->average_cost,
                'description' => 'Devuelto por ' . trim($tecnico->names . ' ' . $tecnico->lastname) . ($danado ? ' · dañado' : '') . ($nota ? ' · ' . $nota : ''),
                'user_id' => $usuarioId, 'tecnico_id' => $tecnicoId, 'serial_number' => count($seriales) === 1 ? $seriales[0] : null,
            ]);

            return ['movimiento_id' => $movimiento, 'cantidad' => $cantidad, 'en_bodega' => $queda];
        });
    }

    /**
     * El técnico gastó material sin serial de lo que tenía (cable, conectores).
     * No toca la bodega: baja lo que tiene a su nombre.
     */
    public function consumir(int $tecnicoId, int $inventoryId, float $cantidad, ?string $referencia = null, ?string $nota = null, ?int $usuarioId = null): array
    {
        if ($cantidad <= 0) {
            throw new \DomainException('La cantidad gastada tiene que ser mayor que cero.');
        }

        return DB::transaction(function () use ($tecnicoId, $inventoryId, $cantidad, $referencia, $nota, $usuarioId) {
            $tecnico = $this->tecnico($tecnicoId);
            $item    = $this->item($inventoryId, true);
            $c = DB::table('inventory_custodias')->where('company_id', $this->companyId)->where('inventory_id', $item->id)->where('tecnico_id', $tecnicoId)->lockForUpdate()->first();

            if (!$c || (float) $c->cantidad < $cantidad) {
                throw new \DomainException('El técnico tiene ' . $this->n($c->cantidad ?? 0) . ' de «' . $item->name . '»: no puede gastar ' . $this->n($cantidad) . '.');
            }

            $le = (float) $c->cantidad - $cantidad;
            DB::table('inventory_custodias')->where('id', $c->id)->update(['cantidad' => $le, 'desde' => $le > 0 ? $c->desde : null, 'updated_at' => now()]);

            $movimiento = $this->anotar($item->id, self::CONSUMO, $cantidad, (float) $item->quantity, [
                'unit_price' => $item->unit_price, 'cost_before' => $item->average_cost, 'cost_after' => $item->average_cost,
                'description' => 'Gastado por ' . trim($tecnico->names . ' ' . $tecnico->lastname) . ($nota ? ' · ' . $nota : ''),
                'reference' => $referencia, 'user_id' => $usuarioId, 'tecnico_id' => $tecnicoId,
            ]);

            return ['movimiento_id' => $movimiento, 'cantidad' => $cantidad, 'le_queda' => $le];
        });
    }

    /**
     * Un equipo quedó instalado donde un cliente.
     *
     * Si el serial está en el inventario se sabe de dónde sale: de lo que tenía un técnico o
     * de la bodega. Si no está (existencia cargada antes de llevar seriales), se descuenta
     * uno del renglón indicado y el equipo queda registrado con su serial de aquí en adelante.
     *
     * Nunca lanza: una falla del inventario no puede tumbar una instalación ya hecha.
     *
     * @return array{ok:bool, detalle:string, inventory_id:?int}
     */
    public function instalar(string $serial, int $ordenId, int $clienteUserId, ?int $tecnicoId = null, ?int $inventoryId = null): array
    {
        $limpio = Serial::limpio($serial);

        try {
            return DB::transaction(function () use ($limpio, $serial, $ordenId, $clienteUserId, $tecnicoId, $inventoryId) {
                $unidad = DB::table('inventory_units')->where('company_id', $this->companyId)->where('serial_canonico', Serial::canonico($limpio))->lockForUpdate()->first();

                if ($unidad && $unidad->estado === 'instalada') {
                    return ['ok' => true, 'detalle' => "El serial {$limpio} ya figuraba como instalado.", 'inventory_id' => (int) $unidad->inventory_id];
                }

                $item = $unidad
                    ? $this->item((int) $unidad->inventory_id, true)
                    : ($inventoryId ? $this->item($inventoryId, true) : $this->unicoDeOnt());

                if (!$item) {
                    return ['ok' => false, 'detalle' => "El serial {$limpio} no está en el inventario. El equipo quedó instalado igual; revise la existencia desde la oficina.", 'inventory_id' => null];
                }

                $queda = (float) $item->quantity;
                $deDonde = 'la bodega';
                $tipo = self::SALIDA;

                if ($unidad && $unidad->estado === 'tecnico') {
                    // Ya había salido de bodega cuando se le entregó al técnico.
                    $deDonde = 'lo que tenía el técnico';
                    $tipo = self::CONSUMO;
                } else {
                    if ($queda <= 0) {
                        return ['ok' => false, 'detalle' => "«{$item->name}» figura en cero en la bodega. El equipo quedó instalado igual.", 'inventory_id' => (int) $item->id];
                    }

                    $queda -= 1;
                    DB::table('inventories')->where('id', $item->id)->update(['quantity' => $queda, 'updated_at' => now()]);
                }

                $datos = ['estado' => 'instalada', 'tecnico_id' => $tecnicoId ?? ($unidad->tecnico_id ?? null), 'instalada_en' => now(), 'installation_order_id' => $ordenId, 'cliente_user_id' => $clienteUserId, 'updated_at' => now()];

                $unidad
                    ? DB::table('inventory_units')->where('id', $unidad->id)->update($datos)
                    : DB::table('inventory_units')->insert($datos + ['company_id' => $this->companyId, 'inventory_id' => $item->id, 'serial' => $limpio, 'serial_canonico' => Serial::canonico($limpio), 'created_at' => now()]);

                $this->anotar($item->id, $tipo, 1, $queda, [
                    'unit_price' => $item->unit_price, 'cost_before' => $item->average_cost, 'cost_after' => $item->average_cost,
                    'description' => 'Instalada en casa del cliente',
                    // Con esto se llega de vuelta a la orden y al cliente sin cruzar tablas a mano.
                    'reference' => "instalacion:{$ordenId} cliente:{$clienteUserId}",
                    'serial_number' => $limpio, 'user_id' => $tecnicoId, 'tecnico_id' => $tecnicoId,
                ]);

                $this->avisarSiQuedoBajo($item, $queda);

                return ['ok' => true, 'detalle' => "«{$item->name}», serial {$limpio}, salió de {$deDonde}. Quedan " . $this->n($queda) . ' en bodega.', 'inventory_id' => (int) $item->id];
            });
        } catch (\Throwable $e) {
            Log::warning('[Inventario] No se pudo descontar la instalación', ['serial' => $serial, 'orden' => $ordenId, 'error' => $e->getMessage()]);

            return ['ok' => false, 'detalle' => 'No se pudo descontar del inventario: ' . $e->getMessage(), 'inventory_id' => $inventoryId];
        }
    }

    /** El único renglón de ONT con existencia; con varios no se adivina. */
    private function unicoDeOnt(): ?object
    {
        $onts = self::renglonesDeOnt($this->companyId);

        return count($onts) === 1 ? DB::table('inventories')->where('id', $onts[0]['id'])->lockForUpdate()->first() : null;
    }

    /**
     * Los renglones que son ONT y tienen existencia, para que el técnico elija.
     * «ONT» o «ONU» como palabra: «montaje» o «frontal» no cuentan.
     *
     * @return list<array<string,mixed>>
     */
    public static function renglonesDeOnt(int $companyId): array
    {
        return DB::table('inventories')->where('company_id', $companyId)->whereNull('deleted_at')->where('quantity', '>', 0)
            ->whereRaw("name REGEXP '(^|[^[:alpha:]])(ONT|ONU)([^[:alpha:]]|$)'")
            ->orderBy('name')->get(['id', 'name', 'sku', 'quantity'])->map(fn ($i) => (array) $i)->all();
    }

    // ── Interno ──────────────────────────────────────────────────────────────

    /** @return list<string> seriales limpios, sin repetidos ni vacíos */
    private function serialesLimpios(array $seriales): array
    {
        $limpios = [];

        foreach ($seriales as $s) {
            $l = Serial::limpio((string) $s);

            if ($l === '') {
                continue;
            }
            if (!Serial::valido($l)) {
                throw new \DomainException("«{$s}» no parece un serial: revise la lectura.");
            }

            $limpios[Serial::canonico($l)] = $l;
        }

        return array_values($limpios);
    }

    /** Las unidades de esos seriales, exigiendo que estén donde se espera. */
    private function unidadesEn(int $inventoryId, array $seriales, string $estado, ?int $tecnicoId = null)
    {
        $unidades = DB::table('inventory_units')->where('company_id', $this->companyId)
            ->whereIn('serial_canonico', array_map([Serial::class, 'canonico'], $seriales))->lockForUpdate()->get();

        $faltan = array_diff(array_map([Serial::class, 'canonico'], $seriales), $unidades->pluck('serial_canonico')->all());

        if ($faltan) {
            throw new \DomainException('No están en el inventario: ' . implode(', ', array_slice($faltan, 0, 6)) . '. Regístrelos primero como entrada.');
        }

        foreach ($unidades as $u) {
            if ((int) $u->inventory_id !== $inventoryId) {
                throw new \DomainException("El serial {$u->serial} es de otro producto.");
            }
            if ($u->estado !== $estado || ($tecnicoId !== null && (int) $u->tecnico_id !== $tecnicoId)) {
                $donde = ['bodega' => 'en bodega', 'tecnico' => 'con un técnico', 'instalada' => 'instalado donde un cliente', 'danada' => 'marcado como dañado', 'baja' => 'dado de baja'][$u->estado] ?? $u->estado;
                throw new \DomainException("El serial {$u->serial} está {$donde}" . ($estado === 'tecnico' && $u->estado === 'tecnico' ? ', pero con otro técnico' : '') . '.');
            }
        }

        return $unidades;
    }

    private function anotar(int $inventoryId, string $tipo, float $cantidad, float $saldo, array $datos): int
    {
        return (int) DB::table('inventory_movements')->insertGetId(array_merge([
            'company_id' => $this->companyId, 'inventory_id' => $inventoryId, 'type' => $tipo,
            'quantity' => $cantidad, 'balance_after' => $saldo, 'created_at' => now(), 'updated_at' => now(),
        ], array_map(fn ($v) => is_string($v) ? mb_substr($v, 0, 255) : $v, $datos)));
    }

    /** Si con este movimiento quedó en el mínimo o por debajo, se avisa una vez. */
    private function avisarSiQuedoBajo(object $item, float $queda): void
    {
        if ((float) $item->stock_min <= 0 || $queda > (float) $item->stock_min || $item->aviso_bajo_en) {
            return;
        }

        // Después de confirmar: si la transacción se deshace, no hubo faltante que avisar.
        DB::afterCommit(fn () => AvisosDeInventario::existenciaBaja($this->companyId, (int) $item->id));
    }

    private function n(mixed $v): string
    {
        return rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
    }
}
