<?php

namespace App\Repositories\Interfaces;


/**
 * Clase interfaz encargada de administrar el repositorio de usuarios
 *
 * @package App\Repositories\Interfaces
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/9
 */
interface GeneratePdfRepositoryInterface{

    /**
     * @param int $sponsor_id
     * @return mixed
     */
    public function generatePdf($id): mixed;

 /**
     * @param int $sponsor_id
     * @return mixed
     */
    public function generatePdfRemember($id): mixed;

       /**
     * @param int $user_id
     * @return mixed
     */
    public function generatePdfById($user_id): mixed;


       /**
     * @return mixed
     */
    public function getperiodeNotificationRemenber(): mixed;

    public function getUserPeriode1($Periodo): mixed;

    /**
     * @return mixed
     */
    public function getSaldoAnt($Cab,$numberFactura): mixed;

    /**
     * @return mixed
     */
    public function getPaySaldoAnt($Cab,$numberFactura): mixed;

        /**
     * @return mixed
     */
    public function getTicketInProgressAll($id): mixed;

}