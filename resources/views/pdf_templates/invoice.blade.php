

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
        <h1>NetPlay</h1>
        <?php
        $logoPath = "https://assets.goal.com/v3/assets/bltcc7a7ffd2fbf71f5/blt12dbddde5342ce4c/648866ff21a8556da61fa167/GOAL_-_Blank_WEB_-_Facebook_-_2023-06-13T135350.847.png?auto=webp&format=pjpg&width=3840&quality=60";

        $imagenBase64 = "data:image/png;base64," . base64_encode(file_get_contents($logoPath));

        ?>
        <img src="{{ $imagenBase64 }}" alt="Logo de NetPlay" id="logo">

    </div>
    <h2>Factura de Venta</h2>

    <!-- Resto de tu contenido Blade aquí -->

</body>
</html>
