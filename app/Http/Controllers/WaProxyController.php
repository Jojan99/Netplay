<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;

/**
 * Proxy transparente al whatsapp-service.
 * Inyecta automáticamente el x-api-key de la empresa desde la DB,
 * eliminando el problema de Mixed Content (HTTP vs HTTPS) en el frontend.
 *
 * Ruta: GET|POST|PUT|DELETE /api/wa-proxy/{path}
 */
class WaProxyController extends Controller
{
    private string $waBase;

    public function __construct()
    {
        $base          = config('services.netplay_whatsapp.base_url', 'http://127.0.0.1:3001/crm');
        $this->waBase  = rtrim(preg_replace('#/crm$#', '', $base), '/');
    }

    public function proxy(Request $request, string $path)
    {
        $company = Company::find(getSessionCompanyId());

        if (!$company || !$company->wa_api_key) {
            return response()->json(['status' => 'error', 'message' => 'Sin credenciales WA'], 403);
        }

        $url    = $this->waBase . '/' . ltrim($path, '/');
        $method = strtoupper($request->method());
        $body   = $request->getContent();

        // Agregar query string si existe
        $qs = $request->getQueryString();
        if ($qs) $url .= '?' . $qs;

        $curl    = curl_init();
        $headers = [
            'Content-Type: application/json',
            'Accept: */*',
            'x-api-key: ' . $company->wa_api_key,
        ];

        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HEADER         => true,
        ];

        match ($method) {
            'POST'          => $opts += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body],
            'PUT', 'PATCH'  => $opts += [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_POSTFIELDS => $body],
            'DELETE'        => $opts += [CURLOPT_CUSTOMREQUEST => 'DELETE'],
            default         => null,
        };

        curl_setopt_array($curl, $opts);

        $raw         = curl_exec($curl);
        $httpCode    = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $headerSize  = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $contentType = curl_getinfo($curl, CURLINFO_CONTENT_TYPE) ?? 'application/json';
        $curlErr     = curl_error($curl);
        curl_close($curl);

        if ($curlErr) {
            return response()->json(['status' => 'error', 'message' => 'WA service unreachable: ' . $curlErr], 503);
        }

        $responseBody = substr($raw, $headerSize);

        // Pasar el Content-Type original (imagen, JSON, etc.)
        return response($responseBody, $httpCode ?: 502, [
            'Content-Type' => $contentType,
        ]);
    }
}
