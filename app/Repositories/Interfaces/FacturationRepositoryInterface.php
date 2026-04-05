<?php

namespace App\Repositories\Interfaces;

use App\Http\Requests\Facturation\CreateFacturationRequest;
use App\Http\Requests\Facturation\CreatePaidFacturationRequest;
use App\Http\Requests\Facturation\GetDateFacturePendingnRequest;

/**
 * Clase interfaz encargada de administrar el repositorio de usuarios
 *
 * @package App\Repositories\Interfaces
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/9
 */
interface FacturationRepositoryInterface
{
  /**
   * Método encargado de obtener los datos de un usuario por medio del nombre
   * de usuario
   *
     /**
   * @param string $id_user
   * @return mixed
   */
  public function getCabUserFacturation(string $id_user): mixed;

  /**
   * @param CreateFacturationRequest $data
   * @return mixed
   */
  public function createDetFacturation(CreateFacturationRequest $data): mixed;


    /**
   * @param CreateFacturationRequest $data
   * @return mixed
   */
  public function updateDetFacturation(CreateFacturationRequest $data): mixed;
  /**
   * @param string $id_user
   * @return mixed
   */
  public function createCabFacturation(string $id_user, int $groud,string $fecha): mixed;

  /**
   * Método encargado de obtener los datos de un usuario por medio del nombre
   * de usuario
   *
   * @param string $id_user
   * @return mixed
   */
  public function getPricePlan(string $id_user): mixed;

   /**
     * Método encargado de obtener los datos de un usuario por medio del nombre
     * de usuario
     *
     /**
     * @param string $id_user
     * @return mixed
     */
    public function getDataInfoPenddingFacture() : mixed;

  /**
   * Método encargado de obtener los datos de un usuario por medio del nombre
   * de usuario
   *
     /**
   * @param string $id_user
   * @return mixed
   */
  public function getDateLast(string $id_user): mixed;

  /**
   * Método encargado de obtener los datos de un usuario por medio del nombre
   * de usuario
   *
   * @param string $id_user
   * @return mixed
   */
  public function getDateFacturePending(GetDateFacturePendingnRequest $data): mixed;

  public function getDatePayFacture(GetDateFacturePendingnRequest $data): mixed;

  public function getuserFacture1($getuserFacture1): mixed;

  public function createpaidFacturation(CreatePaidFacturationRequest $data): mixed;

  public function createAboneFacturation(CreatePaidFacturationRequest $data): mixed;

  public function getDateFacturePendingById($det_id): mixed;

  public function getConsecutiveFacture();

  public function getConsecutiveFacture1();

  public function getuserFactureCreate($periodo): mixed;

  // ── Finance v2 ─────────────────────────────────────────────────────────
  public function getClientsPaginated(?string $search, int $page, int $perPage): object;
  public function getClientInvoices(int $cabId): array;
  public function payInvoice(int $detId, string $clientName): bool;
  public function abonarInvoice(int $detId, float $amount, string $clientName): bool;
  public function updateInvoice(int $detId, array $data): bool;
  public function exportPayments(?string $from, ?string $to, ?int $cabId): array;
  public function getPaymentLogsPaginated(?string $search, ?string $from, ?string $to, int $page, int $perPage): object;

}
