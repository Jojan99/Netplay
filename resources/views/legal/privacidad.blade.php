@extends('legal.base')

@section('etiqueta', 'Tratamiento de datos personales')
@section('titulo', 'Política de privacidad')
@section('resumen', 'Qué datos recogemos de nuestros clientes, para qué los usamos y qué puede pedirnos cada persona sobre los suyos.')

@section('cuerpo')

<p>Esta política explica cómo <strong>{{ $empresa }}</strong> trata los datos personales de quienes contratan
nuestro servicio de internet y de quienes se comunican con nosotros. Está escrita conforme a la
<strong>Ley 1581 de 2012</strong>, el <strong>Decreto 1074 de 2015</strong> y las demás normas colombianas de
protección de datos, que reconocen a toda persona el derecho a conocer, actualizar y rectificar la información
que se tenga sobre ella.</p>

<div class="ficha">
  <dl>
    <dt>Responsable</dt><dd>{{ $empresa }}</dd>
    @if($nit)<dt>NIT</dt><dd>{{ $nit }}</dd>@endif
    @if($direccion)<dt>Dirección</dt><dd>{{ $direccion }}</dd>@endif
    @if($telefono)<dt>Teléfono / WhatsApp</dt><dd>{{ $telefono }}</dd>@endif
    @if($correo)<dt>Correo</dt><dd>{{ $correo }}</dd>@endif
  </dl>
</div>

<h2>1. Qué datos recogemos</h2>

<p>Sólo pedimos lo que hace falta para prestar el servicio, facturarlo y atender al cliente.
No recogemos datos por si acaso.</p>

<table>
  <tr><th>Tipo de dato</th><th>Para qué</th></tr>
  <tr>
    <td><strong>Identificación</strong><br>Nombre, documento, dirección, teléfono, correo</td>
    <td>Firmar el contrato, emitir la factura electrónica e instalar el servicio en el lugar correcto</td>
  </tr>
  <tr>
    <td><strong>Del servicio</strong><br>Plan contratado, equipo instalado, estado de la conexión</td>
    <td>Prestar el internet, atender fallas y hacer soporte remoto sin tener que ir al domicilio</td>
  </tr>
  <tr>
    <td><strong>De facturación</strong><br>Facturas, pagos, saldo pendiente</td>
    <td>Cobrar el servicio y llevar la contabilidad que exige la DIAN</td>
  </tr>
  <tr>
    <td><strong>De contacto</strong><br>Mensajes de WhatsApp, correos, comprobantes que nos envía</td>
    <td>Responder consultas, registrar pagos reportados y dejar constancia de lo acordado</td>
  </tr>
  <tr>
    <td><strong>Técnicos de red</strong><br>Dirección IP asignada, potencia de la señal, consumo</td>
    <td>Diagnosticar fallas y dimensionar la red. No inspeccionamos el contenido de lo que navega</td>
  </tr>
</table>

<h3>Lo que no hacemos</h3>
<ul>
  <li>No vendemos ni alquilamos datos personales a nadie.</li>
  <li>No guardamos datos de tarjetas de crédito: de eso se encarga la pasarela de pagos, que está certificada para hacerlo.</li>
  <li>No revisamos el contenido de la navegación, los mensajes ni los archivos de nuestros clientes.</li>
</ul>

<h2>2. WhatsApp</h2>

<p>Atendemos y avisamos por WhatsApp usando la plataforma de <strong>WhatsApp Business de Meta</strong>.
Eso implica que:</p>

<ul>
  <li>Cuando una persona nos escribe, WhatsApp nos entrega su número y el contenido de su mensaje, y nosotros lo guardamos junto a su cuenta para poder darle continuidad a la conversación.</li>
  <li>Le escribimos por ese canal para avisos de facturación, cortes programados, fallas y soporte. Puede pedirnos que dejemos de hacerlo en cualquier momento y lo respetamos.</li>
  <li>Si paga desde WhatsApp, lo que viaja hacia la pasarela es el monto, el número de sus facturas y su celular. La aprobación del pago ocurre en la aplicación de su banco o billetera, no en la nuestra.</li>
  <li>El transporte de los mensajes se rige además por las condiciones de Meta, que no dependen de nosotros.</li>
</ul>

<h2>3. Con quién compartimos datos</h2>

<p>Compartimos lo mínimo indispensable, y sólo con quienes nos ayudan a prestar el servicio:</p>

<ul>
  <li><strong>Pasarelas de pago</strong>, para procesar los pagos en línea.</li>
  <li><strong>Proveedores de facturación electrónica</strong>, porque la DIAN exige que la factura salga con los datos del cliente.</li>
  <li><strong>Meta</strong>, en la medida en que usamos WhatsApp para comunicarnos.</li>
  <li><strong>Autoridades</strong>, cuando una orden judicial o una norma nos obliga a entregar información.</li>
</ul>

<p>Nuestros proveedores sólo pueden usar esos datos para lo que les encargamos, nunca para fines propios.</p>

<h2>4. Cuánto tiempo los guardamos</h2>

<p>Mientras la persona sea cliente, y después el tiempo que exijan las normas contables y tributarias
—diez años para la información de facturación—. Cumplido ese plazo, o cuando alguien pide que se borren
sus datos y no hay obligación legal de conservarlos, los eliminamos.</p>

<h2>5. Sus derechos</h2>

<p>Toda persona cuyos datos tengamos puede, gratis y sin tener que justificarlo:</p>

<ul>
  <li><strong>Conocer</strong> qué datos suyos tenemos y de dónde salieron.</li>
  <li><strong>Actualizarlos o corregirlos</strong> si están mal o quedaron viejos.</li>
  <li><strong>Pedir que los borremos</strong>, cuando no exista un deber legal o contractual de conservarlos.</li>
  <li><strong>Revocar la autorización</strong> que nos dio para tratarlos.</li>
  <li><strong>Pedir copia</strong> de la autorización que firmó.</li>
  <li><strong>Presentar queja</strong> ante la Superintendencia de Industria y Comercio, si considera que no cumplimos.</li>
</ul>

@php
    $canales = array_filter([
        $correo   ? 'al correo ' . $correo : null,
        $telefono ? 'por WhatsApp al ' . $telefono : null,
    ]);
@endphp

<p>Para ejercerlos, escríbanos {{ $canales ? implode(' o ', $canales) : 'por los canales de atención habituales' }}.
Respondemos las consultas en <strong>diez días hábiles</strong> y los reclamos en <strong>quince días hábiles</strong>,
como manda la ley. En la página de <a href="/eliminacion-de-datos">eliminación de datos</a> está el detalle de cómo pedir el borrado.</p>

<h2>6. Cómo cuidamos la información</h2>

<ul>
  <li>Todo viaja cifrado: la plataforma sólo se abre por HTTPS.</li>
  <li>Cada empleado ve únicamente lo que necesita para su trabajo, según su rol.</li>
  <li>Las contraseñas se guardan cifradas, de modo que ni nosotros podemos leerlas.</li>
  <li>Queda registro de quién consulta o modifica la información de un cliente.</li>
  <li>Hacemos copias de respaldo para no perder información ante una falla.</li>
</ul>

<p>Ningún sistema es infalible. Si llegara a ocurrir un incidente que afecte datos personales, avisaremos a
los afectados y a la Superintendencia de Industria y Comercio, como corresponde.</p>

<h2>7. Menores de edad</h2>

<p>No prestamos el servicio a menores de edad ni les pedimos datos. Si un menor aparece como contacto
en la cuenta de un titular adulto, tratamos esos datos con la misma reserva y sólo para asuntos del servicio.</p>

<h2>8. Cambios a esta política</h2>

<p>Si cambiamos algo, publicamos la versión nueva en esta misma dirección y actualizamos la fecha del pie.
Cuando el cambio sea de fondo, además avisamos a los clientes por los medios de contacto que tengamos.</p>

@endsection
