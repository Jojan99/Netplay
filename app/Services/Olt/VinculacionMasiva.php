<?php

namespace App\Services\Olt;

use App\Models\OltOnt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Empareja las ONT de la OLT con los clientes del sistema.
 *
 * La mayoría de las ONT se autorizaron con el nombre del cliente como
 * descripción ("JOJANNY_MANUEL_POMBO"), así que el vínculo está a la vista
 * pero nunca se guardó: de 541 ONT sólo 14 tenían cliente, y sin ese vínculo
 * la ficha no muestra el equipo ni el cliente ve su WiFi en el portal.
 *
 * Acá se proponen las parejas y las confirma una persona. Nada se vincula
 * solo: un equipo en el cliente equivocado manda a un técnico a otra casa.
 */
class VinculacionMasiva
{
    /** Con menos parecido que esto no se propone nada. */
    private const PARECIDO_MINIMO = 0.62;

    public function __construct(private int $companyId) {}

    /**
     * Qué ONT se pueden vincular y con quién.
     *
     * @return array<string,mixed>
     */
    public function propuestas(?int $oltId = null): array
    {
        $clientes = $this->clientes();
        $indice   = $this->indicePorPalabra($clientes);
        $conOnt   = $this->clientesConOnt();

        $propuestas = [];
        $sinCandidato = 0;
        $ambiguas = 0;

        foreach ($this->ontsSinCliente($oltId) as $ont) {
            $candidato = $this->mejorCandidato($ont, $clientes, $indice);

            if (!$candidato) {
                $sinCandidato++;
                continue;
            }

            if ($candidato['ambiguo']) {
                $ambiguas++;
            }

            $cliente = $clientes[$candidato['user_id']];

            $propuestas[] = [
                'ont' => [
                    'id'          => (int) $ont->id,
                    'olt'         => $ont->olt,
                    'olt_id'      => (int) $ont->olt_id,
                    'fsp'         => $ont->fsp,
                    'ont_id'      => (int) $ont->ont_id,
                    'serial'      => $ont->serial,
                    'description' => $ont->description,
                    'estado'      => $ont->status,
                ],
                'cliente' => [
                    'user_id'   => (int) $cliente->user_id,
                    'nombre'    => $cliente->nombre,
                    'documento' => $cliente->dni,
                    'plan'      => $cliente->plan,
                    'direccion' => $cliente->address,
                    'ya_tiene_ont' => isset($conOnt[$cliente->user_id]),
                ],
                'confianza' => $candidato['confianza'],
                'motivo'    => $candidato['motivo'],
                'ambiguo'   => $candidato['ambiguo'],
            ];
        }

        // Una misma persona propuesta para dos ONT es señal de duda: se marcan
        // las dos para que alguien mire, en vez de vincular la primera.
        $vecesPorCliente = array_count_values(array_map(fn ($p) => $p['cliente']['user_id'], $propuestas));

        foreach ($propuestas as $i => $p) {
            if (($vecesPorCliente[$p['cliente']['user_id']] ?? 0) > 1) {
                $propuestas[$i]['ambiguo'] = true;
                $propuestas[$i]['confianza'] = 'baja';
                $propuestas[$i]['motivo'] .= ' · el mismo cliente aparece en otra ONT';
            }
        }

        usort($propuestas, fn ($a, $b) => [self::peso($a['confianza']), $a['ont']['fsp']] <=> [self::peso($b['confianza']), $b['ont']['fsp']]);

        return [
            'propuestas'    => $propuestas,
            'resumen'       => [
                'sin_cliente'   => count($this->ontsSinCliente($oltId)),
                'con_propuesta' => count($propuestas),
                'alta'          => count(array_filter($propuestas, fn ($p) => $p['confianza'] === 'alta')),
                'media'         => count(array_filter($propuestas, fn ($p) => $p['confianza'] === 'media')),
                'baja'          => count(array_filter($propuestas, fn ($p) => $p['confianza'] === 'baja')),
                'sin_candidato' => $sinCandidato,
                'ambiguas'      => $ambiguas,
            ],
        ];
    }

    /**
     * Guarda los vínculos confirmados.
     *
     * @param  list<array{ont:int, user_id:int}>  $pares
     * @return array{vinculadas:int, errores:list<string>}
     */
    public function aplicar(array $pares): array
    {
        $onts = OltOnt::whereIn('id', array_column($pares, 'ont'))
            ->whereHas('olt', fn ($q) => $q->where('company_id', $this->companyId))
            ->get()->keyBy('id');

        $clientes = $this->clientes();
        $vinculadas = 0;
        $errores = [];

        foreach ($pares as $par) {
            $ont = $onts[$par['ont']] ?? null;
            $cliente = $clientes[$par['user_id']] ?? null;

            if (!$ont) {
                $errores[] = "La ONT {$par['ont']} no es de tu empresa.";
                continue;
            }

            if (!$cliente) {
                $errores[] = "El cliente {$par['user_id']} no es de tu empresa.";
                continue;
            }

            $ont->update(['user_data_id' => (int) $par['user_id']]);
            $vinculadas++;
        }

        Log::info('[OLT] Vinculación masiva', [
            'empresa' => $this->companyId, 'vinculadas' => $vinculadas, 'errores' => count($errores),
        ]);

        return ['vinculadas' => $vinculadas, 'errores' => $errores];
    }

    // ── Datos ─────────────────────────────────────────────────────────────

    /** @return array<int,object> clientes activos por users.id */
    private function clientes(): array
    {
        return DB::table('user_data as ud')
            ->join('users as u', 'u.id', '=', 'ud.user_id')
            ->leftJoin('internet_plans as ip', 'ip.id', '=', 'ud.internet_plans_id')
            ->where('u.company_id', $this->companyId)
            ->where('ud.active', 1)
            ->get(['ud.user_id', 'ud.names', 'ud.lastname', 'ud.dni', 'ud.address', 'ud.pppoe_user', 'ip.plan_name as plan'])
            ->each(function ($c) {
                $c->nombre     = trim($c->names . ' ' . $c->lastname);
                $c->normalizado = self::normalizar($c->nombre);
                $c->palabras   = self::palabras($c->normalizado);
            })
            ->keyBy('user_id')
            ->all();
    }

    /** @return array<int,true> */
    private function clientesConOnt(): array
    {
        return DB::table('olt_onts as o')
            ->join('olt_admins as a', 'a.id', '=', 'o.olt_id')
            ->where('a.company_id', $this->companyId)
            ->whereNotNull('o.user_data_id')
            ->pluck('o.user_data_id')
            ->flip()
            ->map(fn () => true)
            ->all();
    }

    /** @return list<object> */
    private function ontsSinCliente(?int $oltId): array
    {
        static $cache = [];

        return $cache[$oltId ?? 0] ??= DB::table('olt_onts as o')
            ->join('olt_admins as a', 'a.id', '=', 'o.olt_id')
            ->where('a.company_id', $this->companyId)
            ->when($oltId, fn ($q) => $q->where('o.olt_id', $oltId))
            ->whereNull('o.user_data_id')
            ->orderBy('o.olt_id')->orderBy('o.fsp')->orderBy('o.ont_id')
            ->get(['o.id', 'o.olt_id', 'o.fsp', 'o.ont_id', 'o.serial', 'o.description', 'o.status', 'a.name as olt'])
            ->all();
    }

    // ── Emparejado ────────────────────────────────────────────────────────

    /**
     * Palabra → clientes que la tienen en su nombre. Con esto se comparan sólo
     * los que comparten algo, y no los 1.169 contra cada ONT.
     *
     * @param  array<int,object>  $clientes
     * @return array<string,list<int>>
     */
    private function indicePorPalabra(array $clientes): array
    {
        $indice = [];

        foreach ($clientes as $c) {
            foreach ($c->palabras as $palabra) {
                $indice[$palabra][] = (int) $c->user_id;
            }
        }

        return $indice;
    }

    /**
     * @param  array<int,object>  $clientes
     * @param  array<string,list<int>>  $indice
     * @return array{user_id:int, confianza:string, motivo:string, ambiguo:bool}|null
     */
    private function mejorCandidato(object $ont, array $clientes, array $indice): ?array
    {
        $texto = self::normalizar((string) $ont->description);

        if ($texto === '') {
            return null;
        }

        // El documento en la descripción no deja lugar a dudas.
        if (preg_match('/\b(\d{6,12})\b/', $texto, $m)) {
            foreach ($clientes as $c) {
                if (preg_replace('/\D/', '', (string) $c->dni) === $m[1]) {
                    return ['user_id' => (int) $c->user_id, 'confianza' => 'alta', 'motivo' => 'El documento está en la descripción', 'ambiguo' => false];
                }
            }
        }

        $palabras = self::palabras($texto);

        if (!$palabras) {
            return null;
        }

        $puntajes = [];

        foreach ($palabras as $palabra) {
            foreach ($indice[$palabra] ?? [] as $userId) {
                $puntajes[$userId] = ($puntajes[$userId] ?? 0) + 1;
            }
        }

        if (!$puntajes) {
            return null;
        }

        $mejores = [];

        foreach ($puntajes as $userId => $coincidencias) {
            $c = $clientes[$userId];
            // Parecido = palabras en común sobre el total de palabras distintas.
            $union = count(array_unique(array_merge($palabras, $c->palabras)));
            $mejores[$userId] = $union ? $coincidencias / $union : 0;
        }

        arsort($mejores);

        // Caso frecuente: la ONT trae el nombre corto ("KAREN_OCHOA") y el
        // cliente lo tiene completo ("KAREN OCHOA OSPINO"). Si todas las
        // palabras de la ONT están en un solo cliente, es él.
        $contienen = array_keys(array_filter(
            $clientes,
            fn ($c) => !array_diff($palabras, $c->palabras)
        ));

        if (count($contienen) === 1) {
            $userId = (int) $contienen[0];
            $exacto = $clientes[$userId]->normalizado === $texto;

            return [
                'user_id'   => $userId,
                'confianza' => $exacto || count($palabras) >= 2 ? 'alta' : 'media',
                'motivo'    => $exacto
                    ? 'El nombre coincide igual'
                    : 'El nombre de la ONT está completo en el del cliente',
                'ambiguo'   => false,
            ];
        }

        if (count($contienen) > 1) {
            $userId = (int) $contienen[0];

            return [
                'user_id'   => $userId,
                'confianza' => 'baja',
                'motivo'    => 'Hay ' . count($contienen) . ' clientes con ese nombre',
                'ambiguo'   => true,
            ];
        }

        $userId = (int) array_key_first($mejores);
        $puntaje = $mejores[$userId];

        if ($puntaje < self::PARECIDO_MINIMO) {
            return null;
        }

        $segundo = count($mejores) > 1 ? array_values($mejores)[1] : 0;

        return [
            'user_id'   => $userId,
            'confianza' => $puntaje >= 0.8 && $puntaje - $segundo > 0.1 ? 'media' : 'baja',
            'motivo'    => 'Se parece al nombre (' . round($puntaje * 100) . '%)',
            'ambiguo'   => ($puntaje - $segundo) < 0.08,
        ];
    }

    /** Sin acentos, sin signos y en mayúsculas: "Peña-Gómez" → "PENA GOMEZ". */
    private static function normalizar(string $texto): string
    {
        $texto = strtr(mb_strtoupper(trim($texto)), [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        ]);

        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Z0-9]+/', ' ', $texto)));
    }

    /**
     * Las palabras que sirven para comparar: se sacan las de una o dos letras
     * y los "DE", "DEL", "LA", que están en medio mundo.
     *
     * @return list<string>
     */
    private static function palabras(string $normalizado): array
    {
        $vacias = ['DE', 'DEL', 'LA', 'LAS', 'LOS', 'EL', 'Y', 'DA', 'DI'];

        return array_values(array_unique(array_filter(
            explode(' ', $normalizado),
            fn ($p) => mb_strlen($p) > 2 && !in_array($p, $vacias, true)
        )));
    }

    private static function peso(string $confianza): int
    {
        return ['alta' => 0, 'media' => 1, 'baja' => 2][$confianza] ?? 3;
    }
}
