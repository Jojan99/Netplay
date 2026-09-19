<?php

namespace App\Services\WhatsApp;

use App\Models\Company;
use App\Services\WhatsAppApiService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * El catálogo de líneas de WhatsApp Web de cada empresa.
 *
 * El servicio Node siempre soportó varias instancias por empresa; Laravel
 * guardaba una sola (companies.wa_instance_id). Resultado: un mensaje que
 * entraba por la segunda línea no resolvía empresa y se perdía, y responder un
 * chat de esa línea habría salido desde otro número.
 *
 * Acá vive la traducción instancia ↔ empresa ↔ línea. companies.wa_instance_id
 * se mantiene sincronizado con la línea principal para no romper nada de lo que
 * ya envía por ahí (facturas, avisos, campañas).
 *
 * TODO lo que toca la tabla está protegido: mientras la migración no corra,
 * cada método devuelve lo mismo que devolvía el código anterior.
 */
class LineasDeWhatsApp
{
    /** Se consulta una vez por petición: son consultas al information_schema. */
    private static ?bool $hayTabla   = null;
    private static ?bool $hayEnConv  = null;
    private static ?bool $hayEnMsg   = null;

    /** ¿Existe el catálogo? */
    public static function disponible(): bool
    {
        if (self::$hayTabla === null) {
            try {
                self::$hayTabla = Schema::hasTable('wa_lineas');
            } catch (\Throwable) {
                self::$hayTabla = false;
            }
        }

        return self::$hayTabla;
    }

    /** ¿Las conversaciones ya guardan su línea? */
    public static function enConversaciones(): bool
    {
        if (self::$hayEnConv === null) {
            try {
                self::$hayEnConv = self::disponible() && Schema::hasColumn('crm_conversations', 'wa_linea_id');
            } catch (\Throwable) {
                self::$hayEnConv = false;
            }
        }

        return self::$hayEnConv;
    }

    /** ¿Los mensajes ya guardan su línea? */
    public static function enMensajes(): bool
    {
        if (self::$hayEnMsg === null) {
            try {
                self::$hayEnMsg = self::disponible() && Schema::hasColumn('crm_messages', 'wa_linea_id');
            } catch (\Throwable) {
                self::$hayEnMsg = false;
            }
        }

        return self::$hayEnMsg;
    }

    /* =====================================================================
     * LECTURA
     * =================================================================== */

    /**
     * La empresa dueña de una instancia del servicio Node.
     *
     * Primero el catálogo (soporta varias líneas); si todavía no existe, cae en
     * companies.wa_instance_id, que es exactamente lo que se hacía antes.
     */
    public static function empresaDeInstancia(string $instanceId): ?int
    {
        if (self::disponible()) {
            $id = DB::table('wa_lineas')->where('instance_id', $instanceId)->value('company_id');
            if ($id) return (int) $id;
        }

        $id = Company::where('wa_instance_id', $instanceId)->value('id');

        return $id ? (int) $id : null;
    }

    /**
     * La línea de una instancia. Con $companyId se exige que sea de esa empresa:
     * una instancia ajena no puede quedar colgada de otra bandeja.
     */
    public static function porInstancia(string $instanceId, ?int $companyId = null): ?object
    {
        if (!self::disponible()) return null;

        return DB::table('wa_lineas')
            ->where('instance_id', $instanceId)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->first();
    }

    /** La línea por id, siempre dentro de la empresa. */
    public static function porId(int $lineaId, int $companyId): ?object
    {
        if (!self::disponible()) return null;

        return DB::table('wa_lineas')
            ->where('id', $lineaId)
            ->where('company_id', $companyId)
            ->first();
    }

    /** La línea principal de la empresa (la de companies.wa_instance_id). */
    public static function principal(int $companyId): ?object
    {
        if (!self::disponible()) return null;

        $linea = DB::table('wa_lineas')
            ->where('company_id', $companyId)
            ->where('principal', 1)
            ->first();

        if ($linea) return $linea;

        // Sin principal marcada: la que coincide con la de la empresa, o la primera activa.
        $instancia = Company::where('id', $companyId)->value('wa_instance_id');

        return DB::table('wa_lineas')
            ->where('company_id', $companyId)
            ->when($instancia, fn ($q) => $q->orderByRaw('instance_id = ? DESC', [$instancia]))
            ->orderByDesc('activa')
            ->orderBy('id')
            ->first();
    }

    /**
     * El instance_id por el que tiene que salir un envío de esta conversación.
     *
     * null significa "la de siempre": NetplayWhatsAppService usa entonces
     * companies.wa_instance_id, igual que antes de todo esto.
     */
    public static function instanciaDeConversacion(?int $lineaId, int $companyId): ?string
    {
        if (!self::disponible() || !$lineaId) return null;

        $linea = self::porId($lineaId, $companyId);

        return $linea->instance_id ?? null;
    }

    /**
     * Las líneas de la empresa, listas para el panel.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function deEmpresa(int $companyId, bool $soloActivas = true): array
    {
        if (!self::disponible()) return [];

        return DB::table('wa_lineas')
            ->where('company_id', $companyId)
            ->when($soloActivas, fn ($q) => $q->where('activa', 1))
            ->orderByDesc('principal')
            ->orderBy('nombre')
            ->get()
            ->map(fn ($l) => [
                'id'          => (int) $l->id,
                'instance_id' => $l->instance_id,
                'nombre'      => $l->nombre,
                'telefono'    => $l->telefono,
                'estado'      => $l->estado,
                'activa'      => (bool) $l->activa,
                'principal'   => (bool) $l->principal,
            ])
            ->toArray();
    }

    /* =====================================================================
     * ESCRITURA
     * =================================================================== */

    /**
     * Trae las instancias del servicio Node y deja el catálogo igual.
     *
     * Lo que el Node ya no tiene se desactiva en vez de borrarse: las
     * conversaciones viejas siguen apuntando a esa línea y perderían el badge.
     *
     * @return array<int, array<string, mixed>> El catálogo ya actualizado.
     */
    public function sincronizar(int $companyId): array
    {
        if (!self::disponible()) return [];

        $company = Company::find($companyId);

        if (!$company || !$company->wa_api_key) {
            return self::deEmpresa($companyId, false);
        }

        try {
            $respuesta  = (new WhatsAppApiService())->getInstances($company->wa_api_key);
            $instancias = $respuesta['instances'] ?? [];
        } catch (\Throwable $e) {
            // El servicio Node caído no puede dejar al panel sin líneas: se
            // devuelve lo último que se sabía.
            Log::warning('[Líneas WA] No se pudo sincronizar con el servicio', [
                'company_id' => $companyId, 'error' => $e->getMessage(),
            ]);

            return self::deEmpresa($companyId, false);
        }

        $vistas = [];

        foreach ($instancias as $inst) {
            $instanceId = $inst['instanceId'] ?? $inst['id'] ?? null;
            if (!$instanceId) continue;

            $vistas[] = $instanceId;

            // Una instancia que ya figura en otra empresa no se roba: sería
            // darle a una empresa la línea de otra.
            $ajena = DB::table('wa_lineas')
                ->where('instance_id', $instanceId)
                ->where('company_id', '<>', $companyId)
                ->exists();

            if ($ajena) {
                Log::warning('[Líneas WA] Instancia reclamada por dos empresas', [
                    'instance_id' => $instanceId, 'company_id' => $companyId,
                ]);
                continue;
            }

            $datos = [
                'company_id'      => $companyId,
                'nombre'          => $inst['name'] ?? 'Línea',
                'telefono'        => $inst['phone'] ?? null,
                'estado'          => $inst['status'] ?? 'disconnected',
                'activa'          => 1,
                'sincronizado_en' => now(),
                'updated_at'      => now(),
            ];

            $existe = DB::table('wa_lineas')->where('instance_id', $instanceId)->exists();

            $existe
                ? DB::table('wa_lineas')->where('instance_id', $instanceId)->update($datos)
                : DB::table('wa_lineas')->insert($datos + ['instance_id' => $instanceId, 'created_at' => now()]);
        }

        if ($vistas) {
            DB::table('wa_lineas')
                ->where('company_id', $companyId)
                ->whereNotIn('instance_id', $vistas)
                ->update(['activa' => 0, 'updated_at' => now()]);
        }

        $this->asegurarPrincipal($companyId, $company);

        return self::deEmpresa($companyId, false);
    }

    /**
     * La empresa siempre tiene que tener una línea principal: es la que usan
     * las facturas, los avisos y todo lo que no cuelga de una conversación.
     */
    private function asegurarPrincipal(int $companyId, Company $company): void
    {
        $principal = DB::table('wa_lineas')
            ->where('company_id', $companyId)->where('principal', 1)->where('activa', 1)
            ->first(['id', 'instance_id']);

        if (!$principal) {
            // Sin principal: la que la empresa ya usaba, o la primera conectada.
            $candidata = DB::table('wa_lineas')
                ->where('company_id', $companyId)->where('activa', 1)
                ->when($company->wa_instance_id, fn ($q) => $q->orderByRaw('instance_id = ? DESC', [$company->wa_instance_id]))
                ->orderByRaw("estado = 'connected' DESC")
                ->orderBy('id')
                ->first(['id', 'instance_id']);

            if (!$candidata) return;

            DB::table('wa_lineas')->where('company_id', $companyId)->update(['principal' => 0]);
            DB::table('wa_lineas')->where('id', $candidata->id)->update(['principal' => 1, 'updated_at' => now()]);
            $principal = $candidata;
        }

        if ($company->wa_instance_id !== $principal->instance_id) {
            $company->update(['wa_instance_id' => $principal->instance_id]);
        }
    }

    /**
     * Cambia cuál es la línea principal de la empresa.
     * Mueve también companies.wa_instance_id: es la que usa todo lo demás.
     */
    public function marcarPrincipal(int $companyId, int $lineaId): bool
    {
        if (!self::disponible()) return false;

        $linea = self::porId($lineaId, $companyId);

        if (!$linea || !$linea->activa) return false;

        DB::transaction(function () use ($companyId, $linea) {
            DB::table('wa_lineas')->where('company_id', $companyId)->update(['principal' => 0, 'updated_at' => now()]);
            DB::table('wa_lineas')->where('id', $linea->id)->update(['principal' => 1, 'updated_at' => now()]);
            Company::where('id', $companyId)->update(['wa_instance_id' => $linea->instance_id]);
        });

        return true;
    }

    /** Da de alta una instancia recién creada en el Node. */
    public function registrar(int $companyId, string $instanceId, string $nombre): void
    {
        if (!self::disponible()) return;

        $ajena = DB::table('wa_lineas')
            ->where('instance_id', $instanceId)
            ->where('company_id', '<>', $companyId)
            ->exists();

        if ($ajena) return;

        // La primera línea de la empresa queda como principal; las siguientes no
        // tocan la que ya estaba en uso.
        $primera = !DB::table('wa_lineas')->where('company_id', $companyId)->where('activa', 1)->exists();

        $datos = [
            'company_id'      => $companyId,
            'nombre'          => $nombre,
            'estado'          => 'waiting_qr',
            'activa'          => 1,
            'sincronizado_en' => now(),
            'updated_at'      => now(),
        ];

        if ($primera) $datos['principal'] = 1;

        DB::table('wa_lineas')->where('instance_id', $instanceId)->exists()
            ? DB::table('wa_lineas')->where('instance_id', $instanceId)->update($datos)
            : DB::table('wa_lineas')->insert($datos + ['instance_id' => $instanceId, 'created_at' => now()]);
    }

    /** Baja lógica: la instancia se borró en el Node. */
    public function darDeBaja(int $companyId, string $instanceId): void
    {
        if (!self::disponible()) return;

        DB::table('wa_lineas')
            ->where('company_id', $companyId)
            ->where('instance_id', $instanceId)
            ->update(['activa' => 0, 'principal' => 0, 'estado' => 'disconnected', 'updated_at' => now()]);

        $company = Company::find($companyId);
        if ($company) $this->asegurarPrincipal($companyId, $company);
    }
}
