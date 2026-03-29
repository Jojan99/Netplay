<?php

namespace App\Http\Controllers;

use App\Constants\ApiResponseConstants;
use App\Http\Requests\Gestions\GestionUserRequest;
use App\Http\Requests\Gestions\OltDataRequest;
use App\Managers\Interfaces\SSHConnectionManagerInterface;
use App\Services\SSHConnectionService;
use App\UseCases\ManagementRouter\Interfaces\GetIpAvaliblesUseCaseInterface;
use App\UseCases\ManagementRouter\Interfaces\UpdateStatusUserUseCaseInterface;
use Illuminate\Http\JsonResponse;
use phpseclib3\Exception\ConnectionClosedException;
use phpseclib3\Net\SSH2;
use SSHConnectionManager;
use Tymon\JWTAuth\Exceptions\JWTException;

class ManagementRouterController extends Controller
{
    public function updateStatus(
        UpdateStatusUserUseCaseInterface $updateStatusUserUseCaseInterface,
        GestionUserRequest $gestionUserRequest
    ): object {
        try {
            $result = $updateStatusUserUseCaseInterface->updateStatus($gestionUserRequest);
        } catch (JWTException $e) {
            return standardApiReponse(
                'No se pudieron consultar las tasas de cambio: ' . $e->getMessage(),
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::ERROR,
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return standardApiReponse(
            $result['message'],
            $result['data'],
            $result['status'],
            JsonResponse::HTTP_OK
        );
    }



public function getLanSegments(
        GetIpAvaliblesUseCaseInterface $getIpAvaliblesUseCaseInterface,
        GestionUserRequest $gestionUserRequest
    ): object {
        try {
            $result = $getIpAvaliblesUseCaseInterface->getLanSegments();
        } catch (JWTException $e) {
            return standardApiReponse(
                'No se pudieron consultar las tasas de cambio: ' . $e->getMessage(),
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::ERROR,
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return standardApiReponse(
            $result['message'],
            $result['data'],
            $result['status'],
            JsonResponse::HTTP_OK
        );
    }

    public function getIpAvalibles(
        GetIpAvaliblesUseCaseInterface $getIpAvaliblesUseCaseInterface,
        GestionUserRequest $gestionUserRequest
    ): object {
        try {
            $result = $getIpAvaliblesUseCaseInterface->GetIpAvalibles($gestionUserRequest);
        } catch (JWTException $e) {
            return standardApiReponse(
                'No se pudieron consultar las tasas de cambio: ' . $e->getMessage(),
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::ERROR,
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return standardApiReponse(
            $result['message'],
            $result['data'],
            $result['status'],
            JsonResponse::HTTP_OK
        );
    }

       public function autorizarServicio(
        GetIpAvaliblesUseCaseInterface $getIpAvaliblesUseCaseInterface,
        GestionUserRequest $gestionUserRequest
    ): object {
        try {
            $result = $getIpAvaliblesUseCaseInterface->autorizarServicio($gestionUserRequest);
        } catch (JWTException $e) {
            return standardApiReponse(
                'No se pudieron consultar las tasas de cambio: ' . $e->getMessage(),
                ApiResponseConstants::DATA_NULL,
                ApiResponseConstants::ERROR,
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return standardApiReponse(
            $result['message'],
            $result['data'],
            $result['status'],
            JsonResponse::HTTP_OK
        );
    }

    public function getCpuStatus()
    {
        $host = '181.48.150.43';     // Reemplazar con la IP correcta
        $port = 22;                // Puerto SSH, por defecto 22
        $username = 'root';        // Reemplazar con el usuario SSH
        $password = 'admin';       // Reemplazar con la contraseña SSH

        // Crear la instancia SSH
        $ssh = new SSH2($host, $port);

        // Autenticarse
        if (!$ssh->login($username, $password)) {
            return response()->json(['error' => 'Autenticación fallida'], 500);
        }

        // Enviar los comandos sin esperar demasiado tiempo entre ellos
        $ssh->write("scroll 512\n");
        $ssh->write("enable\n");
        $ssh->write("config\n");
        $ssh->write("interface gpon 0/0\n");
        $ssh->write("display ont info 0 all\n");

        // Leer toda la salida de una vez
        $output = $ssh->read();

        // Limpiar la salida eliminando caracteres no imprimibles
        $output = preg_replace('/\s+/', ' ', trim($output));

        // Buscar las coincidencias con expresiones regulares
        $matches = [];
        preg_match_all('/(\d+\/\s*\d+\/\d+)\s+(\d+)\s+([a-zA-Z0-9]+)\s+(\w+)\s+(\w+)\s+(\w+)\s+(\w+)/', $output, $matches);

        // Verificar si encontramos las coincidencias
        if (empty($matches[2]) || empty($matches[3])) {
            return response()->json(['error' => 'No se encontraron coincidencias en la salida del comando'], 500);
        }

        // Asignar los valores de las coincidencias
        $FS_P = isset($matches[1]) ? $matches[1] : [];
        $ontId = isset($matches[2]) ? $matches[2] : [];
        $sn = isset($matches[3]) ? $matches[3] : [];
        $controlFlag = isset($matches[4]) ? $matches[4] : [];
        $runState = isset($matches[5]) ? $matches[5] : [];
        $configState = isset($matches[6]) ? $matches[6] : [];
        $matchState = isset($matches[7]) ? $matches[7] : [];

        // Crear el array de detalles de los ONTs
        $ontDetails = [];
        for ($i = 0; $i < count($ontId); $i++) {
            $ontDetails[] = [
                'FS_P' => $FS_P[$i] ?? '',
                'ontId' => (int)$ontId[$i],
                'sn' => $sn[$i] ?? '',
                'controlFlag' => $controlFlag[$i] ?? '',
                'runState' => $runState[$i] ?? '',
                'configState' => $configState[$i] ?? '',
                'matchState' => $matchState[$i] ?? '',
                'protectState' => 'no', // No hay esta información en la salida, puedes ajustarlo según tus necesidades
            ];
        }

        // Crear la respuesta con la información procesada
        $response = [
            'ontDetails' => $ontDetails,
            'summary' => [
                'inPort' => '0/0/0', // Información de la salida
                'totalOnts' => count($ontDetails),
                'onlineOnts' => count(array_filter($runState, fn($state) => $state === 'online'))
            ]
        ];

        return response()->json($response);
    }

    public function getOntStatusAll(
        SSHConnectionManagerInterface $sSHConnectionManagerInterface,
    )
    {
        $ssh = $sSHConnectionManagerInterface->getSSH();

        // Autenticarse
        $ssh->setTimeout(2.5); 

        // Enviar los comandos
        $ssh->write("scroll 512\n");
        $ssh->write("enable\n");
        $ssh->write("scroll 512\n");

        // Activar el modo MMI
        // $ssh->write("mmi-mode enable\n"); // Agregar el comando aquí

        $ssh->write("scroll 512\n");
        $ssh->write("display ont info 0 all\n");
        $ssh->write("scroll 512\n");

        // Leer la salida
        $output = $ssh->read();

        error_log($output);
    
        // Limpiar la salida eliminando caracteres no imprimibles
        $output = preg_replace('/\s+/', ' ', trim($output));
    
        // Expresión regular para obtener los detalles de los ONTs (Primera tabla)
        $matches = [];
        preg_match_all('/(\d+\/\s*\d+\/\d+)\s+(\d+)\s+([a-zA-Z0-9]+)\s+(\w+)\s+(\w+)\s+(\w+)\s+(\w+)/', $output, $matches);
    
        // Expresión regular para obtener las descripciones (Segunda tabla)
        $descMatches = [];
        preg_match_all('/(\d+\/\s*\d+\/\d+)\s+(\d+)\s+(\w+)/', $output, $descMatches);
    
        // Asignar los valores de las coincidencias para la primera tabla
        $FS_P = isset($matches[1]) ? $matches[1] : [];
        $ontId = isset($matches[2]) ? $matches[2] : [];
        $sn = isset($matches[3]) ? $matches[3] : [];
        $controlFlag = isset($matches[4]) ? $matches[4] : [];
        $runState = isset($matches[5]) ? $matches[5] : [];
        $configState = isset($matches[6]) ? $matches[6] : [];
        $matchState = isset($matches[7]) ? $matches[7] : [];
    
        // Asignar los valores de las coincidencias para la segunda tabla (descripciones)
        $desc_FS_P = isset($descMatches[1]) ? $descMatches[1] : [];
        $desc_ontId = isset($descMatches[2]) ? $descMatches[2] : [];
        $ontDescription = isset($descMatches[3]) ? $descMatches[3] : [];
    
        // Crear un array para almacenar las descripciones asociadas al `ontId`
        $ontDescriptionsMap = [];
        for ($i = 0; $i < count($desc_ontId); $i++) {
            // Crear una clave combinada de FS_P y ontId
            $key = $desc_FS_P[$i] . '-' . $desc_ontId[$i];  // Usamos una cadena única combinando FS_P y ontId
            $ontDescriptionsMap[$key] = $ontDescription[$i] ?? ''; // Asignamos la descripción, si no existe, vacía
        }
    
        // Crear el array de detalles de los ONTs (Primera tabla)
        $ontDetails = [];
        for ($i = 0; $i < count($ontId); $i++) {
            // Crear la clave combinada de FS_P y ontId
            $key = $FS_P[$i] . '-' . $ontId[$i];
    
            // Asociar la descripción del ONT usando la clave combinada
            $description = isset($ontDescriptionsMap[$key]) ? $ontDescriptionsMap[$key] : '';
    
            $ontDetails[] = [
                'FS_P' => preg_replace('/\s+/', '', $FS_P[$i] ?? ''),
                'ontId' => (int)$ontId[$i],
                'sn' => $sn[$i] ?? '',
                'controlFlag' => $controlFlag[$i] ?? '',
                'runState' => $runState[$i] ?? '',
                'configState' => $configState[$i] ?? '',
                'matchState' => $matchState[$i] ?? '',
                'protectState' => 'no', // Ajustar si es necesario
                'description' => $description // Agregar la descripción aquí
            ];
        }
        $ssh->write("scroll 512\n");
    
        // Contar ONTs en línea
        $onlineOnts = count(array_filter($runState, fn($state) => $state === 'online'));
    
        // Crear la respuesta con dos objetos, uno para cada tabla
        $response = [
            'ontDetails' => [
                'table' => 'ONT Status',
                'data' => $ontDetails
            ],
            'summary' => [
                'totalOnts' => count($ontDetails),
                'onlineOnts' => $onlineOnts
            ]
        ];
        return response()->json($response);
    }



  public function obtenerInformacionSNMP()
    {
        $host = '181.48.150.43'; // Dirección del host
        $community = 'public'; // Comunidad SNMP

        // OID de nombres y otros OIDs
        $oidNombres = '1.3.6.1.4.1.2011.6.128.1.1.2.43.1.9';
        $oids = [
            '1.3.6.1.4.1.2011.6.128.1.1.2.51.1.4',  // RX
            '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.15', // Estado
            '1.3.6.1.4.1.2011.6.128.1.1.2.43.1.3'   // Serial
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

            // Depuración: mostrar resultados crudos de snmp2_walk
            error_log('snmpOutputNombres: ' . print_r($snmpOutputNombres, true));

            // Procesar nombres
            $nombres = $this->procesarNombres($snmpOutputNombres);

            // Depuración: mostrar el array de nombres procesado
            error_log('Nombres procesados: ' . print_r($nombres, true));

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

                // Depuración: mostrar resultados crudos de snmp2_walk
                error_log("Resultado SNMP para OID $oid: " . print_r($snmpOutput, true));

                $ontInfo = $this->parseSnmpOutput($snmpOutput);
                foreach ($ontInfo as $key => $value) {
                    // Asegurarnos de que el valor en ontInfoCombinada[$key] sea un array
                    if (!isset($ontInfoCombinada[$key])) {
                        $ontInfoCombinada[$key] = [];
                    }
                    // Mezclar los valores asegurándonos de que ambos sean arrays
                    if (is_array($value)) {
                        $ontInfoCombinada[$key] = array_merge($ontInfoCombinada[$key], $value);
                    } else {
                        $ontInfoCombinada[$key][] = $value; // Si no es un array, agregar como nuevo valor
                    }
                }
            }

            // Depuración: mostrar la información combinada
            error_log('Información combinada: ' . print_r($ontInfoCombinada, true));

            // Combinar los nombres con la información recopilada
            foreach ($nombres as $key => $nombre) {
                if (isset($ontInfoCombinada[$key])) {
                    $ontInfoCombinada[$key]['name'] = $nombre;
                } else {
                    $ontInfoCombinada[$key] = ['name' => $nombre];
                }
            }

            return response()->json($ontInfoCombinada);

        } catch (\Exception $e) {
            error_log('Error al ejecutar SNMP: ' . $e->getMessage());
            return response()->json(['error' => 'Error al ejecutar SNMP: ' . $e->getMessage()]);
        }
    }

    // Función para procesar los nombres, convirtiendo el array recibido en un formato adecuado
    private function procesarNombres($snmpOutput)
    {
        $nombres = [];
        foreach ($snmpOutput as $valor) {
            if (is_string($valor)) {
                $nombres[] = $valor;  // Si es un string, lo agregamos al array de nombres
            } else {
                error_log("Valor no esperado en el OID de nombres: " . print_r($valor, true));
            }
        }
        return $nombres;
    }

    // Función para procesar la salida de SNMP en función del tipo de dato
    private function parseSnmpOutput($snmpOutput)
    {
        $parsedData = [];
        foreach ($snmpOutput as $key => $value) {
            if (is_array($value)) {
                // Si el valor es un array de valores (por ejemplo, INTEGER o Hex-STRING)
                foreach ($value as $v) {
                    if (is_integer($v)) {
                        $parsedData[$key][] = $v;
                    } elseif (is_string($v) && strpos($v, 'Hex-STRING') !== false) {
                        $parsedData[$key][] = $this->convertHexToString($v); // Convertir Hex-STRING
                    }
                }
            } else {
                // Si es un valor único
                $parsedData[$key] = $value;
            }
        }
        return $parsedData;
    }

    // Función para convertir un Hex-STRING a un string legible
    private function convertHexToString($hex)
    {
        $hex = str_replace(' ', '', $hex);
        $str = '';
        for ($i = 0; $i < strlen($hex); $i += 2) {
            $str .= chr(hexdec($hex[$i] . $hex[$i + 1]));
        }
        return $str;
    }
    

    public function getOntPort()
    {
        $host = '181.48.150.43';     // Reemplazar con la IP correcta
        $port = 22;                // Puerto SSH, por defecto 22
        $username = 'root';        // Reemplazar con el usuario SSH
        $password = 'admin';    // Reemplazar con la contraseña SSH
    
        // Crear la instancia SSH
        $ssh = new SSH2($host, $port);
        $ssh->setTimeout(2.5); 
    
        try {
            // Autenticarse
            if (!$ssh->login($username, $password)) {
                return response()->json(['error' => 'Autenticación fallida'], 500);
            }
    
            $maxAttempts = 5; // Número máximo de intentos
            $attempt = 0;
            $ontDetails = [];
    
            while ($attempt < $maxAttempts) {
                $attempt++;
                error_log("Intento #" . $attempt);
    
                // Enviar los comandos
                $ssh->write("scroll 512\n");
                $ssh->write("enable\n");
                $ssh->write("display ont autofind all\n");
    
                // Leer la salida completa
                $output = '';
                while ($buffer = $ssh->read()) {
                    $output .= $buffer;
                    if (strpos($buffer, '---- More ----') !== false) {
                        $ssh->write(" "); // Continuar si hay más salida
                    } else {
                        break;
                    }
                }
    
                // Limpiar la salida eliminando caracteres no imprimibles
                $output = preg_replace('/\s+/', ' ', trim($output));
    
                // Buscar coincidencias con expresiones regulares
                $matches = [];
                preg_match_all('/Number\s*:\s*(\d+)\s*F\/S\/P\s*:\s*([0-9\/]+)\s*Ont SN\s*:\s*([a-zA-Z0-9]+)\s*\(([a-zA-Z0-9\-]+)\)\s*.*?Ont SoftwareVersion\s*:\s*([a-zA-Z0-9\.]+)\s*Ont EquipmentID\s*:\s*(\w+)/', $output, $matches);
    
                // Verificar si encontramos las coincidencias
                if (!empty($matches[1])) {
                    // Crear el array de detalles de los ONTs
                    for ($i = 0; $i < count($matches[1]); $i++) {
                        $ontDetails[] = [
                            'number' => $matches[1][$i] ?? '',
                            'F_S_P' => $matches[2][$i] ?? '',
                            'ontSN' => $matches[3][$i] ?? '',
                            'vendorID' => $matches[4][$i] ?? '',
                            'softwareVersion' => $matches[5][$i] ?? '',
                            'equipmentID' => $matches[6][$i] ?? '',
                        ];
                    }
                    break; // Salir del bucle si se encontraron coincidencias
                }
            }
    
            if (empty($ontDetails)) {
                return response()->json(['error' => 'No se encontraron coincidencias en la salida del comando'], 500);
            }
    
            // Crear la respuesta con la información procesada
            $response = [
                'ontDetails' => $ontDetails,
                'onlineOntsAutori' => count($ontDetails)
            ];
    
            // Cerrar la conexión SSH
            $ssh->disconnect();
    
            return response()->json($response);
    
        } catch (ConnectionClosedException $e) {
            // Manejar la excepción si la conexión se cierra
            $ssh->disconnect(); // Cerrar la conexión en caso de excepción
            return response()->json(['error' => 'Conexión cerrada por el servidor: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            // Manejar cualquier otra excepción
            return response()->json(['error' => 'Error al procesar la solicitud: ' . $e->getMessage()], 500);
        }
    }

    public function registerOnt(OltDataRequest $oltDataRequest) {
        $portPosition = $oltDataRequest['port_potition'];
        $parts = explode('/', $portPosition);
        $lastPart = end($parts);
    
        $host = '10.11.104.2';     // Reemplazar con la IP correcta
        $port = 22;                // Puerto SSH, por defecto 22
        $username = 'root';        // Reemplazar con el usuario SSH
        $password = 'admin';    // Reemplazar con la contraseña SSH
    
        // Crear la instancia SSH
        $ssh = new SSH2($host, $port);
      
        $ssh->setTimeout(5); 
    
        // Autenticarse
        if (!$ssh->login($username, $password)) {
            return response()->json(['error' => 'Autenticación fallida'], 500);
        }
    
        // Enviar los comandos para registrar el ONT
        $ssh->write("enable\n");
        $ssh->write("enable\n");
        $ssh->write("config\n");
        $ssh->write("interface gpon 0/0\n");
        $ssh->write("ont confirm $lastPart sn-auth {$oltDataRequest['serial']} omci ont-lineprofile-id 10 ont-srvprofile-id 10 desc {$oltDataRequest['descripcion']}\n");
    
        // Leer la salida del registro
        $output = $ssh->read();
        error_log("Salida del comando: " . $output);
    
        // Capturar el mensaje "Number of ONTs that can be added: 1, success: 1."
        $matches = [];
        preg_match('/Number of ONTs that can be added: \d+, success: \d+\./', $output, $matches);
        
        $ontMessage = isset($matches[0]) ? $matches[0] : 'No se pudo capturar el mensaje de ONT';

        // Capturar PortID y ONTID usando una expresión regular
        $portOntMatches = [];
        preg_match('/PortID :(\d+), ONTID :(\d+)/', $output, $portOntMatches);
    
        if (count($portOntMatches) > 0) {
            $portId = isset($portOntMatches[1]) ? intval($portOntMatches[1]) : null;
            $ontId = isset($portOntMatches[2]) ? intval($portOntMatches[2]) : null;
    
            // Ejecutar el siguiente comando si se ha registrado el ONT correctamente
            $ssh->write("quit\n");
            $ssh->write("service-port 20000 vlan 200 gpon 0/0/{$portId} ont {$ontId} gemport 1 multi-service\n");
            $ssh->write("\n");
    
            // Leer la salida final
            $output = $ssh->read();
            error_log("Comando de servicio ejecutado: " . $output);
    
            // Responder con los valores capturados y el mensaje completo del ONT
            return response()->json([
                'portId' => $portId,
                'ontId'  => $ontId,
                'ontMessage' => $ontMessage,
                'message' => 'ONT registered successfully'
            ]);
        } else {
            // Si no se puede capturar PortID y ONTID
            return response()->json(['error' => 'No se pudo capturar el PortID y ONTID o la respuesta no es válida'], 500);
        }
    }
    
    public function deleteontOnt(

        OltDataRequest $oltDataRequest
    ) {
      
        $host = '181.48.150.43';     // Reemplazar con la IP correcta
        $port = 22;                // Puerto SSH, por defecto 22
        $username = 'root';        // Reemplazar con el usuario SSH
        $password = 'admin';    // Reemplazar con la contraseña SSH
    
        // Crear la instancia SSH
        $ssh = new SSH2($host, $port);
    
        $ssh->setTimeout(3); 

        // Autenticarse
        if (!$ssh->login($username, $password)) {
            return response()->json(['error' => 'Autenticación fallida'], 500);
        }
    
        // Enviar los comandos
        $ssh->write("enable\n");
        $ssh->write("enable\n");
        $ssh->write("config\n");
        $ssh->write("undo service-port port {$oltDataRequest['port_potition']} ont {$oltDataRequest['ont']}\n");
        $ssh->write("\n");
        $ssh->write("yes\n");
        $ssh->write("interface gpon 0/0\n");

        $portPosition = $oltDataRequest['port_potition'];
        $parts = explode('/', $portPosition);
        $lastPart = end($parts);
        $ssh->write("ont delete $lastPart {$oltDataRequest['ont']}\n");

        $output = $ssh->read();

        error_log($output);

            return response()->json($output);
     
    }
    
    
}
