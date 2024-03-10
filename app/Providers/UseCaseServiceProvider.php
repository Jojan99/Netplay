<?php

namespace App\Providers;

use App\IManagerConection\IManagerConection\IManagerConection;
use App\ManagerConection\ManagerConection;
use App\UseCases\User\GetUserUseCase;
use App\UseCases\User\UpdateUserDataUseCase;
use App\UseCases\Oauth\Interfaces\SignInUseCaseInterface;
use App\UseCases\Oauth\SignInUseCase;
use App\UseCases\RequestPurchaseAll\AproveedInvestmentRequestUseCase;
use App\UseCases\User\CreateUserDataUseCase;
use App\UseCases\User\CreateWalletUseCase;
use App\UseCases\User\GetDataHomeUseCase;
use App\UseCases\User\GetUserAllUseCase;
use App\UseCases\User\GetUserByIdUseCase;
use App\UseCases\User\GetUserIdAllByUseCase;
use App\UseCases\User\GetUserIdAllUseCase;
use App\UseCases\User\GetUserProfileUseCase;
use App\UseCases\GeneratePdf\GeneratePdfUseCase;
use App\UseCases\User\Interfaces\CreateUserDataUseCaseInterface;
use App\UseCases\User\Interfaces\CreateWalletUseCaseInterface;
use App\UseCases\User\Interfaces\GetDataHomeUseCaseInterface;
use App\UseCases\User\Interfaces\GetUserAllUseCaseInterface;
use App\UseCases\User\Interfaces\UpdateUserDataUseCaseInterface;
use App\UseCases\User\Interfaces\GetUserByIdUseCaseInterface;
use App\UseCases\User\Interfaces\GetUserIdAllByUseCaseInterface;
use App\UseCases\User\Interfaces\GetUserIdAllUseCaseInterface;
use App\UseCases\User\Interfaces\GetUserProfileUseCaseInterface;
use App\UseCases\User\Interfaces\GetUserUseCaseInterface;
use App\UseCases\User\Interfaces\NetworkGoldeUseCaseInterface;
use App\UseCases\User\Interfaces\ValidateTokenWalletUseCaseInterface;
use App\UseCases\GeneratePdf\Interfaces\GeneratePdfUseCaseInterface;
use App\UseCases\User\NetworkGoldeUseCase;
use App\UseCases\User\ValidateTokenWalletUseCase;
use Illuminate\Support\ServiceProvider;
use App\UseCases\Facturation\CreateDetFacturationUseCase;
use App\UseCases\Facturation\Interfaces\CreateDetFacturationUseCaseInterface;
use App\UseCases\GeneratePdf\GeneratePdfByIdUseCase;
use App\UseCases\GeneratePdf\Interfaces\GeneratePdfByIdUseCaseInterface;
use App\UseCases\User\DeleteUserDatabyIdUseCase;
use App\UseCases\User\Interfaces\DeleteUserDatabyIdUseCaseInterface;
use App\UseCases\Facturation\GetDateFacturePendingUseCase;
use App\UseCases\Facturation\Interfaces\GetDateFacturePendingUseCaseInterface;
use App\UseCases\Gender\Interfaces\GetGenderUseCaseInterface;
use App\UseCases\Gender\GetGenderUseCase;
use App\UseCases\InfoInternet\GetInternetPlanUseCase;
use App\UseCases\InfoInternet\Interfaces\GetInternetPlanUseCaseInterface;
use App\UseCases\Location\LocationUseCase;
use App\UseCases\Location\Interfaces\LocationUseCaseInterface;
use App\UseCases\Dni\GetDniUseCase;
use App\UseCases\Dni\Interfaces\GetDniUseCaseInterface;
use App\UseCases\Facturation\Interfaces\GetDataInfoPenddingFactureUseCaseInterface;
use App\UseCases\Facturation\GetDataInfoPenddingFactureUseCase;
/**
 * Clase proveedora de casos de usos
 *
 * @package App\Providers
 * @author Network Golden <sa.networkgolden@gmail.com>
 * @copyright 2022/06/9
 */
class UseCaseServiceProvider extends ServiceProvider
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
      
        [GetUserUseCaseInterface::class, GetUserUseCase::class],
        [CreateUserDataUseCaseInterface::class, CreateUserDataUseCase::class],
        [GetGenderUseCaseInterface::class, GetGenderUseCase::class],
        [GetDniUseCaseInterface::class, GetDniUseCase::class],
        [GetDataInfoPenddingFactureUseCaseInterface::class, GetDataInfoPenddingFactureUseCase::class],
     
        [GetUserByIdUseCaseInterface::class, GetUserByIdUseCase::class],
    
        [GetUserAllUseCaseInterface::class, GetUserAllUseCase::class],
        [updateUserDataUseCaseInterface::class, updateUserDataUseCase::class],
        [GeneratePdfUseCaseInterface::class, GeneratePdfUseCase::class],
        [CreateDetFacturationUseCaseInterface::class, CreateDetFacturationUseCase::class],
        [GeneratePdfByIdUseCaseInterface::class, GeneratePdfByIdUseCase::class],
        [DeleteUserDatabyIdUseCaseInterface::class, DeleteUserDatabyIdUseCase::class],
        [GetDateFacturePendingUseCaseInterface::class, GetDateFacturePendingUseCase::class],
        [GetGenderUseCaseInterface::class, GetGenderUseCase::class],
        [GetInternetPlanUseCaseInterface::class, GetInternetPlanUseCase::class],
        [LocationUseCaseInterface::class, LocationUseCase::class],
        [GetDniUseCaseInterface::class, GetDniUseCase::class],
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
