<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Firma de Contrato</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f2f5;
            color: #1a1a2e;
            min-height: 100vh;
        }

        /* ── Header ── */
        .header {
            background: linear-gradient(135deg, #1a1a2e, #16213e);
            color: #fff;
            padding: 18px 20px;
            text-align: center;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 12px rgba(0,0,0,.3);
        }
        .header h1 { font-size: 17px; font-weight: 600; }
        .header p  { font-size: 12px; color: #aac; margin-top: 3px; }

        /* ── Contenido ── */
        .container { max-width: 720px; margin: 0 auto; padding: 16px; }

        /* Estado */
        .badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;
        }
        .badge.pending  { background: #fff3cd; color: #856404; }
        .badge.signed   { background: #d4edda; color: #155724; }
        .badge .dot     { width: 7px; height: 7px; border-radius: 50%; background: currentColor; }

        /* Card */
        .card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 2px 12px rgba(0,0,0,.07);
            margin-bottom: 16px;
            overflow: hidden;
        }
        .card-header {
            padding: 16px 20px 12px;
            border-bottom: 1px solid #f0f0f0;
            display: flex; align-items: center; justify-content: space-between;
        }
        .card-title { font-size: 15px; font-weight: 600; }
        .card-body  { padding: 20px; }

        /* ── Contrato — estilos formales INJECTED ── */
        #contract-view {
            font-family: Georgia, 'Times New Roman', Times, serif;
            font-size: 14px;
            line-height: 1.85;
            color: #222;
            max-height: 55vh;
            overflow-y: auto;
            padding-right: 4px;
            text-align: justify;
            background: #fff;
            padding: 12px 8px;
            border-radius: 6px;
        }
        #contract-view::-webkit-scrollbar { width: 4px; }
        #contract-view::-webkit-scrollbar-thumb { background: #ccc; border-radius: 4px; }

        /* Guía */
        #contract-view .contract-guide {
            font-size: 11px;
            color: #666;
            border-bottom: 1px solid #ddd;
            padding-bottom: 8px;
            margin-bottom: 15px;
            text-indent: 0;
            display: block;
        }

        /* Headings — con bordes y fondos OBVIOS */
        #contract-view h1 {
            font-family: Georgia, serif;
            font-size: 20px;
            font-weight: bold;
            text-align: center;
            color: #1a1a2e;
            margin: 24px 0 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #1a1a2e;
            padding-bottom: 6px;
        }
        #contract-view h2 {
            font-family: Georgia, serif;
            font-size: 16px;
            font-weight: bold;
            text-align: center;
            color: #1a1a2e;
            margin: 20px 0 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid #ccc;
            padding-bottom: 4px;
        }
        #contract-view h3 {
            font-family: Georgia, serif;
            font-size: 14px;
            font-weight: bold;
            text-align: left;
            color: #333;
            margin: 16px 0 8px;
            background: #f5f5f5;
            padding: 4px 8px;
            border-left: 3px solid #6c63ff;
        }
        #contract-view h4 {
            font-family: Georgia, serif;
            font-size: 13px;
            font-weight: bold;
            color: #444;
            margin: 12px 0 6px;
        }

        /* Párrafos */
        #contract-view p {
            font-size: 14px;
            line-height: 1.85;
            margin-bottom: 10px;
            text-indent: 30px;
            color: #222;
            text-align: justify;
        }
        #contract-view p:first-child { text-indent: 0; }
        #contract-view h2 + p,
        #contract-view h3 + p { text-indent: 0; }

        /* Clases especiales */
        #contract-view .list-item {
            margin-bottom: 6px;
            padding-left: 20px;
            text-indent: 0;
        }
        #contract-view .signature-line {
            margin-bottom: 6px;
            font-weight: bold;
            text-indent: 0;
            background: #f5f5f5;
            padding: 4px 8px;
            border-radius: 4px;
        }

        /* Tablas */
        #contract-view table {
            width: 100%;
            border-collapse: collapse;
            margin: 12px 0;
            font-size: 13px;
        }
        #contract-view td, #contract-view th {
            border: 1px solid #bbb;
            padding: 8px 10px;
            text-align: left;
            background: #fafafa;
        }
        #contract-view th {
            background: #f0f0f0;
            font-weight: bold;
        }

        /* Negrita */
        #contract-view strong, #contract-view b {
            color: #111;
            font-weight: bold;
        }

        /* Listas */
        #contract-view ul, #contract-view ol {
            margin: 10px 0 10px 25px;
            padding-left: 15px;
        }
        #contract-view li { margin-bottom: 5px; }

        /* HR */
        #contract-view hr {
            border: none;
            border-top: 1px solid #ccc;
            margin: 16px 0;
        }

        /* Blockquote */
        #contract-view blockquote {
            border-left: 3px solid #6c63ff;
            padding-left: 12px;
            margin: 10px 0;
            color: #555;
            font-style: italic;
        }

        /* Variables {{xxx}} */
        #contract-view span {
            color: #c0392b;
            font-weight: bold;
            background: #fdecea;
            padding: 1px 4px;
            border-radius: 3px;
        }

        /* Área de firma */
        .sign-wrapper {
            display: flex; flex-direction: column; align-items: center; gap: 12px;
        }
        .canvas-container {
            width: 100%; position: relative;
            border: 2px dashed #6c63ff;
            border-radius: 12px;
            background: #fafafa;
            touch-action: none;
            overflow: hidden;
        }
        .canvas-container canvas {
            display: block;
            width: 100%;
            cursor: crosshair;
        }
        .canvas-label {
            position: absolute; bottom: 10px; left: 50%; transform: translateX(-50%);
            font-size: 11px; color: #bbb; pointer-events: none; white-space: nowrap;
            transition: opacity .3s;
        }

        /* Botones */
        .btn-row { display: flex; gap: 10px; width: 100%; }
        .btn {
            flex: 1; padding: 14px; border: none; border-radius: 10px;
            font-size: 15px; font-weight: 600; cursor: pointer;
            transition: opacity .2s, transform .1s;
        }
        .btn:active { transform: scale(.97); opacity: .85; }
        .btn-clear   { background: #f0f0f0; color: #555; }
        .btn-sign    { background: linear-gradient(135deg, #6c63ff, #4a42d6); color: #fff; }
        .btn-sign:disabled { opacity: .5; cursor: not-allowed; }

        /* Ya firmado */
        .signed-signature {
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            padding: 12px;
            text-align: center;
        }
        .signed-signature img { max-width: 260px; max-height: 120px; }
        .signed-info { font-size: 12px; color: #888; margin-top: 8px; }

        /* Alerta */
        .alert {
            padding: 12px 16px; border-radius: 10px; font-size: 13px;
            margin-bottom: 14px; display: none;
        }
        .alert.success { background: #d4edda; color: #155724; }
        .alert.error   { background: #f8d7da; color: #721c24; }
        .alert.show    { display: block; }

        /* Spinner */
        .spinner {
            display: none; width: 20px; height: 20px; border: 3px solid #fff4;
            border-top-color: #fff; border-radius: 50; animation: spin .7s linear infinite;
            margin: auto;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        .loading .spinner { display: inline-block; }
        .loading .btn-text { display: none; }
    </style>
</head>
<body>

<div class="header">
    @if($logo)
        <div style="margin-bottom: 10px;">
            <img src="{{ $logo }}" alt="Logo" style="max-height: 60px; max-width: 180px; display: block; margin: 0 auto;">
        </div>
    @endif
    <h1>{{ $clientContract->contract->title }}</h1>
    <p>Por favor lea el contrato y firme al final</p>
</div>

<div class="container">

    <div id="alert" class="alert"></div>

    <!-- Contrato -->
    <div class="card">
        <div class="card-header">
            <span class="card-title">Contrato</span>
            <span class="badge {{ $clientContract->status }}">
                <span class="dot"></span>
                {{ $clientContract->status === 'signed' ? 'Firmado' : 'Pendiente de firma' }}
            </span>
        </div>
        <div class="card-body">
            <div id="contract-view">
                {!! preg_replace('/\s*style\s*=\s*["\'][^"\']*["\']/i', '', $clientContract->contract->content) !!}
            </div>
        </div>
    </div>

    <!-- Firma -->
    <div class="card">
        <div class="card-header">
            <span class="card-title">
                {{ $clientContract->status === 'signed' ? 'Firma registrada' : 'Su firma' }}
            </span>
        </div>
        <div class="card-body">

            @if($clientContract->status === 'signed')
                <div class="signed-signature">
                    <img src="{{ $clientContract->signature }}" alt="Firma">
                    <p class="signed-info">
                        Firmado el {{ \Carbon\Carbon::parse($clientContract->signed_at)->format('d/m/Y \a \l\a\s H:i') }}
                    </p>
                </div>
            @else
                <div class="sign-wrapper">
                    <div class="canvas-container" id="canvasContainer">
                        <canvas id="signatureCanvas"></canvas>
                        <span class="canvas-label" id="canvasLabel">Firme aquí con su dedo</span>
                    </div>
                    <div class="btn-row">
                        <button class="btn btn-clear" id="btnClear" type="button">Limpiar</button>
                        <button class="btn btn-sign" id="btnSign" type="button" disabled>
                            <span class="btn-text">Firmar contrato</span>
                            <span class="spinner"></span>
                        </button>
                    </div>
                </div>
            @endif

        </div>
    </div>

</div>

<script>
(function () {
    const token    = @json($token);
    const apiBase  = @json(config('app.url'));

    // ── Canvas setup ─────────────────────────────────────────────────────────
    const canvas    = document.getElementById('signatureCanvas');
    if (!canvas) return; // ya firmado

    const container = document.getElementById('canvasContainer');
    const ctx       = canvas.getContext('2d');
    const label     = document.getElementById('canvasLabel');
    const btnSign   = document.getElementById('btnSign');
    const btnClear  = document.getElementById('btnClear');
    let drawing     = false;
    let hasStrokes  = false;

    function resize() {
        const ratio  = window.devicePixelRatio || 1;
        const w      = container.clientWidth;
        const h      = Math.round(w * 0.45);
        canvas.width  = w * ratio;
        canvas.height = h * ratio;
        canvas.style.height = h + 'px';
        ctx.scale(ratio, ratio);
        ctx.strokeStyle = '#1a1a2e';
        ctx.lineWidth   = 2.2;
        ctx.lineCap     = 'round';
        ctx.lineJoin    = 'round';
    }

    function getPos(e) {
        const rect = canvas.getBoundingClientRect();
        const src  = e.touches ? e.touches[0] : e;
        return {
            x: (src.clientX - rect.left) * (canvas.width  / rect.width  / (window.devicePixelRatio || 1)),
            y: (src.clientY - rect.top)  * (canvas.height / rect.height / (window.devicePixelRatio || 1)),
        };
    }

    function onStart(e) {
        e.preventDefault();
        drawing = true;
        const p = getPos(e);
        ctx.beginPath();
        ctx.moveTo(p.x, p.y);
    }

    function onMove(e) {
        e.preventDefault();
        if (!drawing) return;
        const p = getPos(e);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
        if (!hasStrokes) {
            hasStrokes = true;
            label.style.opacity = '0';
            btnSign.disabled    = false;
        }
    }

    function onEnd(e) {
        e.preventDefault();
        drawing = false;
    }

    // Touch
    canvas.addEventListener('touchstart', onStart, { passive: false });
    canvas.addEventListener('touchmove',  onMove,  { passive: false });
    canvas.addEventListener('touchend',   onEnd,   { passive: false });
    // Mouse
    canvas.addEventListener('mousedown', onStart);
    canvas.addEventListener('mousemove', onMove);
    canvas.addEventListener('mouseup',   onEnd);

    // Limpiar
    btnClear.addEventListener('click', function () {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        hasStrokes         = false;
        btnSign.disabled   = true;
        label.style.opacity = '1';
    });

    // ── Enviar firma ─────────────────────────────────────────────────────────
    btnSign.addEventListener('click', async function () {
        if (!hasStrokes) return;

        const signature = canvas.toDataURL('image/png');

        btnSign.classList.add('loading');
        btnSign.disabled = true;

        try {
            const res = await fetch(apiBase + '/api/contracts/sign-token/' + token, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body:    JSON.stringify({ signature }),
            });
            const data = await res.json();

            if (data.status === 0) {
                showAlert('¡Contrato firmado exitosamente! Gracias.', 'success');
                setTimeout(() => location.reload(), 1800);
            } else {
                showAlert(data.message || 'Error al firmar.', 'error');
                btnSign.classList.remove('loading');
                btnSign.disabled = false;
            }
        } catch (err) {
            showAlert('Error de conexión. Intente nuevamente.', 'error');
            btnSign.classList.remove('loading');
            btnSign.disabled = false;
        }
    });

    function showAlert(msg, type) {
        const el = document.getElementById('alert');
        el.textContent = msg;
        el.className   = 'alert ' + type + ' show';
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    resize();
    window.addEventListener('resize', resize);
})();
</script>
</body>
</html>
