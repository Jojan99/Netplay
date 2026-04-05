<?php

namespace App\UseCases\Company;

use App\Constants\ApiResponseConstants;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\UseCases\Company\Interfaces\GetStaffUseCaseInterface;

class GetStaffUseCase implements GetStaffUseCaseInterface
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
    ) {}

    public function getAll(): mixed
    {
        $staff = $this->userRepository->getStaff();

        return ['message' => 'OK', 'data' => $staff, 'status' => 0];
    }
}
