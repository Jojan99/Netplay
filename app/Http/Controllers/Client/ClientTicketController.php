<?php

namespace App\Http\Controllers\Client;

use App\Constants\ApiResponseConstants;
use App\Http\Controllers\Controller;
use App\Services\Avisos\MensajeDeAviso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Reportes de falla del portal de cliente.
 * El cliente puede crear tickets y ver el estado de los suyos.
 */
class ClientTicketController extends Controller
{
    const VALID_CATEGORIES = [
        'sin_internet',
        'lentitud',
        'intermitencia',
        'wifi',
        'otro',
    ];

    /** Cómo se lee cada categoría en el aviso al grupo. */
    const CATEGORIAS = [
        'sin_internet'  => 'Sin internet',
        'lentitud'      => 'Lentitud',
        'intermitencia' => 'Intermitencia',
        'wifi'          => 'WiFi',
        'otro'          => 'Otro',
    ];

    /**
     * GET /api/client/tickets
     * Lista los tickets del cliente autenticado con el estado legible.
     */
    public function index(): JsonResponse
    {
        $user = JWTAuth::user();

        $tickets = DB::table('tickets as t')
            ->leftJoin('ticket_status as ts', 'ts.id', '=', 't.status_id')
            ->where('t.user_id', $user->id)
            ->where('t.company_id', $user->company_id)
            ->select(
                't.id',
                't.category',
                't.observation',
                't.source',
                't.created_at',
                't.updated_at',
                't.started_at',
                't.finished_at',
                't.closed_at',
                'ts.name as status'
            )
            ->orderByDesc('t.created_at')
            ->get();

        return response()->json([
            'message' => 'Tickets obtenidos correctamente',
            'data'    => $tickets,
            'status'  => ApiResponseConstants::SUCCESS,
        ], JsonResponse::HTTP_OK);
    }

    /**
     * POST /api/client/tickets
     * Crea un nuevo reporte de falla desde el portal.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'category'    => 'required|string|in:' . implode(',', self::VALID_CATEGORIES),
            'description' => 'required|string|min:10|max:1000',
        ], [
            'category.required'    => 'Seleccione una categoría',
            'category.in'          => 'Categoría no válida',
            'description.required' => 'Describa el problema',
            'description.min'      => 'La descripción debe tener al menos 10 caracteres',
            'description.max'      => 'La descripción no puede superar 1000 caracteres',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos incompletos',
                'data'    => $validator->errors(),
                'status'  => ApiResponseConstants::ERROR,
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = JWTAuth::user();

        // El listado de tickets exige técnico, servicio y prioridad: sin ellos el ticket
        // quedaba guardado y nadie lo veía. Va al técnico con menos tickets abiertos.
        $tecnico = \App\Services\Tickets\AsignacionDeTecnico::menosCargado((int) $user->company_id);
        $servicio = DB::table('ticket_type_services')->whereIn('company_id', [$user->company_id, 0])->where('active', 1)
            ->orderByRaw("CASE WHEN UPPER(name) LIKE 'SOPORTE%' THEN 0 WHEN UPPER(name) LIKE 'DA%' THEN 1 ELSE 2 END")->orderByDesc('company_id')->value('id');
        $prioridad = DB::table('ticket_type_prioritys')->whereIn('company_id', [$user->company_id, 0])->where('active', 1)
            ->orderByRaw("CASE WHEN UPPER(name) = 'MEDIA' THEN 0 ELSE 1 END")->orderByDesc('company_id')->value('id');

        // status_id = 1 → "Por hacer" (estado inicial)
        $ticketId = DB::table('tickets')->insertGetId([
            'company_id'   => $user->company_id,
            'user_id'      => $user->id,
            'status_id'    => 1,
            'technical_id' => $tecnico['id'] ?? null,
            'service_id'   => $servicio,
            'priority_id'  => $prioridad,
            'date'         => now()->toDateString(),
            'category'     => $request->category,
            'observation'  => $request->description,
            'source'       => 'client_portal',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        // Diagnóstico de su conexión, en segundo plano, como novedad del ticket.
        \App\Services\Tickets\DiagnosticoDeTicket::lanzar($ticketId);

        $ticket = DB::table('tickets as t')
            ->leftJoin('ticket_status as ts', 'ts.id', '=', 't.status_id')
            ->where('t.id', $ticketId)
            ->select('t.id', 't.category', 't.observation', 't.source', 't.created_at', 't.updated_at', 'ts.name as status')
            ->first();

        // Notificar a admins vía WhatsApp
        $userData = DB::table('user_data')->where('user_id', $user->id)->first(['names', 'lastname', 'phone', 'address']);
        $clientName = trim(($userData->names ?? '') . ' ' . ($userData->lastname ?? '')) ?: $user->username;
        MensajeDeAviso::nuevo('Reporte del portal del cliente', $user->company_id, '🔧')
            ->dato('Ticket', "#{$ticketId}")
            ->dato('Cliente', $clientName)
            ->telefono('Teléfono', $userData->phone ?? null)
            ->dato('Dirección', $userData->address ?? null)
            ->dato('Categoría', self::CATEGORIAS[$request->category] ?? $request->category)
            ->dato('Origen', 'Portal del cliente')
            ->dato('Técnico', $tecnico['nombre'] ?? 'Sin asignar (no hay técnicos activos)')
            ->fecha('Registrado', now())
            ->bloque('Descripción', $request->description)
            ->enviar('ticket_support');

        return response()->json([
            'message' => 'Reporte enviado correctamente. Nuestro equipo lo atenderá pronto.',
            'data'    => $ticket,
            'status'  => ApiResponseConstants::SUCCESS,
        ], JsonResponse::HTTP_OK);
    }

    /**
     * GET /api/client/tickets/{id}
     * Detalle de un ticket específico (solo si pertenece al cliente).
     */
    public function show(string|int $id): JsonResponse
    {
        $user = JWTAuth::user();

        $ticket = DB::table('tickets as t')
            ->leftJoin('ticket_status as ts', 'ts.id', '=', 't.status_id')
            ->where('t.id', (int) $id)
            ->where('t.user_id', $user->id)
            ->where('t.company_id', $user->company_id)
            ->select('t.id', 't.category', 't.observation', 't.source', 't.created_at', 't.updated_at', 'ts.name as status')
            ->first();

        if (!$ticket) {
            return response()->json([
                'message' => 'Reporte no encontrado',
                'data'    => null,
                'status'  => ApiResponseConstants::ERROR,
            ], JsonResponse::HTTP_NOT_FOUND);
        }

        return response()->json([
            'message' => 'OK',
            'data'    => $ticket,
            'status'  => ApiResponseConstants::SUCCESS,
        ], JsonResponse::HTTP_OK);
    }
}
