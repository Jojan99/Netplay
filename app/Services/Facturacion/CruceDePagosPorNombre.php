<?php

namespace App\Services\Facturacion;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Encuentra a qué cliente corresponde cada nombre de una lista de pagos.
 *
 * Las listas que llegan (de un cuaderno, de un banco, de un chat) traen los nombres
 * como los escribió quien cobró: sin el segundo nombre, con una letra cambiada, con
 * el apellido en otro orden. Cruzarlos exacto no sirve; cruzarlos «parecido» a
 * ciegas, tampoco: una cédula equivocada es un pago aplicado a otra persona.
 *
 * Por eso cada fila sale con una confianza, y sólo dos se pueden aplicar sin que
 * nadie las mire:
 *
 *   Exacta    las mismas palabras, en cualquier orden.
 *   Probable  falta o sobra un nombre, o hay una letra distinta, y hay UN candidato
 *             claro. Un nombre de pila u apellido cambiado por otro NO es «probable».
 *   Revisar   lo demás. Con una sola palabra en común no se propone cédula: es mejor
 *             que la fila quede vacía a que quede con la de otra persona.
 *
 * (La fila «Corregida» la pone una persona desde la pantalla; acá no se decide.)
 */
class CruceDePagosPorNombre
{
    /** Palabras que no distinguen a nadie. */
    private const STOP = ['DE', 'DEL', 'LA', 'LAS', 'LOS', 'TIENDA'];

    /** @var list<array{user_id:int,dni:string,full:string,tk:list<string>}> */
    private array $clientes = [];

    public function __construct(private int $companyId)
    {
        $filas = DB::table('user_data as ud')
            ->join('users as u', 'u.id', '=', 'ud.user_id')
            ->where('u.company_id', $companyId)
            ->whereNotNull('ud.dni')->where('ud.dni', '!=', '')
            ->get(['ud.user_id', 'ud.names', 'ud.lastname', 'ud.dni']);

        foreach ($filas as $f) {
            $full = trim($f->names . ' ' . $f->lastname);
            $this->clientes[] = ['user_id' => (int) $f->user_id, 'dni' => (string) $f->dni, 'full' => $full, 'tk' => $this->palabras($full)];
        }
    }

    // ── Leer la lista pegada ──────────────────────────────────────────────

    /**
     * «NOMBRE    65000» por línea. El valor puede traer $, puntos o comas de miles.
     *
     * @return array{filas: list<array{nombre:string,valor:float}>, no_leidas: list<string>}
     */
    public static function leer(string $texto): array
    {
        $filas = [];
        $no = [];

        foreach (preg_split('/\R/u', $texto) as $linea) {
            $linea = trim($linea);
            if ($linea === '') continue;

            if (!preg_match('/^(.*?)[\s\t]+\$?\s*([\d][\d.,]*)\s*$/u', $linea, $m)) { $no[] = $linea; continue; }

            $valor = $m[2];
            // «65.000» y «65,000» son sesenta y cinco mil; «65.5» no llega a pasar de acá.
            $valor = preg_match('/^\d{1,3}([.,]\d{3})+$/', $valor) ? preg_replace('/[.,]/', '', $valor) : preg_replace('/[^\d]/', '', $valor);

            $nombre = trim(preg_replace('/\s+/', ' ', $m[1]));
            if ($nombre === '' || (float) $valor <= 0) { $no[] = $linea; continue; }

            $filas[] = ['nombre' => $nombre, 'valor' => (float) $valor];
        }

        return ['filas' => $filas, 'no_leidas' => $no];
    }

    // ── Cruzar ────────────────────────────────────────────────────────────

    /**
     * @param  list<array{nombre:string,valor:float}>  $filas
     * @return list<array<string,mixed>>
     */
    public function cruzar(array $filas): array
    {
        $salida = [];

        foreach ($filas as $i => $f) {
            $nombre = $f['nombre'];
            $a = $this->palabras($nombre);

            $puntajes = [];
            foreach ($this->clientes as $k => $c) $puntajes[$k] = $this->puntaje($a, $c['tk']);
            arsort($puntajes);
            $orden = array_keys($puntajes);

            $k1 = $orden[0] ?? null; $k2 = $orden[1] ?? null;
            $c1 = $k1 !== null ? $this->clientes[$k1] : null; $c2 = $k2 !== null ? $this->clientes[$k2] : null;
            $s1 = $k1 !== null ? $puntajes[$k1] : 0.0;   $s2 = $k2 !== null ? $puntajes[$k2] : 0.0;

            $est = 'Revisar'; $cedula = ''; $base = ''; $obs = '';

            if ($c1) {
                $cedula = $c1['dni']; $base = $c1['full'];

                if ($this->igualesSinOrden($nombre, $c1['full'])) {
                    $est = 'Exacta';
                } elseif ($s1 >= 0.7 && !$this->sustituye($a, $c1['tk'])
                    && ($s1 - $s2 >= 0.1 || $this->exactas($nombre, $c1['full']) > ($c2 ? $this->exactas($nombre, $c2['full']) : 0))) {
                    $est = 'Probable';
                } else {
                    $est = 'Revisar';

                    if ($this->enComun($a, $c1['tk']) <= 1) {
                        // Con una sola palabra en común no es una coincidencia: se deja SIN
                        // cédula para que la de otra persona no se cuele en un pago.
                        $obs = "No la encontré en la base. Lo más parecido: {$c1['full']} (CC {$c1['dni']})";
                        $cedula = ''; $base = '';
                    } else {
                        $obs = ($c2 && $s2 >= 0.5) ? "Confirmar. Otra posible: {$c2['full']} (CC {$c2['dni']})" : 'Confirmar el nombre';
                    }
                }
            }

            $salida[] = [
                'ref'        => 'F' . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'nombre'     => $nombre,
                'valor'      => $f['valor'],
                'est'        => $est,
                'cedula'     => $cedula,
                'base'       => $base,
                'obs'        => $obs,
                'candidatos' => collect($orden)->take(3)->filter(fn ($k) => $puntajes[$k] >= 0.4)
                    ->map(fn ($k) => ['cedula' => $this->clientes[$k]['dni'], 'nombre' => $this->clientes[$k]['full'], 'puntaje' => round($puntajes[$k], 2)])->values()->all(),
            ];
        }

        return $salida;
    }

    // ── Piezas ────────────────────────────────────────────────────────────

    private function normalizar(string $t): string
    {
        $t = strtoupper(Str::ascii($t));

        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Z ]/', ' ', $t)));
    }

    /** @return list<string> */
    private function palabras(string $t): array
    {
        return array_values(array_filter(explode(' ', $this->normalizar($t)), fn ($w) => $w !== '' && !in_array($w, self::STOP, true)));
    }

    private function sim(string $a, string $b): float
    {
        if ($a === $b) return 1.0;
        $t = strlen($a) + strlen($b);

        return $t ? similar_text($a, $b) * 2 / $t : 0.0;
    }

    private function igualesSinOrden(string $a, string $b): bool
    {
        $x = explode(' ', $this->normalizar($a)); $y = explode(' ', $this->normalizar($b));
        sort($x); sort($y);

        return $x === $y;
    }

    /** Palabras idénticas: un apellido igual pesa más que uno parecido. */
    private function exactas(string $a, string $b): int
    {
        return count(array_intersect(array_unique(explode(' ', $this->normalizar($a))), array_unique(explode(' ', $this->normalizar($b)))));
    }

    /**
     * Empareja cada palabra de la lista con la que más se le parezca en la base.
     *
     * @return array{usadas:list<int>,sin:list<int>,tot:float,n:int}
     */
    private function emparejar(array $a, array $b): array
    {
        $usadas = []; $sin = []; $tot = 0.0; $n = 0;

        foreach ($a as $i => $w) {
            $mejor = 0.0; $mj = null;
            foreach ($b as $j => $x) {
                if (isset($usadas[$j])) continue;
                $s = $this->sim($w, $x);
                if ($s > $mejor) { $mejor = $s; $mj = $j; }
            }
            if ($mejor >= 0.8) { $usadas[$mj] = true; $tot += $mejor; $n++; } else { $sin[] = $i; }
        }

        return ['usadas' => array_keys($usadas), 'sin' => $sin, 'tot' => $tot, 'n' => $n];
    }

    private function enComun(array $a, array $b): int { return $this->emparejar($a, $b)['n']; }

    private function puntaje(array $a, array $b): float
    {
        if (!$a || !$b) return 0.0;

        $e = $this->emparejar($a, $b);
        $cubre = $e['tot'] / count($a);
        $sobra = count($b) - count($e['usadas']);

        return $cubre * 0.85 + ($sobra <= 1 ? 0.15 : ($sobra == 2 ? 0.10 : 0.05)) * $cubre;
    }

    /**
     * ¿Hay una palabra distinta EN LUGAR de otra —un nombre de pila o un apellido
     * cambiado— y no sólo un nombre que falta? Que falte un segundo nombre es normal;
     * que sea otro nombre, no. Un tipeo (KENORY / KERONY) se parece; YULEISY / DEYIS, casi no.
     */
    private function sustituye(array $a, array $b): bool
    {
        $e = $this->emparejar($a, $b);
        $sin = $e['sin'];
        $sobran = array_values(array_diff(array_keys($b), $e['usadas']));

        if (!$sin || !$sobran) return false;

        $max = 0.0;
        foreach ($sin as $i) foreach ($sobran as $j) $max = max($max, $this->sim($a[$i], $b[$j]));
        if ($max >= 0.6) return false;

        return in_array(0, $sin, true) || in_array(count($a) - 1, $sin, true)
            || in_array(0, $sobran, true) || in_array(count($b) - 1, $sobran, true);
    }

    /** Para que la persona busque a mano a quien el cruce no encontró. */
    public function buscar(string $q, int $limite = 12): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) return [];

        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';

        return DB::table('user_data as ud')
            ->join('users as u', 'u.id', '=', 'ud.user_id')
            ->where('u.company_id', $this->companyId)
            ->where(fn ($w) => $w->where('ud.dni', 'like', $like)
                ->orWhereRaw("CONCAT(COALESCE(ud.names,''),' ',COALESCE(ud.lastname,'')) LIKE ?", [$like]))
            ->orderBy('ud.names')->limit($limite)
            ->get(['ud.user_id', 'ud.names', 'ud.lastname', 'ud.dni'])
            ->map(fn ($r) => ['user_id' => (int) $r->user_id, 'cedula' => (string) $r->dni, 'nombre' => trim($r->names . ' ' . $r->lastname)])
            ->all();
    }
}
