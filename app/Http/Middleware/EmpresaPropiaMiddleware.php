<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Lo que se pide por id en la URL tiene que ser de la empresa del operador.
 *
 * Varias rutas del panel buscaban la OLT, la conversación o la etiqueta sólo por
 * su id: un operador de una empresa podía mandar comandos a la OLT de otra,
 * leer sus chats o escribir a sus clientes con sólo cambiar el número.
 *
 * Se mira el nombre del parámetro, no la ruta: {oltId} es siempre una OLT,
 * {conversationId} una conversación, etc. Los {id} genéricos no se tocan porque
 * en cada ruta significan otra cosa. Si la ruta no trae ninguno de estos
 * parámetros, pasa sin consultar nada (webhooks incluidos).
 *
 * Responde 404 y no 403 para no confirmar que el id existe en otra empresa.
 */
class EmpresaPropiaMiddleware
{
    /** Parámetro de la ruta → cómo averiguar de qué empresa es. */
    private const DUENOS = [
        'oltId'          => 'olt_admins',
        'conversationId' => 'crm_conversations',
        'labelId'        => 'crm_labels',
        'stickerId'      => 'crm_stickers',
        'userId'         => 'users',
        'noteId'         => 'nota',
    ];

    public function handle(Request $request, Closure $next)
    {
        $parametros = array_intersect_key($request->route()?->parameters() ?? [], self::DUENOS);

        if (!$parametros) {
            return $next($request);
        }

        $empresa = (int) getSessionCompanyId();

        foreach ($parametros as $nombre => $valor) {
            if (!$empresa || !ctype_digit((string) $valor) || self::empresaDe($nombre, (int) $valor) !== $empresa) {
                return response()->json([
                    'message' => 'No encontrado',
                    'data'    => null,
                    'error'   => 1,
                ], 404);
            }
        }

        return $next($request);
    }

    private static function empresaDe(string $parametro, int $id): ?int
    {
        $empresa = self::DUENOS[$parametro] === 'nota'
            ? DB::table('crm_notes as n')
                ->join('crm_conversations as c', 'c.id', '=', 'n.conversation_id')
                ->where('n.id', $id)
                ->value('c.company_id')
            : DB::table(self::DUENOS[$parametro])->where('id', $id)->value('company_id');

        return $empresa === null ? null : (int) $empresa;
    }
}
