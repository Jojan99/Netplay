<?php

namespace App\Resources\TemplatesEmail;

use App\Services\Correo\Correo;
use Illuminate\Support\Facades\Log;

class TemplateEmailCompanyConfirmation
{
    public function sendConfirmation(string $toEmail, string $companyName, string $confirmUrl): void
    {
        $plantilla = \App\Services\Correo\PlantillaDeCorreo::netvula()
            ->antetitulo('Bienvenido a Netvula')
            ->titulo('Confirme el correo de su empresa')
            ->parrafo('Hola, <strong>' . htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') . '</strong>.')
            ->parrafo('Su empresa quedó registrada en <strong>Netvula</strong>. Para activar la cuenta y empezar a operar sólo falta confirmar este correo:')
            ->boton('Confirmar mi correo', $confirmUrl)
            ->enlace($confirmUrl)
            ->aviso('El enlace vence en <strong>24 horas</strong>.', 'info')
            ->nota('Si usted no solicitó este registro, puede ignorar este correo: no se activará nada.');
        $html = $plantilla->html();

        // Correo de la plataforma: sale siempre de no-reply@netvula.com a nombre de Netvula.
        $resultado = Correo::plataforma()->enviar(
            ['email' => $toEmail, 'nombre' => $companyName],
            'Confirme el correo de su empresa en Netvula',
            $html,
            $plantilla->texto(),
        );

        if (!$resultado['ok']) {
            Log::error('[Confirmación empresa] correo no enviado', ['detalle' => $resultado['detalle']]);
            throw new \RuntimeException($resultado['detalle']);
        }
    }
}
