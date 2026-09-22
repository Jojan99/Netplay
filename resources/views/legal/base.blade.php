{{--
    El molde de las páginas legales. Se abren desde el celular y muchas veces
    desde el revisor de Meta: todo va acá adentro, sin librerías ni CDN, y
    tiene que poder leerse e imprimirse sin javascript.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>@yield('titulo') · {{ $empresa }}</title>
<meta name="description" content="@yield('resumen')">
<style>
:root{
  --fondo:#f4f5f9; --sup:#fff; --sup-2:#f8f9fc; --texto:#16182b; --texto-2:#5b6079;
  --linea:#e3e5ef; --marca:#4f46e5; --marca-suave:#eef0ff;
  --sombra:0 1px 2px rgba(20,22,40,.06), 0 8px 24px rgba(20,22,40,.06); --radio:14px;
}
@media (prefers-color-scheme:dark){:root{
  --fondo:#0e1020; --sup:#171a2e; --sup-2:#1d2036; --texto:#eef0fa; --texto-2:#a6abc6;
  --linea:#2a2e48; --marca:#8b87ff; --marca-suave:#242648;
  --sombra:0 1px 2px rgba(0,0,0,.4), 0 8px 24px rgba(0,0,0,.35);
}}
*{box-sizing:border-box;margin:0;padding:0}
html{-webkit-text-size-adjust:100%}
body{font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;background:var(--fondo);
  color:var(--texto);font-size:16px;line-height:1.65;padding:0 16px 64px}
a{color:var(--marca)}
.hoja{max-width:760px;margin:0 auto}
header{padding:40px 0 28px;border-bottom:1px solid var(--linea);margin-bottom:32px}
.etiqueta{display:inline-block;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;
  color:var(--marca);background:var(--marca-suave);padding:5px 11px;border-radius:999px;margin-bottom:14px}
h1{font-size:clamp(26px,5vw,36px);line-height:1.2;letter-spacing:-.02em;margin-bottom:10px}
.bajada{color:var(--texto-2);font-size:15px}
h2{font-size:20px;letter-spacing:-.01em;margin:36px 0 12px;padding-top:4px}
h3{font-size:16px;margin:22px 0 8px}
p,li{color:var(--texto-2)}
p{margin-bottom:12px}
ul,ol{margin:0 0 14px 22px}
li{margin-bottom:7px}
strong{color:var(--texto);font-weight:650}
.ficha{background:var(--sup);border:1px solid var(--linea);border-radius:var(--radio);
  padding:20px 22px;box-shadow:var(--sombra);margin:20px 0}
.ficha dl{display:grid;grid-template-columns:auto 1fr;gap:8px 18px;font-size:15px}
.ficha dt{color:var(--texto-2)}
.ficha dd{color:var(--texto);font-weight:600;word-break:break-word}
table{width:100%;border-collapse:collapse;margin:16px 0;font-size:14.5px;
  background:var(--sup);border:1px solid var(--linea);border-radius:var(--radio);overflow:hidden}
th,td{text-align:left;padding:11px 14px;border-bottom:1px solid var(--linea);vertical-align:top}
th{background:var(--sup-2);color:var(--texto);font-weight:650;font-size:13.5px}
tr:last-child td{border-bottom:0}
footer{margin-top:44px;padding-top:22px;border-top:1px solid var(--linea);
  color:var(--texto-2);font-size:14px}
footer a{margin-right:16px;white-space:nowrap}
@media print{body{background:#fff;color:#000;padding:0}.ficha,table{box-shadow:none}}
</style>
</head>
<body>
<div class="hoja">
  <header>
    <span class="etiqueta">@yield('etiqueta')</span>
    <h1>@yield('titulo')</h1>
    <p class="bajada">@yield('resumen')</p>
  </header>

  @yield('cuerpo')

  <footer>
    <a href="/politica-de-privacidad">Política de privacidad</a>
    <a href="/eliminacion-de-datos">Eliminación de datos</a>
    <a href="/terminos-del-servicio">Condiciones del servicio</a>
    <p style="margin-top:12px">Última actualización: {{ $actualizado }} · {{ $empresa }}</p>
  </footer>
</div>
</body>
</html>
