<?php

use Tymon\JWTAuth\Facades\JWTAuth;

if (!function_exists('getSessionUserId')) {
    /**
     * Función encargada de establecer la respuesta estandar para una solicitud
     * api
     *
     * @return int|null
     */
    function getSessionUserId()
    {
        return (session()->has('user') && isset(session()->get('user')->id)) ? session()->get('user')->id : null;
    }
}

if (!function_exists('getSessionUserName')) {
    /**
     * Función encargada de establecer la respuesta estandar para una solicitud 
     * api
     *
     * @return int|null
     */
    function getSessionUserName()
    {
        $token = JWTAuth::getToken();
        return JWTAuth::setToken($token)->toUser()['username'];
    }
}

if (!function_exists('getSessionCompanyId')) {
    /**
     * Retorna el company_id del usuario autenticado en sesión
     *
     * @return int|null
     */
    function getSessionCompanyId()
    {
        return (session()->has('user') && isset(session()->get('user')->company_id)) ? session()->get('user')->company_id : null;
    }
}

if (!function_exists('getSessionUserProfileId')) {
    /**
     * Función encargada de establecer la respuesta estandar para una solicitud
     * api
     *
     * @return int|null
     */
    function getSessionUserProfileId()
    {
        return (session()->has('user') && isset(session()->get('user')->profile_id)) ? session()->get('user')->profile_id : null;
    }
}

if (!function_exists('sessionUserHasProfile')) {
    /**
     * Verifica si el usuario en sesión tiene alguno de los perfiles indicados (por nombre).
     * Funciona para cualquier empresa sin importar el ID del perfil.
     */
    function sessionUserHasProfile(string ...$names): bool
    {
        $profileId = getSessionUserProfileId();
        $companyId = getSessionCompanyId();
        if (!$profileId || !$companyId) return false;
        return \Illuminate\Support\Facades\DB::table('profiles')
            ->where('id', $profileId)
            ->where('company_id', $companyId)
            ->whereIn('name', $names)
            ->exists();
    }
}