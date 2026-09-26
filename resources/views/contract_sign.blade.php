{{--
    Página de firma del cliente. La abre desde el celular, con el link que le
    llega por WhatsApp o correo, y muchas veces con datos y un teléfono de gama
    media: todo va aquí adentro, sin librerías ni CDN, y las animaciones son CSS.
    El controlador entrega los datos ya resueltos y escapados.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
{{-- Sin maximum-scale: el cliente tiene que poder agrandar el contrato --}}
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>{{ $titulo }} · {{ $empresa }}</title>
<style>
:root{
  --fondo:#f4f5f9; --sup:#fff; --sup-2:#f8f9fc; --texto:#16182b; --texto-2:#5b6079;
  --linea:#e3e5ef; --linea-2:#cfd2e2;
  --marca:#4f46e5; --marca-suave:#eef0ff; --marca-ink:#fff;
  --ok:#0f8a5f; --ok-suave:#e6f6ef; --aviso:#9a6200; --aviso-suave:#fff5e0; --error:#b3261e;
  --sombra:0 1px 2px rgba(20,22,40,.06), 0 8px 24px rgba(20,22,40,.06);
  --radio:14px; --papel:#fff; --papel-texto:#1c1e2e;
}
@media (prefers-color-scheme:dark){:root{
  --fondo:#0e1020; --sup:#171a2e; --sup-2:#1d2036; --texto:#eef0fa; --texto-2:#a6abc6;
  --linea:#2a2e48; --linea-2:#3a3f60;
  --marca:#8b87ff; --marca-suave:#242648; --marca-ink:#0e1020;
  --ok:#4ade80; --ok-suave:#16301f; --aviso:#fbbf24; --aviso-suave:#332608; --error:#ff8a80;
  --sombra:0 1px 2px rgba(0,0,0,.4), 0 8px 24px rgba(0,0,0,.35);
  --papel:#f7f7f4; --papel-texto:#1c1e2e;
}}
*{box-sizing:border-box;margin:0;padding:0}
html{-webkit-text-size-adjust:100%}
body{font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;background:var(--fondo);color:var(--texto);
  font-size:15px;line-height:1.55;min-height:100dvh;display:flex;flex-direction:column;overflow-x:hidden}
button{font:inherit;color:inherit;cursor:pointer;border:0;background:none}
img{max-width:100%;display:block}
:focus-visible{outline:3px solid var(--marca);outline-offset:2px;border-radius:6px}
.oculto{display:none!important}
.solo-lectores{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}

/* ── Cabecera con el avance ─────────────────────────────────────────── */
.cab{position:sticky;top:0;z-index:20;background:var(--sup);border-bottom:1px solid var(--linea);
  padding:max(10px,env(safe-area-inset-top)) 16px 0}
.cab-fila{display:flex;align-items:center;gap:10px;max-width:680px;margin:0 auto;padding-bottom:9px}
.cab-logo{height:30px;width:auto;max-width:112px;object-fit:contain;flex-shrink:0}
.cab-marca{font-weight:700;font-size:13px;letter-spacing:.01em;flex-shrink:0}
.cab-tit{font-size:11.5px;color:var(--texto-2);margin-left:auto;text-align:right;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:50%}
.avance{max-width:680px;margin:0 auto;display:flex;gap:5px;padding-bottom:9px}
.avance i{flex:1;height:4px;border-radius:99px;background:var(--linea-2);transition:background .35s ease}
.avance i.hecho{background:var(--marca)}
.avance i.aqui{background:var(--marca);box-shadow:0 0 0 3px var(--marca-suave)}

/* ── Estructura ─────────────────────────────────────────────────────── */
main{flex:1;width:100%;max-width:680px;margin:0 auto;padding:18px 16px 22px}
.paso{display:none;animation:entra .3s ease both}
.paso.activo{display:block}
@keyframes entra{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
.paso-num{font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:var(--marca);margin-bottom:6px}
h1{font-size:23px;line-height:1.22;letter-spacing:-.02em;margin-bottom:8px;text-wrap:balance}
h2{font-size:17px;margin-bottom:6px}
.guia-txt{color:var(--texto-2);font-size:14px;margin-bottom:16px}

.tarjeta{background:var(--sup);border:1px solid var(--linea);border-radius:var(--radio);box-shadow:var(--sombra);
  padding:14px 16px;margin-bottom:14px}
.resumen{display:grid;grid-template-columns:auto 1fr;gap:7px 14px;font-size:14px}
.resumen dt{color:var(--texto-2);white-space:nowrap}
.resumen dd{font-weight:650;text-align:right;overflow-wrap:anywhere}
.lista-pasos{list-style:none;display:grid;gap:11px;margin:4px 0 2px}
.lista-pasos li{display:flex;gap:11px;align-items:flex-start;font-size:14px}
.lista-pasos b{display:grid;place-items:center;flex-shrink:0;width:25px;height:25px;border-radius:50%;
  background:var(--marca-suave);color:var(--marca);font-size:12.5px;font-weight:700}
.lista-pasos span{color:var(--texto-2);display:block;font-size:12.5px}

/* ── Botones ────────────────────────────────────────────────────────── */
.acciones{position:sticky;bottom:0;background:linear-gradient(to top,var(--fondo) 62%,transparent);
  padding:14px 0 max(14px,env(safe-area-inset-bottom));display:flex;gap:9px;z-index:10}
.btn{flex:1;display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:50px;padding:0 18px;
  border-radius:12px;font-size:15.5px;font-weight:700;transition:transform .12s ease,opacity .15s ease,background .15s}
.btn:active{transform:scale(.985)}
.btn-1{background:var(--marca);color:var(--marca-ink)}
.btn-1[disabled]{opacity:.42;cursor:not-allowed;transform:none}
.btn-2{background:var(--sup);color:var(--texto-2);border:1px solid var(--linea-2);flex:0 0 auto;min-width:92px}
.btn-3{background:var(--sup);color:var(--marca);border:1px solid var(--linea-2);min-height:44px;font-size:14px}
.btn-fino{flex:0 0 auto}
.btn .spin{width:19px;height:19px;border-radius:50%;border:2.5px solid rgba(255,255,255,.35);
  border-top-color:currentColor;animation:gira .7s linear infinite}
@keyframes gira{to{transform:rotate(360deg)}}

.aviso{border-radius:11px;padding:11px 13px;font-size:13.5px;margin-bottom:13px;border:1px solid transparent}
.aviso--error{background:#fdecea;color:var(--error);border-color:#f5c6c2}
.aviso--ok{background:var(--ok-suave);color:var(--ok)}
.aviso--info{background:var(--marca-suave);color:var(--marca)}
@media (prefers-color-scheme:dark){.aviso--error{background:#3a1a18;border-color:#5c2a26}}

/* ── Paso: leer el contrato ─────────────────────────────────────────── */
.doc-barra{display:flex;align-items:center;gap:8px;margin-bottom:9px}
.doc-barra .lupa{display:flex;gap:5px;margin-left:auto}
.lupa button{width:36px;height:36px;border-radius:9px;border:1px solid var(--linea-2);background:var(--sup);
  font-weight:700;color:var(--texto-2);display:grid;place-items:center}
.leido{height:4px;border-radius:99px;background:var(--linea-2);overflow:hidden;margin-bottom:-4px;position:relative;z-index:2}
.leido i{display:block;height:100%;width:0;background:var(--marca);transition:width .12s linear}
.papel{background:var(--papel);color:var(--papel-texto);border:1px solid var(--linea);border-radius:var(--radio);
  box-shadow:var(--sombra);padding:20px 18px;max-height:56vh;overflow-y:auto;overscroll-behavior:contain;
  font-family:Georgia,'Times New Roman',serif;line-height:1.72;text-align:justify;hyphens:auto;
  font-size:15px;-webkit-overflow-scrolling:touch}
.papel[data-zoom="1"]{font-size:17px}
.papel[data-zoom="2"]{font-size:19.5px}
.papel[data-zoom="-1"]{font-size:13.5px}
.papel h1,.papel h2{font-size:1.06em;text-align:center;margin:16px 0 8px;letter-spacing:.02em}
.papel h3,.papel h4{font-size:1em;text-align:left;margin:14px 0 6px}
/* Los contratos transcritos de un PDF traen decenas de títulos seguidos, que
   en realidad son las etiquetas del formulario: apretados se leen como una
   lista y no como cincuenta encabezados sueltos. */
.papel :is(h1,h2,h3,h4)+:is(h1,h2,h3,h4){margin-top:0}
.papel p{margin-bottom:8px}
.papel ul,.papel ol{margin:8px 0 8px 20px}
.papel table{width:100%;border-collapse:collapse;margin:10px 0;font-size:.88em}
.papel th,.papel td{border:1px solid #c9ccd6;padding:5px 7px;text-align:left}
.papel hr{border:0;border-top:1px solid #d5d8e2;margin:12px 0}
.papel .dato{background:#fff6cc;border-radius:3px;padding:0 3px;font-weight:700;box-decoration-break:clone}
.papel .dato--vacio{background:transparent;border-bottom:1.5px solid #9aa0b4}
.fin-doc{text-align:center;color:var(--texto-2);font-size:12.5px;padding:14px 0 2px}
/* Hojas del PDF: se ven como el papel, porque son el papel */
.hojas{max-height:62vh;overflow:auto;overscroll-behavior:contain;border-radius:var(--radio);
  background:var(--sup-2);border:1px solid var(--linea);padding:10px;
  touch-action:pan-x pan-y pinch-zoom;-webkit-overflow-scrolling:touch}
.hojas-carril{width:calc(100% * var(--zoom,1));margin:0 auto;display:grid;gap:12px}
.hoja{margin:0;background:#fff;border-radius:6px;overflow:hidden;box-shadow:var(--sombra)}
.hoja img{width:100%;height:auto;display:block;background:#fff}
.hoja figcaption{font-size:11px;font-weight:600;color:var(--texto-2);background:var(--sup);
  text-align:center;padding:5px 0;border-top:1px solid var(--linea)}
.acepto{display:flex;gap:11px;align-items:flex-start;background:var(--sup);border:1px solid var(--linea);
  border-radius:var(--radio);padding:13px 14px;margin-top:13px;font-size:14px;cursor:pointer}
.acepto input{width:21px;height:21px;margin-top:1px;flex-shrink:0;accent-color:var(--marca)}
.acepto.bloqueada{opacity:.55}

/* ── Paso: cédula ───────────────────────────────────────────────────── */
.caras{display:grid;grid-template-columns:1fr 1fr;gap:11px;margin-bottom:14px}
@media (max-width:420px){.caras{grid-template-columns:1fr}}
.cara{background:var(--sup);border:1.5px dashed var(--linea-2);border-radius:var(--radio);padding:10px;
  text-align:center;transition:border-color .25s ease,background .25s ease}
.cara.lista{border-style:solid;border-color:var(--ok);background:var(--ok-suave)}
.cara.turno{border-color:var(--marca);background:var(--marca-suave)}
.cara-tit{font-size:12.5px;font-weight:700;margin-bottom:7px}
.cara-hueco{aspect-ratio:1.586;border-radius:9px;background:var(--sup-2);border:1px solid var(--linea);
  display:grid;place-items:center;overflow:hidden;margin-bottom:7px}
.cara-hueco img{width:100%;height:100%;object-fit:cover;animation:aparece .45s ease both}
@keyframes aparece{from{opacity:0;transform:scale(1.06)}to{opacity:1;transform:none}}
.cara-hueco svg{width:30px;height:30px;stroke:var(--linea-2);fill:none;stroke-width:1.6}
.cara-estado{font-size:11.5px;font-weight:700;color:var(--texto-2)}
.cara.lista .cara-estado{color:var(--ok)}
.cara .rehacer{font-size:12px;font-weight:700;color:var(--marca);text-decoration:underline;margin-top:5px}

/* Cámara a pantalla completa */
.camara{position:fixed;inset:0;z-index:100;background:#000;display:none;flex-direction:column}
.camara.abierta{display:flex}
.camara-video{flex:1;position:relative;overflow:hidden;display:grid;place-items:center}
.camara-video video{width:100%;height:100%;object-fit:contain;background:#000}
.camara-velo{position:absolute;inset:0;pointer-events:none}
.guia{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:90%;aspect-ratio:1.586;
  max-height:70%;border-radius:14px;box-shadow:0 0 0 100vmax rgba(0,0,0,.55);
  transition:box-shadow .4s ease,transform .4s cubic-bezier(.2,.8,.2,1)}
.guia i{position:absolute;width:26px;height:26px;border:3px solid #fff;border-radius:4px;
  transition:border-color .35s ease,width .35s ease,height .35s ease}
.guia i:nth-child(1){top:-2px;left:-2px;border-width:3px 0 0 3px;border-radius:12px 0 0 0}
.guia i:nth-child(2){top:-2px;right:-2px;border-width:3px 3px 0 0;border-radius:0 12px 0 0}
.guia i:nth-child(3){bottom:-2px;left:-2px;border-width:0 0 3px 3px;border-radius:0 0 0 12px}
.guia i:nth-child(4){bottom:-2px;right:-2px;border-width:0 3px 3px 0;border-radius:0 0 12px 0}
.barrido{position:absolute;left:8px;right:8px;top:0;height:2px;border-radius:2px;
  background:linear-gradient(90deg,transparent,rgba(255,255,255,.9),transparent);
  box-shadow:0 0 12px rgba(255,255,255,.55);animation:barre 2.6s cubic-bezier(.45,0,.55,1) infinite}
@keyframes barre{0%{top:6%;opacity:0}12%{opacity:1}88%{opacity:1}100%{top:94%;opacity:0}}
.camara.tomada .guia{transform:translate(-50%,-50%) scale(.965)}
.camara.tomada .guia i{border-color:#4ade80;width:34px;height:34px}
.camara.tomada .barrido{display:none}
.destello{position:absolute;inset:0;background:#fff;opacity:0;pointer-events:none}
.camara.tomada .destello{animation:flash .42s ease-out}
@keyframes flash{0%{opacity:.85}100%{opacity:0}}
.camara-pie{flex-shrink:0;padding:14px 18px max(18px,env(safe-area-inset-bottom));background:#000;
  display:flex;align-items:center;justify-content:space-between;gap:14px}
.camara-tit{position:absolute;top:max(14px,env(safe-area-inset-top));left:0;right:0;text-align:center;
  color:#fff;font-size:14px;font-weight:700;text-shadow:0 1px 6px rgba(0,0,0,.8);padding:0 20px}
.camara-tit small{display:block;font-weight:400;font-size:12px;opacity:.85;margin-top:2px}
.disparo{width:68px;height:68px;border-radius:50%;border:3px solid #fff;background:transparent;position:relative;flex-shrink:0}
.disparo::after{content:'';position:absolute;inset:4px;border-radius:50%;background:#fff;transition:transform .12s ease}
.disparo:active::after{transform:scale(.82)}
.camara-pie .txt{color:#fff;font-size:13.5px;font-weight:700;background:rgba(255,255,255,.16);
  border-radius:99px;padding:9px 15px;min-width:86px;text-align:center}
.analizando{position:absolute;inset:0;display:none;place-items:center;background:rgba(0,0,0,.62);z-index:5}
.camara.analiza .analizando{display:grid}
.analizando div{text-align:center;color:#fff;font-size:14px;font-weight:600}
.analizando .spin{width:34px;height:34px;border-radius:50%;border:3px solid rgba(255,255,255,.28);
  border-top-color:#fff;animation:gira .8s linear infinite;margin:0 auto 11px}

/* ── Paso: firma ────────────────────────────────────────────────────── */
.firma-caja{position:relative;background:var(--papel);border:2px dashed var(--linea-2);border-radius:var(--radio);
  touch-action:none;overflow:hidden;margin-bottom:11px}
.firma-caja canvas{display:block;width:100%;touch-action:none;cursor:crosshair}
.firma-linea{position:absolute;left:24px;right:24px;bottom:30px;border-bottom:1px solid #b9bdcc;pointer-events:none}
.firma-x{position:absolute;left:22px;bottom:33px;color:#b9bdcc;font-size:18px;pointer-events:none;font-family:Georgia,serif}
.firma-ayuda{position:absolute;left:0;right:0;bottom:8px;text-align:center;color:#9aa0b4;font-size:12.5px;
  pointer-events:none;transition:opacity .25s ease}
.firma-caja.escrita .firma-ayuda,.firma-caja.escrita .firma-x,.firma-caja.escrita .firma-linea{opacity:0}
.firma-hecha{background:var(--papel);border:1px solid var(--linea);border-radius:var(--radio);padding:16px;text-align:center}
.firma-hecha img{max-width:270px;max-height:120px;margin:0 auto}
.legal{font-size:12.5px;color:var(--texto-2);margin-bottom:14px}
.terminos{background:var(--sup);border:1px solid var(--linea);border-left:4px solid var(--marca);
  border-radius:var(--radio);padding:13px 15px;margin-bottom:12px}
.terminos-tit{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;
  color:var(--marca);margin-bottom:6px}
.terminos-txt{font-size:13.5px;line-height:1.55;color:var(--texto-2)}

/* ── Paso: listo ────────────────────────────────────────────────────── */
.listo{text-align:center;padding:12px 0 4px}
.tilde{width:82px;height:82px;margin:0 auto 16px;border-radius:50%;background:var(--ok-suave);display:grid;place-items:center;
  animation:brota .45s cubic-bezier(.2,.9,.25,1.2) both}
@keyframes brota{from{transform:scale(.6);opacity:0}to{transform:none;opacity:1}}
.tilde svg{width:42px;height:42px;stroke:var(--ok);fill:none;stroke-width:3.4;stroke-linecap:round;stroke-linejoin:round}
.tilde path{stroke-dasharray:44;stroke-dashoffset:44;animation:traza .5s .25s ease forwards}
@keyframes traza{to{stroke-dashoffset:0}}
.sigue{list-style:none;display:grid;gap:10px;text-align:left;font-size:14px;color:var(--texto-2)}
.sigue li{display:flex;gap:10px}
.sigue li::before{content:'';flex-shrink:0;width:7px;height:7px;border-radius:50%;background:var(--marca);margin-top:8px}

@media (prefers-reduced-motion:reduce){
  *,*::before,*::after{animation-duration:.01ms!important;animation-iteration-count:1!important;
    transition-duration:.01ms!important}
  .barrido{display:none}
}
</style>
</head>
<body>

<header class="cab">
  <div class="cab-fila">
    @if($logoUrl)
      <img class="cab-logo" src="{{ $logoUrl }}" alt="{{ $empresa }}">
    @else
      <span class="cab-marca">{{ $empresa }}</span>
    @endif
    <span class="cab-tit">{{ $titulo }}</span>
  </div>
  <div class="avance" id="avance" role="progressbar" aria-label="Avance de la firma" aria-valuemin="1" aria-valuemax="1" aria-valuenow="1"></div>
</header>

<main>
  <p class="aviso aviso--error oculto" id="alerta" role="alert" aria-live="assertive"></p>

  {{-- ── 1. Bienvenida ─────────────────────────────────────────────── --}}
  <section class="paso" id="p-inicio" data-paso="inicio" aria-labelledby="t-inicio">
    <p class="paso-num">Firma de contrato</p>
    <h1 id="t-inicio" tabindex="-1">Hola, {{ $cliente }}</h1>
    <p class="guia-txt">
      {{ $empresa }} le comparte el contrato <strong>{{ $titulo }}</strong> para que lo lea y lo firme
      desde este mismo teléfono. Toma unos minutos y puede volver atrás cuando quiera.
    </p>

    @if(count($resumen))
      <div class="tarjeta">
        <dl class="resumen">
          @foreach($resumen as $etiqueta => $valor)
            <dt>{{ $etiqueta }}</dt><dd>{{ $valor }}</dd>
          @endforeach
        </dl>
      </div>
    @endif

    <div class="tarjeta">
      <ol class="lista-pasos">
        <li><b>1</b><div>Lea el contrato<span>Con sus datos ya escritos</span></div></li>
        @if($pideDocs)
          <li><b>2</b><div>Fotografíe su cédula<span>Por ambas caras, con la cámara</span></div></li>
        @endif
        <li><b>{{ $pideDocs ? 3 : 2 }}</b><div>Firme con su dedo<span>Y recibe su copia al instante</span></div></li>
      </ol>
    </div>

    <div class="acciones">
      <button type="button" class="btn btn-1" data-ir="siguiente">Comenzar</button>
    </div>
  </section>

  {{-- ── 2. Leer el contrato ───────────────────────────────────────── --}}
  <section class="paso" id="p-leer" data-paso="leer" aria-labelledby="t-leer">
    <p class="paso-num">Paso <span data-numero></span></p>
    <h1 id="t-leer" tabindex="-1">Lea su contrato</h1>
    @if(count($formaHojas))
      <p class="guia-txt">Éste es su contrato tal como queda impreso, con sus datos ya escritos. Use + y − para agrandarlo, o pellizque con dos dedos. Desplácese hasta el final para continuar.</p>
    @else
      <p class="guia-txt">Sus datos ya están escritos y aparecen <mark style="background:#fff6cc;color:#1c1e2e">resaltados</mark>. Desplácese hasta el final para continuar.</p>
    @endif

    <div class="doc-barra">
      @if($pdfUrl)
        <a class="btn btn-3 btn-fino" href="{{ $pdfUrl }}" target="_blank" rel="noopener">Descargar PDF</a>
      @endif
      <div class="lupa">
        <button type="button" id="menos" aria-label="{{ count($formaHojas) ? 'Alejar' : 'Reducir el tamaño de la letra' }}">{{ count($formaHojas) ? '−' : 'A−' }}</button>
        <button type="button" id="mas" aria-label="{{ count($formaHojas) ? 'Acercar' : 'Aumentar el tamaño de la letra' }}">{{ count($formaHojas) ? '+' : 'A+' }}</button>
      </div>
    </div>

    <div class="leido" aria-hidden="true"><i id="leido"></i></div>

    @if(count($formaHojas))
      {{-- El contrato de verdad: cada hoja del PDF, con los datos ya impresos.
           El ancho y el alto van escritos para que el navegador reserve el sitio
           y la página no salte mientras las hojas terminan de bajar. --}}
      <div class="hojas" id="papel" tabindex="0" role="document" aria-label="Contrato, {{ count($formaHojas) }} {{ count($formaHojas) === 1 ? 'hoja' : 'hojas' }}">
        <div class="hojas-carril" id="carril">
          @foreach($formaHojas as $i => $proporcion)
            <figure class="hoja">
              <img src="{{ $hojaUrl }}/{{ $i + 1 }}" alt="Hoja {{ $i + 1 }} del contrato"
                   width="{{ $anchoHoja }}" height="{{ (int) round($anchoHoja * $proporcion) }}"
                   loading="{{ $i === 0 ? 'eager' : 'lazy' }}" decoding="async">
              <figcaption>Hoja {{ $i + 1 }} de {{ count($formaHojas) }}</figcaption>
            </figure>
          @endforeach
          <p class="fin-doc">— Fin del contrato —</p>
        </div>
      </div>
    @endif

    {{-- Respaldo: sin PDF base, o si las hojas no se pudieron dibujar --}}
    <article class="papel {{ count($formaHojas) ? 'oculto' : '' }}" id="papel-texto" tabindex="0" role="document" aria-label="Texto del contrato">
      {!! $contenido !!}
      <p class="fin-doc">— Fin del contrato —</p>
    </article>

    <label class="acepto bloqueada" id="acepto-caja">
      <input type="checkbox" id="acepto" disabled>
      <span id="acepto-txt">Desplácese hasta el final del contrato para continuar.</span>
    </label>

    <div class="acciones">
      <button type="button" class="btn btn-2" data-ir="anterior">Atrás</button>
      <button type="button" class="btn btn-1" id="btn-leido" data-ir="siguiente" disabled>Continuar</button>
    </div>
  </section>

  {{-- ── 3. Documento de identidad ─────────────────────────────────── --}}
  @if($pideDocs)
  <section class="paso" id="p-cedula" data-paso="cedula" aria-labelledby="t-cedula">
    <p class="paso-num">Paso <span data-numero></span></p>
    <h1 id="t-cedula" tabindex="-1">Su documento de identidad</h1>
    <p class="guia-txt">Necesitamos una foto de cada cara. Apoye la cédula sobre una superficie plana, con buena luz, y encájela en el recuadro.</p>

    <div class="caras">
      <div class="cara" id="cara-frente">
        <p class="cara-tit">Cara frontal</p>
        <div class="cara-hueco" id="hueco-frente">
          @if($frenteUrl)<img src="{{ $frenteUrl }}" alt="Foto del frente del documento">@else
          <svg viewBox="0 0 24 24"><rect x="2.5" y="5" width="19" height="14" rx="2.5"/><circle cx="8.5" cy="11" r="2.2"/><path d="M14 10h4M14 14h4M5.5 16.5c.8-1.6 4.2-1.6 5 0"/></svg>@endif
        </div>
        <p class="cara-estado" id="estado-frente">{{ $frenteUrl ? 'Lista' : 'Pendiente' }}</p>
        <button type="button" class="rehacer {{ $frenteUrl ? '' : 'oculto' }}" data-rehacer="frente">Repetir foto</button>
      </div>
      <div class="cara" id="cara-reverso">
        <p class="cara-tit">Cara trasera</p>
        <div class="cara-hueco" id="hueco-reverso">
          @if($reversoUrl)<img src="{{ $reversoUrl }}" alt="Foto del reverso del documento">@else
          <svg viewBox="0 0 24 24"><rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M6 9.5h12M6 12.5h12M6 15.5h7"/></svg>@endif
        </div>
        <p class="cara-estado" id="estado-reverso">{{ $reversoUrl ? 'Lista' : 'Pendiente' }}</p>
        <button type="button" class="rehacer {{ $reversoUrl ? '' : 'oculto' }}" data-rehacer="reverso">Repetir foto</button>
      </div>
    </div>

    <p class="aviso aviso--info oculto" id="aviso-cara" role="status" aria-live="polite"></p>

    <button type="button" class="btn btn-3" id="abrir-camara" style="width:100%;margin-bottom:6px">Tomar la foto</button>
    <p class="guia-txt" style="font-size:12.5px">¿La cámara no abre? <button type="button" id="usar-galeria" style="color:var(--marca);font-weight:700;text-decoration:underline">Elegir una foto guardada</button></p>
    <input type="file" id="galeria" accept="image/*" class="oculto" aria-hidden="true" tabindex="-1">

    <div class="acciones">
      <button type="button" class="btn btn-2" data-ir="anterior">Atrás</button>
      <button type="button" class="btn btn-1" id="btn-cedula" data-ir="siguiente" disabled>Continuar</button>
    </div>
  </section>
  @endif

  {{-- ── 4. Firma ──────────────────────────────────────────────────── --}}
  <section class="paso" id="p-firma" data-paso="firma" aria-labelledby="t-firma">
    <p class="paso-num">Paso <span data-numero></span></p>
    <h1 id="t-firma" tabindex="-1">Su firma</h1>
    <p class="guia-txt">Firme sobre la línea con el dedo. Si está en computador, use el mouse.</p>

    <div class="firma-caja" id="firma-caja">
      <canvas id="lienzo" aria-label="Área para firmar"></canvas>
      <span class="firma-linea"></span><span class="firma-x">✕</span>
      <span class="firma-ayuda">Firme aquí</span>
    </div>

    <div style="display:flex;gap:9px;margin-bottom:14px">
      <button type="button" class="btn btn-3" id="deshacer" disabled>Deshacer</button>
      <button type="button" class="btn btn-3" id="limpiar" disabled>Borrar todo</button>
    </div>

    <div class="terminos">
      <p class="terminos-tit">Términos del contrato</p>
      <p class="terminos-txt" id="terminos-txt">{{ $terminos }}</p>
    </div>

    <label class="acepto" id="acepta-caja">
      <input type="checkbox" id="acepta">
      <span>He leído y <strong>acepto los términos</strong> del contrato {{ $titulo }}.</span>
    </label>

    <p class="legal">Al firmar se guarda la constancia de su aceptación con la fecha, la hora, su dirección IP y el dispositivo desde el que firmó, junto con el contrato.</p>

    <div class="acciones">
      <button type="button" class="btn btn-2" data-ir="anterior">Atrás</button>
      <button type="button" class="btn btn-1" id="btn-firmar" disabled><span class="rotulo">Firmar contrato</span></button>
    </div>
  </section>

  {{-- ── 5. Listo ──────────────────────────────────────────────────── --}}
  <section class="paso" id="p-listo" data-paso="listo" aria-labelledby="t-listo">
    <div class="listo">
      <div class="tilde"><svg viewBox="0 0 24 24"><path d="M4.5 12.5l5 5 10-11"/></svg></div>
      <h1 id="t-listo" tabindex="-1">Contrato firmado</h1>
      <p class="guia-txt" id="firmado-el">{{ $firmadoEl ? 'Firmado el ' . $firmadoEl . '.' : '' }}</p>
    </div>

    @if($contrato->status === 'signed' && $contrato->signature)
      <div class="tarjeta" style="text-align:center">
        <p class="cara-tit" style="margin-bottom:8px">Su firma</p>
        <img src="{{ $contrato->signature }}" alt="Firma registrada" style="max-width:250px;max-height:110px;margin:0 auto">
      </div>
    @endif

    @if($aceptadoEl)
    <div class="tarjeta">
      <p class="terminos-tit">Constancia de aceptación</p>
      <p class="terminos-txt">Aceptó los términos del contrato el {{ $aceptadoEl }}. La constancia, con la hora y el dispositivo, queda impresa en el PDF firmado.</p>
    </div>
    @endif

    <div class="tarjeta">
      <h2>Qué sigue</h2>
      <ul class="sigue">
        <li>Le enviamos una copia del contrato firmado por WhatsApp.</li>
        <li>Nuestro equipo se comunica con usted para coordinar la instalación.</li>
        @if($telefono)<li>Si tiene dudas, escríbanos al {{ $telefono }}.</li>@endif
      </ul>
    </div>

    <div class="acciones">
      <a class="btn btn-1 {{ $descargaUrl ? '' : 'oculto' }}" id="descargar" href="{{ $descargaUrl ?? '#' }}" target="_blank" rel="noopener">Descargar mi copia</a>
    </div>
  </section>
</main>

{{-- ── Cámara ──────────────────────────────────────────────────────── --}}
@if($pideDocs)
<div class="camara" id="camara" role="dialog" aria-modal="true" aria-label="Cámara para fotografiar el documento">
  <div class="camara-video">
    <video id="video" autoplay playsinline muted></video>
    <div class="camara-velo">
      <p class="camara-tit" id="camara-tit">Cara frontal<small>Encájela en el recuadro</small></p>
      <div class="guia"><i></i><i></i><i></i><i></i><span class="barrido"></span></div>
      <span class="destello"></span>
    </div>
    <div class="analizando"><div><span class="spin"></span>Revisando la foto…</div></div>
  </div>
  <div class="camara-pie">
    <button type="button" class="txt" id="cerrar-camara">Cerrar</button>
    <button type="button" class="disparo" id="disparar" aria-label="Tomar la foto"></button>
    <button type="button" class="txt" id="voltear">Voltear</button>
  </div>
</div>
@endif

<script>
(function(){
'use strict';
var TOKEN = @json($token);
var API   = @json(rtrim(config('app.url'), '/'));
var PIDE_DOCS = @json($pideDocs);
var YA_FIRMADO = @json($contrato->status === 'signed');
var CLAVE = 'contrato_' + TOKEN;

var $ = function(id){ return document.getElementById(id); };
function alerta(msg, tipo){
  var el = $('alerta');
  if (!msg) { el.classList.add('oculto'); return; }
  el.textContent = msg;
  el.className = 'aviso aviso--' + (tipo || 'error');
  el.scrollIntoView({ block:'center', behavior:'smooth' });
}

/* ── Navegación entre pasos ─────────────────────────────────────── */
var pasos = [].slice.call(document.querySelectorAll('.paso')).map(function(s){ return s.id; });
var actual = 0;

// Los puntitos del avance: uno por paso, sin contar la bienvenida ni el final.
var avance = $('avance');
for (var i = 1; i < pasos.length - 1; i++) { avance.appendChild(document.createElement('i')); }
avance.setAttribute('aria-valuemax', String(pasos.length - 2));

function ir(indice){
  if (indice < 0 || indice >= pasos.length) return;
  actual = indice;
  pasos.forEach(function(id, n){ $(id).classList.toggle('activo', n === indice); });

  var puntos = avance.children;
  for (var n = 0; n < puntos.length; n++){
    puntos[n].className = (n + 1 < indice ? 'hecho' : (n + 1 === indice ? 'aqui' : ''));
  }
  avance.setAttribute('aria-valuenow', String(Math.min(Math.max(indice, 1), puntos.length)));

  var seccion = $(pasos[indice]);
  var numero = seccion.querySelector('[data-numero]');
  if (numero) numero.textContent = indice + ' de ' + (pasos.length - 2);

  window.scrollTo(0, 0);
  var titulo = seccion.querySelector('h1');
  if (titulo) titulo.focus({ preventScroll: true });
  alerta('');
  if (pasos[indice] === 'p-firma') medirLienzo();
  if (pasos[indice] === 'p-leer') revisarLectura();
}

document.addEventListener('click', function(e){
  var b = e.target.closest('[data-ir]');
  if (!b) return;
  ir(b.getAttribute('data-ir') === 'siguiente' ? actual + 1 : actual - 1);
});

/* ── Paso leer: hojas del PDF (o texto de respaldo) ─────────────── */
var visorHojas = $('papel');            // null si la plantilla no tiene PDF
var visorTexto = $('papel-texto');
var carril = $('carril');
var papel = visorHojas || visorTexto;
var acepto = $('acepto'), aceptoCaja = $('acepto-caja');

// Con hojas el zoom agranda el papel; con texto, la letra.
var zoom = visorHojas ? 1 : 0;
try {
  var guardado = parseFloat(localStorage.getItem(CLAVE + '_zoom'));
  if (!isNaN(guardado)) zoom = guardado;
} catch(e){}

function aplicarZoom(){
  if (visorHojas && papel === visorHojas) carril.style.setProperty('--zoom', String(zoom));
  else visorTexto.setAttribute('data-zoom', String(Math.round(zoom)));
  try { localStorage.setItem(CLAVE + '_zoom', String(zoom)); } catch(e){}
}
aplicarZoom();
$('mas').addEventListener('click', function(){
  zoom = visorHojas ? Math.min(3, zoom + 0.5) : Math.min(2, zoom + 1);
  aplicarZoom();
});
$('menos').addEventListener('click', function(){
  zoom = visorHojas ? Math.max(1, zoom - 0.5) : Math.max(-1, zoom - 1);
  aplicarZoom();
});

function revisarLectura(){
  // Con el paso oculto el alto es 0 y saldría "ya lo leyó todo": solo se mide
  // cuando el contrato está de verdad en pantalla.
  if (!papel.clientHeight) return;
  var alto = papel.scrollHeight - papel.clientHeight;
  var parte = alto <= 8 ? 1 : Math.min(1, papel.scrollTop / alto);
  $('leido').style.width = (parte * 100).toFixed(1) + '%';
  if (parte >= 0.97 && acepto.disabled){
    acepto.disabled = false;
    aceptoCaja.classList.remove('bloqueada');
    $('acepto-txt').textContent = 'Ya leí el contrato.';
  }
}
[visorHojas, visorTexto].forEach(function(v){
  if (v) v.addEventListener('scroll', revisarLectura, { passive:true });
});
window.addEventListener('resize', revisarLectura);
acepto.addEventListener('change', function(){ $('btn-leido').disabled = !acepto.checked; });

// Si una hoja no se pudo dibujar en el servidor, se muestra el texto en vez de
// dejar al cliente con recuadros rotos.
if (visorHojas){
  var fallaron = 0, total = carril.querySelectorAll('img').length;
  carril.querySelectorAll('img').forEach(function(img){
    img.addEventListener('error', function(){
      if (++fallaron < total) { img.parentNode.classList.add('oculto'); return; }
      visorHojas.classList.add('oculto');
      visorTexto.classList.remove('oculto');
      papel = visorTexto;
      zoom = 0; aplicarZoom();
      revisarLectura();
    });
    img.addEventListener('load', revisarLectura);
  });
}

/* ── Paso cédula ────────────────────────────────────────────────── */
var fotos = { frente:null, reverso:null };
var guardadas = { frente:@json((bool) $frenteUrl), reverso:@json((bool) $reversoUrl) };
var esperada = guardadas.frente ? 'reverso' : 'frente';
var ROTULO = { frente:'Cara frontal', reverso:'Cara trasera' };
var LADO_API = { front:'frente', back:'reverso' };

function tiene(cara){ return !!(fotos[cara] || guardadas[cara]); }

function pintarCaras(){
  if (!PIDE_DOCS) return;
  ['frente','reverso'].forEach(function(cara){
    var caja = $('cara-' + cara);
    caja.classList.toggle('lista', tiene(cara));
    caja.classList.toggle('turno', !tiene(cara) && cara === esperada);
    $('estado-' + cara).textContent = tiene(cara) ? 'Lista' : (cara === esperada ? 'Es la que sigue' : 'Pendiente');
    caja.querySelector('[data-rehacer]').classList.toggle('oculto', !tiene(cara));
  });
  if (!tiene('frente')) esperada = 'frente';
  else if (!tiene('reverso')) esperada = 'reverso';
  $('abrir-camara').textContent = (tiene('frente') && tiene('reverso'))
    ? 'Repetir una foto' : 'Tomar la foto de la ' + ROTULO[esperada].toLowerCase();
  $('btn-cedula').disabled = !(tiene('frente') && tiene('reverso'));
}

function guardarFoto(cara, base64){
  fotos[cara] = base64;
  guardadas[cara] = false;
  var hueco = $('hueco-' + cara);
  hueco.innerHTML = '';
  var img = document.createElement('img');
  img.src = base64;
  img.alt = 'Foto de la ' + ROTULO[cara].toLowerCase() + ' del documento';
  hueco.appendChild(img);
  esperada = cara === 'frente' ? 'reverso' : 'frente';
  pintarCaras();
}

if (PIDE_DOCS){
  pintarCaras();

  document.querySelectorAll('[data-rehacer]').forEach(function(b){
    b.addEventListener('click', function(){
      var cara = b.getAttribute('data-rehacer');
      fotos[cara] = null; guardadas[cara] = false; esperada = cara;
      $('hueco-' + cara).innerHTML = '';
      pintarCaras();
      abrirCamara();
    });
  });

  /* ── Cámara ─────────────────────────────────────────────────── */
  var camara = $('camara'), video = $('video');
  var flujo = null, lente = 'environment', scrollGuardado = 0;

  function abrirCamara(){
    scrollGuardado = window.scrollY || 0;
    camara.classList.add('abierta');
    camara.classList.remove('tomada','analiza');
    $('camara-tit').innerHTML = ROTULO[esperada] + '<small>Encájela en el recuadro</small>';
    document.body.style.overflow = 'hidden';
    encender();
  }
  function cerrarCamara(){
    camara.classList.remove('abierta','tomada','analiza');
    document.body.style.overflow = '';
    apagar();
    window.scrollTo(0, scrollGuardado);
  }
  function encender(){
    apagar();
    // Sin cámara disponible (navegador viejo, o la página abierta sin HTTPS) se
    // pasa directo a elegir una foto del teléfono.
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia){
      cerrarCamara(); $('galeria').click(); return;
    }
    navigator.mediaDevices.getUserMedia({
      video: { facingMode: lente, width:{ ideal:1920 }, height:{ ideal:1080 } }, audio:false
    }).then(function(s){ flujo = s; video.srcObject = s; })
      .catch(function(){ cerrarCamara(); $('galeria').click(); });
  }
  function apagar(){
    if (flujo) { flujo.getTracks().forEach(function(t){ t.stop(); }); flujo = null; }
    video.srcObject = null;
  }

  // Recorta justo el recuadro de la guía, corrigiendo el object-fit:contain.
  function recortar(){
    if (!flujo || !video.videoWidth) return null;
    var vw = video.videoWidth, vh = video.videoHeight;
    var caja = video.getBoundingClientRect();
    var escala = Math.min(caja.width / vw, caja.height / vh);
    var anchoPintado = vw * escala, altoPintado = vh * escala;
    var margenX = (caja.width - anchoPintado) / 2, margenY = (caja.height - altoPintado) / 2;

    var g = camara.querySelector('.guia').getBoundingClientRect();
    var x = Math.max(0, Math.round((g.left - caja.left - margenX) / escala));
    var y = Math.max(0, Math.round((g.top - caja.top - margenY) / escala));
    var w = Math.min(vw - x, Math.round(g.width / escala));
    var h = Math.min(vh - y, Math.round(g.height / escala));
    if (w <= 0 || h <= 0) return null;

    var lienzo = document.createElement('canvas');
    lienzo.width = w; lienzo.height = h;
    lienzo.getContext('2d').drawImage(video, x, y, w, h, 0, 0, w, h);
    return lienzo.toDataURL('image/jpeg', 0.9);
  }

  function aceptarFoto(cara, base64){
    guardarFoto(cara, base64);
    cerrarCamara();
    var aviso = $('aviso-cara');
    if (tiene('frente') && tiene('reverso')){
      aviso.textContent = 'Listo, ya tenemos las dos caras.';
      aviso.className = 'aviso aviso--ok';
    } else {
      aviso.textContent = 'Guardada la ' + ROTULO[cara].toLowerCase() + '. Ahora la ' + ROTULO[esperada].toLowerCase() + '.';
      aviso.className = 'aviso aviso--info';
    }
  }

  function preguntarCara(detectada, base64){
    var aviso = $('aviso-cara');
    aviso.className = 'aviso aviso--info';
    aviso.textContent = 'Parece que fotografió la ' + ROTULO[detectada].toLowerCase()
      + ' y esperábamos la ' + ROTULO[esperada].toLowerCase() + '. ';
    var usar = document.createElement('button');
    usar.type = 'button';
    usar.style.cssText = 'font-weight:700;text-decoration:underline;color:inherit';
    usar.textContent = 'Guardarla como ' + ROTULO[detectada].toLowerCase();
    var repetir = document.createElement('button');
    repetir.type = 'button';
    repetir.style.cssText = 'font-weight:700;text-decoration:underline;color:inherit;margin-left:12px';
    repetir.textContent = 'Repetir';
    aviso.appendChild(usar); aviso.appendChild(repetir);
    usar.addEventListener('click', function(){ aceptarFoto(detectada, base64); });
    repetir.addEventListener('click', function(){ aviso.classList.add('oculto'); abrirCamara(); });
    cerrarCamara();
  }

  function procesar(base64){
    var destino = esperada;
    camara.classList.add('tomada');
    setTimeout(function(){ camara.classList.add('analiza'); }, 380);

    fetch(API + '/api/contracts/detect-document-side', {
      method:'POST',
      headers:{ 'Content-Type':'application/json', 'Accept':'application/json' },
      body: JSON.stringify({ image: base64, token: TOKEN })
    }).then(function(r){ return r.json(); }).then(function(d){
      var lado = (d && d.status === 0 && LADO_API[d.side]) ? LADO_API[d.side] : null;
      // Solo se molesta al cliente cuando la heurística está segura de que es la
      // otra cara; si no reconoce nada, se guarda donde toca y listo.
      if (lado && lado !== destino) { preguntarCara(lado, base64); return; }
      aceptarFoto(destino, base64);
    }).catch(function(){ aceptarFoto(destino, base64); });
  }

  $('abrir-camara').addEventListener('click', abrirCamara);
  $('cerrar-camara').addEventListener('click', cerrarCamara);
  $('voltear').addEventListener('click', function(){
    lente = lente === 'environment' ? 'user' : 'environment';
    encender();
  });
  $('disparar').addEventListener('click', function(){
    var base64 = recortar();
    if (base64) procesar(base64);
  });
  $('usar-galeria').addEventListener('click', function(){ $('galeria').click(); });
  $('galeria').addEventListener('change', function(){
    var f = this.files && this.files[0];
    if (!f) return;
    var lector = new FileReader();
    lector.onload = function(){ procesar(String(lector.result)); };
    lector.readAsDataURL(f);
    this.value = '';
  });
}

/* ── Paso firma ─────────────────────────────────────────────────── */
var lienzo = $('lienzo'), cajaFirma = $('firma-caja');
var ctx = lienzo.getContext('2d');
var trazos = [], trazo = null, pintando = false;

function medirLienzo(){
  var ratio = window.devicePixelRatio || 1;
  var ancho = cajaFirma.clientWidth;
  var alto = Math.max(150, Math.round(ancho * 0.46));
  lienzo.width = Math.round(ancho * ratio);
  lienzo.height = Math.round(alto * ratio);
  lienzo.style.height = alto + 'px';
  ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
  repintar();
}
var acepta = $('acepta');
function puedeFirmar(){ return trazos.length > 0 && acepta.checked; }

function repintar(){
  ctx.clearRect(0, 0, lienzo.width, lienzo.height);
  ctx.strokeStyle = '#151728';
  ctx.lineWidth = 2.4; ctx.lineCap = 'round'; ctx.lineJoin = 'round';
  trazos.forEach(function(t){
    if (t.length < 2) return;
    ctx.beginPath(); ctx.moveTo(t[0].x, t[0].y);
    for (var i = 1; i < t.length; i++) ctx.lineTo(t[i].x, t[i].y);
    ctx.stroke();
  });
  var hay = trazos.length > 0;
  cajaFirma.classList.toggle('escrita', hay);
  $('deshacer').disabled = !hay;
  $('limpiar').disabled = !hay;
  $('btn-firmar').disabled = !puedeFirmar();
}
function punto(e){
  var r = lienzo.getBoundingClientRect();
  return { x: e.clientX - r.left, y: e.clientY - r.top };
}
lienzo.addEventListener('pointerdown', function(e){
  e.preventDefault();
  lienzo.setPointerCapture(e.pointerId);
  pintando = true; trazo = [punto(e)]; trazos.push(trazo);
});
lienzo.addEventListener('pointermove', function(e){
  if (!pintando) return;
  e.preventDefault();
  trazo.push(punto(e)); repintar();
});
['pointerup','pointercancel','pointerleave'].forEach(function(ev){
  lienzo.addEventListener(ev, function(){ if (pintando) { pintando = false; repintar(); } });
});
acepta.addEventListener('change', function(){ $('btn-firmar').disabled = !puedeFirmar(); });
$('deshacer').addEventListener('click', function(){ trazos.pop(); repintar(); });
$('limpiar').addEventListener('click', function(){ trazos = []; repintar(); });
window.addEventListener('resize', function(){ if ($('p-firma').classList.contains('activo')) medirLienzo(); });

/* ── Envío ──────────────────────────────────────────────────────── */
var botonFirmar = $('btn-firmar');
botonFirmar.addEventListener('click', function(){
  if (!trazos.length) return;
  if (!acepta.checked){
    alerta('Marque la casilla de aceptación de los términos antes de firmar.');
    return;
  }
  if (PIDE_DOCS && !(tiene('frente') && tiene('reverso'))){
    alerta('Antes de firmar necesitamos las fotos de las dos caras de su documento.');
    return;
  }

  var datos = { signature: lienzo.toDataURL('image/png'), acepta: true };
  if (fotos.frente)  datos.document_front = fotos.frente;
  if (fotos.reverso) datos.document_back  = fotos.reverso;

  botonFirmar.disabled = true;
  botonFirmar.querySelector('.rotulo').textContent = 'Firmando…';
  botonFirmar.insertAdjacentHTML('beforeend', '<span class="spin"></span>');

  fetch(API + '/api/contracts/sign-token/' + encodeURIComponent(TOKEN), {
    method:'POST',
    headers:{ 'Content-Type':'application/json', 'Accept':'application/json' },
    body: JSON.stringify(datos)
  }).then(function(r){ return r.json(); }).then(function(d){
    if (d && d.status === 0){
      if (d.firmado_el) $('firmado-el').textContent = 'Firmado el ' + d.firmado_el + '.';
      if (d.pdf_url) { $('descargar').href = d.pdf_url; $('descargar').classList.remove('oculto'); }
      ir(pasos.indexOf('p-listo'));
    } else {
      restaurarBoton();
      alerta((d && d.message) || 'No se pudo firmar. Intente de nuevo.');
    }
  }).catch(function(){
    restaurarBoton();
    alerta('Se perdió la conexión. Revise sus datos e intente de nuevo.');
  });
});
function restaurarBoton(){
  var spin = botonFirmar.querySelector('.spin');
  if (spin) spin.remove();
  botonFirmar.querySelector('.rotulo').textContent = 'Firmar contrato';
  botonFirmar.disabled = !puedeFirmar();
}

/* ── Arranque ───────────────────────────────────────────────────── */
ir(YA_FIRMADO ? pasos.indexOf('p-listo') : 0);
})();
</script>
</body>
</html>
