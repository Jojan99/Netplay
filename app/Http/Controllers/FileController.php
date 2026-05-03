<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FileController extends Controller
{

    public function listFiles()
    {
        if (sessionUserHasProfile('CONTADOR', 'ADMIN')) {
            $filePath = storage_path('archiveZip');

            $files = scandir($filePath);


            $files = array_filter($files, function ($file) {
                return !in_array($file, ['.', '..', '.gitignore']);
            });

            return response()->json(['files' => array_values($files)]);
        } else {
            echo "No tienes permiso para esta accion";
        }
    }


    public function downloadFiles($name)
    {
        $archivo = $name;
        $nombreArchivo = basename($archivo);
        $rutaArchivo = storage_path('archiveZip/' . $nombreArchivo);

        if (file_exists($rutaArchivo)) {
            // Descargar el archivo con el nombre original
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
            readfile($rutaArchivo);
        } else {
            // Mostrar un mensaje de error si el archivo no existe
            echo "El archivo no existe.";
        }
    }
}
