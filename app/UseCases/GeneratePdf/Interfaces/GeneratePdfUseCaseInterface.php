<?php

namespace App\UseCases\GeneratePdf\Interfaces;
/**
 *
 * @package App\UseCases\GeneratePdf\Interfaces
 * @author NetPlay <atencionalcliente@netplay.com.co
 * @copyright 2023/09/29
 */
interface GeneratePdfUseCaseInterface
{
    /**
     * Método encargado de generar pdf masivo .zip
     * @return mixed
     */
    public function generatePdf($Periodo): mixed;

}
