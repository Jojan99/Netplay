<?php

namespace App\Services;

use App\Models\Company;
use App\Models\WaBotConfig;
use App\Models\WaBotSession;
use App\Models\UserData;
use App\Models\CabFacturation;
use App\Models\DetFacturation;
use App\Models\PaymentProof;
use App\Models\PaymentProofAudit;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\QueryException;
use App\Services\WhatsAppService;
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

        $from = $payload['from'] ?? '';
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

        // A menu session is required so the next message can be routed to a flow.
        if (in_array($normalizedMessage, $triggerWords, true) || $invoiceIntent || !$session) {
            if (in_array($normalizedMessage, $triggerWords, true)) {
                $this->clearSession($company->id, $from);
                $this->createSession($company->id, $from, 'menu', 'awaiting_option');
                $this->sendWelcomeMenu($company, $config, $from);
                return true;
            }

            if ($invoiceIntent) {
                return $this->startFlow($company, $from, 'consultar_factura');
            }

            return false; // Let CRM handle non-trigger messages without session
        }

        if ($normalizedMessage === 'menu' || $normalizedMessage === 'inicio') {
            $this->createSession($company->id, $from, 'menu', 'awaiting_option');
            $this->sendWelcomeMenu($company, $config, $from);
            return true;
        }

        // Handle active flow
        return $this->handleFlowStep($company, $config, $session, $from, $normalizedMessage, $payload);
    }

    private function findCompanyByPhoneNumberId(string $phoneNumberId): ?Company
    {
        return Company::where('wa_phone_number_id', $phoneNumberId)->first();
    }

    private function clearSession(int $companyId, string $phone): void
    {
        WaBotSession::where('company_id', $companyId)
            ->where('phone', $phone)
            ->delete();
    }

    private function createSession(int $companyId, string $phone, string $flow, string $step, array $data = []): WaBotSession
    {
        $this->clearSession($companyId, $phone);
        return WaBotSession::create([
            'company_id' => $companyId,
            'phone' => $phone,
            'current_flow' => $flow,
            'current_step' => $step,
            'data' => $data,
            'expires_at' => now()->addMinutes(10),
        ]);
    }

    private function sendWelcomeMenu(Company $company, WaBotConfig $config, string $to): void
    {
        $options = $config->options ?? [
            ['key' => '1', 'label' => 'Consultar factura', 'flow' => 'consultar_factura'],
            ['key' => '2', 'label' => 'Consultar revisión / soporte', 'flow' => 'consultar_revision'],
            ['key' => '3', 'label' => 'Reportar pago', 'flow' => 'reportar_pago'],
        ];

        $hasReportarPagoOption = false;
        foreach ($options as $opt) {
            $key = strtolower(trim((string) ($opt['key'] ?? '')));
            $label = strtolower(trim((string) ($opt['label'] ?? $opt['title'] ?? '')));
            if ($key === '3' || str_contains($label, 'reportar pago') || str_contains($label, 'reportar')) {
                $hasReportarPagoOption = true;
                break;
            }
        }

        if (!$hasReportarPagoOption) {
            $options[] = ['key' => '3', 'label' => 'Reportar pago', 'flow' => 'reportar_pago'];
        }

        $menuText = ($config->welcome_message ?: "Hola, bienvenido a {$company->name}.\n\n¿En qué puedo ayudarte?");
        $buttons = [];

        foreach ($options as $index => $opt) {
            $key = $opt['key'] ?? (string) ($index + 1);
            $label = $opt['label'] ?? $opt['title'] ?? 'Opción';
            $buttons[] = ['id' => $key, 'title' => $label];
        }

        $wa = new WhatsAppService($company->id);
        $wa->sendInteractiveButtons($to, $menuText, $buttons);
    }

    private function handleFlowStep(Company $company, WaBotConfig $config, WaBotSession $session, string $phone, string $message, array $payload = []): bool
    {
        $flow = $session->current_flow;
        $step = $session->current_step;
        $data = $session->data ?? [];

        if ($flow === 'menu') {
            $options = $config->options ?? [
                ['key' => '1', 'label' => 'Consultar factura', 'flow' => 'consultar_factura'],
                ['key' => '2', 'label' => 'Consultar revisión', 'flow' => 'consultar_revision'],
                ['key' => '3', 'label' => 'Reportar pago', 'flow' => 'reportar_pago'],
            ];

            foreach ($options as $index => $opt) {
                $key = strtolower(trim((string) ($opt['key'] ?? ($index + 1))));
                if ($message === $key) {
                    // New builder uses flow_id; older configurations use flow.
                    $targetFlow = $opt['flow_id'] ?? $opt['flow'] ?? $key;
                    return $this->startFlow($company, $phone, $targetFlow);
                }
            }

            if (in_array($message, ['3', 'reportar pago', 'reportar_pago', 'reporte pago', 'reporte_pago'], true)) {
                return $this->startFlow($company, $phone, 'reportar_pago');
            }

            $this->sendTextMessage($company, $phone, "Opción no válida. Por favor escribe una de las opciones del menú.");
            return true;
        }

        if ($flow === 'consultar_factura') {
            return $this->handleConsultarFactura($company, $session, $phone, $message);
        }

        if ($flow === 'consultar_revision') {
            return $this->handleConsultarRevision($company, $session, $phone, $message);
        }

        if ($flow === 'reportar_pago') {
            return $this->handleReportarPago($company, $session, $phone, $message, $payload);
        }

        return false;
    }

    private function startFlow(Company $company, string $phone, string $flow): bool
    {
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

        return false;
    }

    private function handleConsultarFactura(Company $company, WaBotSession $session, string $phone, string $message): bool
    {
        $step = $session->current_step;
        $data = $session->data ?? [];

        if ($step === 'ask_dni') {
            $dni = preg_replace('/[^0-9]/', '', $message);

            // Validar que sea un DNI válido (8-10 dígitos)
            if (strlen($dni) < 8 || strlen($dni) > 10) {
                $this->sendTextMessage($company, $phone, "Por favor, ingresa un número de cédula válido (8-10 dígitos).");
                return true;
            }

            $client = UserData::where('company_id', $company->id)
                ->where('dni', $dni)
                ->get()
                ->first(fn (UserData $candidate): bool => $this->phonesMatch($candidate->phone, $phone));

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
                'expires_at' => now()->addMinutes(10),
            ]);

            $wa = new WhatsAppService($company->id);
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

            $invoices = DetFacturation::whereIn('cab_id', $cabIds)
                ->orderByDesc('date_facturation')
                ->limit(10)
                ->get();

            if ($invoices->isEmpty()) {
                $this->sendTextMessage($company, $phone, "Hola {$clientName}, no tienes facturas registradas en nuestro sistema.\n\nEscribe *menu* para volver al inicio.");
                $this->clearSession($company->id, $phone);
                return true;
            }

            // Build interactive buttons for invoices
            $buttons = [];
            $invoiceList = [];

            foreach ($invoices->take(3) as $index => $inv) {
                $number = (int)($index + 1);
                $total = $inv->price_total ?? $inv->total ?? 0;
                $discount = $inv->price_discount ?? 0;
                $balance = max(0, (float) $total - (float) $discount - (float) ($inv->price_abone ?? 0));
                $status = ($inv->paid ?? false) ? 'Pagada' : 'Pendiente';

                $buttons[] = [
                    'id' => "invoice_{$number}",
                    'title' => $number . ". Fac. #{$inv->number_facture}",
                ];

                $invoiceList[] = [
                    'option' => $number,
                    'number_facture' => $inv->number_facture,
                    'id' => $inv->id,
                    'status' => $status,
                    'balance' => $balance,
                ];
            }

            $session->update([
                'current_step' => 'select_invoice',
                'data' => array_merge($data, ['invoices' => $invoiceList]),
                'expires_at' => now()->addMinutes(10),
            ]);

            $wa = new WhatsAppService($company->id);
            if (count($invoiceList) > 3) {
                $sections = [[
                    'title' => 'Tus facturas',
                    'rows' => array_map(fn ($inv) => [
                        'id' => "invoice_{$inv['option']}",
                        'title' => "Factura #{$inv['number_facture']}",
                        'description' => date('d/m/Y', strtotime($inv['date_facturation'] ?? now()->toDateString())) . ' - $' . number_format($inv['balance'], 0, ',', '.') . ' (' . ($inv['status'] ?? 'Pendiente') . ')',
                    ], $invoiceList),
                ]];

                $wa->sendInteractiveList(
                    $phone,
                    "Hola {$clientName}, selecciona la factura que deseas descargar:",
                    $sections,
                    'Ver facturas'
                );
            } else {
                $wa->sendInteractiveButtons(
                    $phone,
                    "Hola {$clientName}, selecciona la factura que deseas descargar:",
                    array_map(fn ($inv) => [
                        'id' => "invoice_{$inv['option']}",
                        'title' => $inv['option'] . '. Fac. #' . $inv['number_facture'],
                    ], $invoiceList)
                );
            }

            return true;
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

            if ($selectedOption <= 0) {
                $this->sendTextMessage($company, $phone, "Por favor, escribe el número de la factura que deseas (1, 2, 3...)");
                return true;
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
                'expires_at' => now()->addMinutes(10),
            ]);

            $wa = new WhatsAppService($company->id);
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
                $session->update(['current_step' => 'select_invoice', 'expires_at' => now()->addMinutes(10)]);

                $wa = new WhatsAppService($company->id);
                $wa->sendInteractiveButtons(
                    $phone,
                    "Está bien, selecciona otra factura:",
                    array_map(fn ($inv) => [
                        'id' => "invoice_{$inv['option']}",
                        'title' => $inv['option'] . '. Fac. #' . $inv['number_facture'],
                    ], $data['invoices'] ?? [])
                );
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
                'expires_at' => now()->addMinutes(10),
            ]);

            $wa = new WhatsAppService($company->id);
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
                $session->update(['current_step' => 'select_invoice', 'expires_at' => now()->addMinutes(10)]);

                $invoices = $data['invoices'] ?? [];
                $wa = new WhatsAppService($company->id);

                if (count($invoices) > 3) {
                    $sections = [[
                        'title' => 'Tus facturas',
                        'rows' => array_map(fn ($inv) => [
                            'id' => "invoice_{$inv['option']}",
                            'title' => "Factura #{$inv['number_facture']}",
                            'description' => '$' . number_format($inv['balance'], 0, ',', '.') . ' (' . ($inv['status'] ?? 'Pendiente') . ')',
                        ], $invoices),
                    ]];

                    $wa->sendInteractiveList($phone, 'Selecciona otra factura:', $sections, 'Ver facturas');
                } else {
                    $wa->sendInteractiveButtons(
                        $phone,
                        'Selecciona otra factura:',
                        array_map(fn ($inv) => [
                            'id' => "invoice_{$inv['option']}",
                            'title' => $inv['option'] . '. Fac. #' . $inv['number_facture'],
                        ], $invoices)
                    );
                }
                return true;
            }

            if ($message === 'another_no' || $message === 'no' || $message === '2' || $message === 'menu' || $message === 'menú') {
                $this->clearSession($company->id, $phone);
                return false; // Return to menu
            }

            return true;
        }

        return true;
    }

    private function handleReportarPago(Company $company, WaBotSession $session, string $phone, string $message, array $payload = []): bool
    {
        $step = $session->current_step;
        $data = $session->data ?? [];

        if ($step === 'payment_complete') {
            if (in_array($message, ['payment_retry', 'reenviar comprobante', 'reenviar', '1'], true)) {
                $session->update(['current_step' => 'awaiting_payment_proof', 'expires_at' => now()->addMinutes(10)]);
                $this->sendTextMessage($company, $phone, 'Envía nuevamente la foto o el documento del comprobante. Verifica que se vean el monto, la fecha y la referencia.');
                return true;
            }

            if (in_array($message, ['payment_another', 'otra factura', 'otra', '1'], true)) {
                $session->update(['current_step' => 'ask_dni', 'expires_at' => now()->addMinutes(10)]);
                return $this->handleReportarPago($company, $session->fresh(), $phone, (string) ($data['client_dni'] ?? ''));
            }

            if (in_array($message, ['payment_menu', 'menu', 'menú', '2'], true)) {
                $config = WaBotConfig::where('company_id', $company->id)->first();
                $this->createSession($company->id, $phone, 'menu', 'awaiting_option');
                if ($config) {
                    $this->sendWelcomeMenu($company, $config, $phone);
                }
                return true;
            }

            return true;
        }

        if ($step === 'ask_dni') {
            $dni = preg_replace('/[^0-9]/', '', $message);

            if (strlen($dni) < 8 || strlen($dni) > 10) {
                $this->sendTextMessage($company, $phone, "Por favor, ingresa una cédula válida (8-10 dígitos).");
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
                $this->sendTextMessage($company, $phone, "No pudimos validar esa cédula con este número de WhatsApp. Verifica los datos del titular o contacta soporte.");
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
                'expires_at' => now()->addMinutes(10),
            ]);

            $rows = array_map(static fn (array $inv): array => [
                'id' => "payment_invoice_{$inv['option']}",
                'title' => "Factura #{$inv['number_facture']}",
                'description' => 'Abonado $' . number_format($inv['paid_amount'], 0, ',', '.')
                    . ' | Restante $' . number_format($inv['balance'], 0, ',', '.'),
            ], $invoiceList);

            try {
                (new WhatsAppService($company->id))->sendInteractiveList(
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
                'expires_at' => now()->addMinutes(10),
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
                    'expires_at' => now()->addMinutes(10),
                ]);
                (new WhatsAppService($company->id))->sendInteractiveButtons(
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
                    'expires_at' => now()->addMinutes(10),
                ]);
                (new WhatsAppService($company->id))->sendInteractiveButtons(
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

    private function extractTextFromProof(string $path): ?string
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

    private function extractPaymentProofDetails(string $text): array
    {
        $amount = null;
        if (preg_match('/(?:valor\s+de\s+la\s+transferencia|valor\s+transferido|monto\s+transferido|importe\s+enviado|cu[aá]nto\??)[^\d$]{0,80}\$?\s*([\d\.,]+)/iu', $text, $match)
            || preg_match('/(?:^|\R)\s*(?:monto|valor|total|pago|abono)\s*[:$]?\s*\$?\s*([\d\.,]+)/imu', $text, $match)) {
            $amount = $this->parsePaymentAmount($match[1]);
        }

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

    private function extractReference(string $text): ?string
    {
        if (preg_match('/(?:comprobante\s+(?:no\.?|n[uú]mero)|ref(?:erencia)?|referencia|n[uú]mero\s+de\s+operaci[oó]n)[^A-Za-z0-9]*([A-Za-z0-9-]{4,})/iu', $text, $matches)) {
            return trim($matches[1]);
        }

        if (preg_match('/(?:\b\d{6,20}\b)/', $text, $matches)) {
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

            $session->update([
                'current_step' => 'show_tickets',
                'data' => array_merge($data, ['client_id' => $client->id, 'dni' => $dni]),
                'expires_at' => now()->addMinutes(10),
            ]);

            // Get latest tickets
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

    private function sendTextMessage(Company $company, string $to, string $text): void
    {
        try {
            $result = (new WhatsAppService($company->id))->mensajeInformativo($to, $text);
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

    private function sendInvoicePdf(Company $company, string $to, object $invoice): void
    {
        $number = (string) ($invoice->number_facture ?? '');
        if ($number === '') return;

        $filename = 'factura_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $number) . '.pdf';
        $pdfUrl = url('/api/generatePdf/generatePdfbyId/' . rawurlencode($number));
        $result = (new WhatsAppService($company->id))->sendDocument(
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
