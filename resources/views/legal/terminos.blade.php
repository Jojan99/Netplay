@extends('legal.base')

@section('etiqueta', 'Condiciones del servicio')
@section('titulo', 'Términos y condiciones')
@section('resumen', 'Las reglas del servicio de internet: qué ofrecemos, qué esperamos del cliente, cómo se factura y cómo se termina.')

@section('cuerpo')

<p>Estas condiciones rigen el servicio de acceso a internet que presta <strong>{{ $empresa }}</strong> y el uso
de sus canales de atención, incluida la plataforma en línea y la atención por WhatsApp. Al contratar el
servicio, el cliente las acepta. Están redactadas conforme a la <strong>Ley 1341 de 2009</strong>, el
<strong>Régimen de Protección de Usuarios de la CRC</strong> (Resolución 5111 de 2017) y el
<strong>Estatuto del Consumidor</strong> (Ley 1480 de 2011).</p>

<div class="ficha">
  <dl>
    <dt>Prestador</dt><dd>{{ $empresa }}</dd>
    @if($nit)<dt>NIT</dt><dd>{{ $nit }}</dd>@endif
    @if($direccion)<dt>Dirección</dt><dd>{{ $direccion }}</dd>@endif
    @if($telefono)<dt>Teléfono / WhatsApp</dt><dd>{{ $telefono }}</dd>@endif
    @if($correo)<dt>Correo</dt><dd>{{ $correo }}</dd>@endif
  </dl>
</div>

<h2>1. Qué incluye el servicio</h2>

<p>Entregamos acceso a internet en el domicilio del cliente, con la velocidad y el precio del plan que haya
contratado. El plan contratado, su valor y la fecha de corte quedan en el contrato y en la factura; esos
documentos mandan sobre cualquier cosa dicha de palabra.</p>

<ul>
  <li>La velocidad contratada es la que se entrega hasta nuestra red. Como en todo servicio de internet, lo que el cliente percibe también depende de su equipo, del wifi, de la distancia al router y del servidor al que se conecte.</li>
  <li>El equipo que instalamos (ONT, router) es <strong>nuestro</strong>, salvo que se haya vendido expresamente. Se entrega en comodato y debe devolverse al terminar el servicio.</li>
  <li>La instalación la hace personal autorizado. Modificar o mover el equipo por cuenta propia puede dejar el servicio sin funcionar y los daños corren por cuenta del cliente.</li>
</ul>

<h2>2. Qué esperamos del cliente</h2>

<ul>
  <li><strong>Pagar a tiempo</strong> el valor del plan, en la fecha que indique la factura.</li>
  <li><strong>Cuidar el equipo</strong> entregado y permitir el acceso del técnico cuando haga falta revisarlo.</li>
  <li><strong>No revender</strong> el servicio ni compartirlo fuera del domicilio contratado.</li>
  <li><strong>No usarlo para actividades ilegales</strong>: fraude, distribución de material prohibido, ataques a terceros o envío masivo de mensajes no solicitados.</li>
  <li><strong>Mantener sus datos al día</strong>, en especial el teléfono, que es por donde avisamos todo.</li>
</ul>

<h2>3. Facturación y pagos</h2>

<ul>
  <li>El servicio se factura por mes anticipado. La factura electrónica llega por los medios que el cliente haya autorizado.</li>
  <li>Se puede pagar en línea desde la plataforma o desde WhatsApp, por los medios que estén habilitados, o en los puntos de pago que se informen.</li>
  <li><strong>Si la factura no se paga</strong> en la fecha indicada, el servicio puede suspenderse tras el aviso correspondiente. Se reactiva cuando el pago quede registrado.</li>
  <li>La suspensión por falta de pago no libera al cliente de pagar lo adeudado.</li>
  <li>Cualquier cambio de precio se avisa con la anticipación que exige la norma, antes de aplicarlo.</li>
</ul>

<h2>4. Fallas y soporte</h2>

<p>Atendemos los reportes de falla por WhatsApp, teléfono o desde la plataforma. Hacemos lo posible por
resolver de forma remota; si no alcanza, programamos una visita técnica.</p>

<ul>
  <li>Cuando una falla imputable a nosotros deje al cliente sin servicio, se descuenta de la factura el tiempo no prestado, como exige la CRC.</li>
  <li>No responden a nuestro cargo las interrupciones por causas ajenas: cortes de energía, desastres naturales, robo de cableado, o daños causados por el propio cliente o por terceros.</li>
  <li>Podemos programar mantenimientos. Cuando impliquen interrupción, se avisa con anticipación.</li>
</ul>

<h2>5. Atención por WhatsApp</h2>

<p>Usamos WhatsApp para avisos de facturación, cortes, soporte y para recibir comprobantes de pago.
El cliente puede pedirnos en cualquier momento que dejemos de escribirle por ese canal, y lo respetamos.
Lo que se conversa por ahí queda registrado en su cuenta; cómo tratamos esa información está en la
<a href="/politica-de-privacidad">política de privacidad</a>.</p>

<h2>6. Terminación</h2>

<ul>
  <li>El cliente puede <strong>terminar el contrato cuando quiera</strong>, avisando por cualquiera de nuestros canales. No cobramos penalidad por retirarse, salvo el saldo de equipos financiados o de una cláusula de permanencia pactada por escrito y vigente.</li>
  <li>Al terminar, debe devolverse el equipo entregado en comodato, en buen estado salvo el desgaste normal.</li>
  <li>Podemos terminar el contrato si hay mora prolongada, uso fraudulento del servicio, o si el cliente impide el acceso al equipo que nos pertenece.</li>
</ul>

<h2>7. Quejas y reclamos</h2>

<p>Toda petición, queja o reclamo puede presentarse por nuestros canales de atención. Respondemos dentro de
los <strong>quince días hábiles</strong> que fija la norma. Si la respuesta no conforma al cliente, tiene derecho
a que el caso pase a la <strong>Superintendencia de Industria y Comercio</strong> en recurso de apelación,
y se lo informamos junto con la respuesta.</p>

<h2>8. Cambios a estas condiciones</h2>

<p>Si cambian, publicamos la versión nueva en esta dirección y actualizamos la fecha del pie. Los cambios que
afecten el precio o las condiciones esenciales se avisan al cliente antes de aplicarlos, y no rigen para
contratos ya firmados sin su aceptación.</p>

@endsection
