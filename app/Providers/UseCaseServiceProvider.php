<?php

namespace App\Providers;

use App\IManagerConection\IManagerConection\IManagerConection;
use App\ManagerConection\ManagerConection;
use App\Repositories\FacturationRepository;
use App\Repositories\Interfaces\FacturationRepositoryInterface;
use App\UseCases\Crm\CloseConversationUseCase;
use App\UseCases\Crm\GetConversationMessagesUseCase as UseCasesCrmGetConversationMessagesUseCase;
use App\UseCases\Crm\GetCrmAgentsUseCase as CrmGetCrmAgentsUseCase;
use App\UseCases\Crm\GetInboxConversationsUseCase as CrmGetInboxConversationsUseCase;
use App\UseCases\Crm\Interfaces\CloseConversationUseCaseInterface;
use App\UseCases\Crm\Interfaces\GetConversationMessagesUseCaseInterface;
use App\UseCases\Crm\Interfaces\GetCrmAgentsUseCaseInterface;
use App\UseCases\Crm\Interfaces\GetInboxConversationsUseCaseInterface as InterfacesGetInboxConversationsUseCaseInterface;
use App\UseCases\Crm\Interfaces\ReceiveConversationMessageUseCaseInterface;
use App\UseCases\Crm\Interfaces\SendConversationMediaUseCaseInterface;
use App\UseCases\Crm\Interfaces\SendMessageUseCaseInterface;
use App\UseCases\Crm\Interfaces\TransferConversationUseCaseInterface;
use App\UseCases\Crm\ReceiveConversationMessageUseCase;
use App\UseCases\Crm\SendConversationMediaUseCase;
use App\UseCases\Crm\SendMessageUseCase;
use App\UseCases\Crm\TransferConversationUseCase as CrmTransferConversationUseCase;
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
use App\UseCases\Facturation\Interfaces\CreatePaidFacturationUseCaseInterface;
use App\UseCases\Facturation\CreatePaidFacturationUseCase;
use App\UseCases\Facturation\Interfaces\CreateAboneFacturationUseCaseInterface;
use App\UseCases\Facturation\CreateAboneFacturationUseCase;
use App\UseCases\InfoInternet\Interfaces\GetDataCorteUseCaseInterface;
use App\UseCases\InfoInternet\GetDataCorteUseCase;
use App\UseCases\InfoInternet\Interfaces\GetIpAllByIdZoneUseCaseInterface;
use App\UseCases\InfoInternet\GetIpAllByIdZoneUseCase;
use App\UseCases\Search\Interfaces\SearchUseCaseInterface;
use App\UseCases\Search\SearchUseCase;
use App\UseCases\Search\Interfaces\SearchFinancesPaidUseCaseInterface;
use App\UseCases\Search\SearchFinancesPaidUseCase;
use App\UseCases\User\GetCountUserUseCase;
use App\UseCases\User\Interfaces\GetCountUserUseCaseInterface;
use App\UseCases\ManagementRouter\Interfaces\UpdateStatusUserUseCaseInterface;
use App\UseCases\ManagementRouter\UpdateStatusUserUseCase;
use App\UseCases\User\Interfaces\GetTotalPriceMonthUseCaseInterface;
use App\UseCases\User\GetTotalPriceMonthUseCase;
use App\UseCases\GeneratePdf\Interfaces\GeneratePdfByIdFacturesUseCaseInterface;
use App\UseCases\GeneratePdf\GeneratePdfByIdFacturesUseCase;
use App\UseCases\GeneratePdf\GeneratePdfReceiptByIdUseCase;
use App\UseCases\GeneratePdf\Interfaces\GeneratePdfReceiptByIdUseCaseInterface;
use App\UseCases\User\GetTotalClientRegisterMonthUseCase;
use App\UseCases\User\Interfaces\GetTotalClientRegisterMonthUseCaseInterface;
use App\UseCases\User\GetTrazaFactureUseCase;
use App\UseCases\User\Interfaces\GetTrazaFactureUseCaseInterface;
use App\UseCases\GeneratePdf\Interfaces\GeneratePayPdfByIdFacturesUseCaseInterface;
use App\UseCases\GeneratePdf\GeneratePayPdfByIdFacturesUseCase;
use App\UseCases\Egresos\Interfaces\CreateEgresosUseCaseInterface;
use App\UseCases\Egresos\CreateEgresosUseCase;
use App\UseCases\Egresos\Interfaces\GetEgresosUseCaseInterface;
use App\UseCases\Egresos\GetEgresosUseCase;
use App\UseCases\Egresos\Interfaces\GetPriceEgresseUseCaseInterface;
use App\UseCases\Egresos\GetIngresosDetailedUseCase;
use App\UseCases\Egresos\Interfaces\GetIngresosDetailedUseCaseInterface;
use App\UseCases\Egresos\GetPriceEgresseUseCase;
use App\UseCases\Facturation\GetDatePayFactureUseCase;
use App\UseCases\Facturation\Interfaces\GetDatePayFactureUseCaseInterface;
use App\UseCases\GeneratePdf\GeneratePdfTicketByIdUseCase;
use App\UseCases\GeneratePdf\Interfaces\GeneratePdfTicketByIdUseCaseInterface;
use App\UseCases\Company\ConfirmCompanyEmailUseCase;
use App\UseCases\Company\CreateStaffUseCase;
use App\UseCases\Company\GetStaffUseCase;
use App\UseCases\Company\UpdateBillingConfigUseCase;
use App\UseCases\Company\Interfaces\ConfirmCompanyEmailUseCaseInterface;
use App\UseCases\Company\Interfaces\CreateStaffUseCaseInterface;
use App\UseCases\Company\Interfaces\GetStaffUseCaseInterface;
use App\UseCases\Company\Interfaces\UpdateBillingConfigUseCaseInterface;
use App\UseCases\Company\Interfaces\RegisterCompanyUseCaseInterface;
use App\UseCases\Company\RegisterCompanyUseCase;
use App\UseCases\Oauth\ChangePasswordUseCase;
use App\UseCases\Oauth\Interfaces\ChangePasswordUseCaseInterface;
use App\UseCases\ManagementRouter\GetIpAvaliblesUseCase;
use App\UseCases\ManagementRouter\Interfaces\GetIpAvaliblesUseCaseInterface;
use App\UseCases\ManagementRouter\Interfaces\validateMikrotikConnectionUseCaseInterface;
use App\UseCases\ManagementRouter\validateMikrotikConnectionUseCase;
use App\UseCases\ManagementRouter\MikrotikInfoUseCase;
use App\UseCases\ManagementRouter\Interfaces\MikrotikInfoUseCaseInterface;
use App\UseCases\Notifications\Interfaces\SendNotificationUseCaseInterface;
use App\UseCases\Notifications\SendNotificationUseCase;
use App\UseCases\Ticket\CreateTicketUseCase;
use App\UseCases\Ticket\GetTechnicalUseCase;
use App\UseCases\Ticket\GetTicketInProgressAllUseCase;
use App\UseCases\Ticket\GetTypePriorityUseCase;
use App\UseCases\Ticket\GetTypeServiceUseCase;
use App\UseCases\Ticket\Interfaces\CreateTicketUseCaseInterface;
use App\UseCases\Ticket\Interfaces\GetTechnicalUseCaseInterface;
use App\UseCases\Ticket\Interfaces\GetTicketInProgressAllUseCaseInterface;
use App\UseCases\Ticket\Interfaces\GetTypePriorityUseCaseInterface;
use App\UseCases\Ticket\Interfaces\GetTypeServiceUseCaseInterface;
use App\UseCases\Ticket\Interfaces\UpdateTicketUseCaseInterface;
use App\UseCases\Ticket\UpdateTicketUseCase;
use App\UseCases\Inventory\CreateInventoryCategoryUseCase;
use App\UseCases\Inventory\CreateInventoryMovementUseCase;
use App\UseCases\Inventory\CreateInventoryUseCase;
use App\UseCases\Inventory\DeleteInventoryCategoryUseCase;
use App\UseCases\Inventory\DeleteInventoryUseCase;
use App\UseCases\Inventory\GetInventoryCategoriesUseCase;
use App\UseCases\Inventory\GetInventoriesUseCase;
use App\UseCases\Inventory\GetInventoryMovementsUseCase;
use App\UseCases\Inventory\UpdateInventoryCategoryUseCase;
use App\UseCases\Inventory\UpdateInventoryUseCase;
use App\UseCases\Inventory\Interfaces\CreateInventoryCategoryUseCaseInterface;
use App\UseCases\Inventory\Interfaces\CreateInventoryMovementUseCaseInterface;
use App\UseCases\Inventory\Interfaces\CreateInventoryUseCaseInterface;
use App\UseCases\Inventory\Interfaces\DeleteInventoryCategoryUseCaseInterface;
use App\UseCases\Inventory\Interfaces\DeleteInventoryUseCaseInterface;
use App\UseCases\Inventory\Interfaces\GetInventoryCategoriesUseCaseInterface;
use App\UseCases\Inventory\Interfaces\GetInventoriesUseCaseInterface;
use App\UseCases\Inventory\Interfaces\GetInventoryMovementsUseCaseInterface;
use App\UseCases\Inventory\Interfaces\UpdateInventoryCategoryUseCaseInterface;
use App\UseCases\Inventory\Interfaces\UpdateInventoryUseCaseInterface;
use GetCrmAgentsUseCase;
use TransferConversationUseCase;

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
        [CreatePaidFacturationUseCaseInterface::class, CreatePaidFacturationUseCase::class],
        [CreateAboneFacturationUseCaseInterface::class, CreateAboneFacturationUseCase::class],
        [GetDataCorteUseCaseInterface::class, GetDataCorteUseCase::class],
        [GetIpAllByIdZoneUseCaseInterface::class, GetIpAllByIdZoneUseCase::class],
        [SearchUseCaseInterface::class, SearchUseCase::class],
        [SearchFinancesPaidUseCaseInterface::class, SearchFinancesPaidUseCase::class],
        [UpdateStatusUserUseCaseInterface::class, UpdateStatusUserUseCase::class],
        [GeneratePdfReceiptByIdUseCaseInterface::class, GeneratePdfReceiptByIdUseCase::class],
        [GetCountUserUseCaseInterface::class, GetCountUserUseCase::class],
        [GetTotalPriceMonthUseCaseInterface::class, GetTotalPriceMonthUseCase::class],
        [GetTotalClientRegisterMonthUseCaseInterface::class, GetTotalClientRegisterMonthUseCase::class],
        [GeneratePdfByIdFacturesUseCaseInterface::class, GeneratePdfByIdFacturesUseCase::class],
        [GetTrazaFactureUseCaseInterface::class, GetTrazaFactureUseCase::class],
        [GeneratePayPdfByIdFacturesUseCaseInterface::class, GeneratePayPdfByIdFacturesUseCase::class],
        [CreateEgresosUseCaseInterface::class, CreateEgresosUseCase::class],
        [GetEgresosUseCaseInterface::class, GetEgresosUseCase::class],
        [GetPriceEgresseUseCaseInterface::class, GetPriceEgresseUseCase::class],
        [GetIngresosDetailedUseCaseInterface::class, GetIngresosDetailedUseCase::class],
        [GetTypeServiceUseCaseInterface::class, GetTypeServiceUseCase::class],
        [GetTypePriorityUseCaseInterface::class, GetTypePriorityUseCase::class],
        [GetTechnicalUseCaseInterface::class, GetTechnicalUseCase::class],
        [CreateTicketUseCaseInterface::class, CreateTicketUseCase::class],
        [GetTicketInProgressAllUseCaseInterface::class, GetTicketInProgressAllUseCase::class],
        [UpdateTicketUseCaseInterface::class, UpdateTicketUseCase::class],
        [GeneratePdfTicketByIdUseCaseInterface::class, GeneratePdfTicketByIdUseCase::class],
        [GetDatePayFactureUseCaseInterface::class, GetDatePayFactureUseCase::class],
        [SendNotificationUseCaseInterface::class, SendNotificationUseCase::class],
        [validateMikrotikConnectionUseCaseInterface::class, validateMikrotikConnectionUseCase::class],
        [GetIpAvaliblesUseCaseInterface::class, GetIpAvaliblesUseCase::class],
        [MikrotikInfoUseCaseInterface::class, MikrotikInfoUseCase::class],
        [InterfacesGetInboxConversationsUseCaseInterface::class, CrmGetInboxConversationsUseCase::class],
        [GetConversationMessagesUseCaseInterface::class, UseCasesCrmGetConversationMessagesUseCase::class],
        [ReceiveConversationMessageUseCaseInterface::class, ReceiveConversationMessageUseCase::class],
        [SendMessageUseCaseInterface::class, SendMessageUseCase::class],
        [CloseConversationUseCaseInterface::class, CloseConversationUseCase::class],
        [GetCrmAgentsUseCaseInterface::class, CrmGetCrmAgentsUseCase::class],
        [TransferConversationUseCaseInterface::class, CrmTransferConversationUseCase::class],
        [SendConversationMediaUseCaseInterface::class, SendConversationMediaUseCase::class],
        [RegisterCompanyUseCaseInterface::class, RegisterCompanyUseCase::class],
        [ConfirmCompanyEmailUseCaseInterface::class, ConfirmCompanyEmailUseCase::class],
        [CreateStaffUseCaseInterface::class, CreateStaffUseCase::class],
        [GetStaffUseCaseInterface::class, GetStaffUseCase::class],
        [UpdateBillingConfigUseCaseInterface::class, UpdateBillingConfigUseCase::class],
        [ChangePasswordUseCaseInterface::class, ChangePasswordUseCase::class],
        [GetInventoryCategoriesUseCaseInterface::class, GetInventoryCategoriesUseCase::class],
        [CreateInventoryCategoryUseCaseInterface::class, CreateInventoryCategoryUseCase::class],
        [UpdateInventoryCategoryUseCaseInterface::class, UpdateInventoryCategoryUseCase::class],
        [DeleteInventoryCategoryUseCaseInterface::class, DeleteInventoryCategoryUseCase::class],
        [GetInventoriesUseCaseInterface::class, GetInventoriesUseCase::class],
        [CreateInventoryUseCaseInterface::class, CreateInventoryUseCase::class],
        [UpdateInventoryUseCaseInterface::class, UpdateInventoryUseCase::class],
        [DeleteInventoryUseCaseInterface::class, DeleteInventoryUseCase::class],
        [CreateInventoryMovementUseCaseInterface::class, CreateInventoryMovementUseCase::class],
        [GetInventoryMovementsUseCaseInterface::class, GetInventoryMovementsUseCase::class],
        [\App\UseCases\Contract\Interfaces\GetContractsUseCaseInterface::class, \App\UseCases\Contract\GetContractsUseCase::class],
        [\App\UseCases\Contract\Interfaces\CreateContractUseCaseInterface::class, \App\UseCases\Contract\CreateContractUseCase::class],
        [\App\UseCases\Contract\Interfaces\ClientContractUseCaseInterface::class, \App\UseCases\Contract\ClientContractUseCase::class],
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
