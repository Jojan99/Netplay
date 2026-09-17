<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SnmpController extends Controller
{
    public function getCpuStatusSnmpnew()
    {
        // Deshabilitada: consultaba por SNMP la OLT de Netplay con IP y comunidad fijas en el
        // código, sin importar la empresa. Las consultas de OLT van por olt/{oltId}.
        return response()->json(['error' => 'Función deshabilitada'], 410);
    }
    

    private function procesarNombresEquipo($snmpOutput) {
        $nombresEquipo = [];
    
        // Recorrer el array SNMP
        foreach ($snmpOutput as $indice => $valor) {
            // Solo almacenar valores que no estén vacíos
            if (strpos($valor, 'STRING') !== false && trim($valor) !== 'STRING: ""') {
                // Obtener el número único completo (antes del primer punto final) del índice
                preg_match('/\.(\d+\.\d+\.\d+)$/', $indice, $matches);
                if (!empty($matches[1])) {
                    $numero = $matches[1]; // Ejemplo: 4194304000.0.0
                    
                    // Limpiar el valor para quitar el 'STRING: ' y comillas dobles
                    $nombreEquipo = str_replace(['STRING: ', '"'], '', trim($valor));
                    
                    // Agregar al array con el formato limpio
                    $nombresEquipo[] = [
                        'nombreEquipo' => $nombreEquipo
                    ];
                }
            }
        }
    
        return $nombresEquipo;
    }

// Procesar las horas correspondientes
private function procesarHoras($snmpOutput) {
    $nombresEquipo = [];

    // Recorrer el array SNMP
    foreach ($snmpOutput as $indice => $valor) {
        // Solo almacenar valores que no estén vacíos
        if (strpos($valor, 'STRING') !== false && trim($valor) !== 'STRING: ""') {
            // Obtener el número único completo (antes del primer punto final) del índice
            preg_match('/\.(\d+\.\d+\.\d+)$/', $indice, $matches);
            if (!empty($matches[1])) {
                $numero = $matches[1]; // Ejemplo: 4194304000.0.0
                
                // Limpiar el valor para quitar el 'STRING: ' y comillas dobles
                $nombreEquipo = str_replace(['STRING: ', '"'], '', trim($valor));
                
                // Agregar al array con el formato limpio
                $nombresEquipo[] = [
                    'procesarHora' => $nombreEquipo
                ];
            }
        }
    }

    return $nombresEquipo;
}

private function procesarDbProfile($snmpOutput) {
    $nombresEquipo = [];

    // Recorrer el array SNMP
    foreach ($snmpOutput as $indice => $valor) {
        // Solo almacenar valores que no estén vacíos
        if (strpos($valor, 'STRING') !== false && trim($valor) !== 'STRING: ""') {
            // Obtener el número único completo (antes del primer punto final) del índice
            preg_match('/\.(\d+\.\d+\.\d+)$/', $indice, $matches);
            if (!empty($matches[1])) {
                $numero = $matches[1]; // Ejemplo: 4194304000.0.0
                
                // Limpiar el valor para quitar el 'STRING: ' y comillas dobles
                $nombreEquipo = str_replace(['STRING: ', '"'], '', trim($valor));
                
                // Agregar al array con el formato limpio
                $nombresEquipo[] = [
                    'nombreDbProfile' => $nombreEquipo
                ];
            }
        }
    }
    
    return $nombresEquipo;
}


    private function procesarPuertos($snmpOutput)
    {

        // error_log(json_encode($snmpOutput)); // Log inicial para depuración.
        $puertos = [];
        

        foreach ($snmpOutput as $oid => $valor) {
            if (is_string($valor) && strpos($valor, 'INTEGER') !== false) {
                // Extraemos las partes del OID para obtener puerto y posición ONT.
                $partesOid = explode('.', $oid);
                $posOnt = array_pop($partesOid); // Penúltimo número antes del último punto.
                $puerto = array_pop($partesOid); // Último número después del punto.


                // error_log($puerto);
                // error_log($posOnt);

                switch ($puerto) {
                    case 4194304000:
                        $puerto = 0;
                        break;
                    case 4194304256:
                        $puerto = 1;
                        break;
                    case 4194304512:
                        $puerto = 2;
                        break;
                    case 4194304768:
                        $puerto = 3;
                        break;
                    case 4194305024:
                        $puerto = 4;
                        break;
                    case 4194305280:
                        $puerto = 5;
                        break;
                    case 4194305536:
                        $puerto = 6;
                        break;
                    case 4194305792:
                        $puerto = 7;
                        break;
                    case 4194306048:
                        $puerto = 8;
                        break;
                    case 4194306304:
                        $puerto = 9;
                        break;
                    case 4194306560:
                        $puerto = 10;
                        break;
                    case 4194306816:
                        $puerto = 11;
                        break;
                    case 4194307072:
                        $puerto = 12;
                        break;
                    case 4194307328:
                        $puerto = 13;
                        break;
                    case 4194307584:
                        $puerto = 14;
                        break;
                    case 4194307840:
                        $puerto = 15;
                        break;
                    default:
                        $puerto = -1; // Si no se encuentra en la lista, asignamos un valor por defecto.
                        break;
                }

                // Agregamos los datos al resultado.
                $puertos[] = [
                    'slot' => (string)$puerto,
                    'position_ont' => (string)$posOnt,
                ];

            } else {
                error_log("Valor inesperado en el OID de puertos: " . print_r($valor, true));
            }
        }
    
        return $puertos;
    }

    function convertir_a_hexadecimal($cadena) {
        // Dividir la cadena en un array de caracteres y convertir cada uno a su valor hexadecimal
        $hexadecimal = implode('', array_map(function($c) {
            return strtoupper(sprintf('%02X', ord($c)));
        }, str_split($cadena)));
    
        return $hexadecimal;
    }


    private function procesarNombres($snmpOutput)
    {
        $nombres = [];
        foreach ($snmpOutput as $valor) {
            // Aquí puedes extraer los nombres de forma correcta
            if (is_string($valor) && strpos($valor, 'STRING') !== false) {
                // Extraer el nombre entre las comillas
                preg_match('/STRING: "(.*?)"/', $valor, $matches);
                if (isset($matches[1])) {
                    $nombres[] = $matches[1];
                }
            } else {
                error_log("Valor no esperado en el OID de nombres: " . print_r($valor, true));
            }
        }
        return $nombres;
    }

    private function parseSnmpOutput1($snmpOutput)
    {
    
        $parsedData = [];
        foreach ($snmpOutput as $key => $value) {
            // Comprobar si el valor contiene "Hex-STRING"
            if (is_string($value)) {
                if (strpos($value, 'Hex-STRING') !== false) {
                    // Extraer el valor hexadecimal y convertirlo
                    $hexString = trim(explode(': ', $value)[1]);
                    $parsedData[$key][] = $this->convertHexToString($hexString);
                } elseif (strpos($value, 'STRING') !== false) {
                    // Limpiar el valor de STRING: y las comillas
                    $stringValue = trim(explode(': ', $value)[1]); // Eliminar el prefijo 'STRING: '
                    $stringValue = trim($stringValue, '"'); // Eliminar las comillas
                    $parsedData[$key][] = $stringValue;
                } else {
                    // Si no es Hex-STRING ni STRING, lo dejamos tal cual
                    $parsedData[$key][] = $value;
                }
            }
        }
        error_log(json_encode($parsedData));
    
        return $parsedData;
    }

    private function parseSnmpOutput($snmpOutput)
    {
        $parsedData = [];
        foreach ($snmpOutput as $key => $value) {
            // Comprobar si el valor contiene "INTEGER" o "Hex-STRING"
            if (is_string($value)) {
                if (strpos($value, 'INTEGER') !== false) {
                    $parsedData[$key][] = (int) trim(explode(': ', $value)[1]);
                } elseif (strpos($value, 'Hex-STRING') !== false) {
                    $parsedData[$key][] = $this->convertHexToString(trim(explode(': ', $value)[1]));
                } elseif (strpos($value, 'STRING') !== false) {
                    // Limpiar el valor de STRING: y las comillas
                    $stringValue = trim(explode(': ', $value)[1]); // Eliminar el prefijo 'STRING: '
                    $stringValue = trim($stringValue, '"'); // Eliminar las comillas
                    $parsedData[$key][] = $stringValue;
                } else {
                    // Si no es Hex-STRING ni STRING, lo dejamos tal cual
                    $parsedData[$key][] = $value;
                }
            }
        }
        return $parsedData;
    }

    private function convertHexToString($hex)
    {
        $hex = str_replace(' ', '', $hex);
        $str = '';
        for ($i = 0; $i < strlen($hex); $i += 2) {
            // Convertir cada par de caracteres hexadecimales a su valor ASCII correspondiente
            $str .= chr(hexdec($hex[$i] . $hex[$i + 1]));
        }
        
        // Intentar forzar la codificación a UTF-8 para evitar problemas de caracteres mal formateados
        return mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1');
    }
    

 

    public function getOntAutoFind()
    {
        // Deshabilitada: consultaba por SNMP la OLT de Netplay con IP y comunidad fijas en el
        // código, sin importar la empresa. Las consultas de OLT van por olt/{oltId}.
        return response()->json(['error' => 'Función deshabilitada'], 410);
    }
    
    
    

}