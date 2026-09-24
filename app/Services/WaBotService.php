<?php

namespace App\Services;

use App\Models\Company;
use App\Models\WaBotConfig;
use App\Models\WaBotSession;
use App\Models\UserData;
use App\Models\CabFacturation;
use App\Models\DetFacturation;
use App\Models\OnlinePaymentTransaction;
use App\Models\PaymentProof;
use App\Models\PaymentProofAudit;
use App\Models\Ticket;
use App\Models\CrmMessage;
use App\Events\NewMessageEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\QueryException;
use App\Services\WhatsAppService;
use App\Models\WaIdentity;
use App\Services\PaymentGateways\EfiPayGateway;
use App\Services\PaymentGateways\PaymentInitiationService;
use App\Services\PaymentGateways\PaymentLinkService;
use App\Services\PaymentGateways\WompiGateway;
use Symfony\Component\Process\Process;

class WaBotService
{
    /**
     * Procesa un mensaje entrante y determina si debe ser manejado por el bot.
     * Retorna true si el mensaje fue procesado por el bot (no debe pasar al CRM).
     */
    public function handleIncomingMessage(array $payload, string $phoneNumberId): bool
    {
        $company = $this->findCompanyByPhoneNumberId($phoneNumberId);
        if (!$company) {
            return false;
        }

        $config = WaBotConfig::where('company_id', $company->id)->first();
        if (!$config || !$config->enabled) {
            return false;
        }

        $from = $payload['from'] ?? '';
        if ($from && DB::table('wa_bot_pauses')->where(['company_id' => $company->id, 'provider' => 'meta', 'phone' => $from])->exists()) {
            return false;
        }

        $message = $payload['text']['body'] ?? null;
        if (!$message && (($payload['type'] ?? null) === 'interactive')) {
            $message = $payload['interactive']['button_reply']['id']
                ?? $payload['interactive']['list_reply']['id']
                ?? null;
        }

        if (!$message && in_array(($payload['type'] ?? null), ['image', 'document'], true)) {
            $caption = $payload['image']['caption'] ?? $payload['document']['caption'] ?? null;
            $message = $caption ?: 'comprobante';
            $payload['text'] = ['body' => $message];
        }

        if (!$from || !$message) {
            return false;
        }

        $normalizedMessage = strtolower(trim($message));
        $triggerWords = array_filter(array_map(
            static fn (string $word): string => strtolower(trim($word)),
            explode(',', (string) $config->trigger_word)
        ));

        // Check active session
        $session = WaBotSession::where('company_id', $company->id)
            ->where('phone', $from)
            ->where('expires_at', '>', now())
            ->first();

        $invoiceIntent = preg_match('/\b(factura|facturas|facturacion|facturación)\b/u', $normalizedMessage) === 1;

        // "menu" vale siempre, como la palabra clave: es la salida que el bot le
        // ofrece al cliente en cada mensaje, y no puede quedar bloqueada por la
        // espera justo después de que él mismo se la sugirió al cerrar.
        $pideMenu       = in_array($normalizedMessage, ['menu', 'menú', 'inicio'], true);
        $esPalabraClave = in_array($normalizedMessage, $triggerWords, true) || $pideMenu;

        // Si el bot acaba de cerrar, se queda callado un rato: así no interrumpe
        // una conversación con un agente humano saludando a cada mensaje.
        if (!$session && !$esPalabraClave && $this->enEsperaDeSaludar($company->id, $from)) {
            return false;
        }

        // Se registra solo lo que el bot va a atender; lo demás lo guarda el CRM.
        $this->recordBotConversationMessage($company, $from, 'customer', $payload['bot_selection_label'] ?? $message, $payload['id'] ?? null);

        // Sin sesión abierta, cualquier cosa que escriba el cliente abre el
        // menú: no tiene por qué adivinar la palabra mágica. "hola", "buenas" o
        // "necesito ayuda" valen igual.
        if ($esPalabraClave || $invoiceIntent || !$session) {
            if ($invoiceIntent && !$esPalabraClave) {
                return $this->startFlow($company, $from, 'consultar_factura');
            }

            return $this->returnToMenu($company, $from);
        }

        if (in_array($normalizedMessage, ['otra cedula', 'otra cédula', 'cambiar cedula', 'cambiar cédula'], true)) {
            WaIdentity::forget($company->id, $from);
            $this->sendTextMessage($company, $from, "Listo, olvidé esa cédula. Te la pediré de nuevo.");
            return $this->returnToMenu($company, $from);
        }

        // Handle active flow
        return $this->handleFlowStep($company, $config, $session, $from, $normalizedMessage, $payload);
    }

    /**
     * Cierra las conversaciones que quedaron esperando una respuesta.
     *
     * El vencimiento de la sesión por sí solo no le dice nada al cliente: la
     * conversación simplemente se queda muda y la siguiente vez que escribe le
     * vuelve a salir el menú sin explicación. Esto le avisa y cierra.
     *
     * Lo llama el comando programado wa:cerrar-sesiones-inactivas.
     *
     * @return int Cuántas se cerraron.
     */
    public function closeIdleSessions(int $minutosDeGracia = 30): int
    {
        // Si el programador estuvo caído no se avisa de conversaciones viejas:
        // un mensaje de cierre horas después confunde más de lo que ayuda.
        $vencidas = WaBotSession::where('expires_at', '<=', now())
            ->where('expires_at', '>', now()->subMinutes($minutosDeGracia))
            ->get();

        $cerradas = 0;

        foreach ($vencidas as $sesion) {
            $company = Company::find($sesion->company_id);

            if (!$company) {
                $sesion->delete();
                continue;
            }

            try {
                $this->sendTextMessage(
                    $company,
                    $sesion->phone,
                    'No recibimos ninguna consulta, así que cerramos por ahora. '
                    . 'Escríbenos cuando quieras y con gusto te ayudamos.'
                );
            } catch (\Throwable $e) {
                Log::warning('[WaBotService] No se pudo avisar el cierre por inactividad', [
                    'company_id' => $sesion->company_id,
                    'error'      => $e->getMessage(),
                ]);
            }

            // Borra y arranca la espera, para no volver a saludar enseguida.
            $this->clearSession($sesion->company_id, $sesion->phone);
            $cerradas++;
        }

        return $cerradas;
    }

    private function findCompanyByPhoneNumberId(string $phoneNumberId): ?Company
    {
        return Company::where('wa_phone_number_id', $phoneNumberId)->first();
    }

    /**
     * Cierra la atención. Único punto por el que el bot suelta al cliente, así
     * que es donde arranca la espera antes de poder volver a saludar solo.
     */
    private function clearSession(int $companyId, string $phone): void
    {
        WaBotSession::where('company_id', $companyId)
            ->where('phone', $phone)
            ->delete();

        $this->empezarEspera($companyId, $phone);
    }

    private function createSession(int $companyId, string $phone, string $flow, string $step, array $data = []): WaBotSession
    {
        // Borrado directo, no clearSession: abrir una sesión no es soltar al
        // cliente, y no debe arrancar la espera para volver a saludar.
        WaBotSession::where('company_id', $companyId)->where('phone', $phone)->delete();

        return WaBotSession::create([
            'company_id' => $companyId,
            'phone' => $phone,
            'current_flow' => $flow,
            'current_step' => $step,
            'data' => $data,
            'expires_at' => self::vencimientoSesion(),
        ]);
    }

    /**
     * Devuelve al cliente al menú principal.
     *
     * Borrar la sesión no basta: sin menú enviado el cliente toca "Ir al menú"
     * y no recibe nada, que es exactamente como se veía el flujo de facturas.
     */
    private function returnToMenu(Company $company, string $phone): bool
    {
        $config = WaBotConfig::where('company_id', $company->id)->first();

        // createSession ya borra la anterior.
        $this->createSession($company->id, $phone, 'menu', 'awaiting_option');

        if ($config) {
            $this->sendWelcomeMenu($company, $config, $phone);
        }

        return true;
    }

    /**
     * Las opciones del menú, en un solo lugar.
     *
     * Antes vivían duplicadas aquí y en el enrutador, y bastaba tocar una para
     * que el cliente viera un botón que no llevaba a ninguna parte.
     */
    private function menuOptions(Company $company, WaBotConfig $config): array
    {
        $options = $config->options ?: [
            ['key' => '1', 'label' => 'Consultar factura', 'flow' => 'consultar_factura'],
        ];

        // "Pagar mi factura" solo aparece si la pasarela está realmente operativa:
        // ofrecerla sin configurar sería llevar al cliente a un callejón sin salida.
        if ($this->onlinePaymentAvailable($company) && !$this->hasOption($options, 'pagar_factura')) {
            $options[] = ['label' => 'Pagar mi factura', 'flow' => 'pagar_factura'];
        }

        // Numeración corrida: quitar una opción no puede dejar huecos, porque el
        // número es lo que el cliente escribe si no toca el botón.
        foreach ($options as $i => $opt) {
            $options[$i]['key'] = (string) ($i + 1);
        }

        return array_values($options);
    }

    private function sendWelcomeMenu(Company $company, WaBotConfig $config, string $to): void
    {
        $options = $this->menuOptions($company, $config);

        $menuText = ($config->welcome_message ?: "Hola, bienvenido a {$company->name}.\n\n¿En qué puedo ayudarte?");

        $wa = new WhatsAppService($company->id, false, 'meta');

        // Meta admite máximo 3 botones; con más opciones hay que usar lista.
        if (count($options) > 3) {
            $rows = [];
            foreach ($options as $index => $opt) {
                $rows[] = [
                    'id'    => (string) ($opt['key'] ?? ($index + 1)),
                    'title' => mb_substr($opt['label'] ?? $opt['title'] ?? 'Opción', 0, 24),
                ];
            }

            $wa->sendInteractiveList($to, $menuText, [['title' => 'Opciones', 'rows' => $rows]], 'Ver opciones');
            return;
        }

        $buttons = [];
        foreach ($options as $index => $opt) {
            $key = $opt['key'] ?? (string) ($index + 1);
            $label = $opt['label'] ?? $opt['title'] ?? 'Opción';
            $buttons[] = ['id' => $key, 'title' => $label];
        }

        $wa->sendInteractiveButtons($to, $menuText, $buttons);
    }

    /** ¿La empresa tiene una pasarela configurada y encendida? */
    private function onlinePaymentAvailable(Company $company): bool
    {
        return (bool) $company->pg_active && !empty($company->pg_gateway);
    }

    /** ¿El menú configurado ya trae ese flujo? */
    private function hasOption(array $options, string $flow): bool
    {
        foreach ($options as $opt) {
            if (($opt['flow_id'] ?? $opt['flow'] ?? null) === $flow) {
                return true;
            }
        }

        return false;
    }

    private function handleFlowStep(Company $company, WaBotConfig $config, WaBotSession $session, string $phone, string $message, array $payload = []): bool
    {
        $flow = $session->current_flow;
        $step = $session->current_step;
        $data = $session->data ?? [];

        if ($flow === 'menu') {
            $options = $this->menuOptions($company, $config);

            foreach ($options as $index => $opt) {
                $key = strtolower(trim((string) ($opt['key'] ?? ($index + 1))));
                if ($message === $key) {
                    // El constructor nuevo usa flow_id; las configuraciones
                    // viejas, flow.
                    $targetFlow = $opt['flow_id'] ?? $opt['flow'] ?? $key;
                    return $this->startFlow($company, $phone, $targetFlow);
                }
            }

            // Escribir la intención también vale, sin tocar el botón.
            if (in_array($message, ['pagar', 'pagar factura', 'pagar_factura', 'pagar mi factura'], true)) {
                return $this->startFlow($company, $phone, 'pagar_factura');
            }

            $this->sendTextMessage($company, $phone, "Opción no válida. Por favor escribe una de las opciones del menú.");
            return true;
        }

        return $this->routeFlow($company, $session, $phone, $message, $payload);
    }

    /**
     * Lleva el mensaje al flujo que corresponde.
     *
     * Vive aparte de handleFlowStep porque startFlow también necesita entrar a
     * un flujo, cuando ya sabe de quién se trata y se salta la cédula.
     */
    private function routeFlow(
        Company $company,
        WaBotSession $session,
        string $phone,
        string $message,
        array $payload = []
    ): bool {
        return match ($session->current_flow) {
            'consultar_factura'  => $this->handleConsultarFactura($company, $session, $phone, $message),
            'consultar_revision' => $this->handleConsultarRevision($company, $session, $phone, $message),
            'reportar_pago'      => $this->handleReportarPago($company, $session, $phone, $message, $payload),
            'pagar_factura'      => $this->handlePagarFactura($company, $session, $phone, $message),
            default              => false,
        };
    }

    /**
     * Cuánto espera el bot a que el cliente conteste.
     *
     * Pasado esto le avisa que no recibió ninguna consulta y cierra, para no
     * dejar una conversación a medias esperando indefinidamente.
     */
    private const MINUTOS_PARA_CONTESTAR = 5;

    /**
     * Cuánto tarda el bot en poder volver a saludar por su cuenta.
     *
     * Sin esta espera, cualquier mensaje suelto después de cerrar volvería a
     * abrir el menú: quien está conversando con un agente humano recibiría el
     * saludo del bot a cada rato. La palabra clave y "menu" nunca esperan.
     */
    private const MINUTOS_ANTES_DE_VOLVER_A_SALUDAR = 10;

    private static function vencimientoSesion(): \Illuminate\Support\Carbon
    {
        return now()->addMinutes(self::MINUTOS_PARA_CONTESTAR);
    }

    /** ¿El bot acaba de cerrar con este cliente? */
    private function enEsperaDeSaludar(int $companyId, string $sender): bool
    {
        try {
            return (bool) Cache::get(self::claveEspera($companyId, $sender));
        } catch (\Throwable $e) {
            // Sin caché se prefiere saludar: molesta menos que quedarse mudo.
            return false;
        }
    }

    /** Arranca la espera. Se llama cada vez que el bot deja de atender. */
    private function empezarEspera(int $companyId, string $sender): void
    {
        try {
            Cache::put(
                self::claveEspera($companyId, $sender),
                1,
                now()->addMinutes(self::MINUTOS_ANTES_DE_VOLVER_A_SALUDAR)
            );
        } catch (\Throwable $e) {
            // Ni con caché rota puede fallar la atención al cliente.
        }
    }

    private static function claveEspera(int $companyId, string $sender): string
    {
        return 'wa_bot_espera:' . $companyId . ':' . $sender;
    }

    /**
     * ¿Esto parece una cédula?
     *
     * Se exigían entre 8 y 10 dígitos, y con eso el bot rechazaba a 133 de los
     * 907 clientes: las cédulas colombianas antiguas tienen 6 o 7 dígitos. El
     * rango amplio no abre ningún hueco, porque después hay que acertar además
     * el celular registrado.
     */
    private function pareceCedula(string $dni): bool
    {
        $largo = strlen($dni);

        return $largo >= 5 && $largo <= 12;
    }

    /** Flujos que empiezan pidiendo la cédula del titular. */
    private const FLUJOS_CON_CEDULA = [
        'consultar_factura',
        'consultar_revision',
        'reportar_pago',
        'pagar_factura',
    ];

    private function startFlow(Company $company, string $phone, string $flow): bool
    {
        // A quien ya se comprobó no se le vuelve a pedir la cédula: se entra
        // derecho al flujo con la que dejó registrada.
        if (in_array($flow, self::FLUJOS_CON_CEDULA, true)
            && ($conocido = WaIdentity::lookup($company->id, $phone))) {

            if ($flow === 'pagar_factura' && !$this->onlinePaymentAvailable($company)) {
                $this->sendTextMessage($company, $phone, "El pago en línea no está disponible por ahora.\n\nEscribe *menu* para volver al inicio.");
                $this->clearSession($company->id, $phone);
                return true;
            }

            $session = $this->createSession($company->id, $phone, $flow, 'ask_dni', [
                // Sin esto, a quien escribe sin teléfono se le volvería a pedir
                // el celular: la comprobación ya se hizo, se reutiliza.
                'verified_phone' => $conocido->verified_phone,
            ]);

            $this->sendTextMessage(
                $company,
                $phone,
                "Continúo con la cédula {$conocido->maskedDni()} que ya validaste.\n\n"
                . "Si necesitas consultar otra, escribe *otra cedula*."
            );

            return $this->routeFlow($company, $session, $phone, $conocido->dni);
        }

        if ($flow === 'consultar_factura') {
            $this->createSession($company->id, $phone, 'consultar_factura', 'ask_dni');
            $this->sendTextMessage($company, $phone, "Para consultar tu factura, por favor envíame tu número de cédula o DNI.");
            return true;
        }

        if ($flow === 'consultar_revision') {
            $this->createSession($company->id, $phone, 'consultar_revision', 'ask_dni');
            $this->sendTextMessage($company, $phone, "Para consultar tu revisión o ticket de soporte, por favor envíame tu número de cédula o DNI.");
            return true;
        }

        if ($flow === 'reportar_pago') {
            $this->createSession($company->id, $phone, 'reportar_pago', 'ask_dni');
            $this->sendTextMessage($company, $phone, "Para registrar tu pago, primero envía la cédula del titular de la cuenta.");
            return true;
        }

        if ($flow === 'pagar_factura') {
            if (!$this->onlinePaymentAvailable($company)) {
                $this->sendTextMessage($company, $phone, "El pago en línea no está disponible por ahora.\n\nEscribe *menu* para volver al inicio.");
                $this->clearSession($company->id, $phone);
                return true;
            }

            $this->createSession($company->id, $phone, 'pagar_factura', 'ask_dni');
            $this->sendTextMessage($company, $phone, "Para generar tu link de pago, envíame el número de cédula del titular de la cuenta.");
            return true;
        }

        return false;
    }

    private function handleConsultarFactura(Company $company, WaBotSession $session, string $phone, string $message): bool
    {
        $step = $session->current_step;
        $data = $session->data ?? [];

        if ($step === 'ask_dni') {
            $dni = preg_replace('/[^0-9]/', '', $message);

            if (!$this->pareceCedula($dni)) {
                $this->sendTextMessage($company, $phone, "Por favor, ingresa un número de cédula válido.");
                return true;
            }

            // Sin teléfono no hay con qué comparar: se le pide el registrado.
            if (self::isUserIdentity($phone) && empty($data['verified_phone'])) {
                $session->update([
                    'current_step' => 'ask_phone',
                    'data' => array_merge($data, ['dni' => $dni]),
                    'expires_at' => self::vencimientoSesion(),
                ]);
                $this->sendTextMessage($company, $phone, "Para confirmar que eres el titular, escríbeme el número de celular registrado en tu cuenta.");
                return true;
            }

            $client = $this->resolveDniOwner($company, $dni, $phone, $data['verified_phone'] ?? null);

            if (!$client) {
                $this->sendTextMessage($company, $phone, "No pudimos validar esos datos con este número de WhatsApp. Verifica la cédula registrada en tu cuenta o comunícate con soporte.");
                return true;
            }

            // ✅ Confirmar cédula con botones
            $session->update([
                'current_step' => 'confirm_dni',
                'data' => array_merge($data, [
                    'client_id' => $client->id,
                    'client_name' => $client->names,
                    'client_dni' => $dni,
                ]),
                'expires_at' => self::vencimientoSesion(),
            ]);

            $wa = new WhatsAppService($company->id, false, 'meta');
            $wa->sendInteractiveButtons(
                $phone,
                "¿Es correcta tu cédula: {$dni}?",
                [
                    ['id' => 'confirm_yes', 'title' => 'Sí, es correcta'],
                    ['id' => 'confirm_no', 'title' => 'No, intenta de nuevo'],
                ]
            );
            return true;
        }

        if ($step === 'confirm_dni') {
            $message = strtolower(trim($message));

            // Aceptar respuesta de botón O texto manual
            if ($message === 'confirm_no' || $message === 'no' || $message === '2') {
                $this->clearSession($company->id, $phone);
                $this->sendTextMessage($company, $phone, "De acuerdo, por favor intenta de nuevo.\n\nEscribe tu cédula:");
                $this->createSession($company->id, $phone, 'consultar_factura', 'ask_dni');
                return true;
            }

            if (!in_array($message, ['confirm_yes', 'sí', 'si', '1', 'yes', 'correcto'], true)) {
                // Si no es una respuesta válida, ignorar
                return true;
            }

            // Get latest invoices
            $clientId = $data['client_id'] ?? null;
            $clientName = $data['client_name'] ?? 'Cliente';

            if (!$clientId) {
                $this->clearSession($company->id, $phone);
                return false;
            }

            $client = UserData::find($clientId);
            if (!$client) {
                $this->sendTextMessage($company, $phone, "Hubo un error. Por favor intenta de nuevo.");
                $this->clearSession($company->id, $phone);
                return true;
            }

            $cabIds = CabFacturation::where('company_id', $company->id)
                ->where('user_id', $client->user_id)
                ->pluck('id');

            // Primero las pendientes: son las que el cliente viene a resolver.
            // Y con desempate por id, porque varias facturas comparten fecha y
            // sin él MySQL devuelve un orden arbitrario que dejaba fuera unas u
            // otras en cada consulta. Diez es el máximo de filas que admite una
            // lista interactiva de Meta; se muestran las cinco más recientes.
            $invoices = DetFacturation::whereIn('cab_id', $cabIds)
                ->orderBy('paid')
                ->orderByDesc('date_facturation')
                ->orderByDesc('id')
                ->limit(5)
                ->get();

            if ($invoices->isEmpty()) {
                $this->sendTextMessage($company, $phone, "Hola {$clientName}, no tienes facturas registradas en nuestro sistema.\n\nEscribe *menu* para volver al inicio.");
                $this->clearSession($company->id, $phone);
                return true;
            }

            $invoiceList = [];

            foreach ($invoices as $index => $inv) {
                $number = (int)($index + 1);
                $total = $inv->price_total ?? $inv->total ?? 0;
                $discount = $inv->price_discount ?? 0;
                $balance = max(0, (float) $total - (float) $discount - (float) ($inv->price_abone ?? 0));
                $status = ($inv->paid ?? false) ? 'Pagada' : 'Pendiente';
                $fecha = $inv->date_facturation;

                // El título del botón lo lee Meta y exige que sea único entre los
                // tres. Dos facturas del mismo valor y fecha lo repetían y Meta
                // tumbaba el mensaje completo con "(#131009) Parameter value is
                // not valid". El número de factura sí es único.
                $etiqueta = $balance > 0
                    ? '$' . number_format($balance, 0, ',', '.')
                    : $status;

                $invoiceList[] = [
                    'option' => $number,
                    'number_facture' => $inv->number_facture,
                    'id' => $inv->id,
                    'status' => $status,
                    'balance' => $balance,
                    'date_facturation' => $fecha,
                    'button_title' => "#{$inv->number_facture} {$etiqueta}",
                ];
            }

            $session->update([
                'current_step' => 'select_invoice',
                'data' => array_merge($data, ['invoices' => $invoiceList]),
                'expires_at' => self::vencimientoSesion(),
            ]);

            $this->sendInvoicePicker(
                $company,
                $phone,
                $invoiceList,
                "Hola {$clientName}, selecciona la factura que deseas descargar:"
            );

            return true;
        }

        if ($step === 'ask_phone') {
            $typed = preg_replace('/[^0-9]/', '', $message);

            if (strlen($typed) < 10) {
                $this->sendTextMessage($company, $phone, "Escríbeme el número de celular completo, sin espacios ni guiones.");
                return true;
            }

            $client = $this->resolveDniOwner($company, (string) ($data['dni'] ?? ''), $phone, $typed);

            if (!$client) {
                if ($this->tooManyAttempts($session)) {
                    $this->sendTextMessage($company, $phone, "Por seguridad cerramos la consulta. Comunícate con soporte para verificar tu cuenta.");
                    $this->clearSession($company->id, $phone);
                    return true;
                }

                // Un solo mensaje para cédula y teléfono: decir cuál de los dos
                // falló le serviría a alguien para tantear datos ajenos.
                $this->sendTextMessage($company, $phone, "No pudimos validar esos datos con este número de WhatsApp. Verifica la cédula registrada en tu cuenta o comunícate con soporte.");
                return true;
            }

            // Verificado: se vuelve a entrar por el paso normal, que ya sabe
            // seguir. Así no hay dos copias de lo que viene después.
            $session->update([
                'current_step' => 'ask_dni',
                'data' => array_merge($data, ['verified_phone' => $typed, 'dni_attempts' => 0]),
                'expires_at' => self::vencimientoSesion(),
            ]);

            return $this->handleConsultarFactura($company, $session->fresh(), $phone, (string) $data['dni']);
        }

        if ($step === 'select_invoice') {
            // Message comes as invoice_1, invoice_2, etc OR as number 1, 2, etc
            $selectedOption = null;

            // Check if it's a button response (invoice_N format)
            if (preg_match('/invoice_(\d+)/', $message, $matches)) {
                $selectedOption = (int) $matches[1];
            } else {
                // Fallback: accept numeric input directly
                $selectedOption = (int) trim($message);
            }

            $invoices = $data['invoices'] ?? [];

            // Find selected invoice
            $selectedInvoice = null;
            foreach ($invoices as $inv) {
                if ($inv['option'] === $selectedOption) {
                    $selectedInvoice = $inv;
                    break;
                }
            }

            if (!$selectedInvoice) {
                $this->sendTextMessage($company, $phone, "Opción no válida. Por favor intenta de nuevo.");
                return true;
            }

            // Get the actual invoice record
            $invoice = DetFacturation::find($selectedInvoice['id']);
            if (!$invoice) {
                $this->sendTextMessage($company, $phone, "No pudimos encontrar esa factura. Por favor intenta de nuevo.");
                return true;
            }

            $clientName = $data['client_name'] ?? 'Cliente';

            // Confirm before sending PDF
            $session->update([
                'current_step' => 'confirm_download',
                'data' => array_merge($data, ['selected_invoice_id' => $selectedInvoice['id'], 'selected_number' => $selectedInvoice['number_facture']]),
                'expires_at' => self::vencimientoSesion(),
            ]);

            $wa = new WhatsAppService($company->id, false, 'meta');
            $wa->sendInteractiveButtons(
                $phone,
                "¿Descargar factura #{$selectedInvoice['number_facture']}?",
                [
                    ['id' => 'download_yes', 'title' => 'Descargar PDF'],
                    ['id' => 'download_no', 'title' => 'Elegir otra'],
                ]
            );

            return true;
        }

        if ($step === 'confirm_download') {
            $message = strtolower(trim($message));

            if ($message === 'download_no' || $message === 'no' || $message === '2' || $message === 'otra') {
                $session->update(['current_step' => 'select_invoice', 'expires_at' => self::vencimientoSesion()]);

                $this->sendInvoicePicker($company, $phone, $data['invoices'] ?? [], 'Está bien, selecciona otra factura:');
                return true;
            }

            if (!in_array($message, ['download_yes', 'sí', 'si', '1', 'yes', 'descargar'], true)) {
                return true; // Ignorar respuestas inesperadas
            }

            // Send the PDF
            $invoiceId = $data['selected_invoice_id'] ?? null;
            $invoiceNumber = $data['selected_number'] ?? 'factura';

            if (!$invoiceId) {
                $this->sendTextMessage($company, $phone, "Error al procesar tu solicitud.");
                $this->clearSession($company->id, $phone);
                return true;
            }

            $invoice = DetFacturation::find($invoiceId);
            if (!$invoice) {
                $this->sendTextMessage($company, $phone, "No pudimos encontrar la factura.");
                $this->clearSession($company->id, $phone);
                return true;
            }

            $this->sendInvoicePdf($company, $phone, $invoice);

            // Ask if they want another
            $session->update([
                'current_step' => 'ask_another',
                'data' => $data,
                'expires_at' => self::vencimientoSesion(),
            ]);

            $wa = new WhatsAppService($company->id, false, 'meta');
            $wa->sendInteractiveButtons(
                $phone,
                "¿Deseas descargar otra factura?",
                [
                    ['id' => 'another_yes', 'title' => 'Otra factura'],
                    ['id' => 'another_no', 'title' => 'Ir al menú'],
                ]
            );

            return true;
        }

        if ($step === 'ask_another') {
            $message = strtolower(trim($message));

            // Aceptar respuesta de botón O texto manual
            if ($message === 'another_yes' || $message === 'sí' || $message === 'si' || $message === '1' || $message === 'otra') {
                $session->update(['current_step' => 'select_invoice', 'expires_at' => self::vencimientoSesion()]);

                $this->sendInvoicePicker($company, $phone, $data['invoices'] ?? [], 'Selecciona otra factura:');
                return true;
            }

            if ($message === 'another_no' || $message === 'no' || $message === '2' || $message === 'menu' || $message === 'menú') {
                return $this->returnToMenu($company, $phone);
            }

            return true;
        }

        return true;
    }

    /**
     * Muestra las facturas para elegir: con tres o menos caben como botones;
     * con más hace falta una lista interactiva.
     *
     * Esta decisión vivía copiada en tres sitios y se desincronizó. Al tocar
     * "Elegir otra" se mandaban las cinco facturas como botones, Meta rechaza
     * más de tres, y el bot se quedaba callado a mitad del flujo.
     */
    private function sendInvoicePicker(Company $company, string $phone, array $invoices, string $bodyText): void
    {
        if ($invoices === []) return;

        $wa = new WhatsAppService($company->id, false, 'meta');

        if (count($invoices) > 3) {
            $wa->sendInteractiveList($phone, $bodyText, [[
                'title' => 'Tus facturas',
                'rows'  => array_map(fn ($inv) => [
                    'id'          => "invoice_{$inv['option']}",
                    'title'       => "Factura #{$inv['number_facture']}",
                    'description' => date('d/m/Y', strtotime($inv['date_facturation'] ?? now()->toDateString()))
                        . ' · ' . (($inv['balance'] ?? 0) > 0
                            ? 'saldo $' . number_format($inv['balance'], 0, ',', '.')
                            : 'pagada'),
                ], $invoices),
            ]], 'Ver facturas');

            return;
        }

        $wa->sendInteractiveButtons($phone, $bodyText, array_map(fn ($inv) => [
            'id' => "invoice_{$inv['option']}",
            // Las sesiones abiertas antes de este cambio no traen el título.
            'title' => $inv['button_title'] ?? ('#' . $inv['number_facture']),
        ], $invoices));
    }

    private function handleReportarPago(Company $company, WaBotSession $session, string $phone, string $message, array $payload = []): bool
    {
        $step = $session->current_step;
        $data = $session->data ?? [];

        if ($step === 'payment_complete') {
            if (in_array($message, ['payment_retry', 'reenviar comprobante', 'reenviar', '1'], true)) {
                $session->update(['current_step' => 'awaiting_payment_proof', 'expires_at' => self::vencimientoSesion()]);
                $this->sendTextMessage($company, $phone, 'Envía nuevamente la foto o el documento del comprobante. Verifica que se vean el monto, la fecha y la referencia.');
                return true;
            }

            if (in_array($message, ['payment_another', 'otra factura', 'otra', '1'], true)) {
                $session->update(['current_step' => 'ask_dni', 'expires_at' => self::vencimientoSesion()]);
                return $this->handleReportarPago($company, $session->fresh(), $phone, (string) ($data['client_dni'] ?? ''));
            }

            if (in_array($message, ['payment_menu', 'menu', 'menú', '2'], true)) {
                return $this->returnToMenu($company, $phone);
            }

            return true;
        }

        if ($step === 'ask_dni') {
            $dni = preg_replace('/[^0-9]/', '', $message);

            if (!$this->pareceCedula($dni)) {
                $this->sendTextMessage($company, $phone, "Por favor, ingresa un número de cédula válido.");
                return true;
            }

            $possibleClients = UserData::where('company_id', $company->id)
                ->where('dni', $dni)
                ->get();

            $client = null;
            foreach ($possibleClients as $candidate) {
                if ($this->phonesMatch($candidate->phone, $phone)) {
                    $client = $candidate;
                    break;
                }
            }

            if (!$client) {
                $this->sendTextMessage($company, $phone, "No pudimos validar esa cédula con este número de WhatsApp. Verifica los datos del titular y vuelve a ingresar el numero de cedula.");
                return true;
            }

            $cabIds = CabFacturation::where('company_id', $company->id)
                ->where('user_id', $client->user_id)
                ->pluck('id');

            $pendingInvoices = DetFacturation::whereIn('cab_id', $cabIds)
                ->where(function ($query) {
                    $query->where('paid', 0)->orWhereNull('paid');
                })
                ->orderByDesc('date_facturation')
                ->get();

            if ($pendingInvoices->isEmpty()) {
                $this->sendTextMessage($company, $phone, "No tienes facturas pendientes registradas para este titular. Si el pago ya fue realizado, revisa con soporte.");
                $this->clearSession($company->id, $phone);
                return true;
            }

            $invoiceList = [];
            foreach ($pendingInvoices as $index => $invoice) {
                $amount = (float) ($invoice->price_total ?? $invoice->total ?? 0);
                $discount = (float) ($invoice->price_discount ?? $invoice->discount ?? 0);
                $paidAmount = (float) ($invoice->price_abone ?? 0);
                $balance = max(0, $amount - $discount - $paidAmount);

                $invoiceList[] = [
                    'option' => $index + 1,
                    'id' => $invoice->id,
                    'number_facture' => $invoice->number_facture,
                    'total' => max(0, $amount - $discount),
                    'paid_amount' => $paidAmount,
                    'balance' => $balance,
                    'status' => ($invoice->paid ?? false) ? 'Pagada' : 'Pendiente',
                ];
            }

            $session->update([
                'current_step' => 'select_invoice_payment',
                'data' => array_merge($data, [
                    'client_id' => $client->id,
                    'client_name' => $client->names,
                    'client_dni' => $dni,
                    'pending_invoices' => $invoiceList,
                ]),
                'expires_at' => self::vencimientoSesion(),
            ]);

            $rows = array_map(static fn (array $inv): array => [
                'id' => "payment_invoice_{$inv['option']}",
                'title' => "Factura #{$inv['number_facture']}",
                'description' => 'Abonado $' . number_format($inv['paid_amount'], 0, ',', '.')
                    . ' | Restante $' . number_format($inv['balance'], 0, ',', '.'),
            ], $invoiceList);

            try {
                (new WhatsAppService($company->id, false, 'meta'))->sendInteractiveList(
                    $phone,
                    'Selecciona la factura que deseas pagar. Verás el abono acumulado y el saldo pendiente.',
                    [['title' => 'Facturas pendientes', 'rows' => $rows]],
                    'Ver facturas'
                );
            } catch (\Throwable $exception) {
                $text = "Estas son tus facturas pendientes:\n\n";
                foreach ($invoiceList as $inv) {
                    $text .= $inv['option'] . ". Factura #{$inv['number_facture']} - Abonado: $" . number_format($inv['paid_amount'], 0, ',', '.')
                        . " | Restante: $" . number_format($inv['balance'], 0, ',', '.') . "\n";
                }
                $text .= "\nEscribe el número de la factura que quieres pagar.";
                $this->sendTextMessage($company, $phone, $text);
            }
            return true;
        }

        if ($step === 'select_invoice_payment') {
            $selectedOption = preg_match('/payment_invoice_(\d+)/', $message, $matches)
                ? (int) $matches[1]
                : (int) trim($message);
            $invoices = $data['pending_invoices'] ?? [];

            if ($selectedOption <= 0) {
                $this->sendTextMessage($company, $phone, "Por favor escribe solo el número de la factura pendiente.");
                return true;
            }

            $selectedInvoice = null;
            foreach ($invoices as $inv) {
                if ((int) $inv['option'] === $selectedOption) {
                    $selectedInvoice = $inv;
                    break;
                }
            }

            if (!$selectedInvoice) {
                $this->sendTextMessage($company, $phone, "Esa factura no está en tu lista de pendientes. Escribe el número correcto.");
                return true;
            }

            $session->update([
                'current_step' => 'awaiting_payment_proof',
                'data' => array_merge($data, ['selected_invoice_id' => $selectedInvoice['id'], 'selected_invoice_number' => $selectedInvoice['number_facture'], 'selected_invoice_amount' => $selectedInvoice['balance']]),
                'expires_at' => self::vencimientoSesion(),
            ]);

            $this->sendTextMessage($company, $phone, "Perfecto. La factura seleccionada es #{$selectedInvoice['number_facture']} por $" . number_format($selectedInvoice['balance'], 0, ',', '.') . ".\n\nAhora envía la foto o documento del comprobante. Si lo prefieres, también escribe: monto y fecha, por ejemplo: 'Monto: 140000 Fecha: 30/08/2026'.");
            return true;
        }

        if ($step === 'awaiting_payment_proof') {
            $result = $this->validatePaymentProof($company, $session, $phone, $message, $payload);
            $this->sendTextMessage($company, $phone, $result['message']);

            if ($result['approved']) {
                $session->update([
                    'current_step' => 'payment_complete',
                    'expires_at' => self::vencimientoSesion(),
                ]);
                (new WhatsAppService($company->id, false, 'meta'))->sendInteractiveButtons(
                    $phone,
                    '¿Qué deseas hacer ahora?',
                    [
                        ['id' => 'payment_another', 'title' => 'Pagar otra factura'],
                        ['id' => 'payment_menu', 'title' => 'Ir al menú'],
                    ]
                );
                return true;
            }

            if ($result['can_continue'] ?? false) {
                $session->update([
                    'current_step' => 'payment_complete',
                    'expires_at' => self::vencimientoSesion(),
                ]);
                (new WhatsAppService($company->id, false, 'meta'))->sendInteractiveButtons(
                    $phone,
                    'Puedes reenviar el comprobante, elegir otra factura pendiente o volver al menú principal.',
                    [
                        ['id' => 'payment_retry', 'title' => 'Reenviar comprobante'],
                        ['id' => 'payment_another', 'title' => 'Otra factura'],
                        ['id' => 'payment_menu', 'title' => 'Ir al menú'],
                    ]
                );
                return true;
            }

            $this->clearSession($company->id, $phone);
            return true;
        }

        return true;
    }

    private function validatePaymentProof(Company $company, WaBotSession $session, string $phone, string $message, array $payload = []): array
    {
        $data = $session->data ?? [];
        $clientId = $data['client_id'] ?? null;
        $selectedInvoiceId = $data['selected_invoice_id'] ?? null;

        if (!$clientId || !$selectedInvoiceId) {
            return ['approved' => false, 'message' => 'No pudimos relacionar el comprobante con una factura pendiente válida.'];
        }

        $client = UserData::find($clientId);
        if (!$client) {
            return ['approved' => false, 'message' => 'No encontré el titular asociado a esta cédula.'];
        }

        $invoice = DetFacturation::find($selectedInvoiceId);
        if (!$invoice) {
            return ['approved' => false, 'message' => 'La factura seleccionada ya no está disponible en el sistema.'];
        }

        $baseAmount = (float) ($invoice->price_total ?? $invoice->total ?? 0);
        $discount = (float) ($invoice->price_discount ?? $invoice->discount ?? 0);
        $alreadyPaid = (float) ($invoice->price_abone ?? 0);
        $expectedAmount = max(0, $baseAmount - $discount - $alreadyPaid);

        $text = trim($message);
        $media = $payload['payment_proof_media'] ?? [];
        $mediaEvidence = $this->storePaymentProofMedia($company, $media);
        $ocrText = $mediaEvidence['local_path'] ? $this->extractTextFromProof($mediaEvidence['local_path']) : null;
        $details = $this->extractPaymentProofDetails(trim($text . "\n" . ($ocrText ?? '')));
        $reference = $details['reference'] ?: $details['invoice_number'];

        if ($reference) {
            $previousProof = PaymentProof::where('company_id', $company->id)
                ->where('reference_number', $reference)
                ->latest('created_at')
                ->first();

            if ($previousProof) {
                return [
                    'approved' => false,
                    'can_continue' => true,
                    'message' => "Este comprobante ya fue procesado anteriormente.\n\nReferencia: {$reference}\nFactura asociada: #{$previousProof->invoice?->number_facture}\n\nPara proteger tu pago, no se aplicó ningún valor adicional.",
                ];
            }
        }

        try {
            $proofRecord = PaymentProof::create([
                'company_id' => $company->id,
                'user_id' => $client->id,
                'invoice_id' => $invoice->id,
                'file_path' => $mediaEvidence['path'],
                'file_name' => $mediaEvidence['name'],
                'file_hash' => $mediaEvidence['hash'],
                'reported_amount' => $details['amount'],
                'detected_amount' => $details['amount'],
                'payment_date' => $details['payment_date'],
                'reference_number' => $reference,
                'bank_name' => $details['bank_name'],
                'ocr_text' => $ocrText ?: $text,
                'status' => 'pending',
                'rejection_reason' => null,
                'raw_payload' => [
                'message' => $text,
                'phone' => $phone,
                'client_id' => $client->id,
                'invoice_number' => $invoice->number_facture,
                'expected_amount' => $expectedAmount,
                'detected_amount' => $details['amount'],
                'detected_date' => $details['payment_date'],
                'ocr_extraction' => $details['metadata'],
                'media' => $media,
                ],
            ]);
        } catch (QueryException $exception) {
            if ($reference) {
                return [
                    'approved' => false,
                    'can_continue' => true,
                    'message' => "Este comprobante ya fue procesado anteriormente con la referencia {$reference}.\n\nPara proteger tu pago, no se aplicó ningún valor adicional.",
                ];
            }

            throw $exception;
        }

        // Aviso interno: al destino que la empresa eligió en Avisos y destinos.
        \App\Services\Crm\ComprobanteWhatsAppWeb::avisar(
            (int) $company->id,
            trim(($client->names ?? '') . ' ' . ($client->lastname ?? '')) ?: ('Cliente #' . $client->id),
            $proofRecord,
            'bot de WhatsApp'
        );

        if (!$details['payment_date'] || !$reference) {
            $missingFields = [];
            if (!$details['payment_date']) {
                $missingFields[] = 'fecha';
            }
            if (!$reference) {
                $missingFields[] = 'referencia';
            }
            $reason = 'Comprobante pendiente de revisión: no fue posible identificar ' . implode(' y ', $missingFields) . '.';

            $proofRecord->update(['status' => 'pending', 'rejection_reason' => $reason]);
            PaymentProofAudit::create([
                'payment_proof_id' => $proofRecord->id,
                'old_status' => 'pending',
                'new_status' => 'pending',
                'reason' => $reason,
                'metadata' => ['source' => 'automatic_validation', 'missing_fields' => $missingFields],
            ]);

            return ['approved' => false, 'can_continue' => true, 'message' => "Recibimos tu comprobante para la factura #{$invoice->number_facture}. Quedó pendiente de revisión porque no pudimos identificar " . implode(' y ', $missingFields) . '. No se aplicó ningún pago todavía.'];
        }

        // El cliente puede pagar una cifra cercana a la deuda, por ejemplo 49.900 o 50.100
        // sobre un total pendiente de 50.000, siempre que esté dentro de una tolerancia razonable.
        $tolerance = max(5000, $expectedAmount * 0.1);
        $minAccepted = max(0, $expectedAmount - $tolerance);
        $maxAccepted = $expectedAmount + $tolerance;

        $amountMatches = $details['amount'] !== null && $details['amount'] >= $minAccepted && $details['amount'] <= $maxAccepted;
        $invoiceMatches = $details['invoice_number'] === null || (string) $invoice->number_facture === (string) $details['invoice_number'];

        if ($invoiceMatches && $amountMatches) {
            $payedAmount = (float) ($invoice->price_abone ?? 0) + $details['amount'];
            $invoice->update([
                'paid' => $payedAmount >= max(0, $baseAmount - $discount) ? 1 : 0,
                'paid_at' => $payedAmount >= max(0, $baseAmount - $discount) ? now() : null,
                'paid_by_user_id' => $client->id,
                'price_abone' => $payedAmount,
                'abone' => $payedAmount >= max(0, $baseAmount - $discount) ? 1 : 0,
            ]);

            $proofRecord->update([
                'status' => 'approved',
                'rejection_reason' => null,
                'reported_amount' => $details['amount'],
                'payment_date' => $details['payment_date'],
            ]);

            PaymentProofAudit::create([
                'payment_proof_id' => $proofRecord->id,
                'old_status' => 'pending',
                'new_status' => 'approved',
                'reason' => 'Aprobado automáticamente: monto, fecha y referencia coinciden con la factura seleccionada.',
                'metadata' => [
                    'source' => 'automatic_validation',
                    'approved_amount' => $details['amount'],
                    'reference_number' => $reference,
                ],
            ]);

            UserData::where('id', $client->id)->update([
                'active' => true,
                'status' => true,
            ]);

            $invoiceTotal = max(0, $baseAmount - $discount);
            $remainingAmount = max(0, $invoiceTotal - $payedAmount);
            $messageStatus = $remainingAmount === 0 ? 'Pago registrado exitosamente.' : 'Abono registrado exitosamente.';
            $paymentDate = $details['payment_date'] ? date('d/m/Y', strtotime($details['payment_date'])) : 'No identificada';
            $referenceText = $reference ?: 'No identificada';
            $bankText = $details['bank_name'] ?: 'No identificada';

            return [
                'approved' => true,
                'message' => "{$messageStatus}\n\nResumen de tu reporte:\n"
                    . "Factura: #{$invoice->number_facture}\n"
                    . 'Valor recibido: $' . number_format($details['amount'], 0, ',', '.') . "\n"
                    . "Referencia: {$referenceText}\n"
                    . "Fecha del comprobante: {$paymentDate}\n"
                    . "Entidad: {$bankText}\n"
                    . 'Abonos acumulados: $' . number_format($payedAmount, 0, ',', '.') . "\n"
                    . 'Saldo pendiente: $' . number_format($remainingAmount, 0, ',', '.'),
            ];
        }

        $proofRecord->update([
            'status' => 'pending',
            'rejection_reason' => $details['amount'] !== null || $details['payment_date'] !== null || $details['invoice_number'] !== null
                ? 'El comprobante no coincide con la factura seleccionada o con el monto pendiente.'
                : 'No se indicó un monto o referencia verificable.',
        ]);

        if ($details['amount'] !== null || $details['payment_date'] !== null || $details['invoice_number'] !== null) {
            return ['approved' => false, 'can_continue' => true, 'message' => 'No pudimos aplicar este comprobante a la factura seleccionada porque el monto o los datos no coinciden con el saldo pendiente. No se aplicó ningún pago.'];
        }

        return ['approved' => false, 'can_continue' => true, 'message' => 'Recibimos el comprobante, pero aún no fue posible identificar sus datos de pago. No se aplicó ningún pago.'];
    }

    private function storePaymentProofMedia(Company $company, array $media): array
    {
        $mediaId = $media['id'] ?? null;
        if (!$mediaId || !$company->wa_access_token) {
            return ['path' => null, 'name' => $media['filename'] ?? null, 'hash' => null, 'local_path' => null];
        }

        try {
            $metadata = Http::withToken($company->wa_access_token)
                ->get("https://graph.facebook.com/v21.0/{$mediaId}")
                ->throw()
                ->json();
            $downloadUrl = $metadata['url'] ?? null;

            if (!$downloadUrl) {
                return ['path' => null, 'name' => $media['filename'] ?? null, 'hash' => null];
            }

            $response = Http::withToken($company->wa_access_token)->get($downloadUrl)->throw();
            $content = $response->body();
            $mimeType = $response->header('Content-Type', $metadata['mime_type'] ?? 'application/octet-stream');
            $extension = match (strtolower(explode(';', $mimeType)[0])) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'application/pdf' => 'pdf',
                default => 'bin',
            };
            $fileName = $media['filename'] ?? "comprobante-{$mediaId}.{$extension}";
            $path = "payment-proofs/{$company->id}/" . now()->format('Y/m') . '/' . uniqid('proof-', true) . ".{$extension}";

            Storage::disk('public')->put($path, $content);

            return [
                'path' => url(Storage::disk('public')->url($path)),
                'name' => $fileName,
                'hash' => hash('sha256', $content),
                'local_path' => Storage::disk('public')->path($path),
            ];
        } catch (\Throwable $exception) {
            Log::warning('[WaBotService] No fue posible guardar el comprobante adjunto', [
                'company_id' => $company->id,
                'media_id' => $mediaId,
                'error' => $exception->getMessage(),
            ]);

            return ['path' => null, 'name' => $media['filename'] ?? null, 'hash' => null, 'local_path' => null];
        }
    }

    /** Público: lo reusa también el flujo de comprobantes de WhatsApp Web. */
    public function extractTextFromProof(string $path): ?string
    {
        try {
            $process = new Process(['tesseract', $path, 'stdout', '-l', 'spa', '--psm', '6']);
            $process->setTimeout(30);
            $process->run();

            return $process->isSuccessful() ? trim($process->getOutput()) : null;
        } catch (\Throwable $exception) {
            Log::warning('[WaBotService] OCR no disponible para comprobante', ['error' => $exception->getMessage()]);
            return null;
        }
    }

    /** Público: lo reusa también el flujo de comprobantes de WhatsApp Web. */
    /**
     * El monto del comprobante.
     *
     * Antes se tomaba el primer número que apareciera después de «¿Cuánto?»,
     * y el OCR mete ruido: en un comprobante de Nequi la línea salió como
     * «2 $70.008,00 ?» y se guardaron dos pesos.
     *
     * Ahora se juntan todos los candidatos marcados con «$» y gana el mayor
     * de los que estén cerca de la palabra clave; si no hay ninguno marcado,
     * recién ahí se mira un número suelto. El OCR puede errar un dígito en
     * una foto movida —«70.008» por «70.000»—, así que el valor se ofrece
     * para confirmar, no para creerle a ciegas.
     */
    private function montoDelComprobante(string $text): ?float
    {
        $candidatos = [];

        // Con signo de peso: es lo que de verdad indica un importe.
        if (preg_match_all('/\$\s*([\d][\d.,]{2,})/u', $text, $m)) {
            foreach ($m[1] as $bruto) {
                $v = $this->parsePaymentAmount($bruto);
                if ($v !== null && $v >= 100) {
                    $candidatos[] = $v;
                }
            }
        }

        if ($candidatos) {
            // El mayor: en un comprobante los otros números con peso suelen ser
            // costos («a otros bancos te cuesta $7.590»), nunca el importe.
            return max($candidatos);
        }

        // Sin signo de peso, se busca junto a la palabra que lo anuncia.
        if (preg_match('/(?:valor\s+de\s+la\s+transferencia|valor\s+transferido|monto\s+transferido|importe\s+enviado|cu[aá]nto\??|monto|valor|total)[^\d]{0,80}([\d][\d.,]{2,})/iu', $text, $match)) {
            return $this->parsePaymentAmount($match[1]);
        }

        return null;
    }

    public function extractPaymentProofDetails(string $text): array
    {
        $amount = $this->montoDelComprobante($text);

        $date = null;
        if (preg_match('/(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})/', $text, $match)) {
            $date = $this->parsePaymentDate($match[1]);
        } elseif (preg_match('/(\d{1,2})\s+de\s+(enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|octubre|noviembre|diciembre)\s+de\s+(\d{4})/iu', $text, $match)) {
            $months = ['enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12];
            $date = sprintf('%04d-%02d-%02d', $match[3], $months[mb_strtolower($match[2])], $match[1]);
        } elseif (preg_match('/(\d{1,2})\s+(ene|feb|mar|abr|may|jun|jul|ago|sep|oct|nov|dic)\w*\s+(\d{4})/iu', $text, $match)) {
            $months = ['ene' => 1, 'feb' => 2, 'mar' => 3, 'abr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'ago' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dic' => 12];
            $date = sprintf('%04d-%02d-%02d', $match[3], $months[mb_strtolower($match[2])], $match[1]);
        }

        preg_match('/(?:factura|invoice|#)(?:\s*#?)([A-Za-z]*\d+)/i', $text, $invoiceMatch);
        preg_match('/^Para\s*\R\s*([^\r\n]+)/mu', $text, $recipientMatch);
        preg_match('/N[uú]mero\s+(?:Nequi|de cuenta)\s*\n?\s*([\d\s-]+)/iu', $text, $destinationMatch);
        preg_match('/(?:Env[ií]o|Pago|Transferencia)\s+Realizado/iu', $text, $statusMatch);

        return [
            'amount' => $amount,
            'payment_date' => $date,
            'reference' => $this->extractReference($text),
            'bank_name' => $this->extractBankName($text),
            'invoice_number' => $invoiceMatch[1] ?? null,
            'metadata' => [
                'status' => 'automatic',
                'recipient' => isset($recipientMatch[1]) ? trim($recipientMatch[1]) : null,
                'phone_destination' => isset($destinationMatch[1]) ? preg_replace('/\s+/', ' ', trim($destinationMatch[1])) : null,
                'transaction_status' => $statusMatch[0] ?? null,
                'extracted_at' => now()->toDateTimeString(),
            ],
        ];
    }

    private function parsePaymentAmount(string $amount): float
    {
        $amount = preg_replace('/[\.,]\d{2}$/', '', trim($amount));
        return (float) preg_replace('/[^\d]/', '', $amount);
    }

    /**
     * El número de referencia del comprobante.
     *
     * El patrón viejo era «ref(?:erencia)?» y se mordía la cola: contra la
     * palabra «Referencia», si no encontraba un número aceptable después,
     * retrocedía, se quedaba con «Ref» y capturaba «erencia» como si fuera la
     * referencia. Pasó con el pago de SULEIMA NAVARRO, cuyo comprobante decía:
     *
     *     Referencia
     *     2 |M23270977
     *
     * y quedó guardado «erencia». Dos cosas lo arreglan: la etiqueta ahora
     * termina en \b —«Ref» seguido de «erencia» ya no es una etiqueta
     * válida— y, en vez de exigir que el número venga pegado, se mira el
     * pedazo siguiente y se elige el candidato que más se parece a una
     * referencia. El OCR mete basura en el medio («2 |») y antes eso bastaba
     * para perder el dato.
     */
    /**
     * El mejor candidato a referencia dentro de un pedazo de texto.
     *
     * Se prefiere lo que mezcla letras y números (M23270977 de Nequi, los
     * códigos de Bancolombia) sobre un número pelado, y lo más largo sobre lo
     * más corto: el OCR deja sueltos como «2» o «|» que no son referencias de
     * nada.
     */
    public static function mejorReferencia(string $trozo): ?string
    {
        preg_match_all('/[A-Za-z0-9][A-Za-z0-9-]{3,}/u', $trozo, $todos);

        $candidatos = array_filter($todos[0] ?? [], function (string $c) {
            // Una referencia SIEMPRE lleva dígitos. Sin esta regla se colaba
            // una palabra del propio comprobante —«transferencias» ganaba por
            // ser más larga que el número de verdad— y pisaba una referencia
            // que estaba bien.
            if (!preg_match('/\d/', $c)) {
                return false;
            }

            // Y una fecha suelta tampoco es una referencia.
            return !preg_match('/^\d{1,2}[-\/]\d{1,2}([-\/]\d{2,4})?$/', $c);
        });

        if (!$candidatos) {
            return null;
        }

        usort($candidatos, function (string $a, string $b) {
            // Con letras Y números primero: es la forma de casi toda
            // referencia bancaria y descarta los sueltos del OCR.
            $mezcla = fn (string $x) => (int) (preg_match('/[A-Za-z]/', $x) && preg_match('/\d/', $x));

            return [$mezcla($b), strlen($b)] <=> [$mezcla($a), strlen($a)];
        });

        return trim($candidatos[0]);
    }

    private function extractReference(string $text): ?string
    {
        $etiqueta = '/(?:comprobante\s+(?:no\.?|n[uú]mero)|n[uú]mero\s+de\s+(?:operaci[oó]n|aprobaci[oó]n|transacci[oó]n)|referencia|ref\.?|cus)\b/iu';

        if (preg_match($etiqueta, $text, $m, PREG_OFFSET_CAPTURE)) {
            // Lo que viene justo después de la etiqueta, que es donde está el
            // número aunque haya un salto de línea y algún carácter suelto.
            $cola = mb_substr(substr($text, $m[0][1] + strlen($m[0][0])), 0, 60);

            if ($ref = self::mejorReferencia($cola)) {
                return $ref;
            }
        }

        // Sin etiqueta: un número largo suelto es lo más probable.
        if (preg_match('/\b\d{6,20}\b/', $text, $matches)) {
            return trim($matches[0]);
        }

        return null;
    }

    private function extractBankName(string $text): ?string
    {
        $text = strtolower($text);
        $banks = [
            'bancolombia', 'davivienda', 'bbva', 'bogota', 'scotiabank', 'occidente', 'itau', 'colpatria', 'av villas',
            'banco de bogota', 'banco de occidente', 'banco de bogotá', 'nequi', 'daviplata', 'transferencia', 'efecty',
        ];

        foreach ($banks as $bank) {
            if (str_contains($text, $bank)) {
                return ucfirst($bank);
            }
        }

        return null;
    }

    private function parsePaymentDate(?string $dateString): ?string
    {
        if (!$dateString) {
            return null;
        }

        $date = null;
        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})$/', $dateString, $matches)) {
            $day = (int) $matches[1];
            $month = (int) $matches[2];
            $year = (int) $matches[3];
            if ($year < 100) {
                $year += 2000;
            }
            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
        }

        return $date;
    }

    private function handleConsultarRevision(Company $company, WaBotSession $session, string $phone, string $message): bool
    {
        $step = $session->current_step;
        $data = $session->data ?? [];

        if ($step === 'ask_dni') {
            $dni = preg_replace('/[^0-9]/', '', $message);
            $client = UserData::where('company_id', $company->id)
                ->where('dni', $dni)
                ->first();

            if (!$client) {
                $this->sendTextMessage($company, $phone, "No encontré un cliente con esa cédula. Por favor verifica e intenta de nuevo, o escribe *menu* para volver al inicio.");
                return true;
            }

            // Los dos desenlaces de abajo cierran la sesión, así que no hay un
            // paso siguiente que preparar.
            // Últimos tickets
            $tickets = Ticket::where('company_id', $company->id)
                ->where('user_id', $client->user_id)
                ->orderByDesc('created_at')
                ->limit(5)
                ->get();

            if ($tickets->isEmpty()) {
                $this->sendTextMessage($company, $phone, "Hola {$client->names}, no tienes tickets de revisión o soporte registrados.\n\nEscribe *menu* para volver al inicio.");
                $this->clearSession($company->id, $phone);
                return true;
            }

            $text = "Hola {$client->names}, estos son tus últimos tickets:\n\n";
            foreach ($tickets as $ticket) {
                $statusMap = ['Abierto', 'En progreso', 'Cerrado', 'Pendiente'];
                $status = $statusMap[$ticket->status_id - 1] ?? 'Desconocido';
                $text .= "• Ticket #{$ticket->id}\n";
                $text .= "  Fecha: " . date('d/m/Y', strtotime($ticket->date)) . "\n";
                $text .= "  Dirección: {$ticket->address}\n";
                $text .= "  Estado: {$status}\n\n";
            }
            $text .= "Escribe *menu* para volver al inicio.";

            $this->sendTextMessage($company, $phone, $text);
            $this->clearSession($company->id, $phone);
            return true;
        }

        return true;
    }

    // ─── Flujo: pagar factura en línea ───────────────────────────────────────

    /**
     * Genera un link de pago y lo entrega como botón dentro del chat.
     * El cliente elige entre pagar una factura puntual o todo lo pendiente.
     */
    private function handlePagarFactura(Company $company, WaBotSession $session, string $phone, string $message): bool
    {
        $step = $session->current_step;
        $data = $session->data ?? [];

        if (!$this->onlinePaymentAvailable($company)) {
            $this->sendTextMessage($company, $phone, "El pago en línea no está disponible por ahora.\n\nEscribe *menu* para volver al inicio.");
            $this->clearSession($company->id, $phone);
            return true;
        }

        // ── Paso 1: identificar al titular ───────────────────────────────────
        if ($step === 'ask_dni') {
            $dni = preg_replace('/[^0-9]/', '', $message);

            if (!$this->pareceCedula($dni)) {
                $this->sendTextMessage($company, $phone, "Por favor, ingresa un número de cédula válido.");
                return true;
            }

            // El teléfono que escribe debe ser el registrado: el link revela
            // el saldo y el nombre del titular, no puede pedirlo cualquiera.
            // Si el remitente no trae teléfono, se le pide que lo escriba.
            if (self::isUserIdentity($phone) && empty($data['verified_phone'])) {
                $session->update([
                    'current_step' => 'pay_ask_phone',
                    'data' => array_merge($data, ['dni' => $dni]),
                    'expires_at' => self::vencimientoSesion(),
                ]);
                $this->sendTextMessage($company, $phone, "Para confirmar que eres el titular, escríbeme el número de celular registrado en tu cuenta.");
                return true;
            }

            $client = $this->resolveDniOwner($company, $dni, $phone, $data['verified_phone'] ?? null);

            if (!$client) {
                $this->sendTextMessage($company, $phone, "No pudimos validar esos datos con este número de WhatsApp. Verifica la cédula registrada en tu cuenta o comunícate con soporte.");
                return true;
            }

            $invoices = $this->pendingInvoicesFor($company, (int) $client->user_id);

            if ($invoices->isEmpty()) {
                $this->sendTextMessage($company, $phone, "Hola {$client->names}, no tienes facturas pendientes. ¡Estás al día!\n\nEscribe *menu* para volver al inicio.");
                $this->clearSession($company->id, $phone);
                return true;
            }

            $total = $invoices->sum(fn ($inv) => $this->invoiceBalance($inv));

            $session->update([
                'current_step' => 'choose_scope',
                'data' => array_merge($data, [
                    'client_user_id' => (int) $client->user_id,
                    'client_name'    => $client->names,
                ]),
                'expires_at' => self::vencimientoSesion(),
            ]);

            // Con una sola factura pendiente no tiene sentido preguntar.
            if ($invoices->count() === 1) {
                return $this->sendPaymentLink($company, $session, $phone, null, $total, $invoices->count());
            }

            $wa = new WhatsAppService($company->id, false, 'meta');
            $this->recordBotConversationMessage($company, $phone, 'system', 'Opciones de pago');
            $wa->sendInteractiveButtons(
                $phone,
                "Hola {$client->names}, tienes {$invoices->count()} facturas pendientes por un total de " . $this->formatMoney($total) . ".\n\n¿Qué deseas pagar?",
                [
                    ['id' => 'pay_all', 'title' => 'Pagar todo'],
                    ['id' => 'pay_one', 'title' => 'Elegir factura'],
                    ['id' => 'pay_cancel', 'title' => 'Cancelar'],
                ]
            );

            return true;
        }

        if ($step === 'pay_ask_phone') {
            $typed = preg_replace('/[^0-9]/', '', $message);

            if (strlen($typed) < 10) {
                $this->sendTextMessage($company, $phone, "Escríbeme el número de celular completo, sin espacios ni guiones.");
                return true;
            }

            if (!$this->resolveDniOwner($company, (string) ($data['dni'] ?? ''), $phone, $typed)) {
                if ($this->tooManyAttempts($session)) {
                    $this->sendTextMessage($company, $phone, "Por seguridad cerramos la solicitud. Comunícate con soporte para verificar tu cuenta.");
                    $this->clearSession($company->id, $phone);
                    return true;
                }

                $this->sendTextMessage($company, $phone, "No pudimos validar esos datos con este número de WhatsApp. Verifica la cédula registrada en tu cuenta o comunícate con soporte.");
                return true;
            }

            $session->update([
                'current_step' => 'ask_dni',
                'data' => array_merge($data, ['verified_phone' => $typed, 'dni_attempts' => 0]),
                'expires_at' => self::vencimientoSesion(),
            ]);

            return $this->handlePagarFactura($company, $session->fresh(), $phone, (string) $data['dni']);
        }

        // ── Medio de pago: directo a Nequi o a la lista completa ─────────────
        if ($step === 'choose_method') {
            $url = (string) ($data['pay_url'] ?? '');

            if ($url === '' || in_array($message, ['pm_cancel', 'cancelar', '3'], true)) {
                $this->sendTextMessage($company, $phone, "Listo, cancelamos el pago.\n\nEscribe *menu* para volver al inicio.");
                $this->clearSession($company->id, $phone);
                return true;
            }

            if (in_array($message, ['pm_nequi', 'nequi', '1'], true)) {
                return $this->preguntarCelularDeNequi($company, $session, $phone);
            }

            return $this->sendPaymentCta(
                $company,
                $phone,
                $url,
                (float) ($data['pay_amount'] ?? 0),
                (int) ($data['pay_count'] ?? 1),
                $data['pay_vence'] ?? null,
                null
            );
        }

        // ── Nequi: a qué celular se le manda el cobro ────────────────────────
        if ($step === 'nequi_phone') {
            if (in_array($message, ['pm_cancel', 'cancelar', '3'], true)) {
                $this->sendTextMessage($company, $phone, "Listo, cancelamos el pago.\n\nEscribe *menu* para volver al inicio.");
                $this->clearSession($company->id, $phone);
                return true;
            }

            if (in_array($message, ['nq_otro', '2'], true)) {
                $session->update(['current_step' => 'nequi_otro', 'expires_at' => self::vencimientoSesion()]);
                $this->sendTextMessage($company, $phone, "Escribe el celular de Nequi al que quieres que te llegue el cobro (10 dígitos).");

                return true;
            }

            $escrito = $this->celularEscrito($message);

            if ($escrito) {
                return $this->dispararCobroNequi($company, $session, $phone, $escrito);
            }

            if (in_array($message, ['nq_si', 'si', 'sí', '1'], true)) {
                return $this->dispararCobroNequi($company, $session, $phone, (string) (($session->data ?? [])['nequi_phone'] ?? ''));
            }

            $this->sendTextMessage($company, $phone, "No te entendí. Responde *SÍ* para usar ese número, o escribe otro celular de 10 dígitos.");

            return true;
        }

        if ($step === 'nequi_otro') {
            if (in_array($message, ['pm_cancel', 'cancelar'], true)) {
                $this->sendTextMessage($company, $phone, "Listo, cancelamos el pago.\n\nEscribe *menu* para volver al inicio.");
                $this->clearSession($company->id, $phone);
                return true;
            }

            $escrito = $this->celularEscrito($message);

            if (!$escrito) {
                $this->sendTextMessage($company, $phone, "Ese número no parece un celular de 10 dígitos. Escríbelo de nuevo, por ejemplo 3001234567.");

                return true;
            }

            return $this->dispararCobroNequi($company, $session, $phone, $escrito);
        }

        // ── Paso 2: alcance del pago ─────────────────────────────────────────
        if ($step === 'choose_scope') {
            $clientUserId = (int) ($data['client_user_id'] ?? 0);
            if (!$clientUserId) {
                $this->clearSession($company->id, $phone);
                return false;
            }

            if (in_array($message, ['pay_cancel', 'cancelar', '3'], true)) {
                $this->sendTextMessage($company, $phone, "Listo, cancelamos el pago.\n\nEscribe *menu* para volver al inicio.");
                $this->clearSession($company->id, $phone);
                return true;
            }

            $invoices = $this->pendingInvoicesFor($company, $clientUserId);

            if ($invoices->isEmpty()) {
                $this->sendTextMessage($company, $phone, "Ya no tienes facturas pendientes. ¡Estás al día!");
                $this->clearSession($company->id, $phone);
                return true;
            }

            if (in_array($message, ['pay_all', 'todo', '1'], true)) {
                $total = $invoices->sum(fn ($inv) => $this->invoiceBalance($inv));
                return $this->sendPaymentLink($company, $session, $phone, null, $total, $invoices->count());
            }

            if (in_array($message, ['pay_one', 'elegir', '2'], true)) {
                $rows = [];
                foreach ($invoices->take(10) as $inv) {
                    $rows[] = [
                        'id'          => 'pay_inv_' . $inv->id,
                        'title'       => mb_substr('#' . $inv->number_facture, 0, 24),
                        'description' => date('d/m/Y', strtotime($inv->date_facturation)) . ' — ' . $this->formatMoney($this->invoiceBalance($inv)),
                    ];
                }

                $session->update(['current_step' => 'choose_invoice', 'expires_at' => self::vencimientoSesion()]);

                $wa = new WhatsAppService($company->id, false, 'meta');
                $this->recordBotConversationMessage($company, $phone, 'system', 'Listado de facturas por pagar');
                $wa->sendInteractiveList(
                    $phone,
                    'Selecciona la factura que deseas pagar:',
                    [['title' => 'Pendientes', 'rows' => $rows]],
                    'Ver facturas'
                );

                return true;
            }

            $this->sendTextMessage($company, $phone, "No entendí esa opción. Responde con uno de los botones o escribe *menu*.");
            return true;
        }

        // ── Paso 3: factura puntual ──────────────────────────────────────────
        if ($step === 'choose_invoice') {
            $clientUserId = (int) ($data['client_user_id'] ?? 0);

            if (!preg_match('/^pay_inv_(\d+)$/', $message, $m)) {
                $this->sendTextMessage($company, $phone, "Por favor selecciona una factura de la lista.");
                return true;
            }

            $invoiceId = (int) $m[1];

            // La factura debe seguir siendo suya y seguir pendiente.
            $invoice = $this->pendingInvoicesFor($company, $clientUserId)
                ->first(fn ($inv) => (int) $inv->id === $invoiceId);

            if (!$invoice) {
                $this->sendTextMessage($company, $phone, "Esa factura ya no está pendiente. Escribe *menu* para empezar de nuevo.");
                $this->clearSession($company->id, $phone);
                return true;
            }

            return $this->sendPaymentLink($company, $session, $phone, [$invoiceId], $this->invoiceBalance($invoice), 1);
        }

        return false;
    }

    /** Crea el link, lo manda como botón y cierra la sesión. */
    private function sendPaymentLink(
        Company $company,
        WaBotSession $session,
        string $phone,
        ?array $invoiceIds,
        float $amount,
        int $invoiceCount
    ): bool {
        $data         = $session->data ?? [];
        $clientUserId = (int) ($data['client_user_id'] ?? 0);

        try {
            $service = app(PaymentLinkService::class);
            $link    = $service->create($company, $clientUserId, $invoiceIds, 'bot', null, $phone);
            $url     = $service->publicUrl($link);
        } catch (\Throwable $e) {
            Log::error('[WaBotService] No se pudo crear el link de pago', [
                'company_id' => $company->id,
                'error'      => $e->getMessage(),
            ]);

            $this->sendTextMessage($company, $phone, "No pudimos generar tu link de pago en este momento. Intenta de nuevo en unos minutos.");
            $this->clearSession($company->id, $phone);
            return true;
        }

        $vence = $link->expires_at ? $link->expires_at->format('d/m/Y') : null;

        // Con Nequi disponible se pregunta primero, porque llevarlo directo al
        // formulario de Nequi le ahorra elegir entre una lista de medios.
        if ($this->nequiSirveParaEsteMonto($company, $amount)) {
            $session->update([
                'current_step' => 'choose_method',
                'data' => array_merge($data, [
                    'pay_url'      => $url,
                    'pay_amount'   => $amount,
                    'pay_count'    => $invoiceCount,
                    'pay_vence'    => $vence,
                    // Para el cobro por Nequi hace falta saber qué facturas
                    // son: el cobro se crea aparte del link.
                    'pay_invoices' => $invoiceIds,
                ]),
                'expires_at' => self::vencimientoSesion(),
            ]);

            $texto = "Total a pagar: " . $this->formatMoney($amount) . "\n\n¿Cómo prefieres pagar?";
            $this->recordBotConversationMessage($company, $phone, 'system', $texto);

            (new WhatsAppService($company->id, false, 'meta'))->sendInteractiveButtons($phone, $texto, [
                ['id' => 'pm_nequi',  'title' => 'Pagar con Nequi'],
                ['id' => 'pm_all',    'title' => 'PSE y otros'],
                ['id' => 'pm_cancel', 'title' => 'Cancelar'],
            ]);

            return true;
        }

        return $this->sendPaymentCta($company, $phone, $url, $amount, $invoiceCount, $vence, null);
    }

    /**
     * ¿Se le puede ofrecer Nequi por este monto?
     *
     * El checkout esconde el medio cuando el monto supera su tope, así que un
     * cobro "solo Nequi" por encima del límite dejaría al cliente sin ningún
     * botón que tocar. Ante la duda, no se ofrece.
     */
    private function nequiSirveParaEsteMonto(Company $company, float $amount): bool
    {
        return match (strtolower((string) $company->pg_gateway)) {
            // EfiPay no dice qué acepta: hay que averiguar el tope con una
            // sonda y, si no se pudo averiguar, no se ofrece.
            'efipay' => ($max = (new EfiPayGateway($company))->nequiMaxAmount()) !== null && $amount <= $max,
            // Wompi sí lista los medios que la cuenta tiene habilitados.
            'wompi'  => (new WompiGateway($company))->aceptaNequi($amount),
            default  => false,
        };
    }

    /**
     * Pregunta a qué celular se le manda el cobro de Nequi.
     *
     * Se propone el que tenemos registrado, porque casi siempre es el mismo
     * desde el que escribe; cambiarlo es un botón, no una obligación.
     */
    private function preguntarCelularDeNequi(Company $company, WaBotSession $session, string $phone): bool
    {
        $data = $session->data ?? [];

        $guardado = $this->celularDelCliente((int) ($data['client_user_id'] ?? 0))
            ?: substr(preg_replace('/\D/', '', $phone), -10);

        $session->update([
            'current_step' => 'nequi_phone',
            'data'         => array_merge($data, ['nequi_phone' => $guardado]),
            'expires_at'   => self::vencimientoSesion(),
        ]);

        $texto = "Te enviamos el cobro a tu app de Nequi y lo apruebas ahí mismo, sin salir de WhatsApp.\n\n"
            . "¿Te lo enviamos al *{$this->celularBonito($guardado)}*?";

        $this->recordBotConversationMessage($company, $phone, 'system', $texto);

        (new WhatsAppService($company->id, false, 'meta'))->sendInteractiveButtons($phone, $texto, [
            ['id' => 'nq_si',     'title' => 'Sí, a ese'],
            ['id' => 'nq_otro',   'title' => 'Otro número'],
            ['id' => 'pm_cancel', 'title' => 'Cancelar'],
        ]);

        return true;
    }

    /**
     * Dispara el cobro contra la app de Nequi del cliente.
     *
     * Si la pasarela no lo acepta no se lo deja colgado: se le manda el
     * checkout filtrado a Nequi, que es el camino que existía antes.
     */
    private function dispararCobroNequi(Company $company, WaBotSession $session, string $phone, string $celular): bool
    {
        $data     = $session->data ?? [];
        $celular  = substr(preg_replace('/\D/', '', $celular), -10);
        $cliente  = (int) ($data['client_user_id'] ?? 0);

        if (strlen($celular) !== 10) {
            $this->sendTextMessage($company, $phone, "Ese número no parece un celular de 10 dígitos. Escríbelo de nuevo, por ejemplo 3001234567.");

            return true;
        }

        $facturas = $this->pendingInvoicesFor($company, $cliente);
        $pedidas  = $data['pay_invoices'] ?? null;

        if (is_array($pedidas) && $pedidas) {
            $pedidas  = array_map('intval', $pedidas);
            $facturas = $facturas->filter(fn ($f) => in_array((int) $f->id, $pedidas, true))->values();
        }

        if ($facturas->isEmpty()) {
            $this->sendTextMessage($company, $phone, "Tus facturas ya están al día. No hay nada que cobrar.");
            $this->clearSession($company->id, $phone);

            return true;
        }

        $monto = (float) $facturas->sum(fn ($f) => $this->invoiceBalance($f));

        try {
            $r = app(PaymentInitiationService::class)->initiate(
                company:        $company,
                clientUserId:   $cliente,
                invoices:       $facturas,
                amount:         $monto,
                origin:         'bot',
                returnTo:       'whatsapp',
                paymentMethods: ['NEQUI'],
                customerPhone:  $celular,
            );
        } catch (\Throwable $e) {
            Log::error('[WaBotService] No se pudo crear el cobro de Nequi', [
                'company_id' => $company->id,
                'error'      => $e->getMessage(),
            ]);

            return $this->nequiPorElCheckout($company, $session, $phone, $data);
        }

        if (!isset($r['reference']) || !OnlinePaymentTransaction::where('reference', $r['reference'])->exists()) {
            return $this->nequiPorElCheckout($company, $session, $phone, $data);
        }

        $texto = "✅ Listo, ya te enviamos el cobro.\n\n"
            . "*Abre tu app de Nequi* y aprueba los " . $this->formatMoney($monto) . ". "
            . "Te llegó al {$this->celularBonito($celular)}.\n\n"
            . "Apenas lo apruebes te aviso por aquí y tus facturas quedan al día.";

        $this->recordBotConversationMessage($company, $phone, 'system', $texto);
        $this->sendTextMessage($company, $phone, $texto);
        $this->clearSession($company->id, $phone);

        return true;
    }

    /** Cuando el cobro directo falla, el checkout de Nequi sigue sirviendo. */
    private function nequiPorElCheckout(Company $company, WaBotSession $session, string $phone, array $data): bool
    {
        $url = (string) ($data['pay_url'] ?? '');

        if ($url === '') {
            $this->sendTextMessage($company, $phone, "No pudimos enviarte el cobro en este momento. Intenta de nuevo en unos minutos.");
            $this->clearSession($company->id, $phone);

            return true;
        }

        $this->sendTextMessage($company, $phone, "No pudimos enviarte el cobro a Nequi directamente. Te dejamos el enlace para pagarlo:");

        return $this->sendPaymentCta(
            $company,
            $phone,
            $url,
            (float) ($data['pay_amount'] ?? 0),
            (int) ($data['pay_count'] ?? 1),
            $data['pay_vence'] ?? null,
            'nequi'
        );
    }

    /** El celular que tenemos registrado del cliente, en diez dígitos. */
    private function celularDelCliente(int $clientUserId): ?string
    {
        if (!$clientUserId) {
            return null;
        }

        $crudo = (string) DB::table('user_data')->where('user_id', $clientUserId)->value('phone');
        $diez  = substr(preg_replace('/\D/', '', $crudo), -10);

        return strlen($diez) === 10 ? $diez : null;
    }

    /** Un celular escrito por el cliente, si lo que mandó es uno. */
    private function celularEscrito(string $mensaje): ?string
    {
        $diez = substr(preg_replace('/\D/', '', $mensaje), -10);

        return strlen($diez) === 10 && $diez[0] === '3' ? $diez : null;
    }

    /** 3001234567 → 300 123 4567, que es como se lee de un vistazo. */
    private function celularBonito(string $celular): string
    {
        $d = preg_replace('/\D/', '', $celular);

        return strlen($d) === 10 ? substr($d, 0, 3) . ' ' . substr($d, 3, 3) . ' ' . substr($d, 6) : $d;
    }

    /** Manda el botón de pago, apuntando al medio elegido si lo hay. */
    private function sendPaymentCta(
        Company $company,
        string $phone,
        string $url,
        float $amount,
        int $invoiceCount,
        ?string $vence,
        ?string $method
    ): bool {
        $detalle = $invoiceCount > 1
            ? "{$invoiceCount} facturas pendientes"
            : '1 factura pendiente';

        if ($method === 'nequi') {
            $url  .= (str_contains($url, '?') ? '&' : '?') . 'm=nequi';
            $medios = 'Solo tendrás que escribir tu celular y aprobar el pago en tu app de Nequi.';
            $boton  = 'Pagar con Nequi';
        } else {
            $medios = 'Toca el botón para pagar con Nequi, tarjeta, PSE, Bre-B o efectivo.';
            $boton  = 'Pagar ahora';
        }

        $body = "Total a pagar: " . $this->formatMoney($amount) . "\n({$detalle})\n\n" . $medios;

        if ($vence) {
            $body .= "\n\nEste link vence el {$vence}.";
        }

        $this->recordBotConversationMessage($company, $phone, 'system', $body . "\n" . $url);

        $wa     = new WhatsAppService($company->id, false, 'meta');
        $result = $wa->sendCtaUrl($phone, $body, $boton, $url, '', 'Pago seguro');

        // Si el botón no se pudo enviar, el link en texto plano sigue sirviendo.
        if (($result['success'] ?? true) === false) {
            Log::warning('[WaBotService] Botón de pago no enviado, se manda el link en texto', [
                'company_id' => $company->id,
                'error'      => $result['error'] ?? 'desconocido',
            ]);
            $this->sendTextMessage($company, $phone, $body . "\n\n" . $url);
        }

        $this->clearSession($company->id, $phone);
        return true;
    }

    /** Facturas sin pagar del cliente, de la más antigua a la más nueva. */
    private function pendingInvoicesFor(Company $company, int $clientUserId)
    {
        $cabIds = CabFacturation::where('company_id', $company->id)
            ->where('user_id', $clientUserId)
            ->pluck('id');

        if ($cabIds->isEmpty()) {
            return collect();
        }

        return DetFacturation::whereIn('cab_id', $cabIds)
            ->where('paid', 0)
            ->orderBy('date_facturation')
            ->orderBy('id')
            ->get()
            ->filter(fn ($inv) => $this->invoiceBalance($inv) > 0)
            ->values();
    }

    /** Saldo pendiente de una factura, descontando abonos. */
    private function invoiceBalance(object $invoice): float
    {
        return method_exists($invoice, 'outstanding')
            ? $invoice->outstanding()
            : round(max(0, (float) $invoice->price_total
                - (float) ($invoice->price_discount ?? 0)
                - (float) ($invoice->price_abone ?? 0)), 2);
    }

    private function formatMoney(float $value): string
    {
        return '$' . number_format($value, 0, ',', '.');
    }

    /**
     * Deja siempre a la vista cómo volver al menú.
     *
     * Sin esto, cualquier mensaje que no sea un botón deja al cliente sin salida
     * visible: tiene que saber de memoria que la palabra es "menu".
     */
    private function conSalidaAlMenu(string $text): string
    {
        $plano = mb_strtolower($text);

        return str_contains($plano, 'menu') || str_contains($plano, 'menú')
            ? $text
            : $text . "\n\nEscribe *menu* para volver al inicio.";
    }

    private function sendTextMessage(Company $company, string $to, string $text): void
    {
        $text = $this->conSalidaAlMenu($text);

        $this->recordBotConversationMessage($company, $to, 'system', $text);

        try {
            $result = (new WhatsAppService($company->id, false, 'meta'))->mensajeInformativo($to, $text);
            if (($result['success'] ?? true) === false) {
                Log::error('[WaBotService] Error enviando mensaje', [
                    'company_id' => $company->id,
                    'error' => $result['error'] ?? 'Respuesta no exitosa',
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('[WaBotService] Exception enviando mensaje', [
                'company_id' => $company->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function recordBotConversationMessage(Company $company, string $phone, string $senderType, string $content, ?string $externalId = null): void
    {
        // Duplicado solo dentro de la empresa: el mismo id puede estar en otra.
        if ($externalId && DB::table('crm_messages as m')
            ->join('crm_conversations as cv', 'cv.id', '=', 'm.conversation_id')
            ->where('cv.company_id', $company->id)
            ->where('m.external_id', $externalId)
            ->exists()) {
            return;
        }

        $customer = DB::table('crm_customers')
            ->where('company_id', $company->id)
            ->where('phone', $phone)
            ->first();
        $customerId = $customer?->id ?? DB::table('crm_customers')->insertGetId([
            'company_id' => $company->id,
            'phone' => $phone,
            'name' => 'Cliente WhatsApp',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $conversation = DB::table('crm_conversations')
            ->where('company_id', $company->id)
            ->where('provider', 'meta')
            ->where('customer_id', $customerId)
            ->whereIn('status', ['new', 'in_progress'])
            ->latest('id')
            ->first();
        $conversationId = $conversation?->id ?? DB::table('crm_conversations')->insertGetId([
            'company_id' => $company->id,
            'provider' => 'meta',
            'customer_id' => $customerId,
            'status' => 'new',
            'priority' => 'normal',
            'last_message_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $messageId = DB::table('crm_messages')->insertGetId([
            'conversation_id' => $conversationId,
            'sender_type' => $senderType,
            'message_type' => 'text',
            'content' => $content,
            'external_id' => $externalId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('crm_conversations')->where('id', $conversationId)->update(['last_message_at' => now(), 'updated_at' => now()]);
        if ($message = CrmMessage::find($messageId)) {
            broadcast(new NewMessageEvent($message, $conversationId));
        }
    }

    private function sendInvoicePdf(Company $company, string $to, object $invoice): void
    {
        $number = (string) ($invoice->number_facture ?? '');
        if ($number === '') return;

        $filename = 'factura_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $number) . '.pdf';
        // Enlace firmado: la ruta por número de factura ya no es pública.
        $pdfUrl = \App\Http\Controllers\InvoiceLinkController::urlFor((int) $invoice->id);
        $result = (new WhatsAppService($company->id, false, 'meta'))->sendDocument(
            $to,
            $pdfUrl,
            $filename,
            "Factura pendiente #{$number}. Abre el documento para descargarla."
        );

        if (($result['success'] ?? true) === false) {
            Log::error('[WaBotService] Error enviando PDF de factura', [
                'company_id' => $company->id,
                'invoice' => $number,
                'error' => $result['error'] ?? 'Respuesta no exitosa',
            ]);
        }
    }

    /** Intentos de validación permitidos antes de obligar a esperar. */
    private const MAX_DNI_ATTEMPTS = 3;

    /**
     * Titular de esa cédula, verificado contra quien escribe.
     *
     * Cuando el remitente trae teléfono, él mismo es la prueba: basta con que
     * coincida con el registrado. Las cuentas nuevas de WhatsApp llegan solo
     * con identidad de usuario y sin teléfono, así que a esas se les pide que
     * escriban el número registrado y se compara con ese.
     *
     * Esto acredita que conocen el número, no que lo controlen. Es más débil
     * que un código por SMS, pero muy superior a pedir solo la cédula, que en
     * Colombia aparece en cualquier recibo.
     */
    private function resolveDniOwner(Company $company, string $dni, string $senderPhone, ?string $typedPhone = null): ?UserData
    {
        $prueba = $typedPhone ?: $senderPhone;

        // Sin teléfono con qué comparar, nadie pasa.
        if (self::isUserIdentity($senderPhone) && !$typedPhone) {
            return null;
        }

        $candidatos = UserData::where('company_id', $company->id)
            ->where('dni', $dni)
            ->get()
            ->filter(fn (UserData $c): bool => $this->phonesMatch($c->phone, $prueba))
            ->values();

        // Si ese celular apunta a más de un cliente no se puede saber a cuál se
        // le está dando acceso, así que no pasa nadie. Varias filas del mismo
        // cliente sí valen: son duplicados, no personas distintas.
        if ($candidatos->pluck('user_id')->unique()->count() !== 1) {
            return null;
        }

        $cliente = $candidatos->first();

        // Único punto donde se comprueba la titularidad, así que es el único
        // sitio donde tiene sentido dejar constancia. Todos los flujos heredan
        // el reconocimiento sin repetir la lógica.
        WaIdentity::remember(
            $company->id,
            $senderPhone,
            (int) $cliente->user_id,
            $dni,
            $typedPhone ?: (self::isUserIdentity($senderPhone) ? null : $senderPhone)
        );

        return $cliente;
    }

    /** ¿Quien escribe llega sin teléfono, identificado solo por su usuario? */
    private static function isUserIdentity(string $value): bool
    {
        return \App\Services\MetaWhatsAppService::isUserIdentity($value);
    }

    /**
     * Cuenta los intentos fallidos para que nadie pruebe teléfonos a ciegas
     * contra una cédula ajena.
     */
    private function tooManyAttempts(WaBotSession $session): bool
    {
        $data = $session->data ?? [];
        $intentos = (int) ($data['dni_attempts'] ?? 0) + 1;

        $session->update(['data' => array_merge($data, ['dni_attempts' => $intentos])]);

        return $intentos >= self::MAX_DNI_ATTEMPTS;
    }

    private function phonesMatch(?string $storedPhone, string $incomingPhone): bool
    {
        $stored = preg_replace('/\D+/', '', (string) $storedPhone);
        $incoming = preg_replace('/\D+/', '', $incomingPhone);

        if ($stored === '' || $incoming === '') return false;
        if ($stored === $incoming) return true;

        // Permite que uno tenga prefijo internacional (+57) y el otro no,
        // comparando únicamente el número nacional de diez dígitos.
        return strlen($stored) >= 10 && strlen($incoming) >= 10
            && substr($stored, -10) === substr($incoming, -10);
    }
}
