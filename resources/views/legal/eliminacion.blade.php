@extends('legal.base')

@section('etiqueta', 'Derecho de supresión')
@section('titulo', 'Cómo pedir que borremos sus datos')
@section('resumen', 'Los pasos para solicitar la eliminación de la información personal que tenemos, cuánto tardamos y qué estamos obligados a conservar.')

@section('cuerpo')

<p>Cualquier persona puede pedirle a <strong>{{ $empresa }}</strong> que elimine los datos personales que
tenemos sobre ella. No hay que pagar nada ni explicar por qué. Es un derecho que reconoce la
<strong>Ley 1581 de 2012</strong>, y acá está cómo ejercerlo.</p>

<h2>Cómo solicitarlo</h2>

<p>Por cualquiera de estos caminos, el que le quede más cómodo:</p>

<div class="ficha">
  <dl>
    @if($whatsapp)
    <dt>WhatsApp</dt>
    <dd><a href="https://wa.me/{{ $whatsapp }}">{{ $telefono }}</a></dd>
    @endif
    @if($correo)
    <dt>Correo</dt>
    <dd><a href="mailto:{{ $correo }}?subject=Solicitud%20de%20eliminaci%C3%B3n%20de%20datos">{{ $correo }}</a></dd>
    @endif
    @if($direccion)
    <dt>En persona</dt>
    <dd>{{ $direccion }}</dd>
    @endif
  </dl>
</div>

<p>En el mensaje díganos:</p>

<ol>
  <li>Su <strong>nombre completo</strong> y su <strong>número de documento</strong>.</li>
  <li>Que desea <strong>eliminar sus datos personales</strong>.</li>
  <li>Si quiere borrar todo o sólo algo puntual —por ejemplo, dejar de recibir mensajes de WhatsApp, pero seguir siendo cliente—.</li>
</ol>

<p>Pedimos el documento únicamente para confirmar que es usted: nadie puede pedir que se borren los datos de otra persona.</p>

<h2>Qué pasa después</h2>

<table>
  <tr><th>Momento</th><th>Qué ocurre</th></tr>
  <tr><td><strong>Ese mismo día</strong></td><td>Le confirmamos que recibimos la solicitud.</td></tr>
  <tr><td><strong>Hasta 15 días hábiles</strong></td><td>Revisamos qué podemos borrar y qué estamos obligados a conservar, y le respondemos por escrito lo que se hizo con cada cosa.</td></tr>
  <tr><td><strong>Al cerrarse</strong></td><td>Lo borrado se elimina de la plataforma y de las copias de respaldo en el siguiente ciclo de rotación.</td></tr>
</table>

<h2>Qué se borra</h2>

<ul>
  <li>Sus datos de contacto: teléfono, correo, dirección.</li>
  <li>El historial de conversaciones de WhatsApp y los comprobantes que nos haya enviado.</li>
  <li>Los datos técnicos de su conexión y de su equipo.</li>
  <li>Su usuario de la plataforma y sus preferencias.</li>
</ul>

<h2>Qué no podemos borrar, y por qué</h2>

<p>Hay información que la ley nos obliga a conservar aunque usted pida lo contrario. No es una decisión nuestra:</p>

<table>
  <tr><th>Información</th><th>Cuánto</th><th>Por qué</th></tr>
  <tr>
    <td>Facturas emitidas y pagos recibidos</td>
    <td>10 años</td>
    <td>Normas contables y tributarias (Estatuto Tributario y Código de Comercio)</td>
  </tr>
  <tr>
    <td>Contrato firmado</td>
    <td>Mientras pueda reclamarse algo derivado de él</td>
    <td>Es la prueba de lo que se acordó entre las partes</td>
  </tr>
  <tr>
    <td>Deuda pendiente</td>
    <td>Hasta que se pague o prescriba</td>
    <td>No se extingue una obligación pidiendo que se borren los datos</td>
  </tr>
</table>

<p>Esa información queda guardada <strong>sólo</strong> para cumplir con esas obligaciones: no la usamos para
contactarlo ni para nada más. Cumplido el plazo, se elimina.</p>

<h2>Si sigue siendo cliente</h2>

<p>Para prestarle el internet necesitamos, como mínimo, saber a quién le facturamos y dónde está instalado
el servicio. Si pide que borremos todo mientras el servicio sigue activo, se lo diremos con claridad: eso
implica dar de baja el contrato. También puede pedir algo más acotado —por ejemplo, que dejemos de
escribirle por WhatsApp— y eso lo aplicamos de inmediato, sin tocar el servicio.</p>

<h2>Si no está conforme</h2>

<p>Si cree que no atendimos bien su solicitud, puede presentar una queja ante la
<strong>Superintendencia de Industria y Comercio</strong>, que es la autoridad de protección de datos en Colombia:
<a href="https://www.sic.gov.co">www.sic.gov.co</a>. La ley pide que primero nos lo plantee a nosotros,
para darnos la oportunidad de resolverlo.</p>

@endsection
