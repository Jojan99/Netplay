<?php

namespace App\Services;

/**
 * Genera mensajes de WhatsApp "humanizados" y únicos para cada destinatario.
 * Evita patrones repetidos que Meta detecta como spam/bot.
 */
class WhatsAppMessageHumanizerService
{
    /**
     * Variaciones de saludos
     */
    private array $greetings = [
        "Hola {name}",
        "Buen día {name}",
        "¡Hola {name}!",
        "Saludos {name}",
        "Buenas {name}",
        "Hola, {name}",
        "Qué tal {name}",
        "Buen día, {name}",
    ];

    /**
     * Variaciones de introducción de factura
     */
    private array $introLines = [
        "te informamos que tu factura ya está lista.",
        "tu factura del mes ya está disponible.",
        "ya generamos tu factura de este período.",
        "tu factura mensual está lista para revisión.",
        "aquí tienes los detalles de tu factura.",
        "te compartimos la información de tu factura.",
    ];

    /**
     * Variaciones para indicar el número de factura
     */
    private array $billNumberLines = [
        "Factura: *{number_bill}*",
        "Número: *{number_bill}*",
        "Ref: *{number_bill}*",
        "Factura No. *{number_bill}*",
    ];

    /**
     * Variaciones para el valor
     */
    private array $amountLines = [
        'Valor a pagar: *{monthly_price}*',
        'Total: *{monthly_price}*',
        'Monto: *{monthly_price}*',
        'Valor: *{monthly_price}*',
        'Importe: *{monthly_price}*',
    ];

    /**
     * Variaciones para la fecha límite
     */
    private array $dueDateLines = [
        "Fecha límite: *{date_finish_bill}*",
        "Vence: *{date_finish_bill}*",
        "Puedes pagar hasta: *{date_finish_bill}*",
        "Fecha de corte: *{date_finish_bill}*",
    ];

    /**
     * Variaciones de cierre/despedida
     */
    private array $closingLines = [
        "Gracias por preferirnos.",
        "Agradecemos tu confianza.",
        "Gracias por ser parte de nosotros.",
        "Quedamos atentos.",
        "Cualquier duda, escríbenos.",
        "Estamos para ayudarte.",
        "Buen día.",
        "Un saludo.",
    ];

    /**
     * Emojis opcionales para variar el tono (se usan aleatoriamente)
     */
    private array $optionalEmojis = ['📄', '💡', '📅', '💰', '✅', '📲', '👋', '👍'];

    /**
     * Variaciones naturales de métodos de pago tradicional (billing_electronic = 0).
     */
    private array $traditionalPaymentLines = [
        "Medios de pago:\nBANCOLOMBIA CTA AHO 47800013328\nDAVIPLATA 3022042294\n💚 NEQUI 3022042294 (Hum Gom)\n💜 NEQUI 3245127869 (Joj Pom)\n\n📩 Responde con el comprobante cuando realices el pago.",
        "Puedes pagar por:\n🏦 Bancolombia Ahorros 47800013328\n📲 Daviplata 3022042294\n💚 Nequi 3022042294 (Hum Gom)\n💜 Nequi 3245127869 (Joj Pom)\n\n📩 Envíanos el comprobante respondiendo este mensaje.",
        "Realiza tu pago en:\n• Bancolombia Cta Aho 47800013328\n• Daviplata 3022042294\n• 💚 Nequi 3022042294 (Hum Gom)\n• 💜 Nequi 3245127869 (Joj Pom)\n\n📩 Una vez hecho el pago, responde con el comprobante.",
    ];

    /**
     * Variaciones de métodos de pago electrónico / llave (billing_electronic = 1).
     */
    private array $electronicPaymentLines = [
        "Puedes realizar tu pago siguiendo una de estas opciones:\n1️⃣  transferencia por Bre-B usando la siguiente llave:\n🔑 0091768855\n\n📩 Envíanos el comprobante en tu siguiente mensaje.",
        "Opciones de pago:\n📎 Cancela por transferencia Bre-B con llave:\n🔑 0091768855\n\n📩 Responde con el comprobante.",
        "Paga fácil:\n1️⃣ Paga con Bre-B con llave *0091768855*\n\n📩 Envía el comprobante por aquí.",
    ];

    /**
     * Genera un mensaje de factura único y humanizado.
     *
     * @param array $data Datos: name, lastname, number_bill, monthly_price, date_finish_bill, billing_electronic (1=llave/0=trad)
     * @return string Mensaje único
     */
    public function generateInvoiceMessage(array $data): string
    {
        $name = trim(($data['names'] ?? '') . ' ' . ($data['lastname'] ?? ''));
        if (empty($name)) $name = 'cliente';

        $numberBill = $data['number_bill'] ?? '';
        $monthlyPrice = $data['monthly_price'] ?? '0';
        $dueDate = $data['date_finish_bill'] ?? '';
        $isElectronic = (bool) ($data['billing_electronic'] ?? 0);

        // Seleccionar variaciones aleatorias
        $greeting = $this->pickRandom($this->greetings);
        $intro = $this->pickRandom($this->introLines);
        $billLine = $this->pickRandom($this->billNumberLines);
        $amountLine = $this->pickRandom($this->amountLines);
        $dueLine = $this->pickRandom($this->dueDateLines);
        $closing = $this->pickRandom($this->closingLines);

        // Emoji aleatorio (50% de probabilidad de aparecer)
        $emoji = rand(0, 1) ? $this->pickRandom($this->optionalEmojis) . ' ' : '';

        // Construir mensaje con orden aleatorio de secciones intermedias
        $sections = [
            $emoji . str_replace('{number_bill}', $numberBill, $billLine),
            str_replace('{monthly_price}', $monthlyPrice, $amountLine),
            str_replace('{date_finish_bill}', $dueDate, $dueLine),
        ];

        // Barajar las secciones intermedias para más variación
        shuffle($sections);

        // 💳 Métodos de pago según billing_electronic (siempre se incluye)
        $paymentSection = [];
        if ($isElectronic) {
            $paymentSection = ['', $this->pickRandom($this->electronicPaymentLines)];
        } else {
            $paymentSection = ['', $this->pickRandom($this->traditionalPaymentLines)];
        }

        // Ensamblar mensaje final
        $lines = [
            str_replace('{name}', $name, $greeting) . ', ' . $intro,
            '',
            ...$sections,
            ...$paymentSection,
            '',
            $closing,
        ];

        return implode("\n", $lines);
    }

    /**
     * Genera un mensaje de confirmación de pago único.
     */
    public function generatePaymentConfirmation(array $data): string
    {
        $name = trim(($data['names'] ?? '') . ' ' . ($data['lastname'] ?? ''));
        if (empty($name)) $name = 'cliente';
        $invoiceNumber = $data['number_bill'] ?? '';
        $amount = $data['amount'] ?? '0';
        $type = $data['type'] ?? 'completo';

        $greetings = [
            "Hola {name}",
            "Buen día {name}",
            "¡Hola {name}!",
        ];

        $paymentLines = [
            'completo' => [
                "confirmamos que recibimos tu pago completo de la factura *{invoice}* por *{amount}*.",
                "tu pago de *{amount}* por la factura *{invoice}* fue registrado exitosamente.",
                "recibimos el pago total de la factura *{invoice}* — *{amount}*. ¡Gracias!",
            ],
            'abono' => [
                "registramos un abono de *{amount}* en la factura *{invoice}*.",
                "tu abono de *{amount}* para la factura *{invoice}* fue recibido.",
                "recibimos un abono de *{amount}* sobre la factura *{invoice}*.",
            ],
        ];

        $closingLines = [
            "Gracias por tu pago.",
            "Agradecemos tu puntualidad.",
            "Quedamos atentos por si necesitas algo.",
        ];

        $greeting = str_replace('{name}', $name, $this->pickRandom($greetings));
        $paymentLine = str_replace(['{invoice}', '{amount}'], [$invoiceNumber, $amount], $this->pickRandom($paymentLines[$type] ?? $paymentLines['completo']));
        $closing = $this->pickRandom($closingLines);

        return "{$greeting}, {$paymentLine}\n\n{$closing}";
    }

    private function pickRandom(array $options): string
    {
        return $options[array_rand($options)];
    }
}
