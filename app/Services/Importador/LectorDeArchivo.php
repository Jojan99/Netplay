<?php

namespace App\Services\Importador;

/**
 * Lee la exportación de clientes que baja la empresa de su plataforma anterior.
 *
 * CSV (con coma, punto y coma o tabulador, en UTF-8 o Latin-1) y XLSX. El XLSX
 * se lee a mano —es un zip con XML adentro— porque en el servidor no está
 * instalada PhpSpreadsheet y no se quiere agregar una dependencia en
 * producción. El .xls viejo (binario de Excel 97) no se puede leer así: se le
 * pide a la empresa que lo guarde como .xlsx o .csv.
 */
class LectorDeArchivo
{
    /** Tope de filas: más que esto no es una exportación de clientes. */
    public const MAX_FILAS = 20000;

    /**
     * @return array{columnas: string[], filas: array<int, array<int,string>>}
     */
    public static function leer(string $ruta, string $nombreOriginal): array
    {
        $ext = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));

        $tabla = match ($ext) {
            'xlsx'        => self::leerXlsx($ruta),
            'csv', 'txt'  => self::leerCsv($ruta),
            'xls'         => throw new \RuntimeException('El archivo .xls (Excel 97-2003) no se puede leer. Abrilo en Excel y guárdelo como .xlsx o .csv.'),
            default       => throw new \RuntimeException('Formato no admitido. Suba el archivo en .xlsx o .csv.'),
        };

        return self::separarEncabezado($tabla);
    }

    /**
     * La primera fila con al menos dos celdas llenas es el encabezado: algunas
     * exportaciones traen un título arriba.
     *
     * @param  array<int, array<int,string>> $tabla
     * @return array{columnas: string[], filas: array<int, array<int,string>>}
     */
    private static function separarEncabezado(array $tabla): array
    {
        $inicio = null;

        foreach ($tabla as $i => $fila) {
            if (count(array_filter($fila, fn ($c) => trim((string) $c) !== '')) >= 2) {
                $inicio = $i;
                break;
            }
        }

        if ($inicio === null) {
            throw new \RuntimeException('El archivo está vacío o no tiene encabezados.');
        }

        $columnas = array_map(fn ($c) => trim((string) $c), $tabla[$inicio]);
        $ancho = count($columnas);
        $filas = [];

        foreach (array_slice($tabla, $inicio + 1) as $fila) {
            if (!array_filter($fila, fn ($c) => trim((string) $c) !== '')) {
                continue;
            }

            $fila = array_slice(array_pad($fila, $ancho, ''), 0, $ancho);
            $filas[] = array_map(fn ($c) => trim((string) $c), $fila);

            if (count($filas) > self::MAX_FILAS) {
                throw new \RuntimeException('El archivo tiene más de ' . self::MAX_FILAS . ' filas.');
            }
        }

        return ['columnas' => $columnas, 'filas' => $filas];
    }

    /** @return array<int, array<int,string>> */
    private static function leerCsv(string $ruta): array
    {
        $contenido = file_get_contents($ruta);

        if ($contenido === false) {
            throw new \RuntimeException('No se pudo leer el archivo.');
        }

        // BOM de UTF-8 que agrega Excel.
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);

        if (!mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
        }

        $primera = strtok($contenido, "\n") ?: '';
        $separador = ',';
        $max = -1;

        foreach ([',', ';', "\t", '|'] as $s) {
            $n = substr_count($primera, $s);
            if ($n > $max) {
                $max = $n;
                $separador = $s;
            }
        }

        $h = fopen('php://temp', 'r+');
        fwrite($h, $contenido);
        rewind($h);

        $tabla = [];
        while (($fila = fgetcsv($h, 0, $separador, '"', '')) !== false) {
            if ($fila === [null]) {
                continue;
            }
            $tabla[] = array_map(fn ($c) => (string) $c, $fila);

            if (count($tabla) > self::MAX_FILAS + 50) {
                break;
            }
        }
        fclose($h);

        return $tabla;
    }

    /** @return array<int, array<int,string>> */
    private static function leerXlsx(string $ruta): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('El servidor no puede abrir archivos .xlsx. Subilo como .csv.');
        }

        $zip = new \ZipArchive();

        if ($zip->open($ruta) !== true) {
            throw new \RuntimeException('El archivo .xlsx está dañado o no es un Excel.');
        }

        try {
            $compartidos = self::textosCompartidos($zip->getFromName('xl/sharedStrings.xml') ?: '');
            $hoja = self::primeraHoja($zip);
            $xml = $zip->getFromName($hoja);

            if ($xml === false) {
                throw new \RuntimeException('El Excel no tiene hojas.');
            }

            return self::celdas($xml, $compartidos);
        } finally {
            $zip->close();
        }
    }

    /** La ruta de la primera hoja según el libro (no siempre es sheet1.xml). */
    private static function primeraHoja(\ZipArchive $zip): string
    {
        $libro = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($libro && $rels) {
            $l = self::xml($libro);
            $r = self::xml($rels);

            if ($l && $r) {
                $l->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $hojas = $l->xpath('//m:sheets/m:sheet');
                $rid = $hojas ? (string) ($hojas[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '') : '';

                foreach ($r->children() as $rel) {
                    if ((string) $rel['Id'] === $rid) {
                        $destino = ltrim((string) $rel['Target'], '/');
                        return str_starts_with($destino, 'xl/') ? $destino : 'xl/' . $destino;
                    }
                }
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    /** @return string[] */
    private static function textosCompartidos(string $xml): array
    {
        if ($xml === '') {
            return [];
        }

        $sx = self::xml($xml);
        if (!$sx) {
            return [];
        }

        $textos = [];
        foreach ($sx->si as $si) {
            // Texto simple (<t>) o con formato por tramos (<r><t>).
            $t = isset($si->t) ? (string) $si->t : '';
            foreach ($si->r as $r) {
                $t .= (string) $r->t;
            }
            $textos[] = $t;
        }

        return $textos;
    }

    /**
     * @param  string[] $compartidos
     * @return array<int, array<int,string>>
     */
    private static function celdas(string $xml, array $compartidos): array
    {
        $lector = new \XMLReader();
        $lector->XML($xml, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT);

        $tabla = [];
        $fila = null;
        $col = 0;
        $tipo = '';
        $valor = '';

        while ($lector->read()) {
            if ($lector->nodeType === \XMLReader::ELEMENT) {
                switch ($lector->localName) {
                    case 'row':
                        $fila = [];
                        $col = 0;
                        if ($lector->isEmptyElement) {
                            $tabla[] = [];
                            $fila = null;
                        }
                        break;
                    case 'c':
                        $ref = (string) $lector->getAttribute('r');
                        $col = $ref !== '' ? self::indiceColumna($ref) : $col;
                        $tipo = (string) $lector->getAttribute('t');
                        $valor = '';
                        if ($lector->isEmptyElement && $fila !== null) {
                            $fila[$col] = '';
                            $col++;
                        }
                        break;
                    case 'v':
                    case 't':
                        $valor .= $lector->readString();
                        break;
                }
            } elseif ($lector->nodeType === \XMLReader::END_ELEMENT) {
                if ($lector->localName === 'c' && $fila !== null) {
                    $fila[$col] = match ($tipo) {
                        's'     => $compartidos[(int) $valor] ?? '',
                        'b'     => $valor === '1' ? 'TRUE' : 'FALSE',
                        default => self::numeroSinCola($valor),
                    };
                    $col++;
                } elseif ($lector->localName === 'row' && $fila !== null) {
                    $max = $fila ? max(array_keys($fila)) : -1;
                    $completa = [];
                    for ($i = 0; $i <= $max; $i++) {
                        $completa[] = $fila[$i] ?? '';
                    }
                    $tabla[] = $completa;
                    $fila = null;

                    if (count($tabla) > self::MAX_FILAS + 50) {
                        break;
                    }
                }
            }
        }

        $lector->close();

        return $tabla;
    }

    /** "1098765432.0" o "1.098765432E9" → "1098765432": las cédulas llegan como número. */
    private static function numeroSinCola(string $v): string
    {
        if (is_numeric($v) && preg_match('/[eE.]/', $v)) {
            $f = (float) $v;
            if (floor($f) === $f && abs($f) < 1e15) {
                return number_format($f, 0, '', '');
            }
        }

        return $v;
    }

    /** "AB12" → 27 (base 0). */
    private static function indiceColumna(string $ref): int
    {
        $letras = preg_replace('/\d/', '', strtoupper($ref));
        $n = 0;
        foreach (str_split($letras) as $l) {
            $n = $n * 26 + (ord($l) - 64);
        }

        return max(0, $n - 1);
    }

    private static function xml(string $contenido): ?\SimpleXMLElement
    {
        $previo = libxml_use_internal_errors(true);
        $sx = simplexml_load_string($contenido, \SimpleXMLElement::class, LIBXML_NONET);
        libxml_use_internal_errors($previo);

        return $sx ?: null;
    }
}
