<?php

namespace App\Http\Controllers;

use App\Support\ArchivosContrato;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * La pestaña «Contrato» de la ficha del cliente: sus contratos (con permanencia y documentos),
 * los equipos de la empresa que tiene en comodato y sus contactos adicionales, en una sola
 * respuesta y con el permiso de la ficha (el módulo de contratos no lo tienen todos).
 */
class FichaContratoController extends Controller
{
    /** Cuánto duran los enlaces a los documentos: lo que tarda alguien en abrirlos. */
    private const MINUTOS_ENLACE = 30;

    public function show(int $id): JsonResponse
    {
        $empresa = (int) getSessionCompanyId();
        if (!$this->esCliente($id, $empresa)) {
            return response()->json(['error' => 1, 'message' => 'Cliente no encontrado', 'data' => null], 404);
        }

        $vence = now()->addMinutes(self::MINUTOS_ENLACE);

        $contratos = DB::table('client_contracts as cc')
            ->join('contracts as c', 'c.id', '=', 'cc.contract_id')
            ->where('cc.user_id', $id)->where('cc.company_id', $empresa)
            ->orderByDesc('cc.created_at')
            ->get(['cc.id', 'cc.status', 'cc.sent_at', 'cc.sent_channel', 'cc.opened_at', 'cc.signed_at', 'cc.created_at',
                'cc.document_front_path', 'cc.document_back_path', 'cc.token', 'c.title', 'c.plazo', 'c.installation_value'])
            ->map(function ($r) use ($vence) {
                $plazo = is_numeric($r->plazo) ? (int) $r->plazo : null;
                $hasta = $r->signed_at && $plazo ? Carbon::parse($r->signed_at)->addMonths($plazo) : null;
                $firmado = $r->status === 'signed' && ArchivosContrato::firmado((int) $r->id);

                return [
                    'id'                 => (int) $r->id,
                    'titulo'             => $r->title,
                    'estado'             => $r->status,
                    'creado_en'          => $r->created_at,
                    'enviado_en'         => $r->sent_at,
                    'enviado_por'        => $r->sent_channel,
                    'abierto_en'         => $r->opened_at,
                    'firmado_en'         => $r->signed_at,
                    'plazo_meses'        => $plazo,
                    'permanencia_hasta'  => $hasta?->toDateString(),
                    'permanencia_cumplida' => $hasta ? $hasta->isPast() : null,
                    'valor_instalacion'  => $r->installation_value !== null ? (float) $r->installation_value : null,
                    'enlace_firma'       => $r->token ? url('/contrato/firmar/' . $r->token) : null,
                    'documentos'         => array_values(array_filter([
                        $firmado ? ['nombre' => 'Contrato firmado', 'tipo' => 'PDF', 'url' => ArchivosContrato::urlFirmada((int) $r->id, 'firmado', $vence)] : null,
                        $r->document_front_path ? ['nombre' => 'Cédula, frente', 'tipo' => 'Foto', 'url' => ArchivosContrato::urlFirmada((int) $r->id, 'frente', $vence)] : null,
                        $r->document_back_path ? ['nombre' => 'Cédula, reverso', 'tipo' => 'Foto', 'url' => ArchivosContrato::urlFirmada((int) $r->id, 'reverso', $vence)] : null,
                    ])),
                ];
            });

        // Equipos de la bodega instalados a este cliente: son de la empresa y se recogen si se retira.
        $comodato = DB::table('inventory_units as u')
            ->join('inventories as i', 'i.id', '=', 'u.inventory_id')
            ->where('u.cliente_user_id', $id)->where('u.company_id', $empresa)
            ->whereNull('i.deleted_at')
            ->orderByDesc('u.instalada_en')
            ->get(['u.id', 'i.name', 'u.serial', 'u.mac', 'u.estado', 'u.instalada_en', 'u.entregada_en', 'i.unit_price', 'i.average_cost'])
            ->map(fn ($u) => [
                'id'        => (int) $u->id,
                'equipo'    => $u->name,
                'serial'    => $u->serial,
                'mac'       => $u->mac,
                'estado'    => $u->estado,
                'instalado' => $u->instalada_en ?? $u->entregada_en,
                'valor'     => (float) ($u->unit_price ?: $u->average_cost ?: 0),
            ]);

        // Sin unidades de bodega asignadas: la ONT que la OLT tiene a su nombre también es de la
        // empresa. Se muestra sin valor (no pasó por la bodega) para que el retiro no la olvide.
        if ($comodato->isEmpty()) {
            $comodato = DB::table('olt_onts as o')
                ->join('user_data as ud', 'ud.id', '=', 'o.user_data_id')
                ->join('olt_admins as a', 'a.id', '=', 'o.olt_id')
                ->where('ud.user_id', $id)->where('a.company_id', $empresa)
                ->get(['o.id', 'o.description', 'o.serial', 'o.status', 'o.created_at'])
                ->map(fn ($o) => [
                    'id' => (int) $o->id, 'equipo' => 'ONT de fibra', 'serial' => $o->serial, 'mac' => null,
                    'estado' => $o->status === 'online' ? 'instalada' : $o->status, 'instalado' => $o->created_at,
                    'valor' => null, 'nota' => 'Registrada en la OLT; no pasó por la bodega',
                ]);
        }

        return response()->json(['error' => 0, 'message' => 'OK', 'data' => [
            'contratos' => $contratos,
            'comodato'  => $comodato,
            'valor_comodato' => round($comodato->sum('valor'), 2),
            'contactos' => $this->contactos($id, $empresa),
            'telefono'  => DB::table('user_data')->where('user_id', $id)->value('phone'),
        ]]);
    }

    public function guardarContacto(Request $request, int $id): JsonResponse
    {
        $empresa = (int) getSessionCompanyId();
        if (!$this->esCliente($id, $empresa)) {
            return response()->json(['error' => 1, 'message' => 'Cliente no encontrado'], 404);
        }

        $datos = $request->validate([
            'nombre'   => 'required|string|max:120',
            'relacion' => 'nullable|string|max:60',
            'telefono' => 'nullable|string|max:30',
            'rol'      => 'required|in:paga,respaldo,autorizado',
            'nota'     => 'nullable|string|max:255',
        ]);

        DB::table('client_contacts')->insert($datos + [
            'company_id' => $empresa, 'user_id' => $id,
            'creado_por' => optional(auth()->user())->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('user_audit_logs')->insert([
            'user_id' => $id, 'changed_by' => (int) optional(auth()->user())->id, 'company_id' => $empresa,
            'field_changed' => 'datos_generales', 'old_value' => null, 'new_value' => $datos['nombre'],
            'description' => 'Agregó un contacto (' . $datos['rol'] . '): ' . $datos['nombre'] . ($datos['telefono'] ? ' · ' . $datos['telefono'] : ''),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['error' => 0, 'message' => 'Contacto agregado', 'data' => $this->contactos($id, $empresa)]);
    }

    public function borrarContacto(int $id, int $contactoId): JsonResponse
    {
        $empresa = (int) getSessionCompanyId();
        $c = DB::table('client_contacts')->where('id', $contactoId)->where('user_id', $id)->where('company_id', $empresa)->first();
        if (!$c) {
            return response()->json(['error' => 1, 'message' => 'Contacto no encontrado'], 404);
        }

        DB::table('client_contacts')->where('id', $contactoId)->delete();
        DB::table('user_audit_logs')->insert([
            'user_id' => $id, 'changed_by' => (int) optional(auth()->user())->id, 'company_id' => $empresa,
            'field_changed' => 'datos_generales', 'old_value' => $c->nombre, 'new_value' => null,
            'description' => 'Quitó el contacto ' . $c->nombre,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['error' => 0, 'message' => 'Contacto quitado', 'data' => $this->contactos($id, $empresa)]);
    }

    private function contactos(int $id, int $empresa)
    {
        return DB::table('client_contacts')->where('user_id', $id)->where('company_id', $empresa)
            ->orderByRaw("FIELD(rol, 'paga', 'respaldo', 'autorizado')")->orderBy('id')
            ->get(['id', 'nombre', 'relacion', 'telefono', 'rol', 'nota', 'created_at']);
    }

    private function esCliente(int $id, int $empresa): bool
    {
        return $empresa > 0 && DB::table('users')->where('id', $id)->where('company_id', $empresa)->exists();
    }
}
