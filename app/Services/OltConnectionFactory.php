<?php

namespace App\Services;

use App\Models\OltAdmin;
use phpseclib3\Net\SSH2;
use RuntimeException;

class OltConnectionFactory
{
    /**
     * Returns an authenticated SSH2 session to the OLT.
     * Supports two access modes:
     *   - direct : connects straight to $olt->host (server must have routing)
     *   - jump   : tunnels through MikroTik via SSH ProxyJump (-W)
     */
    public function connect(OltAdmin $olt): SSH2
    {
        $ssh = match ($olt->access_mode) {
            'jump'   => $this->connectViaJump($olt),
            default  => $this->connectDirect($olt),
        };

        if (!$ssh->login($olt->username, $olt->password)) {
            throw new RuntimeException("OLT SSH authentication failed for {$olt->host}");
        }

        return $ssh;
    }

    private function connectDirect(OltAdmin $olt): SSH2
    {
        return new SSH2($olt->host, $olt->port, 30);
    }

    private function connectViaJump(OltAdmin $olt): SSH2
    {
        SshTunnelStream::register();

        $url = sprintf(
            '%s://%s:%s@%s:%d/%s:%d',
            SshTunnelStream::SCHEME,
            rawurlencode($olt->jump_user),
            rawurlencode($olt->jump_pass),
            $olt->jump_host,
            $olt->jump_port ?? 22,
            $olt->host,
            $olt->port
        );

        $stream = fopen($url, 'r+b');

        if (!is_resource($stream)) {
            throw new RuntimeException(
                "Could not open SSH tunnel through {$olt->jump_host} to {$olt->host}"
            );
        }

        return new SSH2($stream);
    }
}
