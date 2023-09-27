<?php

namespace App\Repositories;

use App\Models\User;
use App\Models\UserData;
use Dompdf\Dompdf;
use Dompdf\Options;
use App\Http\Requests\User\CreateUserDataRequest;
use Illuminate\Support\Facades\Hash;
use App\Repositories\Interfaces\GeneratePdfRepositoryInterface;
use App\Repositories\Interfaces\UserRepositoryInterface;



class GeneratePdfRepository implements GeneratePdfRepositoryInterface
{
    /**
     * @param int $sponsor_id
     * @return mixed
     */
    public function generatePdf(): mixed
    {

       
        $data = User::where('active', 1)->get();

        return $data;
        
    }
}
