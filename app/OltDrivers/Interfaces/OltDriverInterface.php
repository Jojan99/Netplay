<?php

namespace App\OltDrivers\Interfaces;

use phpseclib3\Net\SSH2;

interface OltDriverInterface
{
    public function __construct(SSH2 $ssh, array $config);

    /**
     * Returns list of ONTs pending authentication.
     * Each entry: ['fsp' => '0/0/3', 'serial' => 'HWTC1234ABCD', 'vendor' => 'HWTC', ...]
     */
    public function getUnauthONTs(): array;

    /**
     * Register (confirm) a new ONT.
     *
     * @param string $fsp          Frame/Slot/Port e.g. "0/0/3"
     * @param string $serial       SN e.g. "HWTC1234ABCD"
     * @param string $description  Client name or ID
     * @return array ['ont_id' => int, 'port_id' => int, 'message' => string]
     */
    public function registerONT(string $fsp, string $serial, string $description): array;

    /**
     * Delete an ONT from the OLT.
     *
     * @param string $fsp        e.g. "0/0/3"
     * @param int    $ontId      ONT ID assigned by OLT
     * @param int    $servicePort Service-port number (0 if none)
     */
    public function deleteONT(string $fsp, int $ontId, int $servicePort = 0): bool;

    /**
     * Create service-port to activate client traffic.
     *
     * @param string $fsp         e.g. "0/0/3"
     * @param int    $ontId       ONT ID
     * @param int    $vlan        Client VLAN
     * @param int    $servicePort Service-port number to assign
     * @param string $description Client description
     */
    public function assignToClient(string $fsp, int $ontId, int $vlan, int $servicePort, string $description): bool;
}
