<?php

namespace App\Services\Vpn;

use Illuminate\Support\Facades\Storage;

/**
 * El único paso que necesita root, listo para copiar y pegar.
 *
 * Se entrega como script en lugar de hacerlo la plataforma porque darle a
 * www-data permiso para instalar paquetes y crear interfaces sería mucho más
 * de lo que hace falta: alcanza con un ayudante y una línea de sudoers que lo
 * autoriza sólo a él, con tres acciones fijas.
 */
class InstaladorVpn
{
    public static function script(): string
    {
        $ayudante = ServidorVpn::AYUDANTE;
        $origen   = Storage::disk('local')->path('vpn/netplay-vpn');
        $servidor = ServidorVpn::configuracion();
        $usuario  = self::usuarioDePhp();

        return <<<BASH
#!/bin/bash
#
# Netplay · instalación del servidor VPN de gestión.
# Se corre una sola vez, como root:
#
#   sudo bash /var/www/netplay/storage/app/vpn/instalar.sh
#
set -euo pipefail

echo "1/5 · Herramientas de WireGuard"
if ! command -v wg >/dev/null 2>&1; then
    apt-get update -qq
    apt-get install -y wireguard-tools
fi

echo "2/5 · Ayudante con privilegios en {$ayudante}"
install -o root -g root -m 0755 "{$origen}" "{$ayudante}"

echo "3/5 · Permiso de sudo para la plataforma ({$usuario})"
# Sólo este comando y sólo estas tres acciones. Nada de comodines.
cat > /etc/sudoers.d/netplay-vpn <<'SUDO'
{$usuario} ALL=(root) NOPASSWD: {$ayudante} sync, {$ayudante} status, {$ayudante} down
SUDO
chmod 0440 /etc/sudoers.d/netplay-vpn
visudo -cf /etc/sudoers.d/netplay-vpn

echo "4/5 · Abrir el puerto UDP {$servidor->listen_port}"
if command -v ufw >/dev/null 2>&1 && ufw status | grep -q "Status: active"; then
    ufw allow {$servidor->listen_port}/udp comment "Netplay VPN"
else
    echo "   ufw no está activo: si hay otro firewall, permitir UDP {$servidor->listen_port} a mano."
fi

echo "5/5 · Levantar el túnel y dejarlo persistente"
# El estado deseado ya lo dejó escrito la plataforma; acá sólo se aplica.
{$ayudante} sync

cat > /etc/systemd/system/netplay-vpn.service <<'UNIT'
[Unit]
Description=Netplay · tunel de gestion (WireGuard)
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart={$ayudante} sync
ExecStop={$ayudante} down

[Install]
WantedBy=multi-user.target
UNIT

systemctl daemon-reload
systemctl enable netplay-vpn.service

echo
echo "Listo. El servidor escucha en UDP {$servidor->listen_port} y vuelve a levantar solo al reiniciar."
wg show {$servidor->interfaz} || true
BASH;
    }

    /**
     * Bajo qué usuario corre la plataforma: es el que necesita el permiso.
     *
     * No sirve mirar el usuario del proceso actual, porque este script se puede
     * generar desde la consola —y ahí el usuario es el del administrador, no el
     * de la web—. El dueño de storage/ sí es el usuario con el que corre
     * Laravel en el servidor, así que se toma de ahí.
     */
    private static function usuarioDePhp(): string
    {
        if (PHP_SAPI !== 'cli' && function_exists('posix_getpwuid')) {
            $datos = posix_getpwuid(posix_geteuid());

            if (!empty($datos['name'])) {
                return $datos['name'];
            }
        }

        if (function_exists('posix_getpwuid')) {
            $dueno = @fileowner(storage_path('app'));

            if ($dueno !== false) {
                $datos = posix_getpwuid($dueno);

                if (!empty($datos['name']) && $datos['name'] !== 'root') {
                    return $datos['name'];
                }
            }
        }

        return 'www-data';
    }

    /** Deja el instalador junto al ayudante, listo para correr. */
    public static function guardar(): string
    {
        $disco = Storage::disk('local');

        $disco->put('vpn/instalar.sh', self::script());

        return $disco->path('vpn/instalar.sh');
    }
}
