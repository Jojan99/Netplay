<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Firma de Contrato</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.5.136/pdf.min.mjs" type="module"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f2f5; color: #1a1a2e;
            min-height: 100vh; overflow-x: hidden;
        }

        /* Header compacto */
        .header {
            background: linear-gradient(135deg, #1a1a2e, #16213e); color: #fff;
            padding: 10px 16px; text-align: center;
            position: sticky; top: 0; z-index: 100;
            box-shadow: 0 2px 12px rgba(0,0,0,.3);
        }
        /* Cuando la cámara está abierta, header NO debe ser sticky para evitar saltos */
        body.cam-open .header {
            position: relative;
        }
        .header h1 { font-size: 15px; font-weight: 600; }
        .header p  { font-size: 11px; color: #aac; margin-top: 2px; }

        .container { max-width: 720px; margin: 0 auto; padding: 12px 16px 40px; }

        /* Badge */
        .badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600;
        }
        .badge.pending  { background: #fff3cd; color: #856404; }
        .badge.signed   { background: #d4edda; color: #155724; }
        .badge .dot     { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }

        /* Card */
        .card {
            background: #fff; border-radius: 14px;
            box-shadow: 0 2px 12px rgba(0,0,0,.07);
            margin-bottom: 14px; overflow: hidden;
        }
        .card-header {
            padding: 12px 16px 10px; border-bottom: 1px solid #f0f0f0;
            display: flex; align-items: center; justify-content: space-between;
        }
        .card-title { font-size: 14px; font-weight: 600; }
        .card-body  { padding: 16px; }

        /* Acordeón contrato */
        .contract-toggle {
            background: none; border: none; color: #6c63ff;
            font-size: 12px; font-weight: 600; cursor: pointer;
            display: inline-flex; align-items: center; gap: 4px;
        }
        .contract-content {
            max-height: 0; overflow: hidden;
            transition: max-height .35s ease;
        }
        .contract-content.open {
            max-height: 2000px;
        }
        .contract-content iframe {
            width: 100%; height: 60vh; border: 0; border-radius: 6px;
            background: #525659;
        }
        .contract-content .html-view {
            font-family: Georgia, 'Times New Roman', Times, serif;
            font-size: 13px; line-height: 1.75; color: #222;
            text-align: justify; max-height: 55vh; overflow-y: auto;
            padding: 8px;
        }
        .pdf-actions-inline {
            display: flex; gap: 8px; flex-wrap: wrap; justify-content: center;
            padding: 12px;
        }
        .pdf-actions-inline .pdf-btn {
            padding: 10px 16px; font-size: 13px;
        }
        .pdf-btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 12px 20px; border-radius: 8px;
            font-size: 14px; font-weight: 600; text-decoration: none;
            color: #fff; background: linear-gradient(135deg, #6c63ff, #4a42d6);
            border: none; cursor: pointer;
        }
        .pdf-btn.outline {
            background: #fff; color: #6c63ff;
            border: 2px solid #6c63ff;
        }
        .pdf-btn.view-inline {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
        }

        /* PDF modal con pdf.js */
        .pdf-modal {
            display: none; position: fixed; inset: 0; z-index: 300;
            background: #1a1a2e; flex-direction: column;
        }
        .pdf-modal.active { display: flex; }
        .pdf-modal-bar {
            height: 48px; background: #1a1a2e; color: #fff;
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 14px; flex-shrink: 0;
            border-bottom: 1px solid #333;
        }
        .pdf-modal-bar h3 { font-size: 13px; font-weight: 600; }
        .pdf-modal-bar button {
            background: rgba(255,255,255,.15); color: #fff;
            border: none; border-radius: 6px; padding: 6px 12px;
            font-size: 12px; font-weight: 600; cursor: pointer;
        }
        .pdf-pages-container {
            flex: 1; overflow-y: auto; overflow-x: hidden;
            background: #2a2a3e; padding: 16px 0;
            display: flex; flex-direction: column; align-items: center; gap: 16px;
        }
        .pdf-page-wrap {
            background: #fff; border-radius: 4px;
            box-shadow: 0 4px 20px rgba(0,0,0,.4);
            max-width: 95vw;
        }
        .pdf-page-wrap canvas {
            display: block; max-width: 100%; height: auto;
        }
        .pdf-loading {
            color: #fff; text-align: center; padding: 40px;
            font-size: 14px;
        }
        .pdf-loading .spinner-pdf {
            width: 32px; height: 32px;
            border: 3px solid #fff3; border-top-color: #6c63ff;
            border-radius: 50%; animation: spin .8s linear infinite;
            margin: 0 auto 12px;
        }

        /* Documentos */
        .doc-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 12px;
        }
        @media (max-width: 480px) { .doc-grid { grid-template-columns: 1fr; } }
        .doc-box {
            border: 1.5px dashed #c4c4e0; border-radius: 10px;
            padding: 10px; text-align: center; background: #fafaff;
        }
        .doc-label { display: block; font-size: 11px; font-weight: 600; color: #444; margin-bottom: 6px; }
        .doc-preview {
            max-width: 100%; max-height: 140px; border-radius: 6px;
            margin-bottom: 6px; object-fit: contain;
            border: 1px solid #e0e0e0; display: block;
        }
        .doc-status {
            font-size: 10px; font-weight: 600;
            padding: 3px 8px; border-radius: 6px; display: inline-block;
        }
        .doc-status.ok { background: #d1fae5; color: #065f46; }
        .doc-status.pending { background: #fef3c7; color: #92400e; }

        /* Cámara */
        .cam-modal {
            display: none; position: fixed; top: 0; left: 0;
            width: 100vw; height: 100vh; height: 100dvh;
            z-index: 200; background: #000; flex-direction: column;
        }
        .cam-modal.active { display: flex; }
        .cam-video-wrap {
            flex: 1; position: relative; overflow: hidden;
            display: flex; align-items: center; justify-content: center;
            background: #111;
        }
        .cam-video-wrap video {
            width: 100%; height: 100%;
            object-fit: contain; /* MUESTRA TODA LA IMAGEN DE LA CÁMARA sin recortar */
            background: #000;
        }
        /* Overlay horizontal para cédula */
        .cam-guide-overlay {
            position: absolute;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            width: 94%; height: 36%;
            border: 3px dashed #10b981;
            border-radius: 10px;
            pointer-events: none;
            z-index: 10;
        }
        .cam-guide-overlay::before {
            content: 'Enmarque el documento aquí';
            position: absolute;
            top: -26px; left: 50%;
            transform: translateX(-50%);
            background: #10b981; color: #fff;
            font-size: 10px; font-weight: 700;
            padding: 3px 10px; border-radius: 4px;
            white-space: nowrap;
        }
        .cam-guide-overlay .corner {
            position: absolute; width: 14px; height: 14px;
            border-color: #10b981; border-style: solid;
        }
        .cam-guide-overlay .corner.tl { top: -3px; left: -3px; border-width: 3px 0 0 3px; }
        .cam-guide-overlay .corner.tr { top: -3px; right: -3px; border-width: 3px 3px 0 0; }
        .cam-guide-overlay .corner.bl { bottom: -3px; left: -3px; border-width: 0 0 3px 3px; }
        .cam-guide-overlay .corner.br { bottom: -3px; right: -3px; border-width: 0 3px 3px 0; }

        .cam-bottom-bar {
            height: 110px; background: #000;
            display: flex; align-items: center; justify-content: center;
            gap: 24px; padding: 0 16px; flex-shrink: 0;
        }
        .cam-btn-capture {
            width: 60px; height: 60px; border-radius: 50%;
            border: 3px solid #fff; background: transparent;
            cursor: pointer; position: relative;
        }
        .cam-btn-capture::after {
            content: ''; position: absolute;
            top: 3px; left: 3px; right: 3px; bottom: 3px;
            border-radius: 50%; background: #fff;
            transition: transform .1s;
        }
        .cam-btn-capture:active::after { transform: scale(.85); }
        .cam-btn-close {
            color: #fff; font-size: 13px; font-weight: 600;
            background: rgba(255,255,255,.15); border: none;
            border-radius: 20px; padding: 8px 14px; cursor: pointer;
        }
        .cam-btn-flip {
            color: #fff; font-size: 12px; font-weight: 600;
            background: rgba(255,255,255,.15); border: none;
            border-radius: 20px; padding: 8px 14px; cursor: pointer;
        }

        /* Toast de detección */
        .cam-toast {
            position: fixed; top: 50%; left: 50%;
            transform: translate(-50%, -50%) scale(.9);
            background: rgba(0,0,0,.85); color: #fff;
            padding: 20px 28px; border-radius: 14px;
            font-size: 14px; font-weight: 600; text-align: center;
            z-index: 220; opacity: 0; pointer-events: none;
            transition: all .25s ease;
            min-width: 200px;
        }
        .cam-toast.show {
            opacity: 1; transform: translate(-50%, -50%) scale(1);
        }
        .cam-toast .toast-icon {
            width: 36px; height: 36px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 8px;
        }
        .cam-toast .toast-icon.ok { background: #10b981; }
        .cam-toast .toast-icon.spin {
            border: 3px solid #fff3; border-top-color: #6c63ff;
            animation: spin .7s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Firma */
        .sign-wrapper {
            display: flex; flex-direction: column; align-items: center; gap: 12px;
        }
        .canvas-container {
            width: 100%; position: relative;
            border: 2px dashed #6c63ff; border-radius: 12px;
            background: #fafafa; touch-action: none; overflow: hidden;
        }
        .canvas-container canvas {
            display: block; width: 100%; cursor: crosshair;
        }
        .canvas-label {
            position: absolute; bottom: 10px; left: 50%;
            transform: translateX(-50%);
            font-size: 11px; color: #bbb; pointer-events: none;
            white-space: nowrap; transition: opacity .3s;
        }
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

        .signed-signature {
            border: 1px solid #e0e0e0; border-radius: 10px;
            padding: 12px; text-align: center;
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

        /* Spinner botón */
        .spinner {
            display: none; width: 20px; height: 20px;
            border: 3px solid #fff4; border-top-color: #fff;
            border-radius: 50; animation: spin .7s linear infinite;
            margin: auto;
        }
        .loading .spinner { display: inline-block; }
        .loading .btn-text { display: none; }
    </style>
</head>
<body>

<div class="header">
    @if($logo)
        <div style="margin-bottom: 4px;">
            <img src="{{ $logo }}" alt="Logo" style="max-height: 44px; max-width: 160px; display: block; margin: 0 auto;">
        </div>
    @endif
    <h1>{{ $clientContract->contract->title }}</h1>
    <p>Revise el contrato y firme al final</p>
</div>

<div class="container">

    <div id="alert" class="alert"></div>

    <!-- Contrato (acordeón) -->
    <div class="card" id="contractCard">
        <div class="card-header">
            <span class="card-title">Contrato</span>
            <span class="badge {{ $clientContract->status }}">
                <span class="dot"></span>
                {{ $clientContract->status === 'signed' ? 'Firmado' : 'Pendiente' }}
            </span>
        </div>
        <div style="padding: 10px 16px; border-bottom: 1px solid #f0f0f0;">
            <button class="contract-toggle" id="btnToggleContract" onclick="toggleContract()">
                <svg id="iconChevron" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="transition: transform .3s;">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
                <span id="toggleText">Ocultar contrato</span>
            </button>
        </div>
        <div class="contract-content open" id="contractContent">
            @if($pdfUrl)
                <div class="pdf-actions-inline">
                    <button class="pdf-btn view-inline" onclick="openPdfModal()">
                        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        Ver en pantalla completa
                    </button>
                    <a href="{{ $pdfUrl }}" download class="pdf-btn outline">
                        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        Descargar PDF
                    </a>
                </div>
            @else
                <div class="html-view" id="contract-view">
                    {!! preg_replace('/\s*style\s*=\s*["\'][^"\']*["\']/i', '', $clientContract->contract->content) !!}
                </div>
            @endif
        </div>
    </div>

    <!-- Documentos -->
    @if($clientContract->status !== 'signed')
    <div class="card" id="documentsCard" style="display: {{ $clientContract->require_documents ? 'block' : 'none' }};">
        <div class="card-header">
            <span class="card-title">Documento de identidad</span>
        </div>
        <div class="card-body">
            <p style="font-size:11px; color:#666; margin-bottom:10px;">
                Enmarque el documento en el recuadro verde. El sistema detectará automáticamente la cara.
                Documento registrado: <strong>{{ $clientDni ?? '---' }}</strong>
            </p>

            <div class="doc-grid">
                <div class="doc-box" id="boxFront">
                    <label class="doc-label">Cara frontal</label>
                    @if($documentFrontUrl)
                        <img src="{{ $documentFrontUrl }}" class="doc-preview" id="previewFront" alt="Documento frontal">
                    @else
                        <img src="" class="doc-preview" id="previewFront" alt="Documento frontal" style="display:none;">
                    @endif
                    <span class="doc-status {{ $documentFrontUrl ? 'ok' : 'pending' }}" id="frontStatus">{{ $documentFrontUrl ? 'Cargado ✓' : 'Pendiente' }}</span>
                </div>

                <div class="doc-box" id="boxBack">
                    <label class="doc-label">Cara trasera</label>
                    @if($documentBackUrl)
                        <img src="{{ $documentBackUrl }}" class="doc-preview" id="previewBack" alt="Documento trasero">
                    @else
                        <img src="" class="doc-preview" id="previewBack" alt="Documento trasero" style="display:none;">
                    @endif
                    <span class="doc-status {{ $documentBackUrl ? 'ok' : 'pending' }}" id="backStatus">{{ $documentBackUrl ? 'Cargado ✓' : 'Pendiente' }}</span>
                </div>
            </div>

            <div style="text-align:center; margin-top:12px;">
                <label class="doc-upload-btn" id="btnOpenCam">
                    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    <span id="camBtnText">{{ ($documentFrontUrl && $documentBackUrl) ? 'Cambiar documentos' : 'Capturar documento' }}</span>
                </label>
            </div>

            <input type="file" id="inputGallery" accept="image/*" style="display:none;">
            <div class="doc-validation" id="docValidationMsg" style="display:none;"></div>
        </div>
    </div>
    @endif

    <!-- Documentos ya firmados (lectura) -->
    @if($clientContract->status === 'signed' && ($documentFrontUrl || $documentBackUrl))
    <div class="card">
        <div class="card-header"><span class="card-title">Documentos adjuntos</span></div>
        <div class="card-body">
            <div class="doc-grid">
                @if($documentFrontUrl)
                <div class="doc-box">
                    <label class="doc-label">Cara frontal</label>
                    <img src="{{ $documentFrontUrl }}" class="doc-preview" style="display:block;" alt="Frontal">
                </div>
                @endif
                @if($documentBackUrl)
                <div class="doc-box">
                    <label class="doc-label">Cara trasera</label>
                    <img src="{{ $documentBackUrl }}" class="doc-preview" style="display:block;" alt="Trasera">
                </div>
                @endif
            </div>
        </div>
    </div>
    @endif

    <!-- Firma -->
    <div class="card">
        <div class="card-header">
            <span class="card-title">{{ $clientContract->status === 'signed' ? 'Firma registrada' : 'Su firma' }}</span>
        </div>
        <div class="card-body">
            @if($clientContract->status === 'signed')
                <div class="signed-signature">
                    <img src="{{ $clientContract->signature }}" alt="Firma">
                    <p class="signed-info">Firmado el {{ \Carbon\Carbon::parse($clientContract->signed_at)->format('d/m/Y \a \l\a\s H:i') }}</p>
                </div>
            @else
                <div class="sign-wrapper">
                    <div class="canvas-container" id="canvasContainer">
                        <canvas id="signatureCanvas"></canvas>
                        <span class="canvas-label" id="canvasLabel">Firme aquí con su dedo</span>
                    </div>
                    <div class="btn-row">
                        <button class="btn btn-clear" id="btnClear">Limpiar</button>
                        <button class="btn btn-sign" id="btnSign" disabled>
                            <span class="btn-text">Firmar contrato</span>
                            <span class="spinner"></span>
                        </button>
                    </div>
                </div>
            @endif
        </div>
    </div>

</div>

<!-- PDF Modal con pdf.js -->
<div class="pdf-modal" id="pdfModal">
    <div class="pdf-modal-bar">
        <h3>{{ $clientContract->contract->title }}</h3>
        <button onclick="closePdfModal()">✕ Cerrar</button>
    </div>
    <div class="pdf-pages-container" id="pdfPagesContainer">
        <div class="pdf-loading" id="pdfLoading">
            <div class="spinner-pdf"></div>
            <p>Cargando contrato…</p>
        </div>
    </div>
</div>

<!-- Cámara modal -->
<div class="cam-modal" id="camModal">
    <div class="cam-video-wrap">
        <video id="camVideo" autoplay playsinline></video>
        <div class="cam-guide-overlay" id="camOverlay">
            <div class="corner tl"></div><div class="corner tr"></div>
            <div class="corner bl"></div><div class="corner br"></div>
        </div>
    </div>
    <div class="cam-bottom-bar">
        <button class="cam-btn-close" id="camClose">✕ Cerrar</button>
        <button class="cam-btn-capture" id="camCapture"></button>
        <button class="cam-btn-flip" id="camFlip">↻ Voltear</button>
    </div>
</div>

<!-- Toast -->
<div class="cam-toast" id="camToast">
    <div class="toast-icon spin" id="toastIcon"></div>
    <div id="toastText">Analizando…</div>
</div>

<script>
(function () {
    const token    = @json($token);
    const apiBase  = @json(config('app.url'));
    const requireDocuments = @json((bool) $clientContract->require_documents);
    const clientDni = @json($clientDni ?? '');
    const hasFrontSaved = @json((bool) $documentFrontUrl);
    const hasBackSaved  = @json((bool) $documentBackUrl);
    const STORAGE_KEY = 'contract_sig_' + token;

    // ══ Estado documentos ════════════════════════════════════════════════════
    let documentFrontBase64 = null;
    let documentBackBase64  = null;
    let documentFrontValid  = hasFrontSaved;
    let documentBackValid   = hasBackSaved;

    const previewFront = document.getElementById('previewFront');
    const previewBack  = document.getElementById('previewBack');
    const frontStatus = document.getElementById('frontStatus');
    const backStatus  = document.getElementById('backStatus');
    const docValidationMsg = document.getElementById('docValidationMsg');

    // ══ Acordeón contrato ═══════════════════════════════════════════════════
    window.toggleContract = function() {
        const content = document.getElementById('contractContent');
        const text = document.getElementById('toggleText');
        const icon = document.getElementById('iconChevron');
        const isOpen = content.classList.contains('open');
        if (isOpen) {
            content.classList.remove('open');
            text.textContent = 'Mostrar contrato';
            icon.style.transform = 'rotate(0deg)';
        } else {
            content.classList.add('open');
            text.textContent = 'Ocultar contrato';
            icon.style.transform = 'rotate(180deg)';
        }
    };

    // ══ PDF modal con pdf.js renderer ══════════════════════════════════════
    const pdfUrl = @json($pdfUrl);
    let pdfDoc = null;

    window.openPdfModal = async function() {
        document.getElementById('pdfModal').classList.add('active');
        document.body.style.overflow = 'hidden';
        if (pdfUrl && !pdfDoc) {
            await renderPdfWithPdfJs();
        }
    };
    window.closePdfModal = function() {
        document.getElementById('pdfModal').classList.remove('active');
        document.body.style.overflow = '';
    };

    async function renderPdfWithPdfJs() {
        const container = document.getElementById('pdfPagesContainer');
        const loading = document.getElementById('pdfLoading');
        if (!pdfUrl) return;

        try {
            // Configurar worker de pdf.js desde CDN
            if (typeof pdfjsLib !== 'undefined') {
                pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.5.136/pdf.worker.min.mjs';
            }

            const pdf = await pdfjsLib.getDocument(pdfUrl).promise;
            pdfDoc = pdf;
            loading.style.display = 'none';

            // Escala alta para nitidez: móvil = 1.5, desktop = 2.0
            // El canvas interno es grande pero CSS lo muestra a max-width:100%
            const isMobile = window.innerWidth < 768;
            const scale = isMobile ? 1.5 : 2.0;

            for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
                const page = await pdf.getPage(pageNum);
                const viewport = page.getViewport({ scale });

                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                canvas.width = viewport.width;
                canvas.height = viewport.height;

                const wrap = document.createElement('div');
                wrap.className = 'pdf-page-wrap';
                wrap.appendChild(canvas);
                container.appendChild(wrap);

                await page.render({ canvasContext: ctx, viewport }).promise;
            }
        } catch (err) {
            console.error('PDF render error:', err);
            loading.innerHTML = '<p style="color:#ff6b6b;">Error al cargar el PDF. Intente descargarlo.</p>';
        }
    }

    // ══ Toast ══════════════════════════════════════════════════════════════
    function showToast(text, type) {
        const toast = document.getElementById('camToast');
        const icon = document.getElementById('toastIcon');
        const txt = document.getElementById('toastText');
        txt.textContent = text;
        icon.className = 'toast-icon ' + (type === 'ok' ? 'ok' : 'spin');
        if (type === 'ok') {
            icon.innerHTML = '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>';
        } else {
            icon.innerHTML = '';
        }
        toast.classList.add('show');
    }
    function hideToast() {
        document.getElementById('camToast').classList.remove('show');
    }

    // ══ Cámara + recorte overlay ═══════════════════════════════════════════
    const camModal   = document.getElementById('camModal');
    const camVideo   = document.getElementById('camVideo');
    const camCapture = document.getElementById('camCapture');
    const camClose   = document.getElementById('camClose');
    const camFlip    = document.getElementById('camFlip');
    const camOverlay = document.getElementById('camOverlay');

    let camStream = null;
    let facingMode = 'environment';

    function setDocStatus(el, type, text) {
        el.textContent = text; el.className = 'doc-status ' + type;
    }

    let savedScrollY = 0;

    function openCamera() {
        savedScrollY = window.scrollY || window.pageYOffset || 0;
        document.body.classList.add('cam-open');
        document.body.style.overflow = 'hidden';
        camModal.classList.add('active');
        startCamera();
    }
    function closeCamera() {
        camModal.classList.remove('active');
        document.body.classList.remove('cam-open');
        document.body.style.overflow = '';
        stopCamera();
        // Restaurar scroll sin salto visual
        window.scrollTo({ top: savedScrollY, behavior: 'instant' });
    }

    async function startCamera() {
        try {
            if (camStream) stopCamera();
            camStream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: facingMode, width: { ideal: 1920 }, height: { ideal: 1080 } },
                audio: false
            });
            camVideo.srcObject = camStream;
        } catch (err) {
            closeCamera();
            document.getElementById('inputGallery').click();
        }
    }
    function stopCamera() {
        if (camStream) { camStream.getTracks().forEach(t => t.stop()); camStream = null; }
        camVideo.srcObject = null;
    }

    /**
     * Recorta exactamente la región del overlay verde del video.
     * Corrige por object-fit:contain para que el recorte sea preciso.
     */
    function captureAndCrop() {
        if (!camStream || !camVideo.videoWidth) return null;

        const vidW = camVideo.videoWidth;
        const vidH = camVideo.videoHeight;
        const vidAspect = vidW / vidH;

        // Dimensiones del elemento video en pantalla
        const rectW = camVideo.clientWidth;
        const rectH = camVideo.clientHeight;
        const rectAspect = rectW / rectH;

        // Calcular área visible del video dentro del elemento (object-fit:contain)
        let renderedW, renderedH, offsetX, offsetY;
        if (rectAspect > vidAspect) {
            // Contenedor más ancho: barras negras a los lados
            renderedH = rectH;
            renderedW = rectH * vidAspect;
            offsetX = (rectW - renderedW) / 2;
            offsetY = 0;
        } else {
            // Contenedor más alto: barras negras arriba/abajo
            renderedW = rectW;
            renderedH = rectW / vidAspect;
            offsetX = 0;
            offsetY = (rectH - renderedH) / 2;
        }

        // Overlay rect relativo al elemento video
        const overlayRect = camOverlay.getBoundingClientRect();
        const videoRect = camVideo.getBoundingClientRect();

        // Overlay coords dentro del área visible del video
        const olLeft = overlayRect.left - videoRect.left - offsetX;
        const olTop  = overlayRect.top  - videoRect.top  - offsetY;
        const olW    = overlayRect.width;
        const olH    = overlayRect.height;

        // Mapear a coordenadas nativas del video
        const scaleX = vidW / renderedW;
        const scaleY = vidH / renderedH;

        const cropX = Math.max(0, Math.round(olLeft * scaleX));
        const cropY = Math.max(0, Math.round(olTop  * scaleY));
        const cropW = Math.round(Math.min(olW, renderedW - olLeft) * scaleX);
        const cropH = Math.round(Math.min(olH, renderedH - olTop)  * scaleY);

        if (cropW <= 0 || cropH <= 0) return null;

        // Dibujar frame completo
        const fullCanvas = document.createElement('canvas');
        fullCanvas.width = vidW; fullCanvas.height = vidH;
        fullCanvas.getContext('2d').drawImage(camVideo, 0, 0, vidW, vidH);

        // Recortar
        const cropCanvas = document.createElement('canvas');
        cropCanvas.width = cropW; cropCanvas.height = cropH;
        cropCanvas.getContext('2d').drawImage(fullCanvas, cropX, cropY, cropW, cropH, 0, 0, cropW, cropH);

        return cropCanvas.toDataURL('image/jpeg', 0.92);
    }

    async function onCapture() {
        const base64 = captureAndCrop();
        if (!base64) return;
        closeCamera();

        // Mostrar spinner
        showToast('Analizando documento…', 'spin');

        try {
            const res = await fetch(apiBase + '/api/contracts/detect-document-side', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ image: base64 }),
            });
            const data = await res.json();

            let side = 'front'; // default
            if (data.status === 0 && data.side && data.side !== 'unknown') {
                side = data.side;
            }

            // Si el lado detectado ya está lleno, y el otro está vacío, asignar al otro
            if (side === 'front' && documentFrontValid && !documentBackValid) {
                side = 'back';
            } else if (side === 'back' && documentBackValid && !documentFrontValid) {
                side = 'front';
            }
            // Si ambos están llenos, reemplazar el detectado directamente

            // Asignar sin preguntar
            if (side === 'front') {
                documentFrontBase64 = base64; documentFrontValid = true;
                previewFront.src = base64; previewFront.style.display = 'block';
                setDocStatus(frontStatus, 'ok', 'Cargado ✓');
            } else {
                documentBackBase64 = base64; documentBackValid = true;
                previewBack.src = base64; previewBack.style.display = 'block';
                setDocStatus(backStatus, 'ok', 'Cargado ✓');
            }

            hideToast();
            showToast((side === 'front' ? 'Cara frontal' : 'Cara trasera') + ' guardada', 'ok');
            setTimeout(hideToast, 2000);

            updateCamBtnText();
            hideDocValidation();
            updateSignButton();

        } catch (err) {
            hideToast();
            // Si falla el backend, asignar como frontal por defecto
            documentFrontBase64 = base64; documentFrontValid = true;
            previewFront.src = base64; previewFront.style.display = 'block';
            setDocStatus(frontStatus, 'ok', 'Cargado ✓');
            showToast('Documento guardado', 'ok');
            setTimeout(hideToast, 2000);
            updateCamBtnText();
            updateSignButton();
        }
    }

    function flipCamera() { facingMode = facingMode === 'environment' ? 'user' : 'environment'; startCamera(); }

    // Eventos cámara
    document.getElementById('btnOpenCam').addEventListener('click', openCamera);
    camCapture.addEventListener('click', onCapture);
    camClose.addEventListener('click', closeCamera);
    camFlip.addEventListener('click', flipCamera);

    // Fallback galería
    document.getElementById('inputGallery').addEventListener('change', function() {
        if (!this.files || !this.files[0]) return;
        const file = this.files[0];
        const reader = new FileReader();
        reader.onload = () => {
            const base64 = reader.result;
            showToast('Analizando…', 'spin');
            // Enviamos al backend para detectar cara
            fetch(apiBase + '/api/contracts/detect-document-side', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ image: base64 }),
            })
            .then(r => r.json())
            .then(data => {
                let side = (data.status === 0 && data.side) ? data.side : 'front';
                if (side === 'front' && documentFrontValid && !documentBackValid) side = 'back';
                else if (side === 'back' && documentBackValid && !documentFrontValid) side = 'front';

                if (side === 'front') {
                    documentFrontBase64 = base64; documentFrontValid = true;
                    previewFront.src = base64; previewFront.style.display = 'block';
                    setDocStatus(frontStatus, 'ok', 'Cargado ✓');
                } else {
                    documentBackBase64 = base64; documentBackValid = true;
                    previewBack.src = base64; previewBack.style.display = 'block';
                    setDocStatus(backStatus, 'ok', 'Cargado ✓');
                }
                hideToast();
                showToast((side === 'front' ? 'Frontal' : 'Trasera') + ' guardada', 'ok');
                setTimeout(hideToast, 2000);
                updateCamBtnText(); updateSignButton();
            })
            .catch(() => {
                hideToast();
                documentFrontBase64 = base64; documentFrontValid = true;
                previewFront.src = base64; previewFront.style.display = 'block';
                setDocStatus(frontStatus, 'ok', 'Cargado ✓');
                showToast('Guardado', 'ok'); setTimeout(hideToast, 2000);
                updateCamBtnText(); updateSignButton();
            });
        };
        reader.readAsDataURL(file);
    });

    function updateCamBtnText() {
        const txt = document.getElementById('camBtnText');
        if (documentFrontValid && documentBackValid) txt.textContent = 'Cambiar documentos';
        else txt.textContent = !documentFrontValid ? 'Capturar cara frontal' : 'Capturar cara trasera';
    }
    function hideDocValidation() { docValidationMsg.style.display = 'none'; }
    function canSign() { if (!requireDocuments) return true; return documentFrontValid && documentBackValid; }

    // ══ Canvas firma + persistencia ══════════════════════════════════════════
    const canvas = document.getElementById('signatureCanvas');
    if (!canvas) return;

    const container = document.getElementById('canvasContainer');
    const ctx = canvas.getContext('2d');
    const label = document.getElementById('canvasLabel');
    const btnSign = document.getElementById('btnSign');
    const btnClear = document.getElementById('btnClear');
    let drawing = false; let hasStrokes = false;

    function saveSignature() {
        if (!hasStrokes) { sessionStorage.removeItem(STORAGE_KEY); return; }
        try { sessionStorage.setItem(STORAGE_KEY, canvas.toDataURL('image/png')); } catch(e) {}
    }
    function restoreSignature() {
        try {
            const saved = sessionStorage.getItem(STORAGE_KEY);
            if (!saved) return;
            const img = new Image();
            img.onload = function () {
                ctx.drawImage(img, 0, 0, canvas.width / (window.devicePixelRatio || 1), canvas.height / (window.devicePixelRatio || 1));
                hasStrokes = true; label.style.opacity = '0'; updateSignButton();
            };
            img.src = saved;
        } catch(e) {}
    }

    function resize() {
        const ratio = window.devicePixelRatio || 1;
        const w = container.clientWidth;
        const h = Math.round(w * 0.45);
        let saved = null;
        if (hasStrokes) { try { saved = canvas.toDataURL('image/png'); } catch(e) {} }
        canvas.width = w * ratio; canvas.height = h * ratio;
        canvas.style.height = h + 'px';
        ctx.scale(ratio, ratio);
        ctx.strokeStyle = '#1a1a2e'; ctx.lineWidth = 2.2;
        ctx.lineCap = 'round'; ctx.lineJoin = 'round';
        if (saved) { const img = new Image(); img.onload = () => ctx.drawImage(img, 0, 0, w, h); img.src = saved; }
    }

    function getPos(e) {
        const rect = canvas.getBoundingClientRect();
        const src = e.touches ? e.touches[0] : e;
        return {
            x: (src.clientX - rect.left) * (canvas.width / rect.width / (window.devicePixelRatio || 1)),
            y: (src.clientY - rect.top) * (canvas.height / rect.height / (window.devicePixelRatio || 1)),
        };
    }
    function onStart(e) { e.preventDefault(); drawing = true; const p = getPos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); }
    function onMove(e) {
        e.preventDefault(); if (!drawing) return;
        const p = getPos(e); ctx.lineTo(p.x, p.y); ctx.stroke();
        if (!hasStrokes) { hasStrokes = true; label.style.opacity = '0'; updateSignButton(); }
    }
    function onEnd(e) { e.preventDefault(); drawing = false; saveSignature(); }
    function updateSignButton() { btnSign.disabled = !hasStrokes || !canSign(); }

    canvas.addEventListener('touchstart', onStart, { passive: false });
    canvas.addEventListener('touchmove', onMove, { passive: false });
    canvas.addEventListener('touchend', onEnd, { passive: false });
    canvas.addEventListener('mousedown', onStart);
    canvas.addEventListener('mousemove', onMove);
    canvas.addEventListener('mouseup', onEnd);

    btnClear.addEventListener('click', function () {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        hasStrokes = false; updateSignButton(); label.style.opacity = '1'; saveSignature();
    });

    // Enviar firma
    btnSign.addEventListener('click', async function () {
        if (!hasStrokes) return;
        if (requireDocuments) {
            if (!documentFrontValid || !documentBackValid) {
                showAlert('Debe subir ambas caras del documento antes de firmar.', 'error'); return;
            }
        }
        const signature = canvas.toDataURL('image/png');
        btnSign.classList.add('loading'); btnSign.disabled = true;
        const payload = { signature };
        if (requireDocuments) {
            if (documentFrontBase64) payload.document_front = documentFrontBase64;
            if (documentBackBase64) payload.document_back = documentBackBase64;
        }
        try {
            const res = await fetch(apiBase + '/api/contracts/sign-token/' + token, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(payload),
            });
            const data = await res.json();
            if (data.status === 0) {
                sessionStorage.removeItem(STORAGE_KEY);
                showAlert('¡Contrato firmado exitosamente! Gracias.', 'success');
                setTimeout(() => location.reload(), 1800);
            } else {
                showAlert(data.message || 'Error al firmar.', 'error');
                btnSign.classList.remove('loading'); updateSignButton();
            }
        } catch (err) {
            showAlert('Error de conexión. Intente nuevamente.', 'error');
            btnSign.classList.remove('loading'); updateSignButton();
        }
    });

    function showAlert(msg, type) {
        const el = document.getElementById('alert');
        el.textContent = msg; el.className = 'alert ' + type + ' show';
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    resize(); window.addEventListener('resize', resize);
    setTimeout(restoreSignature, 100);
    updateSignButton();
    updateCamBtnText();
})();
</script>
</body>
</html>
