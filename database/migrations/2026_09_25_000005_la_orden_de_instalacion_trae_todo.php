<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La orden de instalación con todo lo que el técnico necesita en la calle.
 *
 * Hoy trae cliente, plan, fecha y técnicos, pero no cómo se conecta: si va por
 * PPPoE o IP fija, con qué credencial, con qué WiFi, por qué OLT y en qué
 * VLAN. Esos datos los sabe quien toma el pedido en la oficina, y sin ellos el
 * técnico llega a la casa y tiene que llamar a preguntar.
 *
 * Se agregan también los del equipo que finalmente se instaló: sin eso, saber
 * qué ONT quedó en qué casa obliga a cruzar tablas a mano.
 *
 * Las claves van cifradas: son credenciales de red del cliente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installation_orders', function (Blueprint $t) {
            // Cómo se conecta
            $t->string('connection_type', 10)->default('static')->after('internet_plan_id');
            $t->string('pppoe_user', 120)->nullable()->after('connection_type');
            $t->text('pppoe_password')->nullable()->after('pppoe_user');
            $t->string('pppoe_profile', 120)->nullable()->after('pppoe_password');
            $t->string('ip_asignada', 45)->nullable()->after('pppoe_profile');
            $t->unsignedBigInteger('router_id')->nullable()->after('ip_asignada');

            // El WiFi que el cliente pidió. Que esté acá es lo que permite
            // dejarlo configurado sin llamar a la oficina.
            $t->string('wifi_ssid', 60)->nullable()->after('router_id');
            $t->text('wifi_password')->nullable()->after('wifi_ssid');

            // Por dónde entra
            $t->unsignedBigInteger('olt_id')->nullable()->after('wifi_password');
            $t->unsignedInteger('vlan')->nullable()->after('olt_id');
            // El grupo de facturación (día de corte), que define cab_facturations.
            $t->unsignedTinyInteger('grupo_facturacion')->nullable()->after('vlan');

            // Lo que quedó instalado
            $t->string('ont_serial', 40)->nullable()->after('grupo_facturacion');
            $t->string('ont_fsp', 20)->nullable()->after('ont_serial');
            $t->unsignedInteger('ont_id')->nullable()->after('ont_fsp');
            $t->unsignedBigInteger('inventory_id')->nullable()->after('ont_id');
            $t->unsignedBigInteger('aprovisionamiento_id')->nullable()->after('inventory_id');
            $t->timestamp('provisioned_at')->nullable()->after('aprovisionamiento_id');
            // Qué pasó al aprovisionar, para que quede en la orden y no sólo en un log.
            $t->json('provision_detalle')->nullable()->after('provisioned_at');
        });
    }

    public function down(): void
    {
        Schema::table('installation_orders', function (Blueprint $t) {
            $t->dropColumn([
                'connection_type', 'pppoe_user', 'pppoe_password', 'pppoe_profile',
                'ip_asignada', 'router_id', 'wifi_ssid', 'wifi_password',
                'olt_id', 'vlan', 'grupo_facturacion',
                'ont_serial', 'ont_fsp', 'ont_id', 'inventory_id',
                'aprovisionamiento_id', 'provisioned_at', 'provision_detalle',
            ]);
        });
    }
};
