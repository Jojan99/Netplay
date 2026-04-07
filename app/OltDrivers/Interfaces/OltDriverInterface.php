<?php

namespace App\OltDrivers\Interfaces;

interface OltDriverInterface
{
    public function __construct(object $ssh, array $config);

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

    /**
     * Returns all registered/authorized ONTs.
     * Each entry: ['fsp'=>'0/1/0','ont_id'=>0,'serial'=>'HWTC...','status'=>'online','description'=>'...']
     */
    public function getAuthorizedONTs(): array;

    /**
     * Returns detailed info for a single ONT including optical power.
     *
     * @param string $fsp   e.g. "0/1/0"
     * @param int    $ontId ONT ID
     */
    public function getOntInfo(string $fsp, int $ontId): array;

    /**
     * Returns service ports, optionally filtered by fsp+ontId.
     * Each entry: ['index'=>1024,'vlan'=>100,'fsp'=>'0/1/0','ont_id'=>0,'gemport'=>1]
     *
     * @param string|null $fsp
     * @param int|null    $ontId
     */
    public function getServicePorts(?string $fsp = null, ?int $ontId = null): array;

    /**
     * Transfer an ONT from one port to another.
     *
     * @param string $fromFsp e.g. "0/1/0"
     * @param int    $ontId
     * @param string $toFsp   e.g. "0/1/2"
     * @return array ['success'=>bool,'new_ont_id'=>int,'message'=>string]
     */
    public function transferONT(string $fromFsp, int $ontId, string $toFsp): array;

    /**
     * Deactivate (shut down) an ONT without deleting it.
     */
    public function deactivateONT(string $fsp, int $ontId): bool;

    /**
     * Re-activate a previously deactivated ONT.
     */
    public function activateONT(string $fsp, int $ontId): bool;
}
