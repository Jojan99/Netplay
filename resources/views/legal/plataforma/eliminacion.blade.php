@extends('legal.base')

@section('etiqueta', 'Derecho de supresión')
@section('titulo', 'Cómo pedir que borremos sus datos')
@section('resumen', 'Los pasos para solicitar la eliminación de la información personal que trata ' . $marca . ', cuánto tardamos y qué estamos obligados a conservar.')

@section('cuerpo')

<p>Cualquier persona puede pedir que se eliminen los datos personales que <strong>{{ $marca }}</strong> tiene
sobre ella. No cuesta nada ni hay que explicar el motivo: es un derecho que reconoce la
<strong>Ley 1581 de 2012</strong>. A quién debe dirigirse depende de cómo llegaron sus datos a la plataforma.</p>

<h2>Primero: ¿de quién son los datos?</h2>

<table>
  <tr><th>Si usted es…</th><th>Quién decide sobre sus datos</th><th>A quién pedir el borrado</th></tr>
  <tr>
    <td><strong>Cliente de un proveedor de internet</strong> que usa {{ $marca }}, o empleado suyo</td>
    <td>Su proveedor de internet</td>
    <td>A su proveedor, por los canales de su propia página de eliminación de datos. Si nos escribe a nosotros, le pasamos la solicitud dentro de los dos días hábiles siguientes y le avisamos.</td>
  </tr>
  <tr>
    <td><strong>Administrador o usuario de una empresa registrada</strong>, o alguien que nos contactó directamente</td>
    <td>{{ $marca }}</td>
    <td>A nosotros, como se explica abajo.</td>
  </tr>
</table>

<h2>Cómo solicitarlo a {{ $marca }}</h2>

<div class="ficha">
  <dl>
    @if($correo)
    <dt>Correo</dt>
    <dd><a href="mailto:{{ $correo }}?subject=Solicitud%20de%20eliminaci%C3%B3n%20de%20datos">{{ $correo }}</a></dd>
    @endif
    @if($whatsapp)
    <dt>WhatsApp</dt>
    <dd><a href="https://wa.me/{{ $whatsapp }}">{{ $telefono }}</a></dd>
    @endif
    @if($direccion)
    <dt>Por escrito</dt>
    <dd>{{ $direccion }}</dd>
    @endif
    @if(!$correo && !$whatsapp)
    <dt>Canales</dt>
    <dd>Los de soporte publicados en {{ $dominio }}</dd>
    @endif
  </dl>
</div>

<p>En el mensaje indique:</p>

<ol>
  <li>Su <strong>nombre completo</strong> y su <strong>número de documento</strong>.</li>
  <li>La <strong>empresa</strong> a la que pertenece su cuenta, si tiene una.</li>
  <li>Qué quiere eliminar: todo, o algo puntual —por ejemplo, dejar de recibir novedades de {{ $marca }}—.</li>
</ol>

<p>Pedimos el documento únicamente para confirmar que es usted. Si la solicitud busca cerrar la cuenta de una
empresa, debe hacerla su administrador o su representante legal.</p>

<h2>Qué pasa después</h2>

<table>
  <tr><th>Momento</th><th>Qué ocurre</th></tr>
  <tr><td><strong>Al recibirla</strong></td><td>Confirmamos el recibo y, si falta algún dato, lo pedimos dentro de los cinco días siguientes.</td></tr>
  <tr><td><strong>Hasta 15 días hábiles</strong></td><td>Revisamos qué se puede borrar y qué estamos obligados a conservar, y respondemos por escrito qué se hizo con cada cosa.</td></tr>
  <tr><td><strong>Al cerrarse</strong></td><td>Lo eliminado desaparece de la plataforma, y de las copias de respaldo cuando se sobrescriben en su ciclo normal de rotación.</td></tr>
</table>

<h2>Cuando se cancela la cuenta de una empresa</h2>

<p>La información de la empresa queda disponible para <strong>exportarla durante 30 días</strong>. Después se
elimina de la plataforma dentro de los 60 días siguientes, incluidos los datos de sus suscriptores y empleados.</p>

<h2>Qué no podemos borrar, y por qué</h2>

<table>
  <tr><th>Información</th><th>Cuánto</th><th>Por qué</th></tr>
  <tr>
    <td>Facturas y pagos de la suscripción</td>
    <td>10 años</td>
    <td>Normas contables y tributarias</td>
  </tr>
  <tr>
    <td>Prueba de la aceptación de los términos y registros de seguridad</td>
    <td>Mientras pueda reclamarse algo derivado del contrato</td>
    <td>Demostrar el cumplimiento y atender reclamaciones</td>
  </tr>
  <tr>
    <td>Saldos pendientes de la suscripción</td>
    <td>Hasta que se paguen o prescriban</td>
    <td>Una obligación no se extingue pidiendo que se borren los datos</td>
  </tr>
</table>

<p>Esa información queda guardada <strong>sólo</strong> para cumplir esas obligaciones. Cumplido el plazo, se elimina.</p>

<h2>Si no está conforme</h2>

<p>Si considera que no atendimos bien su solicitud, puede presentar una queja ante la
<strong>Superintendencia de Industria y Comercio</strong>, autoridad de protección de datos en Colombia:
<a href="https://www.sic.gov.co">www.sic.gov.co</a>. La ley pide que primero nos lo plantee a nosotros.</p>

@endsection
