<?php

namespace App\Providers;



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
        [SignInUseCaseInterface::class, SignInUseCase::class],
        [CreateTransactionUseCaseInterface::class, CreateTransactionUseCase::class],
        [InfoTransactionUseCaseInterface::class, InfoTransactionUseCase::class],
        [CreateWithdrawalUseCaseInterface::class, CreateWithdrawalUseCase::class],
        [infoWithdrawalUseCaseInterface::class, infoWithdrawalUseCase::class],
        [GetPriceCryptoCurrenciesUseCaseInterface::class, GetPriceCryptoCurrenciesUseCase::class],
        [GetUserUseCaseInterface::class, GetUserUseCase::class],
        [CreateUserDataUseCaseInterface::class, CreateUserDataUseCase::class],
        [GetGenderUseCaseInterface::class, GetGenderUseCase::class],
        [GetDniUseCaseInterface::class, GetDniUseCase::class],
        [GetCountryUseCaseInterface::class, GetCountryUseCase::class],
        [GetUserProfileUseCaseInterface::class, GetUserProfileUseCase::class],
        [SendMailUseCaseInterface::class, SendMailUseCase::class],
        [ValidateTokenUseCaseInterface::class, ValidateTokenUseCase::class],
        [CreateWalletUseCaseInterface::class, CreateWalletUseCase::class],
        [ValidateTokenWalletUseCaseInterface::class, ValidateTokenWalletUseCase::class],
        [GetNotificationUseCaseInterface::class, GetNotificationUseCase::class],
        [SeeAllNotificationsUseCaseInterface::class, SeeAllNotificationsUseCase::class],
        [UpdateViewedNotificationUseCaseInterface::class, UpdateViewedNotificationUseCaseI::class],
        [CreateDocumentVerificationRequestUseCaseInterface::class, CreateDocumentVerificationRequestUseCase::class],
        [GetRequestDocumentByUseIdUseCaseInterface::class, GetRequestDocumentByUseIdUseCase::class],
        [GetRequestDocumentAllUseCaseInterface::class, GetRequestDocumentAllUseCase::class],
        [GetDocumentRequestIdUseCaseInterface::class, GetDocumentRequestIdUseCase::class],
        [VerificationDocumenUseCaseInterface::class, VerificationDocumenUseCase::class],
        [GetUserByIdUseCaseInterface::class, GetUserByIdUseCase::class],
        [UpdateCabRequestUseCaseInterface::class, UpdateCabRequestUseCase::class],
        [NetworkGoldeUseCaseInterface::class, NetworkGoldeUseCase::class],
        [RequestPurchaseAllUseCaseInterface::class, RequestPurchaseAllUseCase::class],
        [RequestPurchaseIdUseCaseInterface::class, RequestPurchaseIdUseCase::class],
        [HistoryRequestPurchaseUseCaseInterface::class, HistoryRequestPurchaseUseCase::class],
        [GetUserIdAllUseCaseInterface::class, GetUserIdAllUseCase::class],
        [GetUserIdAllByUseCaseInterface::class, GetUserIdAllByUseCase::class],
        [GetDataHomeUseCaseInterface::class, GetDataHomeUseCase::class],
        [DenyInvestmentRequestUseCaseInterface::class, DenyInvestmentRequestUseCase::class],
        [RequestWithdrawalsUseCaseInterface::class, RequestWithdrawalsUseCase::class],
        [RequestWithdrawalsUseIdCaseInterface::class, RequestWithdrawalsIdUseCase::class],
        [CreateConvertCurrencieUseCaseInterface::class, CreateConvertCurrencieUseCase::class],
        [GetWithdrawlsByIdUseCaseInterface::class, GetWithdrawlsByIdUseCase::class],
        [GetWhithdrawlsRequestUseCaseInterface::class, GetWhithdrawlsRequestUseCase::class],
        [DenyWhithdrawalsRequestUseCaseInterface::class, DenyWhithdrawalsRequestUseCase::class],
        [AproveedWithdrawlsRequestUseCaseInterface::class, AproveedWithdrawlsRequestUseCase::class],
        [AproveedInvestmentRequestUseCaseInterface::class, AproveedInvestmentRequestUseCase::class],
        [GetUserAllUseCaseInterface::class, GetUserAllUseCase::class],
        [updateUserDataUseCaseInterface::class, updateUserDataUseCase::class],
        [GeneratePdfUseCaseInterface::class, GeneratePdfUseCase::class],
        [CreateDetFacturationUseCaseInterface::class, CreateDetFacturationUseCase::class],
        [GeneratePdfByIdUseCaseInterface::class, GeneratePdfByIdUseCase::class],
        [DeleteUserDatabyIdUseCaseInterface::class, DeleteUserDatabyIdUseCase::class],
        [GetDateFacturePendingUseCaseInterface::class, GetDateFacturePendingUseCase::class],

        
        
        
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
