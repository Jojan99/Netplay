<?php

namespace App\Resources\TemplatesEmail;

use App\Constants\ApiResponseConstants;
use App\Services\Correo\Correo;


class TemplateEmailPay
{
  public function EmailPay($data,$price,$id_facture): mixed
  {
    // Aviso interno de abono para la empresa del operador. Antes iba, desde
    // cualquier empresa, a dos correos personales de Netplay con claves de
    // Mailjet escritas en el código.
    $empresa = \App\Models\Company::find(getSessionCompanyId());
    $destino = trim((string) ($empresa?->email ?? ''));
    $correo  = $empresa ? Correo::deEmpresa($empresa) : null;
    if (!$empresa || !filter_var($destino, FILTER_VALIDATE_EMAIL) || !$correo->configurado()) {
      return ['message' => 'Aviso de pago no enviado: la empresa no tiene correo o el correo no está configurado', 'status' => 1, 'data' => ApiResponseConstants::DATA_NULL];
    }
    $nombreEmpresa = trim((string) $empresa->invoice_business_name) ?: trim((string) $empresa->name);
    $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    $cliente = trim(($data['names'] ?? '') . ' ' . ($data['lastname'] ?? ''));
    $plantilla = \App\Services\Correo\PlantillaDeCorreo::deEmpresa($empresa)
      ->antetitulo('Pago recibido')
      ->titulo('Se registró un pago', 'ok')
      ->parrafo('Se registró un pago en ' . $e($nombreEmpresa) . '.')
      ->cifra('Valor recibido', (string) $price)
      ->datos(['Cliente' => $cliente ?: null, 'Factura' => (string) $id_facture, 'Registrado' => now()->locale('es')->isoFormat('D [de] MMMM [de] YYYY, h:mm a')])
      ->nota('Aviso automático del sistema al correo de la empresa.');
    $html = $plantilla->html();

    // Sale por la cuenta de correo de la empresa (propia o la de la plataforma).
    // El aviso no debe tumbar el registro del abono: enviar() no lanza excepciones.
    $resultado = $correo->enviar(
      ['email' => $destino, 'nombre' => $nombreEmpresa],
      'Pago recibido · ' . $id_facture,
      $html,
      $plantilla->texto(),
    );

    return $resultado['ok']
      ? ['message' => 'Email sent successfully', 'status' => 0, 'data' => ApiResponseConstants::DATA_NULL]
      : ['message' => 'The mail could not be sent', 'status' => 1, 'data' => ApiResponseConstants::DATA_NULL];
  }
}
