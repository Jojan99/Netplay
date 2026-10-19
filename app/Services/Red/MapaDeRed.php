<?php

namespace App\Services\Red;

use App\Models\OltAdmin;
use App\Services\Olt\SenalDeLaOlt;
use Illuminate\Support\Facades\DB;

/**
 * Lo que muestra el mapa de la red: las OLT, las cajas NAP y cada cliente con su
 * estado, para ver de un vistazo dónde está una falla.
 *
 * El estado de un cliente sale de lo que la plataforma ya sabe, en este orden
 * (el primero que aplique):
 *  - suspendido: el servicio está cortado (status_internet_id 2);
 *  - caido:      su ONT figura fuera de línea en la OLT;
 *  - falla:      su puerto PON tiene una falla de sector abierta;
 *  - senal:      la última medición óptica es baja o crítica;
 *  - ok;  sin_ont: no tiene ONT vinculada.
 *
 * La señal es la del barrido que la revisión de alertas hace cada 15 minutos
 * (SenalDeLaOlt::de nunca mide en el momento): abrir el mapa no le pega a la OLT.
 */
class MapaDeRed
{
    public const SUSPENDIDO = 2;

    public function __construct(private int $companyId) {}

    /** @return array{puntos: list<array<string,mixed>>, clientes: list<array<string,mixed>>, olts: list<array<string,mixed>>, medido_en: ?string} */
    public function datos(): array
    {
        $olts = OltAdmin::where('company_id', $this->companyId)->get(['id', 'name']);
        [$senal, $medidoEn] = $this->senal($olts);
        $fallas = $this->puertosConFalla();

        $clientes = DB::table('user_data as ud')
            ->leftJoin('cliente_ubicaciones as cu', fn ($j) => $j->on('cu.user_id', '=', 'ud.user_id')->where('cu.company_id', $this->companyId))
            ->leftJoin('olt_onts as o', 'o.user_data_id', '=', 'ud.id')
            ->where('ud.company_id', $this->companyId)
            ->where('ud.active', 1)
            ->get([
                'ud.user_id as id', 'ud.names', 'ud.lastname', 'ud.dni', 'ud.address', 'ud.barrio', 'ud.status_internet_id',
                'cu.lat', 'cu.lng', 'cu.red_punto_id as nap_id', 'cu.fuente',
                'o.olt_id', 'o.fsp', 'o.ont_id', 'o.status as ont_status',
            ])
            // Un cliente con dos ONT vinculadas sale una vez: la que está en línea.
            ->sortBy(fn ($c) => $c->ont_status === 'online' ? 0 : 1)
            ->unique('id')
            ->map(function ($c) use ($senal, $fallas) {
                $medida = $senal[(int) $c->id] ?? null;
                $estado = match (true) {
                    (int) $c->status_internet_id === self::SUSPENDIDO               => 'suspendido',
                    $c->olt_id === null                                            => 'sin_ont',
                    $c->ont_status === 'offline'                                   => 'caido',
                    isset($fallas["{$c->olt_id}|{$c->fsp}"])                       => 'falla',
                    in_array($medida['estado'] ?? null, ['baja', 'critica'], true) => 'senal',
                    default                                                        => 'ok',
                };

                return [
                    'id'        => (int) $c->id,
                    'nombre'    => trim("{$c->names} {$c->lastname}"),
                    'documento' => $c->dni,
                    'direccion' => trim(implode(' · ', array_filter([$c->address, $c->barrio]))),
                    'lat'       => $c->lat !== null ? (float) $c->lat : null,
                    'lng'       => $c->lng !== null ? (float) $c->lng : null,
                    'nap_id'    => $c->nap_id !== null ? (int) $c->nap_id : null,
                    'fuente'    => $c->fuente,
                    'olt_id'    => $c->olt_id !== null ? (int) $c->olt_id : null,
                    'fsp'       => $c->fsp,
                    'ont_id'    => $c->ont_id,
                    'potencia'  => $medida['potencia'] ?? null,
                    'estado'    => $estado,
                ];
            })
            ->values();

        $puntos = DB::table('red_puntos')->where('company_id', $this->companyId)->orderBy('tipo')->orderBy('nombre')->get()
            ->map(fn ($p) => [
                'id'        => (int) $p->id,
                'tipo'      => $p->tipo,
                'nombre'    => $p->nombre,
                'lat'       => (float) $p->lat,
                'lng'       => (float) $p->lng,
                'olt_id'    => $p->olt_id !== null ? (int) $p->olt_id : null,
                'fsp'       => $p->fsp,
                'capacidad' => $p->capacidad !== null ? (int) $p->capacidad : null,
                'nota'      => $p->nota,
                'falla'     => $p->olt_id !== null && $p->fsp !== null && isset($fallas["{$p->olt_id}|{$p->fsp}"]),
            ])
            ->values();

        return [
            'puntos'    => $puntos->all(),
            'clientes'  => $clientes->all(),
            'olts'      => $olts->map(fn ($o) => ['id' => (int) $o->id, 'nombre' => $o->name])->values()->all(),
            'medido_en' => $medidoEn,
        ];
    }

    /** @return array<string,mixed> */
    public function guardarPunto(array $datos, ?int $id = null): array
    {
        $fila = [
            'tipo'      => $datos['tipo'],
            'nombre'    => trim($datos['nombre']),
            'lat'       => $datos['lat'],
            'lng'       => $datos['lng'],
            'olt_id'    => $this->oltPropia($datos['olt_id'] ?? null),
            'fsp'       => ($datos['fsp'] ?? null) ?: null,
            'capacidad' => ($datos['capacidad'] ?? null) ?: null,
            'nota'      => ($datos['nota'] ?? null) ?: null,
            'updated_at' => now(),
        ];

        if ($id === null) {
            $id = DB::table('red_puntos')->insertGetId($fila + ['company_id' => $this->companyId, 'created_at' => now()]);
        } else {
            $this->exigirPunto($id);
            DB::table('red_puntos')->where('id', $id)->update($fila);
        }

        return (array) DB::table('red_puntos')->find($id);
    }

    /** Borra el punto. Los clientes que colgaban de esa caja quedan sin caja, no se borran. */
    public function borrarPunto(int $id): void
    {
        $this->exigirPunto($id);

        DB::transaction(function () use ($id) {
            DB::table('cliente_ubicaciones')->where('company_id', $this->companyId)->where('red_punto_id', $id)->update(['red_punto_id' => null, 'updated_at' => now()]);
            DB::table('red_puntos')->where('id', $id)->delete();
        });
    }

    /** Pone (o mueve) al cliente en el mapa y/o lo cuelga de una caja. */
    public function ubicarCliente(int $userId, ?float $lat, ?float $lng, ?int $napId, int $operador, string $fuente = 'manual'): void
    {
        if (!DB::table('user_data')->where('company_id', $this->companyId)->where('user_id', $userId)->exists()) {
            throw new \InvalidArgumentException('Ese cliente no es de la empresa.');
        }

        if ($napId !== null && !DB::table('red_puntos')->where('company_id', $this->companyId)->where('id', $napId)->where('tipo', 'nap')->exists()) {
            throw new \InvalidArgumentException('Esa caja NAP no existe.');
        }

        DB::table('cliente_ubicaciones')->updateOrInsert(
            ['company_id' => $this->companyId, 'user_id' => $userId],
            ['lat' => $lat, 'lng' => $lng, 'red_punto_id' => $napId, 'fuente' => $fuente, 'updated_by' => $operador, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function quitarCliente(int $userId): void
    {
        DB::table('cliente_ubicaciones')->where('company_id', $this->companyId)->where('user_id', $userId)->delete();
    }

    /**
     * La última medición óptica de cada OLT, por cliente.
     *
     * @return array{0: array<int, array{estado: string, potencia: ?float}>, 1: ?string}
     */
    private function senal($olts): array
    {
        $porCliente = [];
        $medidoEn = null;

        foreach ($olts as $olt) {
            try {
                $r = SenalDeLaOlt::de($olt);
            } catch (\Throwable) {
                continue;
            }

            if (!empty($r['medido_en']) && ($medidoEn === null || $r['medido_en'] < $medidoEn)) {
                $medidoEn = $r['medido_en'];
            }

            foreach ($r['onts'] ?? [] as $ont) {
                if (!empty($ont['user_id'])) {
                    $porCliente[(int) $ont['user_id']] = ['estado' => $ont['estado'] ?? 'sin_dato', 'potencia' => $ont['potencia'] ?? null];
                }
            }
        }

        return [$porCliente, $medidoEn];
    }

    /** @return array<string, true> "olt_id|fsp" de los puertos con una falla de sector abierta */
    private function puertosConFalla(): array
    {
        return DB::table('fallas_sector')
            ->where('company_id', $this->companyId)
            ->whereNotIn('estado', ['descartada', 'resuelta'])
            ->get(['olt_id', 'fsp'])
            ->mapWithKeys(fn ($f) => ["{$f->olt_id}|{$f->fsp}" => true])
            ->all();
    }

    private function oltPropia($oltId): ?int
    {
        if (!$oltId) {
            return null;
        }

        if (!OltAdmin::where('company_id', $this->companyId)->where('id', $oltId)->exists()) {
            throw new \InvalidArgumentException('Esa OLT no es de la empresa.');
        }

        return (int) $oltId;
    }

    private function exigirPunto(int $id): void
    {
        if (!DB::table('red_puntos')->where('company_id', $this->companyId)->where('id', $id)->exists()) {
            throw new \InvalidArgumentException('Ese punto no existe.');
        }
    }
}
