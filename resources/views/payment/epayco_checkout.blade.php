<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redirigiendo al pago...</title>
    <style>
        body { font-family: sans-serif; display: flex; align-items: center; justify-content: center;
               min-height: 100vh; margin: 0; background: #f5f5f5; }
        .box { text-align: center; background: white; padding: 2rem; border-radius: 8px;
               box-shadow: 0 2px 8px rgba(0,0,0,.1); }
        .spinner { width: 40px; height: 40px; border: 4px solid #e0e0e0;
                   border-top-color: #2563eb; border-radius: 50%;
                   animation: spin .8s linear infinite; margin: 1rem auto; }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="box">
        <div class="spinner"></div>
        <p>Redirigiendo a la pasarela de pago ePayco...</p>
    </div>

    <form id="epayco-form" method="POST" action="{{ $formData['checkout_url'] }}">
        @foreach($formData as $key => $value)
            @if($key !== 'checkout_url')
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endif
        @endforeach
    </form>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            setTimeout(function () {
                document.getElementById('epayco-form').submit();
            }, 800);
        });
    </script>
</body>
</html>
