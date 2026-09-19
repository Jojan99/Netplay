<?php

namespace App\Support;

use App\Models\ClientContract;
use Illuminate\Support\Facades\URL;

/**
 * Archivos de contratos con datos del cliente: PDF firmado y fotos de la cédula.
 *
 * Antes se guardaban en storage/app/public, que nginx sirve en /storage/ sin
 * ninguna autenticación (y el firmado tiene nombre adivinable:
 * contract_{id}_signed.pdf). Los nuevos van en storage/app/privado y solo se
 * entregan por la ruta contratos.archivo (firma temporal) o por el token del
 * contrato. Los que ya existen se siguen leyendo desde public mientras no se
 * muevan.
 */
class ArchivosContrato
{
    /** Carpeta privada, relativa a storage/app (disco local). */
    public const PRIVADO = 'privado/contracts';

    /** Tipos que entrega la ruta protegida. */
    public const TIPOS = ['firmado', 'frente', 'reverso'];

    /** Carpeta (relativa a storage/app) para las fotos nuevas de una empresa. */
    public static function dirDocumentos(int $companyId): string
    {
        return self::PRIVADO . "/{$companyId}/documents";
    }

    /** Carpeta (relativa a storage/app) para los PDF base de las plantillas. */
    public static function dirPlantillas(int $companyId): string
    {
        return self::PRIVADO . "/{$companyId}";
    }

    /**
     * Carpeta absoluta donde quedan las hojas dibujadas del contrato de UN
     * cliente. Van por cliente porque cada uno lleva sus datos estampados.
     */
    public static function dirHojas(int $companyId, int $clientContractId): string
    {
        return storage_path('app/' . self::PRIVADO . "/{$companyId}/render/{$clientContractId}");
    }

    /** Carpeta absoluta de las hojas dibujadas del PDF base de una plantilla. */
    public static function dirHojasPlantilla(int $companyId, int $contractId): string
    {
        return storage_path('app/' . self::PRIVADO . "/{$companyId}/render/plantilla-{$contractId}");
    }

    /**
     * PDF base de una plantilla (contracts.pdf_path). Se mudó a privado junto con
     * el resto, pero el código seguía leyéndolo de storage/app/public: el sellado
     * de variables y la vista previa del contrato se caían con "PDF base no
     * encontrado". Se resuelve igual que los demás: privado primero, public de
     * respaldo mientras queden plantillas viejas sin mover.
     */
    public static function plantilla(?string $relativa): ?string
    {
        return self::absoluta($relativa);
    }

    /**
     * Ruta absoluta de un documento guardado en BD. Los nuevos empiezan por
     * "privado/" (relativos a storage/app); los viejos son relativos a public.
     */
    public static function absoluta(?string $relativa): ?string
    {
        $relativa = ltrim((string) $relativa, '/');
        if ($relativa === '' || str_contains($relativa, '..')) {
            return null;
        }

        // Los archivos que estaban en public (contratos firmados y fotos de
        // cédula, que cualquiera podía bajar adivinando el nombre) se copiaron a
        // privado con la misma estructura: se busca ahí primero y public queda
        // sólo de respaldo mientras termina la mudanza.
        $rutas = str_starts_with($relativa, 'privado/')
            ? [storage_path('app/' . $relativa)]
            : [storage_path('app/privado/' . $relativa), storage_path('app/public/' . $relativa)];

        foreach ($rutas as $abs) {
            if (is_file($abs)) {
                return $abs;
            }
        }

        return null;
    }

    /** PDF firmado: el privado o, si es de antes, el que quedó en public. */
    public static function firmado(int $clientContractId): ?string
    {
        foreach ([
            self::firmadoPrivado($clientContractId),
            storage_path("app/public/contracts/signed/contract_{$clientContractId}_signed.pdf"),
        ] as $ruta) {
            if (is_file($ruta)) {
                return $ruta;
            }
        }

        return null;
    }

    /** Guarda el PDF firmado en la carpeta privada y devuelve la ruta absoluta. */
    public static function guardarFirmado(int $clientContractId, string $contenido): string
    {
        $ruta = self::firmadoPrivado($clientContractId);
        if (!is_dir(dirname($ruta))) {
            mkdir(dirname($ruta), 0750, true);
        }
        file_put_contents($ruta, $contenido);

        return $ruta;
    }

    /** Archivo absoluto de un contrato según el tipo pedido. */
    public static function archivo(ClientContract $cc, string $tipo): ?string
    {
        return match ($tipo) {
            'firmado' => self::firmado((int) $cc->id),
            'frente'  => self::absoluta($cc->document_front_path),
            'reverso' => self::absoluta($cc->document_back_path),
            default   => null,
        };
    }

    /**
     * URL completa con firma temporal. La firma es relativa (sin dominio) para
     * que valga igual en netplay.com.co y en netvula.com.
     */
    public static function urlFirmada(int $clientContractId, string $tipo, \DateTimeInterface $vence): string
    {
        return rtrim(url('/'), '/') . self::rutaFirmada($clientContractId, $tipo, $vence);
    }

    /**
     * Para el panel compilado, que arma la imagen con rootUrl + "storage/" + ruta:
     * se sube un nivel para que el navegador pida la ruta protegida en vez del
     * archivo público.
     */
    public static function rutaParaPanel(int $clientContractId, string $tipo): string
    {
        return '..' . self::rutaFirmada($clientContractId, $tipo, now()->addHours(2));
    }

    /** URL para la página de firma, protegida por el token del contrato. */
    public static function urlPorToken(string $token, string $tipo): string
    {
        return url('/api/contracts/archivo-token/' . rawurlencode($token) . '/' . $tipo);
    }

    private static function rutaFirmada(int $clientContractId, string $tipo, \DateTimeInterface $vence): string
    {
        return URL::temporarySignedRoute(
            'contratos.archivo',
            $vence,
            ['clientContract' => $clientContractId, 'tipo' => $tipo],
            false
        );
    }

    private static function firmadoPrivado(int $clientContractId): string
    {
        return storage_path('app/' . self::PRIVADO . "/signed/contract_{$clientContractId}_signed.pdf");
    }
}
