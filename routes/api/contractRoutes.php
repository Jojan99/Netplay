<?php

use App\Http\Controllers\ContractController;
use App\Http\Controllers\ContractSignController;
use Illuminate\Support\Facades\Route;

// Firma por token — sin JWT (el cliente accede desde el link)
Route::post('/contracts/sign-token/{token}', [ContractSignController::class, 'sign']);

// Contrato firmado y fotos de la cédula. Ya no se sirven en /storage: van por
// enlace con firma temporal (panel, WhatsApp) o por el token del contrato.
Route::get('/contracts/archivo/{clientContract}/{tipo}', [ContractSignController::class, 'archivo'])
    ->whereNumber('clientContract')
    ->whereIn('tipo', \App\Support\ArchivosContrato::TIPOS)
    ->middleware('signed:relative')
    ->name('contratos.archivo');
Route::get('/contracts/archivo-token/{token}/{tipo}', [ContractSignController::class, 'archivoPorToken'])
    ->whereIn('tipo', [...\App\Support\ArchivosContrato::TIPOS, 'preview', 'logo']);

// Hojas del contrato dibujadas del PDF real, protegidas por el token del link.
Route::get('/contracts/hoja-token/{token}/{pagina}', [ContractSignController::class, 'hojaPorToken'])
    ->whereNumber('pagina');

// Cara del documento (sin JWT: lo llama la página de firma). Con límite de
// peticiones porque decodifica y analiza una imagen en cada llamada.
Route::post('/contracts/detect-document-side', [ContractSignController::class, 'detectDocumentSide'])
    ->middleware('throttle:20,1');

Route::prefix('contracts')->middleware(['jwt.verify', 'module:contratos'])->group(function () {

    // Parametrización: catálogo de variables y vista previa con datos reales.
    // Van antes de /{id} para que 'variables' no se lea como un id.
    Route::get('/variables',      [ContractController::class, 'variables']);
    Route::post('/vista-previa',  [ContractController::class, 'vistaPrevia']);
    Route::get('/guia/{nombre}',  [ContractController::class, 'guiaPdf']);

    // Plantillas de contrato (ADMIN / CONTADOR)
    Route::get('/',              [ContractController::class, 'index']);
    Route::get('/{id}',          [ContractController::class, 'show'])->whereNumber('id');
    Route::post('/',             [ContractController::class, 'store']);
    Route::put('/{id}',          [ContractController::class, 'update']);
    Route::delete('/{id}',       [ContractController::class, 'destroy']);

    // Asignación y gestión de contratos por cliente
    Route::post('/assign',                              [ContractController::class, 'assign']);
    Route::get('/client/{userId}',                      [ContractController::class, 'clientContracts']);
    Route::get('/client-contract/{clientContractId}',   [ContractController::class, 'clientContractById']);
    Route::delete('/client-contract/{clientContractId}',[ContractController::class, 'deleteClientContract']);
    Route::post('/sign/{clientContractId}',             [ContractController::class, 'sign']);
    Route::get('/pdf/{clientContractId}',               [ContractController::class, 'pdf']);

    // Envío de link
    Route::post('/send-email/{clientContractId}',       [ContractController::class, 'sendEmail']);
    Route::post('/send-whatsapp/{clientContractId}',    [ContractController::class, 'sendWhatsApp']);

    // Documentos de identidad
    Route::post('/client-contract/{clientContractId}/documents', [ContractController::class, 'uploadDocument']);
    Route::get('/client-contract/{clientContractId}/documents',    [ContractController::class, 'getDocuments']);

    // Subir PDF y convertir a HTML
    Route::post('/upload-pdf',                          [ContractController::class, 'uploadPdf']);

    // Subir PDF base (fondo exacto del contrato)
    Route::post('/{id}/pdf-base',                       [ContractController::class, 'uploadPdfBase']);

    // Logo de plantilla
    Route::post('/{id}/logo',                           [ContractController::class, 'uploadLogo']);

    // Campos / coordenadas sobre PDF base
    Route::get('/{id}/pdf-base-archivo',                 [ContractController::class, 'pdfBaseArchivo']);
    Route::post('/{id}/pdf-prueba',                      [ContractController::class, 'pdfPrueba']);
    Route::get('/{id}/hoja/{pagina}',                    [ContractController::class, 'hojaPlantilla'])->whereNumber('pagina');
    Route::get('/{id}/pdf-fields',                      [ContractController::class, 'getPdfFields']);
    Route::post('/{id}/pdf-fields',                      [ContractController::class, 'savePdfFields']);
    Route::get('/{id}/pdf-dimensions',                   [ContractController::class, 'getPdfDimensions']);
    Route::get('/{id}/pdf-preview',                      [ContractController::class, 'pdfPreview']);
});
