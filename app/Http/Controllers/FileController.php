<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FileController extends Controller
{

    public function listFiles()
    {
        if (sessionUserHasProfile('CONTADOR', 'ADMIN')) {
            // Carpeta por empresa: antes todas las empresas veían los mismos archivos
            $filePath = $this->carpetaDeLaEmpresa();

            $files = is_dir($filePath) ? scandir($filePath) : [];


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
        // Mismo permiso que el listado: antes cualquier operador podía bajarlos.
        if (!sessionUserHasProfile('CONTADOR', 'ADMIN')) {
            return response()->json(['message' => 'No tienes permiso para esta acción', 'error' => 1], 403);
        }

        $archivo = $name;
        $nombreArchivo = basename($archivo);
        $rutaArchivo = $this->carpetaDeLaEmpresa() . '/' . $nombreArchivo;

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

    /** storage/archiveZip/{companyId} */
    private function carpetaDeLaEmpresa(): string
    {
        return storage_path('archiveZip/' . (int) getSessionCompanyId());
    }
}
