<?php

namespace App\Repositories\Interfaces;

interface CrmMediaRepositoryInterface
{
    /**
     * Sube un archivo (URL pública) a UltraMsg
     * Retorna mediaId
     */
    public function upload(string $fileUrl): string;
}
