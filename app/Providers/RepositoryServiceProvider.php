<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Repositories\Interfaces\{
    CabDocumentVerificationRequestRepositoryInterface,
    CountryRepositoryInterface,
    DniRepositoryInterface,
    EmailUserSendInterface,
    GenderRepositoryInterface,
    NotificationRepositoryInterface,
    RequestPurchaseRepositoryInterface,
    RequestWithdrawalsRepositoryInterface,
    TypeCurrencisRepositoryInterface,
    UserRepositoryInterface,
    GeneratePdfRepositoryInterface
};
use App\Repositories\{
    CabDocumentVerificationRequestRepository,
    CountryRepository,
    DniRepository,
    EmailUserSendRepository,
    GenderRepository,
    NotificationRepository,
    RequestPurchaseRepository,
    RequestWithdrawalsRepository,
    TypeCurrencisRepository,
    UserRepository,
    GeneratePdfRepository
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
        [TypeCurrencisRepositoryInterface::class, TypeCurrencisRepository::class],
        [RequestPurchaseRepositoryInterface::class, RequestPurchaseRepository::class],
        [NotificationRepositoryInterface::class, NotificationRepository::class],
        [GenderRepositoryInterface::class, GenderRepository::class],
        [CountryRepositoryInterface::class, CountryRepository::class],
        [DniRepositoryInterface::class, DniRepository::class],
        [EmailUserSendInterface::class, EmailUserSendRepository::class],
        [CabDocumentVerificationRequestRepositoryInterface::class, CabDocumentVerificationRequestRepository::class],
        [RequestWithdrawalsRepositoryInterface::class, RequestWithdrawalsRepository::class],
        [GeneratePdfRepositoryInterface::class, GeneratePdfRepository::class],
        
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
