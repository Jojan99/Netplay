<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SnmpController extends Controller
{
    public function getCpuStatusSnmpnew()
    {
        $host = '10.11.104.2'; // Dirección del host
        $community = 'public321'; // Comunidad SNMP
    
        // OID de nombres y otros OIDs
        $oidNombres = '1.3.6.1.4.1.2011.6.128.1.1.2.43.1.9';
        $oidPuerto = '1.3.6.1.4.1.2011.6.128.1.1.2.47.1.10'; // OID para el puerto
    
        // Nuevos OIDs adicionales
        $oidEquipoNombre = '1.3.6.1.4.1.2011.6.128.1.1.2.101.1.5'; // Nombre del equipo
        $serial = '1.3.6.1.4.1.2011.6.128.1.1.2.43.1.3'; // Nombre del equipo
        $oidHora = '1.3.6.1.4.1.2011.6.128.1.1.2.101.1.6'; // Hora
        $oidDbProfile = '1.3.6.1.4.1.2011.6.128.1.1.2.142.1.2'; // DB Profile
    
        // OIDs ya definidos previamente
        $oids = [
            '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.15', // Estado
            '1.3.6.1.4.1.2011.6.128.1.1.2.43.1.3',  // Serial
            '1.3.6.1.4.1.2011.6.128.1.1.2.101.1.5'
        ];
    
        try {
            // Conexión SNMP utilizando snmp2_walk
            $snmpOutputNombres = @snmp2_walk($host, $community, $oidNombres);
            if ($snmpOutputNombres === false) {
                $error = error_get_last();
                error_log('Error en snmp2_walk para OID Nombres: ' . print_r($error, true));
                return response()->json(['error' => 'Error al obtener los nombres desde SNMP']);
            }
    
            // Si no se obtienen datos
            if (empty($snmpOutputNombres)) {
                error_log('No se encontraron resultados para los nombres en SNMP');
                return response()->json(['error' => 'No se encontraron resultados para los nombres en SNMP']);
            }
    
            // Procesar nombres
            $nombres = $this->procesarNombres($snmpOutputNombres);
    
            $snmpOutputPuertos = @snmp2_real_walk($host, $community, $oidPuerto);
            if ($snmpOutputPuertos === false || empty($snmpOutputPuertos)) {
                $error = error_get_last();
                error_log('Error en snmp2_walk para OID Puertos: ' . print_r($error, true));
                return response()->json(['error' => 'Error al obtener los puertos desde SNMP']);
            }
            $puertos = $this->procesarPuertos($snmpOutputPuertos);
    
            // Procesar otros OIDs y combinar la información
            $ontInfoCombinada = [];
            foreach ($oids as $oid) {
                $snmpOutput = @snmp2_walk($host, $community, $oid);
                if ($snmpOutput === false) {
                    $error = error_get_last();
                    error_log("Error al obtener OID $oid: " . print_r($error, true));
                    continue; // Ignorar si no se obtiene información para este OID
                }
    
                // Si no se obtienen datos
                if (empty($snmpOutput)) {
                    error_log("No se encontraron resultados para OID $oid");
                    continue;
                }
    
                $ontInfo = $this->parseSnmpOutput($snmpOutput);
                foreach ($ontInfo as $key => $value) {
                    if (!isset($ontInfoCombinada[$key])) {
                        $ontInfoCombinada[$key] = [];
                    }
                    if (is_array($value)) {
                        $ontInfoCombinada[$key] = array_merge($ontInfoCombinada[$key], $value);
                    } else {
                        $ontInfoCombinada[$key][] = $value;
                    }
                }
            }
    
            // Obtener datos adicionales
            $nombreEquipo = @snmp2_real_walk($host, $community, $oidEquipoNombre);
            $serialprueba = @snmp2_walk($host, $community, $serial);
            
            $hola = $this->parseSnmpOutput1($serialprueba);

            $nombreEquipoProcesado = $this->procesarNombresEquipo($nombreEquipo);
            
           
           
            $responseData = [];
            $contadorEquipos = 0;
            $contadorEquiposOffline = 0;
            // Combinar los nombres con la información recopilada y renombrar claves
            foreach ($nombres as $index => $nombre) {
                if (isset($ontInfoCombinada[$index])) {
    
                    $snValue = $ontInfoCombinada[$index][1] ?? '';
    
                    // Asegurar que la cadena sea tratada como Latin-1 (ISO-8859-1)
                    $snValue = mb_convert_encoding($snValue, 'ISO-8859-1', 'UTF-8');
            
                    // Convertir cada carácter de la cadena a hexadecimal
                    $hexadecimal = '';
                    for ($i = 0; $i < strlen($snValue); $i++) {
                        $hexadecimal .= strtoupper(sprintf('%02X', ord($snValue[$i])));
                    }
                    $nombreEquipo = isset($nombreEquipoProcesado[$index]) ? $nombreEquipoProcesado[$index]['nombreEquipo'] : 'N/A';
                    $horaEquipo = isset($hola[$index]) ? $hola[$index] : 'N/A';
    
                    // Agregar al arreglo de respuesta la información combinada
                    $responseData[] = [
                        // 'RX Value' => $ontInfoCombinada[$index][0] ?? null,
                        'Status' => (string)$ontInfoCombinada[$index][0] ?? null,
                        'SN' => $hexadecimal ?? null,
                        'name' => $nombre,
                        'port' => $puertos[$index] ?? 'N/A',
                        'EquipoNombre' => isset($nombreEquipo) ? $nombreEquipo : 'N/A',
                    ];

                    if($ontInfoCombinada[$index][0] != 1){
                        $contadorEquiposOffline++;
                    }

                    $contadorEquipos++;
                }
            }

            $response = [
                'data' => $responseData,
                'total_equipos' => $contadorEquipos, // Mostrar la cantidad de equipos procesados
                'total_equiposOffline' => $contadorEquiposOffline // Mostrar la cantidad de equipos procesados
            ];
    
            return standardApiReponse(
                "EXITOSO",
                $response,
                "400",
                JsonResponse::HTTP_OK
            );
    
        } catch (\Exception $e) {
            error_log('Error al ejecutar SNMP: ' . $e->getMessage());
            return response()->json(['error' => 'Error al ejecutar SNMP: ' . $e->getMessage()]);
        }
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
        $host = '10.11.104.2'; // Dirección del host
        $community = 'public321'; // Comunidad SNMP
    
        // OID de nombres y OID del serial
        $oidPuerto = '1.3.6.1.4.1.2011.6.128.1.1.2.48.1.4'; // OID para el puerto
        $oidSerial = '1.3.6.1.4.1.2011.6.128.1.1.2.48.1.2'; // OID para el serial
    
        try {
            // Conexión SNMP utilizando snmp2_walk para obtener puertos
            $snmpOutputPuertos = @snmp2_real_walk($host, $community, $oidPuerto);
    
            if ($snmpOutputPuertos === false || empty($snmpOutputPuertos)) {
                $error = error_get_last();
                error_log('Error en snmp2_walk para OID Puertos: ' . print_r($error, true));
                return response()->json(['error' => 'Error al obtener los puertos desde SNMP']);
            }
        error_log(">>>>>>>>>>>>>>>><<<");

            $puertos = $this->procesarPuertos($snmpOutputPuertos);
    
            error_log(">>>>>>>>>>>>>>>><<<1");
            // Obtener el serial (SN)
            $snmpOutputSerial = @snmp2_walk($host, $community, $oidSerial);
            error_log(">>>>>>>>>>>>>>>><<<2");
            error_log(json_encode($snmpOutputSerial));

            if ($snmpOutputSerial === false || empty($snmpOutputSerial)) {
                $error = error_get_last();
                error_log('Error en snmp2_walk para OID Serial: ' . print_r($error, true));
                return response()->json(['error' => 'Error al obtener el serial desde SNMP']);
            }
    
            // Procesar serial
            $ontInfoCombinada = $this->parseSnmpOutput($snmpOutputSerial);
    
            // Combinar los nombres con la información recopilada y renombrar claves
            $responseData = [];
            foreach ($puertos as $index => $nombre) {
                if (isset($ontInfoCombinada[$index])) {
                    $snValue = $ontInfoCombinada[$index][0] ?? '';
    
                    // Asegurar que la cadena sea tratada como Latin-1 (ISO-8859-1)
                    $snValue = mb_convert_encoding($snValue, 'ISO-8859-1', 'UTF-8');
    
                    // Convertir cada carácter de la cadena a hexadecimal
                    $hexadecimal = '';
                    for ($i = 0; $i < strlen($snValue); $i++) {
                        $hexadecimal .= strtoupper(sprintf('%02X', ord($snValue[$i])));
                    }
    
                    $responseData[] = [
                        'SN' => $hexadecimal ?? null,
                        'port' => $puertos[$index] ?? 'N/A',
                    ];
                }
            }
    
            return response()->json($responseData);
    
        } catch (\Exception $e) {
            error_log('Error al ejecutar SNMP: ' . $e->getMessage());
            return response()->json(['error' => 'Error al ejecutar SNMP: ' . $e->getMessage()]);
        }
    }
    
    
    

}