<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use phpseclib3\Net\SSH2;
use Exception;

class OltController extends Controller
{
       /**
     * Conecta al servidor de socket persistente para obtener el nombre o información de la OLT.
     */
    public function getOltName(Request $request)
    {
        $socketHost = '10.11.104.2'; // Suponiendo que el servidor de socket se ejecuta en el mismo host
        $socketPort = 9000;
        
        // Conecta al servidor de socket
        $socket = fsockopen($socketHost, $socketPort, $errno, $errstr, 30);
        if (!$socket) {
            return response()->json([
                'status'  => 'error',
                'message' => "Error al conectar con el servidor de socket: $errstr ($errno)"
            ], 500);
        }
        
        // Envía el comando deseado a la OLT
        $command = "display device manuinfo\n"; // Ajusta el comando según lo necesario
        fwrite($socket, $command);
        
        // Lee la respuesta
        $result = '';
        while (!feof($socket)) {
            $result .= fgets($socket, 1024);
        }
        fclose($socket);
        
        return response()->json([
            'status'   => 'success',
            'olt_info' => $result,
        ]);
    }
}
