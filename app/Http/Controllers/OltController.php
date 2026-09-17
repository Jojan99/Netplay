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
        // Deshabilitada: hablaba con el socket de la OLT de Netplay (IP fija en el
        // código), sin importar la empresa. Las consultas de OLT van por olt/{oltId}.
        return response()->json(['error' => 'Función deshabilitada'], 410);
    }
}
