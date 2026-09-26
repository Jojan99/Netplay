<?php

namespace App\Http\Controllers;

use App\Models\ClientContract;
use App\Repositories\Interfaces\ContractRepositoryInterface;
use App\Support\ArchivosContrato;
use App\Services\RasterizadorPdf;
use App\Support\VariablesContrato;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ContractSignController extends Controller
{
    /** Tope de cada foto de cédula que llega en base64 (8 MB de binario). */
    private const MAX_FOTO = 8 * 1024 * 1024;

    /** Aceptación que se muestra cuando la empresa no escribió la suya. */
    public const TERMINOS_POR_DEFECTO = 'Declaro que leí el contrato completo, que los datos que aparecen en él son correctos y que acepto sus términos y condiciones. Entiendo que esta firma electrónica tiene la misma validez que una firma de puño y letra.';

    public function __construct(
        private ContractRepositoryInterface $contractRepository
    ) {}

    /**
     * Página de firma del cliente: /contrato/firmar/{token}
     *
     * Es pública (el cliente entra desde el link de WhatsApp o del correo), así
     * que aquí se arma todo ya resuelto y escapado: la vista no toca la base.
     */
    public function show(string $token)
    {
        try {
            $cc = $this->contractRepository->getByToken($token);
        } catch (ModelNotFoundException) {
            abort(404, 'Contrato no encontrado.');
        }

        $this->registrarApertura($cc);

        $valores = $this->valoresDe($cc);

        // Contrato en HTML, saneado y con los datos del cliente ya escapados.
        $contenido = VariablesContrato::pintar($cc->contract->content ?? '', $valores);

        // Documento del contrato en PDF, si la plantilla usa uno como base.
        $pdfUrl = null;
        $tienePdfBase = (bool) ArchivosContrato::plantilla($cc->contract->pdf_path ?? null);
        if ($tienePdfBase) {
            $firmado = $cc->status === 'signed' && ArchivosContrato::firmado((int) $cc->id);
            $pdfUrl = ArchivosContrato::urlPorToken($token, $firmado ? 'firmado' : 'preview');
        }

        // Con PDF base el cliente lee SU documento dibujado, no la transcripción.
        $formaHojas = $tienePdfBase ? $this->formaDeLasHojas($cc) : [];

        $descargaUrl = ($cc->status === 'signed' && ArchivosContrato::firmado((int) $cc->id))
            ? ArchivosContrato::urlPorToken($token, 'firmado')
            : null;

        $empresa = DB::table('companies')
            ->where('id', $cc->company_id)
            ->first(['id', 'name', 'phone', 'email']);

        $datos = [
            'token'        => $token,
            'contrato'     => $cc,
            'titulo'       => (string) ($cc->contract->title ?? 'Contrato'),
            'empresa'      => $empresa->name ?? 'Su proveedor de internet',
            'telefono'     => $empresa->phone ?? '',
            'logoUrl'      => $this->logo($cc) ? ArchivosContrato::urlPorToken($token, 'logo') : null,
            'contenido'    => $contenido,
            'formaHojas'   => $formaHojas,
            'anchoHoja'    => RasterizadorPdf::ANCHO_FIRMA,
            // url() se come la barra final: la pone la vista al armar cada hoja.
            'hojaUrl'      => url('/api/contracts/hoja-token/' . rawurlencode($token)),
            'pdfUrl'       => $pdfUrl,
            'descargaUrl'  => $descargaUrl,
            'pideDocs'     => (bool) $cc->require_documents,
            'frenteUrl'    => ArchivosContrato::archivo($cc, 'frente') ? ArchivosContrato::urlPorToken($token, 'frente') : null,
            'reversoUrl'   => ArchivosContrato::archivo($cc, 'reverso') ? ArchivosContrato::urlPorToken($token, 'reverso') : null,
            'resumen'      => $this->resumen($valores),
            'cliente'      => trim((string) ($valores['{{nombre_completo}}'] ?? '')) ?: ($cc->user->username ?? 'Cliente'),
            'firmadoEl'    => $cc->signed_at ? $cc->signed_at->format('d/m/Y \a \l\a\s H:i') : null,
            'terminos'     => self::terminos($cc->contract),
            'aceptadoEl'   => isset($cc->accepted_at) && $cc->accepted_at ? $cc->accepted_at->format('d/m/Y \a \l\a\s H:i') : null,
        ];

        return view('contract_sign', $datos);
    }

    /**
     * Firma desde la página del cliente (sin JWT: autentica el token del link).
     * POST /api/contracts/sign-token/{token}
     */
    public function sign(string $token, Request $request)
    {
        try {
            $cc = $this->contractRepository->getByToken($token);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Contrato no encontrado', 'status' => 1], 404);
        }

        if ($cc->status === 'signed') {
            return response()->json(['message' => 'El contrato ya fue firmado', 'status' => 1]);
        }

        if (!$request->boolean('acepta')) {
            return response()->json(['message' => 'Debe aceptar los términos del contrato para poder firmar.', 'status' => 1]);
        }

        if (!$request->filled('signature') || !$this->esImagenValida((string) $request->input('signature'))) {
            return response()->json(['message' => 'La firma es requerida.', 'status' => 1]);
        }

        // Constancia de aceptación: lo que le sirve a la empresa si después el
        // cliente dice que nunca aceptó. Se guarda el TEXTO completo, no sólo la
        // marca, porque lo que vale es qué fue lo que aceptó.
        $constancia = [
            'momento'    => now(),
            'ip'         => (string) $request->ip(),
            'dispositivo'=> substr((string) $request->userAgent(), 0, 255),
            'texto'      => self::terminos($cc->contract),
        ];
        $this->guardarConstancia($cc, $constancia);

        // ── Documentos de identidad, si la empresa los pide ──────────────────
        if ($cc->require_documents) {
            $tieneFrente = (bool) ArchivosContrato::absoluta($cc->document_front_path);
            $tieneReverso = (bool) ArchivosContrato::absoluta($cc->document_back_path);

            if (!$tieneFrente || !$tieneReverso) {
                $frente  = (string) $request->input('document_front', '');
                $reverso = (string) $request->input('document_back', '');

                if ($frente === '' || $reverso === '') {
                    return response()->json(['message' => 'Se requieren fotos de ambas caras del documento de identidad.', 'status' => 1]);
                }

                if (!$this->esImagenValida($frente) || !$this->esImagenValida($reverso)) {
                    return response()->json(['message' => 'Las fotos del documento deben ser imágenes JPG o PNG de menos de 8 MB.', 'status' => 1]);
                }

                $cc->update([
                    'document_front_path'   => $this->guardarFoto($cc, 'front', $frente),
                    'document_back_path'    => $this->guardarFoto($cc, 'back', $reverso),
                    // El número no se lee de la foto: se deja el que tiene el
                    // cliente en su ficha, que es contra el que se compara.
                    'document_number_front' => (string) DB::table('user_data')->where('user_id', $cc->user_id)->value('dni'),
                    'document_number_back'  => (string) DB::table('user_data')->where('user_id', $cc->user_id)->value('dni'),
                ]);
                $cc->refresh();
            }
        }

        $this->contractRepository->sign($cc->id, $request->input('signature'));
        $cc->refresh();

        $descarga = $this->generarFirmadoYAvisar($cc, (string) $request->input('signature'), $token, $constancia);

        return response()->json([
            'message'  => 'Contrato firmado exitosamente',
            'status'   => 0,
            'pdf_url'  => $descarga,
            'firmado_el' => $cc->signed_at?->format('d/m/Y \a \l\a\s H:i'),
        ]);
    }

    /**
     * GET /api/contracts/hoja-token/{token}/{pagina}
     * Una hoja del contrato del cliente, dibujada del PDF real con sus datos ya
     * estampados. Se dibuja la primera vez que alguien la pide y queda guardada
     * en privado; se redibuja sola si cambia la plantilla, las posiciones o los
     * datos del cliente.
     */
    public function hojaPorToken(string $token, int $pagina)
    {
        try {
            $cc = $this->contractRepository->getByToken($token);
        } catch (ModelNotFoundException) {
            abort(404);
        }

        $hojas = $this->hojasDelContrato($cc);
        if (!isset($hojas[$pagina])) {
            abort(404);
        }

        return response()->file($hojas[$pagina], [
            'Content-Type'  => 'image/png',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * GET /api/contracts/archivo/{clientContract}/{tipo}  (firma temporal)
     * PDF firmado o fotos de la cédula. La URL la genera el panel (JWT y empresa
     * ya validados) o el envío por WhatsApp; sin firma válida no abre.
     */
    public function archivo(int $clientContract, string $tipo)
    {
        $cc = ClientContract::find($clientContract);

        return $this->entregar($cc ? ArchivosContrato::archivo($cc, $tipo) : null, $tipo, $clientContract);
    }

    /**
     * GET /api/contracts/archivo-token/{token}/{tipo}
     * Lo mismo para la página de firma, protegido por el token del contrato.
     * 'preview' arma el PDF rellenado al vuelo, sin dejarlo en disco.
     */
    public function archivoPorToken(string $token, string $tipo)
    {
        try {
            $cc = $this->contractRepository->getByToken($token);
        } catch (ModelNotFoundException) {
            abort(404);
        }

        if ($tipo === 'logo') {
            return $this->entregarLogo($cc);
        }

        if ($tipo !== 'preview') {
            return $this->entregar(ArchivosContrato::archivo($cc, $tipo), $tipo, (int) $cc->id);
        }

        $base = ArchivosContrato::plantilla($cc->contract->pdf_path ?? null);
        if (!$base) {
            abort(404);
        }

        try {
            $campos = \App\Models\ContractPdfField::where('contract_id', $cc->contract_id)
                ->orderBy('page')->orderBy('id')
                ->get();

            $output = (new \App\Services\ContractPdfService())
                ->fillPdfBase($cc->contract->pdf_path, $this->valoresDe($cc), $campos->toArray());
        } catch (\Throwable $e) {
            Log::error('Error generando PDF rellenado para firma: ' . $e->getMessage());
            // La plantilla sin los datos del cliente antes que nada.
            return $this->entregar($base, 'preview', (int) $cc->id);
        }

        return response($output, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="contrato-' . $cc->id . '.pdf"',
            'Cache-Control'       => 'private, no-store',
        ]);
    }

    /**
     * POST /api/contracts/detect-document-side
     * Dice si la foto parece la cara frontal o la trasera de la cédula. No es
     * OCR: es una heurística de color (DocumentSideDetector) que mira si hay
     * tonos de piel arriba a la izquierda, o sea la foto del titular. Se usa
     * para avisarle al cliente que fotografió la cara que no era.
     */
    public function detectDocumentSide(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $imagen = (string) $request->input('image', '');
            if ($imagen === '') {
                return response()->json(['status' => 1, 'message' => 'Imagen requerida.']);
            }

            // Si viene el token del contrato se valida; la página de firma siempre
            // lo manda. Sin él la ruta sigue abierta (hay enlaces ya enviados),
            // pero con el límite de peticiones de la ruta.
            $token = (string) $request->input('token', '');
            if ($token !== '') {
                try {
                    $this->contractRepository->getByToken($token);
                } catch (ModelNotFoundException) {
                    return response()->json(['status' => 1, 'message' => 'Contrato no encontrado.'], 404);
                }
            }

            if (!$this->esImagenValida($imagen)) {
                return response()->json(['status' => 1, 'message' => 'Imagen inválida.']);
            }

            // Sin agregarle extensión: tempnam() ya creó el archivo y el que
            // llevaba el sufijo dejaba el original de 0 bytes tirado en /tmp.
            $tmp = tempnam(sys_get_temp_dir(), 'doc_detect_');
            file_put_contents($tmp, $this->binarioDeBase64($imagen));
            $cara = \App\Services\DocumentSideDetector::detect($tmp);
            @unlink($tmp);

            return response()->json([
                'status'     => 0,
                'side'       => $cara, // 'front', 'back', 'unknown'
                'confidence' => $cara === 'unknown' ? 'low' : 'medium',
            ]);
        } catch (\Throwable $e) {
            Log::error('[DetectDocSide] Error: ' . $e->getMessage());
            return response()->json(['status' => 1, 'message' => 'Error al analizar imagen.']);
        }
    }

    // ── Interno ───────────────────────────────────────────────────────────────

    /**
     * Hojas dibujadas del contrato de un cliente, con sus datos ya estampados.
     * Ya firmado se dibuja el PDF firmado (el definitivo); antes, el relleno.
     *
     * @return array<int, string> número de hoja => ruta absoluta del PNG
     */
    private function hojasDelContrato(ClientContract $cc): array
    {
        $base = ArchivosContrato::plantilla($cc->contract->pdf_path ?? null);
        if (!$base || !RasterizadorPdf::disponible()) {
            return [];
        }

        $firmado = $cc->status === 'signed' ? ArchivosContrato::firmado((int) $cc->id) : null;
        $destino = ArchivosContrato::dirHojas((int) $cc->company_id, (int) $cc->id);

        $campos = \App\Models\ContractPdfField::where('contract_id', $cc->contract_id)
            ->orderBy('page')->orderBy('id')->get()->toArray();

        // La firma del caché junta todo lo que cambia el dibujo: el PDF base, la
        // plantilla, las posiciones, los datos del cliente y si ya está firmado.
        $firmaCache = sha1(implode('|', [
            RasterizadorPdf::firmaDeArchivo($base),
            RasterizadorPdf::firmaDeArchivo($firmado),
            (string) ($cc->contract->updated_at ?? ''),
            md5(json_encode($campos)),
            md5(json_encode($this->valoresDe($cc))),
            RasterizadorPdf::ANCHO_FIRMA,
        ]));

        if ($hojas = RasterizadorPdf::hojasEnCache($destino, $firmaCache)) {
            return $hojas;
        }

        // Cache viejo: hay que armar el PDF con los datos y dibujarlo.
        $pdf = $firmado;
        $temporal = null;

        if (!$pdf) {
            try {
                $contenido = (new \App\Services\ContractPdfService())
                    ->fillPdfBase($cc->contract->pdf_path, $this->valoresDe($cc), $campos);
            } catch (\Throwable $e) {
                Log::error('[ContractSign] No se pudo armar el PDF para dibujar las hojas: ' . $e->getMessage());
                return [];
            }

            $temporal = tempnam(sys_get_temp_dir(), 'contrato_');
            file_put_contents($temporal, $contenido);
            $pdf = $temporal;
        }

        try {
            return RasterizadorPdf::hojas($pdf, $destino, $firmaCache, RasterizadorPdf::ANCHO_FIRMA);
        } finally {
            if ($temporal) {
                @unlink($temporal);
            }
        }
    }

    /**
     * Cuántas hojas mostrar y qué forma tiene cada una, sin dibujarlas todavía:
     * FPDI lo lee al instante y el navegador va pidiendo las imágenes por
     * separado. La proporción sirve para reservar el alto de cada <img> y que la
     * página no salte mientras cargan.
     *
     * @return array<int, float> alto/ancho de cada hoja
     */
    private function formaDeLasHojas(ClientContract $cc): array
    {
        if (!RasterizadorPdf::disponible()) {
            return [];
        }

        $firmado = $cc->status === 'signed' ? ArchivosContrato::firmado((int) $cc->id) : null;
        $pdf = $firmado ?: ArchivosContrato::plantilla($cc->contract->pdf_path ?? null);
        if (!$pdf) {
            return [];
        }

        try {
            $doc = new \setasign\Fpdi\Fpdi();
            $total = $doc->setSourceFile($pdf);

            $formas = [];
            for ($i = 1; $i <= $total; $i++) {
                $medida = $doc->getTemplateSize($doc->importPage($i));
                $formas[] = $medida['width'] > 0 ? round($medida['height'] / $medida['width'], 4) : 1.414;
            }

            return $formas;
        } catch (\Throwable $e) {
            Log::warning('[ContractSign] No se pudo leer la forma de las hojas: ' . $e->getMessage());
            return [];
        }
    }

    /** Variables del contrato con los datos reales del cliente. */
    private function valoresDe(ClientContract $cc): array
    {
        return (new \App\UseCases\Contract\ClientContractUseCase($this->contractRepository))->buildFieldValues(
            $cc->user_id,
            $cc->id,
            $cc->contract->installation_value ?? null,
            isset($cc->contract->plazo) ? (int) $cc->contract->plazo : null
        );
    }

    /** Las cuatro cosas que el cliente quiere confirmar antes de leer nada. */
    private function resumen(array $valores): array
    {
        $filas = [
            'Plan'                => $valores['{{plan_nombre}}'] ?? '',
            'Velocidad'           => $valores['{{plan_velocidad}}'] ?? '',
            'Mensualidad'         => $valores['{{plan_precio}}'] ?? '',
            'Instalación'         => $valores['{{valor_instalacion}}'] ?? ($valores['{{plan_instalacion}}'] ?? ''),
            'Dirección'           => $valores['{{direccion}}'] ?? '',
            'Documento'           => trim(($valores['{{tipo_documento}}'] ?? '') . ' ' . ($valores['{{dni}}'] ?? '')),
        ];

        return array_filter($filas, fn ($v) => trim((string) $v) !== '');
    }

    /** Logo: el de la plantilla si tiene, si no el de la empresa. */
    private function logo(ClientContract $cc): string
    {
        $propio = (string) ($cc->contract->logo ?? '');
        if (str_starts_with($propio, 'data:image')) {
            return $propio;
        }

        $empresa = DB::table('companies')->where('id', $cc->company_id)->first(['logo', 'invoice_logo_base64']);
        foreach ([$empresa->logo ?? '', $empresa->invoice_logo_base64 ?? ''] as $candidato) {
            if (str_starts_with((string) $candidato, 'data:image')) {
                return (string) $candidato;
            }
        }

        return '';
    }

    /**
     * El logo se entrega como archivo y no incrustado en el HTML: el de Netplay
     * pesa 320 KB en base64 y la página se abre desde el celular con datos.
     * Además se reduce a 360 px de ancho y se deja guardado, porque en la
     * cabecera se ve a 30 px de alto.
     */
    private function entregarLogo(ClientContract $cc)
    {
        $cache = storage_path('app/' . ArchivosContrato::dirPlantillas((int) $cc->company_id) . '/logo-cabecera.png');

        if (!is_file($cache)) {
            $logo = $this->logo($cc);
            if ($logo === '' || !preg_match('#^data:image/[a-z+]+;base64,#i', $logo)) {
                abort(404);
            }
            if (!$this->reducirLogo($this->binarioDeBase64($logo), $cache)) {
                abort(404);
            }
        }

        return response()->file($cache, [
            'Content-Type'  => 'image/png',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /** Reduce el logo a 360 px de ancho conservando la transparencia. */
    private function reducirLogo(string $binario, string $destino): bool
    {
        $origen = @imagecreatefromstring($binario);
        if (!$origen) {
            return false;
        }

        $ancho = imagesx($origen);
        $alto = imagesy($origen);
        if ($ancho > 360) {
            $alto = max(1, (int) round($alto * 360 / $ancho));
            $ancho = 360;
        }

        $chico = imagecreatetruecolor($ancho, $alto);
        imagealphablending($chico, false);
        imagesavealpha($chico, true);
        imagecopyresampled($chico, $origen, 0, 0, 0, 0, $ancho, $alto, imagesx($origen), imagesy($origen));

        if (!is_dir(dirname($destino))) {
            mkdir(dirname($destino), 0750, true);
        }
        $ok = imagepng($chico, $destino, 8);
        imagedestroy($origen);
        imagedestroy($chico);

        return $ok && is_file($destino);
    }

    private function entregar(?string $ruta, string $tipo, int $id)
    {
        if (!$ruta || !is_file($ruta)) {
            abort(404);
        }

        $nombre = in_array($tipo, ['firmado', 'preview'], true)
            ? "contrato-{$id}" . ($tipo === 'firmado' ? '-firmado' : '') . '.pdf'
            : "documento-{$id}-{$tipo}." . strtolower(pathinfo($ruta, PATHINFO_EXTENSION));

        return response()->file($ruta, [
            'Content-Disposition' => 'inline; filename="' . $nombre . '"',
            'Cache-Control'       => 'private, no-store',
        ]);
    }

    /** Primera apertura del link por parte del cliente. */
    private function registrarApertura(ClientContract $cc): void
    {
        if (!Schema::hasColumn('client_contracts', 'opened_at') || $cc->opened_at) {
            return;
        }

        try {
            $cc->forceFill(['opened_at' => now()])->save();
        } catch (\Throwable $e) {
            Log::warning('[ContractSign] No se pudo registrar la apertura: ' . $e->getMessage());
        }
    }

    /** Guarda una foto de cédula en la carpeta privada y devuelve su ruta. */
    private function guardarFoto(ClientContract $cc, string $cara, string $base64): string
    {
        $dir = ArchivosContrato::dirDocumentos((int) $cc->company_id);
        $completo = storage_path('app/' . $dir);
        if (!is_dir($completo)) {
            mkdir($completo, 0750, true);
        }

        $ruta = $dir . "/cc_{$cc->id}_{$cara}_" . uniqid() . '.' . $this->extension($base64);
        file_put_contents(storage_path('app/' . $ruta), $this->binarioDeBase64($base64));

        return $ruta;
    }

    /**
     * Post-firma: PDF firmado en privado y WhatsApp con el enlace temporal.
     * Nada de esto puede tumbar la firma, que ya quedó guardada.
     */
    private function generarFirmadoYAvisar(ClientContract $cc, string $firma, string $token, array $constancia = []): ?string
    {
        $ctx = ['client_contract_id' => $cc->id, 'company_id' => $cc->company_id];

        if (!ArchivosContrato::plantilla($cc->contract->pdf_path ?? null)) {
            Log::warning('[ContractSign] Contrato sin PDF base, no se genera PDF firmado', $ctx);
            return null;
        }

        try {
            $campos = \App\Models\ContractPdfField::where('contract_id', $cc->contract_id)
                ->orderBy('page')->orderBy('id')
                ->get();

            $valores = $this->valoresDe($cc);

            $salida = (new \App\Services\ContractPdfService())->combineWithSignature(
                $cc->contract->pdf_path,
                $firma,
                trim((string) ($valores['{{nombre_completo}}'] ?? '')) ?: ($cc->user->username ?? 'Cliente'),
                $valores,
                $campos->toArray(),
                $cc->document_front_path,
                $cc->document_back_path,
                $constancia
            );

            ArchivosContrato::guardarFirmado((int) $cc->id, $salida);
            Log::info('[ContractSign] PDF firmado generado', $ctx);

            $this->enviarPorWhatsApp($cc, $ctx);

            return ArchivosContrato::urlPorToken($token, 'firmado');
        } catch (\Throwable $e) {
            Log::error('[ContractSign] Error post-firma (PDF/WhatsApp): ' . $e->getMessage(), $ctx);
            return null;
        }
    }

    private function enviarPorWhatsApp(ClientContract $cc, array $ctx): void
    {
        $crudo = (string) DB::table('user_data')->where('user_id', $cc->user_id)->value('phone');
        $telefono = preg_replace('/\D/', '', $crudo);

        if (!preg_match('/^(57\d{10}|3\d{9})$/', (string) $telefono)) {
            Log::warning('[ContractSign] Teléfono inválido, no se envía WhatsApp', $ctx + ['raw_phone' => $crudo]);
            return;
        }

        try {
            // El enlace vence en 7 días; el PDF no se sirve en /storage.
            $enlace = ArchivosContrato::urlFirmada((int) $cc->id, 'firmado', now()->addDays(7));

            // Este endpoint no tiene JWT: la empresa sale del contrato.
            $respuesta = (new \App\Services\WhatsAppService($cc->company_id))->sendDocument(
                $telefono,
                $enlace,
                "contrato-{$cc->id}-firmado.pdf",
                '¡Gracias por firmar! Aquí tiene su contrato firmado.'
            );

            if (isset($respuesta['error']) || ($respuesta['success'] ?? true) === false) {
                Log::warning('[ContractSign] WhatsApp post-firma respondió error', $ctx + ['wa_response' => $respuesta]);
            }
        } catch (\Throwable $e) {
            Log::error('[ContractSign] Excepción enviando WA post-firma: ' . $e->getMessage(), $ctx);
        }
    }

    /**
     * Texto de aceptación: el que escribió la empresa en la plantilla o, si no
     * puso ninguno (o la columna todavía no existe), uno claro y corto.
     */
    public static function terminos($contrato): string
    {
        $propio = trim((string) ($contrato->terminos ?? ''));

        return $propio !== '' ? $propio : self::TERMINOS_POR_DEFECTO;
    }

    /** Guarda la constancia de aceptación si ya existen las columnas. */
    private function guardarConstancia(ClientContract $cc, array $constancia): void
    {
        if (!Schema::hasColumn('client_contracts', 'accepted_at')) {
            return;
        }

        try {
            $cc->forceFill([
                'accepted_at'       => $constancia['momento'],
                'accept_ip'         => $constancia['ip'],
                'accept_user_agent' => $constancia['dispositivo'],
                'accepted_terms'    => $constancia['texto'],
            ])->save();
        } catch (\Throwable $e) {
            Log::warning('[ContractSign] No se pudo guardar la constancia de aceptación: ' . $e->getMessage());
        }
    }

    /** Imagen base64 válida (JPG o PNG) y dentro del tope de tamaño. */
    private function esImagenValida(string $base64): bool
    {
        $datos = $this->binarioDeBase64($base64);
        if ($datos === '' || strlen($datos) > self::MAX_FOTO) {
            return false;
        }

        return in_array((new \finfo(FILEINFO_MIME_TYPE))->buffer($datos), ['image/jpeg', 'image/png', 'image/jpg'], true);
    }

    private function extension(string $base64): string
    {
        return str_starts_with($base64, 'data:image/png') ? 'png' : 'jpg';
    }

    private function binarioDeBase64(string $base64): string
    {
        if (str_starts_with($base64, 'data:image')) {
            $partes = explode(',', $base64, 2);
            return isset($partes[1]) ? (string) base64_decode($partes[1], true) : '';
        }

        return (string) base64_decode($base64, true);
    }
}
