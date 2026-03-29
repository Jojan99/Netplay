<?php

namespace App\UseCases\GeneratePdf\Interfaces;
/**
 *
 * @package App\UseCases\GeneratePdf\Interfaces
 * @author NetPlay <atencionalcliente@netplay.com.co
 * @copyright 2023/09/29
 */
interface GeneratePdfByIdFacturesUseCaseInterface
{
    /**
     * Método encargado de generar pdf masivo .zip
     * @return mixed
     */
    public function generatePdfByIdFacture($user_id): mixed;
}
