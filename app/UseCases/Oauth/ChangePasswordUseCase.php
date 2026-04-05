<?php

namespace App\UseCases\Oauth;

use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Models\User;
use App\UseCases\Oauth\Interfaces\ChangePasswordUseCaseInterface;
use Illuminate\Support\Facades\Hash;

class ChangePasswordUseCase implements ChangePasswordUseCaseInterface
{
    public function change(ChangePasswordRequest $data): mixed
    {
        $user = User::find(getSessionUserId());

        if (!$user) {
            return ['message' => 'Usuario no encontrado.', 'status' => 1, 'data' => null];
        }

        if (!Hash::check($data['current_password'], $user->password)) {
            return ['message' => 'La contraseña actual es incorrecta.', 'status' => 1, 'data' => null];
        }

        $user->update(['password' => Hash::make($data['new_password'])]);

        return ['message' => 'Contraseña actualizada correctamente.', 'status' => 0, 'data' => null];
    }
}
