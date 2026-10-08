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
        // Los candados del programador van a Redis y no al caché de archivos: quien lo corre es
        // root (cron), y cada candado dejaba una carpeta del caché con dueño root donde la web
        // (www-data) ya no podía escribir. Cualquier pantalla que guardara algo en caché y cayera
        // en esa carpeta respondía «Server Error».
        $schedule->useCache('redis');

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
        // Justo después del barrido de alertas: guarda esa misma medición para
        // tener historia de la red (no le pregunta nada a la OLT).
        $schedule->command('red:muestrear')->everyFifteenMinutes()->user('www-data')->withoutOverlapping();
        $schedule->command('red:muestrear --limpiar')->weeklyOn(1, '04:20')->user('www-data');

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
        // Sin withoutOverlapping: cada aprovisionamiento lleva su propio candado
        // (AprovisionamientoDeOnt::trabajarPendientes). Con uno solo para toda la
        // pasada, un equipo lento dejaba a los demás esperando. En segundo plano
        // para no demorar al resto de las tareas programadas.
        $schedule->command('aprovisionamiento:trabajar')->everyMinute()->user('www-data')->runInBackground();

        // Cobranza inteligente: detecta deudores, cierra lo pagado y, en el
        // horario de cada empresa, el asistente escribe y recuerda.
        $schedule->command('cobranza:revisar')->everyFiveMinutes()->user('www-data')->withoutOverlapping(20)->runInBackground();

        // Asistente de soporte: termina los cambios de clave que esperaban al
        // equipo, reintenta lo que quedó sin contestar y cierra casos abandonados.
        // Sin withoutOverlapping: el candado lo lleva el propio comando en Redis.
        $schedule->command('soporte:revisar')->everyMinute()->user('www-data')->runInBackground();
        // Fallas de sector: cada empresa elige cada cuántos minutos se lee su OLT (módulo Fallas de sector).
        $schedule->command('red:fallas-sector')->everyMinute()->user('www-data')->runInBackground();

        // Pagos en línea que quedaron colgados: la pasarela avisa por webhook y
        // el cliente vuelve a la página de retorno, pero las dos cosas fallan
        // solas —un webhook sin configurar, un navegador cerrado— y el cliente
        // queda pagando sin que la factura se entere. Esto lo pregunta.
        $schedule->command('pagos:conciliar')->everyFiveMinutes()->user('www-data')->withoutOverlapping(10)->runInBackground();

        // Suspensiones temporales pedidas por el cliente: empiezan y terminan solas. Cada
        // hora a los 20 (después del corte automático de las 7 y la facturación de la 1).
        $schedule->command('clientes:suspensiones-temporales')->hourlyAt(20)->user('www-data')->withoutOverlapping();

        // El semáforo de comprobantes vuelve a aprender de lo aprobado y rechazado del día.
        $schedule->command('comprobantes:aprender')->dailyAt('02:40')->user('www-data');

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
        // Avisa antes de facturar si las facturas no van a poder salir. Waonet
        // emitió 134 y no le llegó nada a nadie: el problema no fue no poder,
        // fue enterarse después.
        $schedule->command('facturacion:revisar-canales')
            ->dailyAt('08:15')
            ->user('www-data');

        // El estado de las líneas sólo se refrescaba al abrir la pantalla de la
        // empresa, así que el panel mostraba caídas líneas que estaban
        // conectadas y enviando.
        $schedule->command('wa:sincronizar-lineas')
            ->everyFiveMinutes()
            ->user('www-data')
            ->withoutOverlapping(10)
            ->runInBackground();

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

        // Factura electrónica (DIAN): emite lo cobrado por las empresas que lo tienen en automático,
        // reintenta lo que quedó a medias y saca las notas crédito de lo revertido o anulado.
        $schedule->command('factura-electronica:emitir')
            // Sin withoutOverlapping: ese candado lo crea el programador (root) y la tarea,
            // que corre como www-data, no lo puede soltar. El comando trae el suyo por empresa.
            ->everyFiveMinutes()->user('www-data')->runInBackground();

        // Inventario: lo que llegó al mínimo y nadie avisó; los lunes, qué técnicos tienen
        // equipos hace tiempo. El aviso inmediato lo dispara el propio movimiento.
        $schedule->command('inventory:avisar')->dailyAt('08:20')->user('www-data');

        // Alegra: refresca la copia local (facturas, pagos, contactos) de las empresas que lo tienen
        // conectado. Sólo lee. Sin withoutOverlapping: el servicio trae su propio candado.
        $schedule->command('alegra:sincronizar')->hourlyAt(20)->user('www-data')->runInBackground();

        // Pruebas y cobros vencidos de las empresas con Netvula: primer aviso,
        // días de gracia y, agotados, suspensión del panel.
        $schedule->command('plataforma:revisar-suscripciones')
            ->dailyAt('06:30')
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
