

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Factura</title>
    <style>
        /* Estilos CSS para tu factura */
    </style>
</head>
<body>
    <div id="header">
        {{-- Plantilla sin uso. Sin marca fija: el membrete lo pone cada empresa. --}}
        <h1>{{ $empresa ?? '' }}</h1>
        @if (!empty($logo))
        <img src="{{ $logo }}" alt="Logo" id="logo">
        @endif

    </div>
    <h2>Factura de Venta</h2>

    <!-- Resto de tu contenido Blade aquí -->

</body>
</html>
