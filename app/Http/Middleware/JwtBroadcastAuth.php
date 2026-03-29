<?php


namespace App\Http\Middleware;

use Closure;
use Tymon\JWTAuth\Facades\JWTAuth;

class JwtBroadcastAuth
{
    public function handle($request, Closure $next)
    {
        try {
            JWTAuth::parseToken()->authenticate();
            return $next($request);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Unauthenticated.'
            ], 401);
        }
    }
}
