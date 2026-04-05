<?php

namespace App\Services;

use Exception;
use phpseclib3\Net\SSH2;


// =============================================================================
// 1. SERVICE: app/Services/SSHConnectionService.php
// =============================================================================

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use phpseclib3\Net\SSH2;

class OltManagementService
{
    private $sshService;

    public function __construct(SSHConnectionService $sshService)
    {
        $this->sshService = $sshService;
    }

    /**
     * Registra un ONT en el OLT
     */
    public function registerOnt(array $oltData)
    {
        try {
            $portPosition = $oltData['port_potition'];
            $parts = explode('/', $portPosition);
            $lastPart = end($parts);

            // Comandos para registrar el ONT
            $commands = [
                'enable',
                'enable',
                'config',
                'interface gpon 0/0',
                "ont confirm $lastPart sn-auth {$oltData['serial']} omci ont-lineprofile-id 10 ont-srvprofile-id 10 desc {$oltData['descripcion']}"
            ];

            // Ejecutar comandos
            $output = $this->sshService->executeCommands($commands);

            // Procesar respuesta
            return $this->processOntRegistrationResponse($output);

        } catch (\Exception $e) {
            Log::error('Error en registerOnt: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Error de conexión: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Procesa la respuesta del registro de ONT
     */
    private function processOntRegistrationResponse($output)
    {
        // Capturar mensaje de éxito
        $matches = [];
        preg_match('/Number of ONTs that can be added: \d+, success: \d+\./', $output, $matches);
        $ontMessage = isset($matches[0]) ? $matches[0] : 'No se pudo capturar el mensaje de ONT';

        // Capturar PortID y ONTID
        $portOntMatches = [];
        preg_match('/PortID :(\d+), ONTID :(\d+)/', $output, $portOntMatches);

        if (count($portOntMatches) > 0) {
            $portId = intval($portOntMatches[1]);
            $ontId = intval($portOntMatches[2]);

            // Ejecutar comando de servicio
            $serviceCommands = [
                'quit',
                "service-port 20000 vlan 200 gpon 0/0/{$portId} ont {$ontId} gemport 1 multi-service"
            ];

            $serviceOutput = $this->sshService->executeCommands($serviceCommands);
            Log::info("Comando de servicio ejecutado: " . $serviceOutput);

            return [
                'success' => true,
                'portId' => $portId,
                'ontId' => $ontId,
                'ontMessage' => $ontMessage,
                'message' => 'ONT registered successfully'
            ];
        }

        return [
            'success' => false,
            'error' => 'No se pudo capturar el PortID y ONTID o la respuesta no es válida'
        ];
    }

    /**
     * Obtiene información de un ONT
     */
    public function getOntInfo($portPosition)
    {

        error_log("LLEGA ACAAA");
        try {
            $commands = [
                'enable',
                // 'enable',
                // 'show ont info ' . $portPosition
            ];

            $output = $this->sshService->executeCommands($commands);
            
            return [
                'success' => true,
                'data' => $output
            ];
        } catch (\Exception $e) {
            Log::error('Error obteniendo info ONT: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}