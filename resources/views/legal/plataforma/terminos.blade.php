@extends('legal.base')

@section('etiqueta', 'Condiciones de uso')
@section('titulo', 'Términos y condiciones de la plataforma')
@section('resumen', 'Las reglas entre ' . $marca . ' y las empresas que usan la plataforma: qué se ofrece, cómo se paga, qué responde cada parte y cómo se tratan los datos.')

@section('cuerpo')

<p>Estos términos son el contrato entre <strong>{{ $empresa }}</strong> (en adelante, «{{ $marca }}») y la
empresa o persona que registra una cuenta para administrar su operación como proveedor de internet (en adelante,
el «ISP»). Al registrarse y marcar la casilla de aceptación, el ISP declara que los leyó y los acepta, junto con
la <a href="/politica-de-privacidad">política de tratamiento de datos personales</a>.</p>

<div class="ficha">
  <dl>
    <dt>Proveedor</dt><dd>{{ $empresa }}</dd>
    @if($nit)<dt>NIT</dt><dd>{{ $nit }}</dd>@endif
    @if($direccion)<dt>Domicilio</dt><dd>{{ $direccion }}</dd>@endif
    @if($telefono)<dt>Teléfono / WhatsApp</dt><dd>{{ $telefono }}</dd>@endif
    @if($correo)<dt>Correo</dt><dd>{{ $correo }}</dd>@endif
  </dl>
</div>

<h2>1. Qué es {{ $marca }}</h2>

<p>{{ $marca }} es un software que se usa por internet (software como servicio). Permite al ISP administrar sus
clientes, planes, contratos, instalaciones, facturación, cartera, cortes por mora, equipos de red (OLT, MikroTik,
equipos del cliente), inventario, personal, tickets y la atención por WhatsApp, y ofrecer a sus suscriptores un
portal con su propia marca.</p>

<p>{{ $marca }} <strong>no presta servicio de internet</strong> ni es parte del contrato entre el ISP y sus
suscriptores. El ISP es el único prestador frente a ellos y frente a las autoridades.</p>

<h2>2. La cuenta</h2>

<ul>
  <li>Quien registra la cuenta declara ser mayor de edad y estar facultado para obligar a la empresa.</li>
  <li>Los datos del registro deben ser verdaderos y mantenerse al día. La cuenta se activa al confirmar el correo.</li>
  <li>El ISP responde por lo que hagan los usuarios que cree en su cuenta y por el cuidado de sus contraseñas. Debe avisarnos de inmediato si sospecha un acceso no autorizado.</li>
  <li>La dirección de la empresa dentro de la plataforma (su subdominio) no puede usar marcas ajenas ni prestarse a engaño. {{ $marca }} puede pedir su cambio en esos casos.</li>
</ul>

<h2>3. Planes, prueba y precios</h2>

<ul>
  @if($pruebaDias)<li>Toda cuenta nueva tiene <strong>{{ $pruebaDias }} días de prueba</strong> sin costo. Terminada la prueba, continuar requiere un plan pago.</li>@endif
  <li>Los planes se diferencian por la cantidad de clientes que admite la cuenta. Cuentan los clientes activos y los suspendidos, y las instalaciones pendientes reservan lugar; los retirados no cuentan. Alcanzado el tope, no se pueden crear clientes nuevos hasta cambiar de plan.</li>
  <li>Algunas funciones se contratan como <strong>complementos</strong> con precio propio, que se suma al del plan mientras estén activos. Hoy lo es la gestión por TR-069 del equipo del cliente; sin ella, la plataforma sigue operando con el resto de funciones.</li>
  <li>Los precios vigentes son los publicados en {{ $dominio }}, en pesos colombianos y más IVA. Los cupones y códigos de referido se aplican en las condiciones con que se entregan.</li>
  <li>Un cambio de precio se avisa con al menos <strong>30 días</strong> de anticipación y no afecta el periodo ya pagado.</li>
</ul>

<h2>4. Pago y mora</h2>

<ul>
  <li>La suscripción se paga por periodo anticipado, mensual o anual.</li>
  <li>Si el pago no se recibe en la fecha de vencimiento, {{ $marca }} avisa a la empresa y, de persistir la mora, puede <strong>suspender el acceso</strong> a la cuenta. La suspensión no borra la información.</li>
  <li>El acceso se restablece al registrarse el pago. Los periodos pagados no se reembolsan, salvo falla grave imputable a {{ $marca }}.</li>
</ul>

<h2>5. Uso permitido</h2>

<p>El ISP se obliga a usar la plataforma para su operación legítima y a <strong>no</strong>:</p>

<ul>
  <li>Cargar datos personales obtenidos sin autorización de sus titulares.</li>
  <li>Enviar mensajes no solicitados, engañosos o que incumplan las políticas de WhatsApp o las normas sobre cobranza y contacto a consumidores (Ley 2300 de 2023).</li>
  <li>Revender, sublicenciar o prestar su acceso a terceros ajenos a su empresa.</li>
  <li>Copiar, descompilar o intentar obtener el código de la plataforma, o usarla para construir un producto competidor.</li>
  <li>Eludir los límites del plan, probar vulnerabilidades sin permiso escrito o afectar el funcionamiento para otros usuarios.</li>
  <li>Usarla para actividades ilícitas.</li>
</ul>

<h2>6. Lo que responde el ISP</h2>

<ul>
  <li><strong>Su habilitación y sus obligaciones regulatorias</strong> como proveedor de redes y servicios de telecomunicaciones, y el cumplimiento del régimen de protección de usuarios frente a sus suscriptores.</li>
  <li><strong>Su red y sus equipos.</strong> El ISP entrega a la plataforma las credenciales de sus equipos para operarlos. Toda acción ejecutada desde su cuenta —autorizar un equipo, cortar o reactivar un servicio, cambiar una configuración— se entiende ordenada por el ISP, también cuando ocurre por una regla automática que él configuró.</li>
  <li><strong>Su facturación y sus impuestos</strong>, incluida la facturación electrónica ante la DIAN y la exactitud de precios, planes y fechas de corte que configure.</li>
  <li><strong>Su relación con suscriptores y empleados</strong>: contratos, cobros, contenido de los mensajes que envía y atención de peticiones, quejas y reclamos.</li>
  <li><strong>Los datos personales que carga</strong>, en los términos de la sección 11.</li>
</ul>

<h2>7. Servicios de terceros</h2>

<p>Varias funciones dependen de servicios que no controla {{ $marca }}, cada uno con sus propias condiciones:</p>

<ul>
  <li><strong>WhatsApp.</strong> El canal oficial es la API de WhatsApp Business de Meta, que el ISP conecta con su propia cuenta y cuyas políticas debe cumplir. La plataforma también permite vincular una línea por WhatsApp Web; esa modalidad no es un servicio oficial de Meta y el ISP la usa bajo su propio riesgo, incluida la posibilidad de que Meta restrinja el número.</li>
  <li><strong>Pasarelas de pago.</strong> El ISP las contrata y configura con sus propias credenciales. El dinero de los suscriptores va directamente a la cuenta del ISP en la pasarela: {{ $marca }} no lo recibe ni lo custodia.</li>
  <li><strong>Correo, inteligencia artificial y otros servicios conectados</strong>, que pueden cambiar sus condiciones, precios o disponibilidad.</li>
</ul>

<p>{{ $marca }} no responde por fallas, bloqueos, cambios o cobros de esos terceros, aunque hará lo razonable
para mantener las integraciones funcionando.</p>

<h2>8. Disponibilidad, respaldo y soporte</h2>

<ul>
  <li>{{ $marca }} hace lo razonable para mantener la plataforma disponible de forma continua, pero no garantiza un servicio ininterrumpido ni libre de errores. Puede haber mantenimientos; los programados que impliquen interrupción se avisan con anticipación.</li>
  <li>Se hacen copias de respaldo diarias para recuperar la plataforma ante una falla. No reemplazan los archivos propios del ISP: se recomienda exportar periódicamente la información importante.</li>
  <li>El soporte se presta por los canales publicados, en el horario informado y según el plan.</li>
  <li>Las funciones pueden evolucionar. Si se retira una función esencial, se avisa con anticipación razonable.</li>
</ul>

<h2>9. Propiedad intelectual</h2>

<p>El software, su diseño, su documentación y la marca {{ $marca }} pertenecen a {{ $empresa }}. El ISP recibe
una licencia de uso limitada, no exclusiva e intransferible, por el tiempo que dure su suscripción. La
información que el ISP carga es del ISP: {{ $marca }} no adquiere derechos sobre ella más allá de lo necesario
para prestar el servicio.</p>

<h2>10. Confidencialidad</h2>

<p>Cada parte mantendrá en reserva la información no pública de la otra que conozca por razón del servicio, y
la usará sólo para cumplir este contrato. El deber se mantiene después de terminado.</p>

<h2>11. Datos personales: acuerdo de transmisión</h2>

<p>Respecto de los datos de sus suscriptores y empleados, el ISP es el <strong>responsable</strong> del
tratamiento y {{ $marca }} es el <strong>encargado</strong>. Esta sección es el contrato de transmisión que
exige el artículo 25 del Decreto 1377 de 2013 (artículo 2.2.2.25.5.2 del Decreto 1074 de 2015).</p>

<h3>Alcance</h3>
<p>{{ $marca }} trata esos datos para alojarlos, organizarlos, mostrarlos, enviarlos por los canales que el ISP
active y ejecutar las funciones de la plataforma, durante la vigencia de la suscripción y conforme a la política
de tratamiento del ISP y a sus instrucciones, que el ISP imparte al configurar y usar su cuenta.</p>

<h3>Obligaciones de {{ $marca }} como encargado</h3>
<ul>
  <li>Tratar los datos sólo para prestar el servicio y según los principios de la Ley 1581 de 2012.</li>
  <li>Guardar confidencialidad y exigirla a su personal.</li>
  <li>Aplicar las medidas de seguridad descritas en la política de tratamiento de datos.</li>
  <li>Trasladar al ISP, dentro de los dos días hábiles siguientes, las consultas y reclamos de titulares que reciba, y apoyarlo razonablemente para responderlos.</li>
  <li>Avisar al ISP sin demora injustificada cuando confirme un incidente de seguridad que afecte sus datos, con la información disponible para que pueda cumplir sus deberes ante la SIC y los titulares.</li>
  <li>Al terminar la suscripción, permitir la exportación y luego suprimir los datos, en los plazos de la sección 14.</li>
</ul>

<h3>Obligaciones del ISP como responsable</h3>
<ul>
  <li>Contar con la autorización previa, expresa e informada de los titulares, incluida la necesaria para contactarlos por WhatsApp y correo, para el uso de asistentes automáticos y, en el caso de sus técnicos, para registrar su ubicación durante la jornada.</li>
  <li>Tener y publicar su política de tratamiento. La plataforma le ofrece una plantilla en su propia dirección; el ISP debe revisarla y ajustarla a su operación.</li>
  <li>Atender las consultas y reclamos de sus titulares y registrar sus bases de datos ante la SIC cuando esté obligado.</li>
  <li>No cargar datos sensibles ni de menores de edad sin la autorización reforzada que exige la ley.</li>
</ul>

<h3>Proveedores y transmisión internacional</h3>
<p>El ISP autoriza a {{ $marca }} a apoyarse en los proveedores descritos en la política de tratamiento de datos
(infraestructura en la nube, Meta, correo, inteligencia artificial cuando se active), algunos ubicados fuera de
Colombia, que tratan los datos sólo para cumplir su función.</p>

<h2>12. Límite de responsabilidad</h2>

<ul>
  <li>{{ $marca }} responde por los daños directos causados por su incumplimiento. No responde por lucro cesante, pérdida de clientela, daños indirectos ni por hechos de terceros, caso fortuito o fuerza mayor.</li>
  <li>En particular, no responde por fallas de la red o los equipos del ISP, por decisiones de facturación, corte o cobro que el ISP configure o ejecute, por errores en los datos que el ISP cargue, ni por las respuestas de asistentes automáticos que el ISP decida activar.</li>
  <li>La responsabilidad total de {{ $marca }} frente al ISP se limita a lo pagado por la suscripción en los <strong>doce meses</strong> anteriores al hecho que origine el reclamo.</li>
  <li>Estos límites no aplican en caso de dolo o culpa grave.</li>
</ul>

<h2>13. Indemnidad</h2>

<p>El ISP mantendrá indemne a {{ $marca }} frente a reclamos de suscriptores, empleados, terceros o autoridades
que se originen en la operación del ISP, en los datos que cargó, en los mensajes que envió o en el uso que hizo
de la plataforma en contra de estos términos o de la ley.</p>

<h2>14. Suspensión y terminación</h2>

<ul>
  <li>El ISP puede cancelar su cuenta en cualquier momento, avisando por los canales de soporte.</li>
  <li>{{ $marca }} puede suspender o terminar la cuenta por mora, por incumplimiento de estos términos, por uso que ponga en riesgo la plataforma o a terceros, o por orden de autoridad. Salvo urgencia, avisa antes y da oportunidad de corregir.</li>
  <li>Terminada la cuenta, la información queda disponible para <strong>exportarla durante 30 días</strong>. Después se elimina de la plataforma dentro de los 60 días siguientes, salvo lo que deba conservarse por ley.</li>
</ul>

<h2>15. Cambios a estos términos</h2>

<p>{{ $marca }} puede actualizar estos términos. Los cambios de fondo se avisan a la empresa con al menos
<strong>15 días</strong> de anticipación, por correo o dentro de la plataforma. Seguir usando el servicio después
de esa fecha implica aceptarlos; si el ISP no está de acuerdo, puede cancelar su cuenta antes de que rijan.</p>

<h2>16. Ley aplicable y controversias</h2>

<p>Este contrato se rige por las leyes de la República de Colombia. Las partes intentarán resolver de forma
directa cualquier diferencia durante treinta días. Si no lo logran, conocerán de ella los jueces competentes
@if($ciudad)de {{ $ciudad }}, Colombia.@else del domicilio de {{ $empresa }}, en Colombia.@endif</p>

<h2>17. Aceptación por medios electrónicos</h2>

<p>La aceptación dada al marcar la casilla del registro es un mensaje de datos con plena validez, conforme a la
Ley 527 de 1999. {{ $marca }} conserva la fecha, la versión aceptada y la dirección IP como prueba. Estos
términos rigen desde el {{ $actualizado }}@if($version) (versión {{ $version }})@endif.</p>

@endsection
