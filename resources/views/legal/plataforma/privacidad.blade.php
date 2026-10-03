@extends('legal.base')

@section('etiqueta', 'Tratamiento de datos personales')
@section('titulo', 'Política de tratamiento de datos personales')
@section('resumen', 'Qué datos trata ' . $marca . ', en qué calidad lo hace, para qué, con quién los comparte y cómo puede cada persona ejercer sus derechos.')

@section('cuerpo')

<p><strong>{{ $marca }}</strong> es un software en línea para proveedores de servicio de internet (ISP): con él
administran sus clientes, su facturación, su cartera, su red y su atención por WhatsApp. Esta política explica
cómo tratamos los datos personales que pasan por la plataforma. Está escrita conforme al artículo 15 de la
Constitución, la <strong>Ley 1581 de 2012</strong>, el <strong>Decreto 1377 de 2013</strong> (compilado en el
<strong>Decreto 1074 de 2015</strong>) y las instrucciones de la Superintendencia de Industria y Comercio (SIC).</p>

<div class="ficha">
  <dl>
    <dt>Responsable</dt><dd>{{ $empresa }}</dd>
    @if($nit)<dt>NIT</dt><dd>{{ $nit }}</dd>@endif
    @if($direccion)<dt>Domicilio</dt><dd>{{ $direccion }}</dd>@endif
    @if($telefono)<dt>Teléfono / WhatsApp</dt><dd>{{ $telefono }}</dd>@endif
    @if($correo)<dt>Correo para datos personales</dt><dd>{{ $correo }}</dd>@endif
    <dt>Sitio</dt><dd>{{ $dominio }}</dd>
  </dl>
</div>

<h2>1. En qué calidad actúa {{ $marca }}</h2>

<p>La ley distingue entre quien decide sobre los datos (el <strong>responsable</strong>) y quien los trata por
cuenta de otro (el <strong>encargado</strong>). {{ $marca }} cumple los dos papeles, según de quién sean los datos:</p>

<table>
  <tr><th>Datos de</th><th>Papel de {{ $marca }}</th><th>Quién responde ante el titular</th></tr>
  <tr>
    <td><strong>Las empresas que se registran</strong>, sus representantes y quienes nos contactan o visitan el sitio</td>
    <td>Responsable</td>
    <td>{{ $marca }}, por los canales de esta política</td>
  </tr>
  <tr>
    <td><strong>Los suscriptores y empleados de cada ISP</strong>, que el ISP carga o recoge usando la plataforma</td>
    <td>Encargado</td>
    <td>El ISP, que es el responsable y publica su propia política en su dirección dentro de la plataforma</td>
  </tr>
</table>

<p>Si usted es cliente de un proveedor de internet que usa {{ $marca }}, quien decide sobre sus datos es ese
proveedor. Puede escribirnos igual: le diremos a quién dirigirse y le pasaremos su solicitud.</p>

<h2>2. Datos que tratamos como responsable</h2>

<p>Pedimos lo necesario para abrir la cuenta, prestar el servicio y cobrarlo.</p>

<table>
  <tr><th>Dato</th><th>Para qué</th></tr>
  <tr>
    <td><strong>De la empresa</strong><br>Nombre o razón social, NIT, dirección, ciudad, teléfono y correo</td>
    <td>Crear la cuenta, emitir la factura de la suscripción y contactar a la empresa sobre el servicio</td>
  </tr>
  <tr>
    <td><strong>Del administrador y de los usuarios de la cuenta</strong><br>Nombre, documento, celular, correo, usuario y contraseña (guardada cifrada)</td>
    <td>Identificar a quien entra, dar los permisos que correspondan y dejar constancia de quién hizo cada cosa</td>
  </tr>
  <tr>
    <td><strong>De la suscripción</strong><br>Plan, pagos, facturas, cupones y códigos de referido</td>
    <td>Cobrar el servicio, aplicar descuentos y cumplir las obligaciones contables y tributarias</td>
  </tr>
  <tr>
    <td><strong>De soporte</strong><br>Mensajes, correos y archivos que nos envían al pedir ayuda</td>
    <td>Resolver la solicitud y mejorar el producto</td>
  </tr>
  <tr>
    <td><strong>De uso</strong><br>Fecha y hora de ingreso, dirección IP, acciones realizadas dentro de la cuenta</td>
    <td>Seguridad, prevención de fraude, auditoría y diagnóstico de fallas</td>
  </tr>
  <tr>
    <td><strong>De la aceptación</strong><br>Fecha, versión del texto aceptado y dirección IP</td>
    <td>Conservar la prueba de la autorización, como exige la ley</td>
  </tr>
</table>

<p>El sitio guarda en el navegador lo indispensable para funcionar (la sesión y la preferencia de tema claro u
oscuro). No usamos cookies publicitarias ni vendemos espacios de publicidad.</p>

<h3>Finalidades</h3>
<ul>
  <li>Prestar, mantener y mejorar la plataforma, y dar soporte.</li>
  <li>Facturar y cobrar la suscripción.</li>
  <li>Avisar sobre el servicio: mantenimientos, cambios, incidentes, vencimientos y novedades de la cuenta.</li>
  <li>Enviar, a las empresas registradas que lo hayan autorizado, información sobre funciones nuevas y ofertas de {{ $marca }}. Se puede pedir la baja en cualquier momento y no afecta el servicio.</li>
  <li>Proteger la plataforma, investigar usos indebidos y cumplir órdenes de autoridad competente.</li>
  <li>Elaborar estadísticas de uso sin identificar a personas.</li>
</ul>

<h2>3. Datos que tratamos como encargado</h2>

<p>Cada ISP carga en su cuenta la información de su operación. Sobre esos datos {{ $marca }} actúa por cuenta
del ISP y siguiendo sus instrucciones, en los términos del acuerdo de transmisión que forma parte de los
<a href="/terminos-del-servicio">términos del servicio</a>.</p>

<table>
  <tr><th>Categoría</th><th>Ejemplos</th></tr>
  <tr><td><strong>Identificación y contacto de suscriptores</strong></td><td>Nombre, documento, dirección, ubicación de la instalación, teléfono, correo</td></tr>
  <tr><td><strong>Del servicio</strong></td><td>Plan, contrato y su firma electrónica, equipo instalado, órdenes de instalación y traslado, tickets</td></tr>
  <tr><td><strong>Financieros del servicio</strong></td><td>Facturas, pagos, saldos, comprobantes de pago enviados por el suscriptor, acuerdos de pago</td></tr>
  <tr><td><strong>Comunicaciones</strong></td><td>Conversaciones de WhatsApp y correos entre el ISP y sus suscriptores</td></tr>
  <tr><td><strong>Técnicos de red</strong></td><td>Dirección IP, usuario de conexión, potencia de señal, estado y consumo del equipo</td></tr>
  <tr><td><strong>Del personal del ISP</strong></td><td>Nombre, documento, cargo, contacto y, si el ISP activa el mapa de técnicos, la ubicación del técnico durante su jornada</td></tr>
</table>

<p>Frente a estos datos, {{ $marca }}:</p>
<ul>
  <li>Los trata <strong>únicamente</strong> para prestar la plataforma al ISP. No los usa para fines propios, no los vende ni los cede.</li>
  <li>No contacta a los suscriptores por iniciativa propia: los mensajes que reciben los envía su ISP a través de la plataforma.</li>
  <li>Mantiene separada la información de cada ISP: una empresa no puede ver los datos de otra.</li>
  <li>No inspecciona el contenido de la navegación de los suscriptores. La plataforma administra la conexión, no el tráfico.</li>
</ul>

<p>Corresponde al ISP, como responsable, obtener la autorización de sus suscriptores y empleados, informarles su
política y atender sus consultas y reclamos.</p>

<h2>4. Tratamientos automatizados e inteligencia artificial</h2>

<p>La plataforma ofrece funciones automáticas que el ISP decide si activa y cómo las configura:</p>

<ul>
  <li><strong>Cortes y reactivaciones por mora</strong>, según las reglas de facturación que fija el ISP.</li>
  <li><strong>Lectura de comprobantes de pago</strong>, para cruzar el pago reportado con la factura. El ISP puede revisar cada caso a mano y revertir lo aplicado.</li>
  <li><strong>Asistente automático de conversación y cobranza por WhatsApp</strong>, que responde con un modelo de inteligencia artificial de un proveedor externo. Para generar la respuesta, el proveedor recibe el texto de la conversación y los datos mínimos de la cuenta (por ejemplo, el saldo).</li>
</ul>

<p>Una persona del ISP puede intervenir en cualquier momento, y el suscriptor puede pedir que lo atienda una
persona. {{ $marca }} no usa los datos de los ISP ni de sus suscriptores para entrenar modelos propios. Estos
tratamientos siguen los principios de la Ley 1581 y las instrucciones de la SIC sobre inteligencia artificial
(Circular Externa 002 de 2024).</p>

<h2>5. Con quién se comparten los datos</h2>

<p>Para operar nos apoyamos en proveedores que tratan datos por cuenta nuestra o del ISP, sólo para la función
que cumplen:</p>

<table>
  <tr><th>Proveedor</th><th>Para qué</th></tr>
  <tr><td><strong>Infraestructura y seguridad en la nube</strong> (alojamiento de servidores, protección contra ataques, copias de respaldo)</td><td>Tener la plataforma disponible y protegida</td></tr>
  <tr><td><strong>Meta (WhatsApp Business)</strong></td><td>Transportar los mensajes de WhatsApp, cuando el ISP conecta ese canal</td></tr>
  <tr><td><strong>Servicio de correo transaccional</strong></td><td>Enviar facturas, comprobantes y avisos por correo</td></tr>
  <tr><td><strong>Pasarelas de pago</strong> que cada ISP elige y configura con su propia cuenta</td><td>Procesar los pagos en línea. {{ $marca }} no guarda datos de tarjetas ni recibe el dinero de los suscriptores</td></tr>
  <tr><td><strong>Proveedores de inteligencia artificial</strong></td><td>Generar las respuestas del asistente automático, cuando el ISP lo activa</td></tr>
  <tr><td><strong>Autoridades</strong></td><td>Cuando una norma o una orden de autoridad competente lo exige</td></tr>
</table>

<h3>Transmisión internacional</h3>
<p>Algunos de esos proveedores tienen servidores fuera de Colombia, principalmente en Estados Unidos y la Unión
Europea. Los datos viajan a ellos en calidad de <strong>transmisión</strong> para que presten su servicio, bajo
contratos o condiciones que los obligan a protegerlos y a usarlos sólo para ese fin, como prevén el artículo 26
de la Ley 1581 y el artículo 25 del Decreto 1377 de 2013. Al aceptar esta política, el titular autoriza esas
transmisiones.</p>

<p>{{ $marca }} no reporta información a centrales de riesgo. Si un ISP lo hace, es una decisión suya y debe
cumplir la Ley 1266 de 2008.</p>

<h2>6. Cuánto tiempo se conservan</h2>

<ul>
  <li><strong>Datos de la cuenta</strong>: mientras la empresa use la plataforma.</li>
  <li><strong>Al cancelar la cuenta</strong>: la información del ISP queda disponible para exportarla durante 30 días y se elimina de la plataforma dentro de los 60 días siguientes. Las copias de respaldo se sobrescriben en su ciclo normal de rotación.</li>
  <li><strong>Facturas y soportes contables de la suscripción</strong>: diez años, por las normas contables y tributarias.</li>
  <li><strong>Prueba de la autorización y registros de seguridad</strong>: el tiempo necesario para demostrar el cumplimiento y atender reclamaciones.</li>
</ul>

<p>Las bases de datos permanecen vigentes mientras subsista la finalidad para la que se recogieron los datos o
exista un deber legal o contractual de conservarlos.</p>

<h2>7. Derechos del titular</h2>

<p>Toda persona cuyos datos tratemos como responsable puede, sin costo:</p>

<ul>
  <li><strong>Conocer, actualizar y rectificar</strong> sus datos.</li>
  <li><strong>Pedir prueba</strong> de la autorización que otorgó.</li>
  <li><strong>Ser informada</strong> del uso que se ha dado a sus datos.</li>
  <li><strong>Revocar la autorización o pedir la supresión</strong> de sus datos, cuando no exista un deber legal o contractual de conservarlos.</li>
  <li><strong>Acceder gratis</strong> a sus datos al menos una vez al mes y cada vez que esta política cambie de fondo.</li>
  <li><strong>Presentar queja</strong> ante la Superintendencia de Industria y Comercio, después de haber agotado el trámite ante nosotros.</li>
</ul>

<h3>Cómo ejercerlos</h3>

@php
    $canales = array_filter([
        $correo   ? 'al correo ' . $correo : null,
        $telefono ? 'por WhatsApp al ' . $telefono : null,
    ]);
@endphp

<p>Escriba {{ $canales ? implode(' o ', $canales) : 'por los canales de soporte publicados en ' . $dominio }},
indicando su nombre, su documento, la empresa a la que pertenece su cuenta, qué solicita y a dónde quiere recibir
la respuesta. El área de soporte y cumplimiento de {{ $marca }} es la encargada de tramitar estas solicitudes.</p>

<table>
  <tr><th>Trámite</th><th>Plazo de respuesta</th></tr>
  <tr><td><strong>Consulta</strong> (saber qué datos tenemos)</td><td>Diez días hábiles desde que la recibimos. Si no alcanza, avisamos el motivo y respondemos dentro de los cinco días hábiles siguientes.</td></tr>
  <tr><td><strong>Reclamo</strong> (corregir, actualizar, suprimir o denunciar un incumplimiento)</td><td>Quince días hábiles desde el día siguiente al recibo. Si no alcanza, avisamos el motivo y respondemos dentro de los ocho días hábiles siguientes.</td></tr>
</table>

<p>Si al reclamo le falta información, la pedimos dentro de los cinco días siguientes; si pasan dos meses sin
que se complete, se entiende desistido. Si la solicitud corresponde a datos de los que es responsable un ISP,
se la trasladamos dentro de los dos días hábiles siguientes y le avisamos. El detalle de cómo pedir el borrado
está en <a href="/eliminacion-de-datos">eliminación de datos</a>.</p>

<h2>8. Autorización</h2>

<p>Quien registra una empresa acepta de forma expresa esta política y los términos del servicio antes de crear
la cuenta; guardamos la fecha, la versión aceptada y la dirección IP como prueba. Quien nos escribe por los
canales de contacto autoriza el tratamiento de los datos que nos entrega para atender su solicitud. El
administrador de la empresa declara que está facultado para entregar los datos de los usuarios que cree en su
cuenta.</p>

<h2>9. Seguridad</h2>

<ul>
  <li>La plataforma sólo se abre por conexión cifrada (HTTPS).</li>
  <li>Las contraseñas se guardan con cifrado irreversible: nadie puede leerlas, tampoco nosotros.</li>
  <li>Las claves de las pasarelas de pago, de WhatsApp y de correo, y las contraseñas de conexión y de wifi de los suscriptores, se guardan cifradas.</li>
  <li>Cada usuario ve sólo lo que su rol permite, y los datos de cada empresa están separados de los de las demás.</li>
  <li>Queda registro de los ingresos y de quién modifica la información.</li>
  <li>Se hacen copias de respaldo diarias.</li>
  <li>El acceso de nuestro personal a los datos de los ISP se limita a soporte y mantenimiento, bajo deber de confidencialidad.</li>
</ul>

<p>Ningún sistema es infalible. Si ocurre un incidente de seguridad que afecte datos personales, lo reportaremos
a la Superintendencia de Industria y Comercio dentro del plazo legal y avisaremos a los afectados. Cuando se
trate de datos de un ISP, le avisaremos sin demora para que pueda cumplir sus propios deberes.</p>

<h2>10. Menores de edad y datos sensibles</h2>

<p>{{ $marca }} se dirige a empresas. No pedimos datos de menores de edad ni datos sensibles (salud, origen
étnico, orientación política o religiosa, biometría). Los ISP no deben cargar ese tipo de información; si por
su operación llegaran a hacerlo, les corresponde contar con la autorización reforzada que exige la ley.</p>

<h2>11. Vigencia y cambios</h2>

<p>Esta política rige desde el {{ $actualizado }}@if($version) (versión {{ $version }})@endif. Si cambia,
publicamos la versión nueva en esta misma dirección. Cuando el cambio sea de fondo —por ejemplo, en las
finalidades o en quién es el responsable— avisamos a las empresas registradas antes de aplicarlo.</p>

@endsection
