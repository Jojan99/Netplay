<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Pago en línea</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: #f1f5f9;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            color: #0f172a;
        }
        .card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            box-shadow: 0 4px 24px rgba(15, 23, 42, .06);
            max-width: 420px;
            width: 100%;
            padding: 32px 28px;
            text-align: center;
        }
        .icon {
            width: 56px;
            height: 56px;
            margin: 0 auto 20px;
            border-radius: 50%;
            background: #e0f2fe;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .icon svg { width: 28px; height: 28px; stroke: #0284c7; }
        h1 { font-size: 18px; margin: 0 0 10px; font-weight: 700; }
        p  { font-size: 14px; line-height: 1.6; color: #475569; margin: 0; }
        .foot { margin-top: 24px; font-size: 12px; color: #94a3b8; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon" aria-hidden="true">
            <svg fill="none" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
        </div>
        <h1>Pago en línea</h1>
        <p>{{ $message }}</p>
        <p class="foot">Si necesitas ayuda, responde por WhatsApp al mismo chat donde recibiste este link.</p>
    </div>
</body>
</html>
