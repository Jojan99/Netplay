<?php

namespace App\Repositories\Interfaces;

use App\Http\Requests\Search\SearchRequest;

/**
 * Clase interfaz encargada de administrar el repositorio de usuarios
 *
 * @package App\Repositories\Interfaces
 * @author Netplay <sa.networkgolden@gmail.com>
 * @copyright 2023/06/9
 */
interface SearchRepositoryInterface
{

    /**
     * @return mixed
     */
    public function getSearchUseCase(SearchRequest $searchRequest): mixed;

        /**
     * @return mixed
     */
    public function SearchFinancesPaid(SearchRequest $searchRequest): mixed;
}
