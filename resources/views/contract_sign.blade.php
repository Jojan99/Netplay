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
            overflow-x: hidden;
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

        /* ── Contrato PDF en móvil: NO iframe, solo botón ── */
        .pdf-mobile-actions {
            display: flex;
            flex-direction: column;
            gap: 10px;
            align-items: center;
            padding: 20px;
        }
        .pdf-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 24px;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
            color: #fff;
            background: linear-gradient(135deg, #6c63ff, #4a42d6);
            width: 100%;
            justify-content: center;
            max-width: 320px;
        }
        .pdf-btn.outline {
            background: #fff;
            color: #6c63ff;
            border: 2px solid #6c63ff;
        }

        /* PDF iframe solo en desktop */
        .pdf-desktop-frame {
            width:100%; height:65vh; border:1px solid #ddd; border-radius:8px; overflow:hidden; background:#525659;
        }
        @media (max-width: 768px) {
            .pdf-desktop-frame { display: none !important; }
        }
        @media (min-width: 769px) {
            .pdf-mobile-actions { display: none !important; }
        }

        /* ── Documentos ── */
        .doc-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }
        @media (max-width: 480px) {
            .doc-grid { grid-template-columns: 1fr; }
        }
        .doc-box {
            border: 1.5px dashed #c4c4e0;
            border-radius: 10px;
            padding: 12px;
            text-align: center;
            background: #fafaff;
            position: relative;
        }
        .doc-label { display: block; font-size: 12px; font-weight: 600; color: #444; margin-bottom: 8px; }
        .doc-preview {
            max-width: 100%; max-height: 180px; border-radius: 6px; margin-bottom: 8px;
            object-fit: contain; border: 1px solid #e0e0e0; display: block;
        }
        .doc-upload-btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 14px; background: #6c63ff; color: #fff;
            border-radius: 8px; font-size: 12px; font-weight: 600;
            cursor: pointer; transition: opacity .2s;
        }
        .doc-upload-btn:active { opacity: .8; }
        .doc-status {
            font-size: 11px; font-weight: 600; margin-top: 6px;
            padding: 4px 8px; border-radius: 6px; display: inline-block;
        }
        .doc-status.ok { background: #d1fae5; color: #065f46; }
        .doc-status.pending { background: #fef3c7; color: #92400e; }

        /* ── Cámara modal ── */
        .cam-modal {
            display: none;
            position: fixed; inset: 0; z-index: 200;
            background: #000;
            flex-direction: column;
        }
        .cam-modal.active { display: flex; }
        .cam-video-wrap {
            flex: 1; position: relative; overflow: hidden;
            display: flex; align-items: center; justify-content: center;
            background: #111;
        }
        .cam-video-wrap video {
            width: 100%; height: 100%; object-fit: cover;
        }
        /* Overlay de enmarcado verde */
        .cam-guide-overlay {
            position: absolute;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            width: 86%; height: 52%;
            border: 3px dashed #10b981;
            border-radius: 10px;
            pointer-events: none;
            z-index: 10;
        }
        .cam-guide-overlay::before {
            content: 'Enmarque el documento aquí';
            position: absolute;
            top: -28px; left: 50%;
            transform: translateX(-50%);
            background: #10b981; color: #fff;
            font-size: 11px; font-weight: 700;
            padding: 4px 12px; border-radius: 6px;
            white-space: nowrap;
        }
        .cam-guide-overlay .corner {
            position: absolute; width: 16px; height: 16px;
            border-color: #10b981; border-style: solid;
        }
        .cam-guide-overlay .corner.tl { top: -3px; left: -3px; border-width: 4px 0 0 4px; }
        .cam-guide-overlay .corner.tr { top: -3px; right: -3px; border-width: 4px 4px 0 0; }
        .cam-guide-overlay .corner.bl { bottom: -3px; left: -3px; border-width: 0 0 4px 4px; }
        .cam-guide-overlay .corner.br { bottom: -3px; right: -3px; border-width: 0 4px 4px 0; }

        .cam-bottom-bar {
            height: 120px; background: #000;
            display: flex; align-items: center; justify-content: center;
            gap: 30px; padding: 0 20px;
        }
        .cam-btn-capture {
            width: 66px; height: 66px; border-radius: 50%;
            border: 4px solid #fff; background: transparent;
            cursor: pointer; position: relative;
        }
        .cam-btn-capture::after {
            content: ''; position: absolute;
            top: 4px; left: 4px; right: 4px; bottom: 4px;
            border-radius: 50%; background: #fff;
            transition: transform .1s;
        }
        .cam-btn-capture:active::after { transform: scale(.85); }
        .cam-btn-close {
            color: #fff; font-size: 14px; font-weight: 600;
            background: rgba(255,255,255,.15);
            border: none; border-radius: 20px;
            padding: 8px 16px; cursor: pointer;
        }
        .cam-btn-flip {
            color: #fff; font-size: 13px; font-weight: 600;
            background: rgba(255,255,255,.15);
            border: none; border-radius: 20px;
            padding: 8px 16px; cursor: pointer;
        }

        /* ── Confirmación de cara ── */
        .confirm-modal {
            display: none;
            position: fixed; inset: 0; z-index: 210;
            background: rgba(0,0,0,.92);
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .confirm-modal.active { display: flex; }
        .confirm-img {
            max-width: 100%; max-height: 50vh;
            border-radius: 10px; border: 2px solid #fff;
            margin-bottom: 16px;
        }
        .confirm-title {
            color: #fff; font-size: 16px; font-weight: 600;
            margin-bottom: 6px; text-align: center;
        }
        .confirm-hint {
            color: #aaa; font-size: 12px; margin-bottom: 20px; text-align: center;
        }
        .confirm-btns {
            display: flex; gap: 12px; width: 100%; max-width: 340px;
        }
        .confirm-btn {
            flex: 1; padding: 14px; border: none; border-radius: 10px;
            font-size: 14px; font-weight: 700; cursor: pointer;
        }
        .confirm-btn.front { background: #10b981; color: #fff; }
        .confirm-btn.back  { background: #3b82f6; color: #fff; }
        .confirm-btn.retake { background: #ef4444; color: #fff; }

        /* ── Firma ── */
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
            display: block; width: 100%; cursor: crosshair;
        }
        .canvas-label {
            position: absolute; bottom: 10px; left: 50%; transform: translateX(-50%);
            font-size: 11px; color: #bbb; pointer-events: none; white-space: nowrap;
            transition: opacity .3s;
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
        <div class="card-body" style="padding:0;">
            @if($pdfUrl)
                <!-- Desktop: iframe -->
                <div class="pdf-desktop-frame">
                    <object data="{{ $pdfUrl }}#toolbar=1&navpanes=0&scrollbar=1&zoom=page-width"
                           type="application/pdf"
                           style="width:100%; height:100%; border:0; display:block;"
                           title="Contrato PDF">
                    </object>
                </div>
                <!-- Mobile: botones -->
                <div class="pdf-mobile-actions">
                    <p style="font-size:13px; color:#666; text-align:center; margin-bottom:4px;">
                        Este contrato contiene sus datos personales.
                    </p>
                    <a href="{{ $pdfUrl }}" target="_blank" class="pdf-btn">
                        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                        </svg>
                        Ver contrato
                    </a>
                    <a href="{{ $pdfUrl }}" download class="pdf-btn outline">
                        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        Descargar PDF
                    </a>
                </div>
            @else
                <div id="contract-view" style="padding:12px 8px;">
                    {!! preg_replace('/\s*style\s*=\s*["\'][^"\']*["\']/i', '', $clientContract->contract->content) !!}
                </div>
            @endif
        </div>
    </div>

    <!-- Documentos de identidad (pendiente) -->
    @if($clientContract->status !== 'signed')
    <div class="card" id="documentsCard" style="display: {{ $clientContract->require_documents ? 'block' : 'none' }};">
        <div class="card-header">
            <span class="card-title">Documento de identidad</span>
        </div>
        <div class="card-body">
            <p style="font-size:12px; color:#666; margin-bottom:12px;">
                Requerimos fotos claras de <strong>ambas caras</strong>. Documento registrado: <strong style="color:#1a1a2e;">{{ $clientDni ?? '---' }}</strong>
            </p>

            <div class="doc-grid">
                <!-- Cara frontal -->
                <div class="doc-box" id="boxFront">
                    <label class="doc-label">Cara frontal</label>
                    @if($documentFrontUrl)
                        <img src="{{ $documentFrontUrl }}" class="doc-preview" id="previewFront" alt="Documento frontal">
                    @else
                        <img src="" class="doc-preview" id="previewFront" alt="Documento frontal" style="display:none;">
                    @endif
                    <label class="doc-upload-btn" id="btnOpenCamFront">
                        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        <span id="frontBtnText">{{ $documentFrontUrl ? 'Cambiar foto' : 'Tomar foto frontal' }}</span>
                    </label>
                    <span class="doc-status {{ $documentFrontUrl ? 'ok' : 'pending' }}" id="frontStatus">{{ $documentFrontUrl ? 'Documento cargado ✓' : 'Pendiente' }}</span>
                </div>

                <!-- Cara trasera -->
                <div class="doc-box" id="boxBack">
                    <label class="doc-label">Cara trasera</label>
                    @if($documentBackUrl)
                        <img src="{{ $documentBackUrl }}" class="doc-preview" id="previewBack" alt="Documento trasero">
                    @else
                        <img src="" class="doc-preview" id="previewBack" alt="Documento trasero" style="display:none;">
                    @endif
                    <label class="doc-upload-btn" id="btnOpenCamBack">
                        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        <span id="backBtnText">{{ $documentBackUrl ? 'Cambiar foto' : 'Tomar foto trasera' }}</span>
                    </label>
                    <span class="doc-status {{ $documentBackUrl ? 'ok' : 'pending' }}" id="backStatus">{{ $documentBackUrl ? 'Documento cargado ✓' : 'Pendiente' }}</span>
                </div>
            </div>

            <!-- Fallback galería (oculto, para accesibilidad) -->
            <input type="file" id="inputGallery" accept="image/*" style="display:none;">

            <div class="doc-validation" id="docValidationMsg" style="display:none;"></div>
        </div>
    </div>
    @endif

    <!-- Documentos ya firmados (solo lectura) -->
    @if($clientContract->status === 'signed' && ($documentFrontUrl || $documentBackUrl))
    <div class="card">
        <div class="card-header">
            <span class="card-title">Documentos de identidad adjuntos</span>
        </div>
        <div class="card-body">
            <div class="doc-grid">
                @if($documentFrontUrl)
                <div class="doc-box">
                    <label class="doc-label">Cara frontal</label>
                    <img src="{{ $documentFrontUrl }}" class="doc-preview" alt="Documento frontal" style="display:block;">
                </div>
                @endif
                @if($documentBackUrl)
                <div class="doc-box">
                    <label class="doc-label">Cara trasera</label>
                    <img src="{{ $documentBackUrl }}" class="doc-preview" alt="Documento trasero" style="display:block;">
                </div>
                @endif
            </div>
        </div>
    </div>
    @endif

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

<!-- ═══════════════════════════════════════════ -->
<!-- MODAL: Cámara con overlay verde             -->
<!-- ═══════════════════════════════════════════ -->
<div class="cam-modal" id="camModal">
    <div class="cam-video-wrap">
        <video id="camVideo" autoplay playsinline></video>
        <div class="cam-guide-overlay" id="camGuide">
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

<!-- ═══════════════════════════════════════════ -->
<!-- MODAL: Confirmar cara frontal/trasera     -->
<!-- ═══════════════════════════════════════════ -->
<div class="confirm-modal" id="confirmModal">
    <img src="" class="confirm-img" id="confirmImg" alt="Preview">
    <p class="confirm-title">¿Qué cara del documento es?</p>
    <p class="confirm-hint">Seleccione la opción correcta para continuar</p>
    <div class="confirm-btns">
        <button class="confirm-btn front" id="btnConfirmFront">✓ Cara frontal</button>
        <button class="confirm-btn back" id="btnConfirmBack">✓ Cara trasera</button>
    </div>
    <div style="margin-top:12px;">
        <button class="confirm-btn retake" id="btnRetake">↺ Tomar otra foto</button>
    </div>
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
    let pendingConfirmSide = null; // 'front' o 'back' — lado que se está capturando
    let lastCapturedBase64 = null;

    const previewFront = document.getElementById('previewFront');
    const previewBack  = document.getElementById('previewBack');
    const frontBtnText = document.getElementById('frontBtnText');
    const backBtnText  = document.getElementById('backBtnText');
    const frontStatus = document.getElementById('frontStatus');
    const backStatus  = document.getElementById('backStatus');
    const docValidationMsg = document.getElementById('docValidationMsg');

    // ══ Cámara ══════════════════════════════════════════════════════════════
    const camModal   = document.getElementById('camModal');
    const camVideo   = document.getElementById('camVideo');
    const camCapture = document.getElementById('camCapture');
    const camClose   = document.getElementById('camClose');
    const camFlip    = document.getElementById('camFlip');
    const confirmModal = document.getElementById('confirmModal');
    const confirmImg   = document.getElementById('confirmImg');
    const btnConfirmFront = document.getElementById('btnConfirmFront');
    const btnConfirmBack  = document.getElementById('btnConfirmBack');
    const btnRetake       = document.getElementById('btnRetake');

    let camStream = null;
    let facingMode = 'environment'; // cámara trasera por defecto

    function showDocValidation(msg, type) {
        docValidationMsg.textContent = msg;
        docValidationMsg.style.display = 'block';
        docValidationMsg.className = 'doc-validation doc-' + type;
    }
    function hideDocValidation() {
        docValidationMsg.style.display = 'none';
    }

    function setDocStatus(el, type, text) {
        el.textContent = text;
        el.className = 'doc-status ' + type;
    }

    function openCamera(forSide) {
        pendingConfirmSide = forSide;
        camModal.classList.add('active');
        document.body.style.overflow = 'hidden';
        startCamera();
    }

    function closeCamera() {
        camModal.classList.remove('active');
        document.body.style.overflow = '';
        stopCamera();
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
            alert('No se pudo acceder a la cámara. Asegúrese de dar permisos o use la galería.');
            closeCamera();
            // Fallback: abrir galería
            document.getElementById('inputGallery').click();
        }
    }

    function stopCamera() {
        if (camStream) {
            camStream.getTracks().forEach(t => t.stop());
            camStream = null;
        }
        camVideo.srcObject = null;
    }

    function captureFromCamera() {
        if (!camStream) return;
        const track = camStream.getVideoTracks()[0];
        const settings = track.getSettings();
        const w = settings.width || camVideo.videoWidth || 1280;
        const h = settings.height || camVideo.videoHeight || 720;

        const canvas = document.createElement('canvas');
        canvas.width = w;
        canvas.height = h;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(camVideo, 0, 0, w, h);

        // Convertir a base64 con calidad 0.92
        lastCapturedBase64 = canvas.toDataURL('image/jpeg', 0.92);
        closeCamera();

        // Mostrar confirmación de cara
        confirmImg.src = lastCapturedBase64;
        confirmModal.classList.add('active');
    }

    function flipCamera() {
        facingMode = facingMode === 'environment' ? 'user' : 'environment';
        startCamera();
    }

    function confirmSide(side) {
        // Validar que no sea duplicado accidental
        if (side === 'front' && documentFrontValid) {
            if (!confirm('Ya tiene una foto de la cara frontal. ¿Desea reemplazarla?')) return;
        }
        if (side === 'back' && documentBackValid) {
            if (!confirm('Ya tiene una foto de la cara trasera. ¿Desea reemplazarla?')) return;
        }

        confirmModal.classList.remove('active');

        if (side === 'front') {
            documentFrontBase64 = lastCapturedBase64;
            documentFrontValid = true;
            previewFront.src = lastCapturedBase64;
            previewFront.style.display = 'block';
            frontBtnText.textContent = 'Cambiar foto';
            setDocStatus(frontStatus, 'ok', 'Documento cargado ✓');
        } else {
            documentBackBase64 = lastCapturedBase64;
            documentBackValid = true;
            previewBack.src = lastCapturedBase64;
            previewBack.style.display = 'block';
            backBtnText.textContent = 'Cambiar foto';
            setDocStatus(backStatus, 'ok', 'Documento cargado ✓');
        }

        hideDocValidation();
        updateSignButton();
    }

    function retakePhoto() {
        confirmModal.classList.remove('active');
        openCamera(pendingConfirmSide);
    }

    // Eventos cámara
    document.getElementById('btnOpenCamFront').addEventListener('click', () => openCamera('front'));
    document.getElementById('btnOpenCamBack').addEventListener('click', () => openCamera('back'));
    camCapture.addEventListener('click', captureFromCamera);
    camClose.addEventListener('click', closeCamera);
    camFlip.addEventListener('click', flipCamera);
    btnConfirmFront.addEventListener('click', () => confirmSide('front'));
    btnConfirmBack.addEventListener('click', () => confirmSide('back'));
    btnRetake.addEventListener('click', retakePhoto);

    // Fallback galería (por si la cámara no funciona)
    document.getElementById('inputGallery').addEventListener('change', function() {
        if (!this.files || !this.files[0]) return;
        const file = this.files[0];
        const reader = new FileReader();
        reader.onload = () => {
            lastCapturedBase64 = reader.result;
            confirmImg.src = lastCapturedBase64;
            pendingConfirmSide = null; // el usuario elegirá en el modal
            confirmModal.classList.add('active');
        };
        reader.readAsDataURL(file);
    });

    function canSign() {
        if (!requireDocuments) return true;
        return documentFrontValid && documentBackValid;
    }

    // ══ Canvas firma + persistencia ════════════════════════════════════════
    const canvas    = document.getElementById('signatureCanvas');
    if (!canvas) return; // ya firmado

    const container = document.getElementById('canvasContainer');
    const ctx       = canvas.getContext('2d');
    const label     = document.getElementById('canvasLabel');
    const btnSign   = document.getElementById('btnSign');
    const btnClear  = document.getElementById('btnClear');
    let drawing     = false;
    let hasStrokes  = false;

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
                hasStrokes = true;
                label.style.opacity = '0';
                updateSignButton();
            };
            img.src = saved;
        } catch(e) {}
    }

    function resize() {
        const ratio  = window.devicePixelRatio || 1;
        const w      = container.clientWidth;
        const h      = Math.round(w * 0.45);
        let saved = null;
        if (hasStrokes) {
            try { saved = canvas.toDataURL('image/png'); } catch(e) {}
        }
        canvas.width  = w * ratio;
        canvas.height = h * ratio;
        canvas.style.height = h + 'px';
        ctx.scale(ratio, ratio);
        ctx.strokeStyle = '#1a1a2e';
        ctx.lineWidth   = 2.2;
        ctx.lineCap     = 'round';
        ctx.lineJoin    = 'round';
        if (saved) {
            const img = new Image();
            img.onload = function () { ctx.drawImage(img, 0, 0, w, h); };
            img.src = saved;
        }
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
            updateSignButton();
        }
    }

    function onEnd(e) {
        e.preventDefault();
        drawing = false;
        saveSignature();
    }

    function updateSignButton() {
        btnSign.disabled = !hasStrokes || !canSign();
    }

    canvas.addEventListener('touchstart', onStart, { passive: false });
    canvas.addEventListener('touchmove',  onMove,  { passive: false });
    canvas.addEventListener('touchend',   onEnd,   { passive: false });
    canvas.addEventListener('mousedown', onStart);
    canvas.addEventListener('mousemove', onMove);
    canvas.addEventListener('mouseup',   onEnd);

    btnClear.addEventListener('click', function () {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        hasStrokes = false;
        updateSignButton();
        label.style.opacity = '1';
        saveSignature();
    });

    // ══ Enviar firma ════════════════════════════════════════════════════════
    btnSign.addEventListener('click', async function () {
        if (!hasStrokes) return;
        if (requireDocuments) {
            if (!documentFrontValid || !documentBackValid) {
                showAlert('Debe subir fotos de ambas caras del documento antes de firmar.', 'error');
                return;
            }
        }

        const signature = canvas.toDataURL('image/png');
        btnSign.classList.add('loading');
        btnSign.disabled = true;

        const payload = { signature };
        if (requireDocuments) {
            if (documentFrontBase64) payload.document_front = documentFrontBase64;
            if (documentBackBase64)  payload.document_back  = documentBackBase64;
        }

        try {
            const res = await fetch(apiBase + '/api/contracts/sign-token/' + token, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body:    JSON.stringify(payload),
            });
            const data = await res.json();

            if (data.status === 0) {
                sessionStorage.removeItem(STORAGE_KEY);
                showAlert('¡Contrato firmado exitosamente! Gracias.', 'success');
                setTimeout(() => location.reload(), 1800);
            } else {
                showAlert(data.message || 'Error al firmar.', 'error');
                btnSign.classList.remove('loading');
                updateSignButton();
            }
        } catch (err) {
            showAlert('Error de conexión. Intente nuevamente.', 'error');
            btnSign.classList.remove('loading');
            updateSignButton();
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
    setTimeout(restoreSignature, 100);
    updateSignButton();
})();
</script>
</body>
</html>
