<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Repositories\Interfaces\{
    ConversationRepositoryInterface,
    CrmMediaRepositoryInterface,
    DniRepositoryInterface,
    GenderRepositoryInterface,
    UserRepositoryInterface,
    GeneratePdfRepositoryInterface,
    FacturationRepositoryInterface,
    InternetInfoRepositoryInterface,
    LocationRepositoryInterface,
    SearchRepositoryInterface,
    RouterRepositoryInterface,
    ManagementRouterRepositoryInterface,
    EgresosRepositoryInterface,
    TicketRepositoryInterface,
};
use App\Repositories\{
    ConversationRepository,
    CrmMediaRepository,
    DniRepository,
    GenderRepository,
    UserRepository,
    GeneratePdfRepository,
    FacturationRepository,
    InternetInfoRepository,
    LocationRepository,
    RouterRepository,
    SearchRepository,
    ManagementRouterRepository,
    EgresosRepository,
    TicketRepository,
};

/**
 * Clase proveedora de repositorios
 *
 * @package App\Providers
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/9
 */
class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Constante de la posición de la constante SERVICES para referenciar a la
     * interfaz
     * 
     * @const int
     */
    const INTERFACE_POSITION = 0;

    /**
     * Constante de la posición de la constante SERVICES para referenciar a la
     * clase
     * 
     * @const int
     */
    const CLASS_POSITION = 1;

    /**
     * Constante de los servicios a los que se le va a proveer
     * 
     * @const array [interfaz, clase]
     */
    const SERVICES = [
        [UserRepositoryInterface::class, UserRepository::class],
        [GenderRepositoryInterface::class, GenderRepository::class],
        [DniRepositoryInterface::class, DniRepository::class],
        [GeneratePdfRepositoryInterface::class, GeneratePdfRepository::class],
        [FacturationRepositoryInterface::class, FacturationRepository::class],
        [InternetInfoRepositoryInterface::class, InternetInfoRepository::class],
        [LocationRepositoryInterface::class, LocationRepository::class],
        [SearchRepositoryInterface::class, SearchRepository::class],
        [RouterRepositoryInterface::class, RouterRepository::class],
        [ManagementRouterRepositoryInterface::class, ManagementRouterRepository::class],
        [EgresosRepositoryInterface::class, EgresosRepository::class],
        [TicketRepositoryInterface::class, TicketRepository::class],
        [ConversationRepositoryInterface::class, ConversationRepository::class],
        [CrmMediaRepositoryInterface::class, CrmMediaRepository::class],

        
    ];

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $services = self::SERVICES;
        array_walk($services, function ($value) {
            $this->app->bind($value[self::INTERFACE_POSITION], $value[self::CLASS_POSITION]);
        });
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
