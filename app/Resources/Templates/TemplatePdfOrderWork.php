<?php

namespace App\Resources\Templates;

use Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options;

class TemplatePdfOrderWork
{
  
  public function PdfOrderWork($user): mixed
  {

    $nombre = $user[0]->user_names .' '. $user[0]->user_lastname;
    $telefono = $user[0]->phone;
    $cedula = $user[0]->cedula;
    $direccion = $user[0]->address;
    $fecha = $user[0]->date;
    $orden = $user[0]->id;
    $nombreTecnico = $user[0]->tech_names .' '. $user[0]->tech_lastname;
    $estado = $user[0]->status;
    $servicio = $user[0]->service;
    $observacion = $user[0]->observation;
    $prioridad = $user[0]->prioritys;


    $logoPath = realpath(__DIR__ . "/../../../resources/img/NET-PLAY-LOGO-Mesa-de-trabajo-1.jpg");

    $imagenBase64 = "data:image/png;base64," . base64_encode(file_get_contents($logoPath));

    $html = '
        <!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: Arial, sans-serif;
            color: #2c2c39;
        }

        .header {
            text-align: center;
            margin-bottom: 20px;
        }

        .header h1 {
            margin: 0;
            color: #2c2c39;
        }

        .header img {
            max-height: 100px;
        }

        .section {
            border: 2px solid #52c0db;
            margin-bottom: 20px;
            padding: 10px;
            border-radius: 5px;
        }

        .section-title {
            background-color: #52c0db;
            color: white;
            padding: 5px;
            font-weight: bold;
            border-radius: 3px 3px 0 0;
        }

        .section-content {
            padding: 10px;
        }

        .section-content p {
            margin: 5px 0;
        }

        .section-content strong {
            font-weight: bold;
        }

        .ticket-info {
            display: flex;
            justify-content: space-between;
        }

        .ticket-info div {
            width: 48%;
        }
    </style>
    <title>Ticket de Soporte</title>
</head>
<body>
    <div class="header">
           <div class="logo"><img src="' . $imagenBase64 . '" alt="Logo"></div>
        <h1>SOLUCIONES NETPLAY S.A.S.</h1>
        <p>NIT 901911441 - 2</p>
        <h3 style="color: #52c0db;">Nro. '.$orden.'</h3>
    </div>

    <!-- Sección de Datos del Cliente -->
    <div class="section">
        <div class="section-title">DATOS DEL CLIENTE</div>
        <div class="section-content">
            <p><strong>NOMBRE:</strong>'.$nombre.'</p>
            <p><strong>CELULAR:</strong>'.$telefono.'</p>
            <p><strong>DIRECCION:</strong> '.$direccion.'</p>
            <p><strong>BARRIO:</strong> SOLEDAD</p>
        </div>
    </div>

    <!-- Sección de Ticket de Soporte -->
    <div class="section">
        <div class="section-title">TICKET DE SOPORTE</div>
        <div class="section-content">
            <div class="ticket-info">
                <div>
                    <p><strong>Fecha:</strong> '.$fecha.'</p>
                    <p><strong>Nro Ticket:</strong> '.$orden.'</p>
                    <p><strong>Tipo de Servicio:</strong> '.$servicio.'</p>
                </div>
                <div>
                    <p><strong>Técnico Asociado:</strong>'.$nombreTecnico.'</p>
                    <p><strong>Estado:</strong> '.$estado.'</p>
                    <p><strong>Prioridad:</strong> '.$prioridad.'</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Sección de Reporte del Cliente -->
    <div class="section">
        <div class="section-title">REPORTE DEL CLIENTE</div>
        <div class="section-content">
            <p><strong>'.$observacion.'</strong></p>
        </div>
    </div>
</body>
</html>
';
    return $html;
  }
}