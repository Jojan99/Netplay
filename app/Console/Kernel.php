<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Revisa cada hora qué empresas tienen proceso programado para este día y hora exacta
        $schedule->command('billing:auto')->hourly();

        // Verifica compromisos de pago vencidos y suspende servicio si no pagó
        $schedule->command('commitments:check')->dailyAt('08:00');

        // Suspende clientes con facturas vencidas (corre el día del mes configurado por empresa)
        $schedule->command('clients:auto-suspend')->dailyAt('07:00')->user('www-data');

        // Reactiva clientes que ya están al día — corre cada 4 minutos para respuesta rápida tras un pago
        $schedule->command('clients:auto-reactivate')->everyFourMinutes()->user('www-data');

        // Revisa la red y deja anotado lo que hay que mirar: señal caída,
        // puertos PON apagados, túneles sin saludo, OLT que no se dejan leer.
        $schedule->command('alertas:revisar')->everyFifteenMinutes()->user('www-data')->withoutOverlapping();

        // Resumen de la mañana al grupo de WhatsApp de los técnicos: lo que
        // sigue abierto, antes de que salgan a la calle.
        $schedule->command('alertas:resumen')->dailyAt('07:30')->user('www-data');

        // Saca del servidor TR-069 los equipos falsos que dejan los escáneres:
        // el puerto tiene que estar abierto para las ONT y lo encuentran solos.
        $schedule->command('acs:limpiar')->dailyAt('05:30')->user('www-data');

        // Consumo de datos de cada cliente: lee los contadores que las ONT le
        // reportan al TR-069 (cada hora) y suma lo nuevo al día.
        $schedule->command('consumo:registrar')->hourlyAt(7)->user('www-data')->withoutOverlapping();

        // Aprovisionamiento de ONT recién autorizadas: les aplica WAN, WiFi y
        // cuenta de administración en cuanto aparecen en el TR-069.
        $schedule->command('aprovisionamiento:trabajar')->everyMinute()->user('www-data')->withoutOverlapping();

        // Sincroniza ARP MikroTik con STATUS de plataforma — corrige desyncs diariamente
        $schedule->command('arp:sync')->dailyAt('06:00')->user('www-data');

        // Trae al sistema la IP que cada cliente tiene realmente en el router.
        // En el MikroTik el cliente se identifica por su documento (el comment
        // del ARP), y esa es la IP con la que navega de verdad: si la
        // plataforma dice otra cosa, se rompe el diagnóstico, la suspensión y
        // la lista de IPs libres.
        //
        // Queda apagado hasta que se revise la primera pasada: hoy la primera
        // corrida tocaría 634 clientes de la empresa 1 (481 que no tienen IP
        // registrada y 153 que la tienen distinta a la del router). Conviene
        // mirarlo antes desde MikroTik → Conflictos de IP → Sincronizar con el
        // router, que muestra el detalle sin escribir nada, o con
        // `php artisan red:sincronizar-ips --simular`.
        //
        // Para dejarlo automático, descomentar:
        // $schedule->command('red:sincronizar-ips')
        //     ->hourly()
        //     ->user('www-data')
        //     ->withoutOverlapping();

        // Envía facturas pendientes por email para todas las empresas (respeta límite diario por empresa)
        $schedule->command('invoices:send-pending-emails')->dailyAt('08:00')->user('www-data');

        // Avisa y cierra las conversaciones del bot que quedaron sin respuesta.
        // Cada minuto porque el cliente espera de a pocos minutos, no de a horas.
        // Sin withoutOverlapping a propósito: el candado se guarda en caché y
        // un fallo de permisos ahí tumbaría el programador entero. Solapar no
        // hace daño, porque la sesión se borra al avisar y la segunda pasada
        // ya no la encuentra.
        $schedule->command('wa:cerrar-sesiones-inactivas')
            ->everyMinute()
            ->user('www-data');

        // Despacha por tandas los envíos masivos en curso. Cada minuto para que
        // un envío grande avance sin que nadie tenga que esperar en pantalla.
        $schedule->command('wa:enviar-campanas')
            ->everyMinute()
            ->user('www-data');

        // Recordatorios de pago y avisos de suspensión. Una vez al día: cuándo
        // le toca a cada cliente lo decide la configuración de cada empresa.
        $schedule->command('wa:avisos')
            ->dailyAt('09:00')
            ->user('www-data');
        
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
