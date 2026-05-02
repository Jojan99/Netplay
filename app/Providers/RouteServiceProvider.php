<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {

        $this->configureRateLimiting();
       
        $this->routes(function () {
            Route::middleware('api', 'jwt.verify')
                ->prefix('api')
                ->namespace('App\Http\Controllers')
                ->group(function () {
                    require base_path('routes/api/userRoutes.php');
                    require base_path('routes/api/oauthRoutes.php');
                    require base_path('routes/api/facturationRoutes.php');
                    require base_path('routes/api/generatePdfRoutes.php');
                    require base_path('routes/api/genderRoutes.php');
                    require base_path('routes/api/infoInternetRoutes.php');
                    require base_path('routes/api/locationRoutes.php');
                    require base_path('routes/api/dniRoutes.php');
                    require base_path('routes/api/shearchRoutes.php');
                    require base_path('routes/api/managementRouterRoutes.php');
                    require base_path('routes/api/egresosRoutes.php');
                    require base_path('routes/api/ticketRoutes.php');
                    require base_path('routes/api/broadcastingRoutes.php');
                    require base_path('routes/api/companyRoutes.php');
                    require base_path('routes/api/inventoryRoutes.php');
                    require base_path('routes/api/waProxyRoutes.php');
                    require base_path('routes/api/employeeRoutes.php');
                    require base_path('routes/api/internetPlanRoutes.php');
                    require base_path('routes/api/paymentMethodRoutes.php');
                    require base_path('routes/api/installationRoutes.php');
                    require base_path('routes/api/transferRoutes.php');
                });

            // Contratos: sign-token es público (sin JWT), el resto usa jwt.verify interno
            Route::middleware('api')
                ->prefix('api')
                ->namespace('App\Http\Controllers')
                ->group(function () {
                    require base_path('routes/api/contractRoutes.php');
                });

            // Portal de cliente: rutas públicas (login) y protegidas (jwt.client)
            // El middleware jwt.client se aplica internamente por grupo en clientRoutes.php
            Route::middleware('api')
                ->prefix('api')
                ->group(function () {
                    require base_path('routes/api/clientRoutes.php');
                });

            // Landing page pública: GET /api/landing/{slug} sin JWT.
            // Las rutas /api/landing-admin/* tienen jwt.verify via el grupo principal arriba
            // y role:admin aplicado internamente en landingRoutes.php.
            Route::middleware('api')
                ->prefix('api')
                ->group(function () {
                    require base_path('routes/api/landingRoutes.php');
                });

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(30)->by(optional($request->user())->id ?: $request->ip());
        });
    }
}
