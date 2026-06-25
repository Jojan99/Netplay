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
     * Método encargado de generar y enviar facturas masivamente.
     *
     * @param mixed $Periodo
     * @param int $companyId
     * @param int $billingDay
     * @param string $sendChannel 'whatsapp' | 'email' | 'both'
     * @return mixed
     */
    public function generatePdf($Periodo, int $companyId = 0, int $billingDay = 0, string $sendChannel = 'whatsapp', ?int $emailDailyLimit = null): mixed;

}
