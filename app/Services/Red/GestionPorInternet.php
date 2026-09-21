<?php

namespace App\Services\Red;

use App\Models\Aprovisionamiento;
use App\Models\GestionRemota;
use App\Services\Acs\GenieAcs;
use App\Services\OltTelnetDispatcher;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Deja a cada ONT Huawei gestionada sólo por su conexión de internet, para que
 * un reinicio no le borre nada.
 *
 * Con la gestión de la OLT ("ont ipconfig"), en cada arranque la OLT le vuelve
 * a cargar su configuración y el Huawei borra las conexiones creadas por
 * TR-069: el 19-09 LILIANA_JIMENEZ y ELVIRA_JIMENEZ amanecieron sin internet
 * (3 de 4 reinicios con gestión de la OLT; 0 de 9 sin ella).
 *
 * Quitarle la gestión de la OLT lo deja mudo para el TR-069 hasta que se
 * reinicia (el 17-09 así quedaron 16 equipos); después del reinicio reporta
 * por su conexión de internet para siempre (KEYLA_DE_AVILA, 19-09 1:33). Por
 * eso se hacen las dos cosas juntas: se quita y se reinicia desde la OLT.
 *
 * - Instalaciones nuevas: apenas termina el aprovisionamiento, con el técnico ahí.
 * - Los que ya estaban: de madrugada (3 a. m.), cuando nadie usa el servicio.
 *
 * Sólo a los que ya tienen el TR-069 en su conexión de internet, conectada y
 * con IP. Si al volver le faltara la conexión, repararTrasReinicio se la pone.
 */
class GestionPorInternet
{
    private const VIGILANDO = 'gestion-internet:vigilando';
    private const ESPERA_VUELTA_MIN = 20;
    private const HORA_MADRUGADA = 3;

    /** Lo llama la tarea de cada minuto. */
    public static function trabajar(): int
    {
        $hechas = self::vigilar() + self::nuevas();

        // Varias pasadas dentro de la hora de la madrugada: si el equipo no
        // contesta, intentar() lo deja en espera 15 minutos y en la siguiente
        // vuelta se vuelve a probar. Con una sola pasada, una noche mala dejaba
        // todo para la noche siguiente (20-09: pasó eso, 0 equipos).
        if (now()->hour === self::HORA_MADRUGADA && Cache::add('gestion-internet:madrugada:' . now()->format('Y-m-d-H-i'), 1, 290)) {
            $hechas += self::deMadrugada();
        }

        return $hechas;
    }

    /** Instalaciones de las últimas horas: se pasan en el momento. */
    public static function nuevas(): int
    {
        $hechas = 0;

        Aprovisionamiento::where('estado', 'listo')->whereNotNull('acs_id')
            ->where('created_at', '>=', now()->subHours(3))
            ->where('updated_at', '<=', now()->subMinute())
            ->orderBy('id')->get()
            ->filter(fn (Aprovisionamiento $a) => empty($a->datos['origen']) && !empty($a->datos['wan']))
            ->each(function (Aprovisionamiento $a) use (&$hechas) {
                $hechas += (int) self::intentar((int) $a->company_id, (string) $a->acs_id, 'la instalación');
            });

        return $hechas;
    }

    /** Los que ya estaban instalados, de madrugada y de a pocos. */
    public static function deMadrugada(int $maximo = 8): int
    {
        $hechas = 0;

        foreach (self::empresas() as $companyId) {
            $acs = GenieAcs::deEmpresa($companyId);
            $recientes = now()->subHour()->toIso8601String();

            // Huawei que reportan; intentar() descarta los que ya no tienen la de la OLT.
            $equipos = collect($acs->dispositivos(['_deviceId._OUI' => '00259E', '_lastInform' => ['$gt' => $recientes]], ['_id']))->pluck('_id');

            foreach ($equipos as $id) {
                if ($hechas >= $maximo) {
                    return $hechas;
                }

                $hechas += (int) self::intentar($companyId, (string) $id, 'la revisión de madrugada');
            }
        }

        return $hechas;
    }

    /**
     * Mira si el equipo está listo y, si lo está, le quita la gestión de la OLT
     * y lo reinicia. Cada equipo se intenta una vez por día como mucho.
     */
    public static function intentar(int $companyId, string $acsId, string $cuando): bool
    {
        $marca = "gestion-internet:intento:{$acsId}";

        if (Cache::has($marca) || array_key_exists($acsId, Cache::get(self::VIGILANDO, []))) {
            return false;
        }

        $g = GestionRemota::where('company_id', $companyId)->first();
        $vlanGestion = (int) ($g?->vlan ?: 0);

        if (!$vlanGestion) {
            return false;
        }

        $acs = GenieAcs::deEmpresa($companyId);
        $d = $acs->dispositivo($acsId);

        if (!$d || ($d['_deviceId']['_OUI'] ?? '') !== '00259E') {
            return false;
        }

        $yo = new AprovisionamientoDeOnt($companyId);

        // Sin la de gestión de la OLT no hay nada que hacer.
        if (!collect($yo->conexiones($d))->contains(fn ($c) => $c['servicios'] === 'TR069' || $c['vlan'] === $vlanGestion)) {
            return false;
        }

        $candidata = collect($yo->conexiones($d))->first(fn ($c) => $c['vlan'] !== $vlanGestion
            && str_contains($c['servicios'], 'TR069') && str_contains($c['servicios'], 'INTERNET'));

        if (!$candidata) {
            return false;
        }

        // Se confirma con lo que el equipo dice ahora, no con lo guardado. Sólo
        // lo necesario: leer todas las conexiones tarda más de 30 s en algunos
        // (ANGIE_MONTALVO) y se quedaba reintentando cada minuto.
        // 60 s, no 30: el servidor TR-069 ahora deja sesiones de 90 s y estos
        // Huawei tardan. Con 30 s la pasada de las 3 a. m. del 21-09 no alcanzó
        // a leer ninguno y no pasó ni un equipo.
        $r = $acs->tarea($acsId, ['name' => 'getParameterValues', 'parameterNames' => array_map(
            fn ($p) => "{$candidata['ruta']}.{$p}", ['ConnectionStatus', 'ExternalIPAddress', 'X_HW_SERVICELIST'])], 60);

        if (!($r['hecha'] ?? false)) {
            Cache::put($marca, 1, now()->addMinutes(15));

            return false;
        }

        $d = $acs->dispositivo($acsId) ?? $d;
        $internet = AprovisionamientoDeOnt::v($d, "{$candidata['ruta']}.ConnectionStatus") === 'Connected'
            && filter_var((string) AprovisionamientoDeOnt::v($d, "{$candidata['ruta']}.ExternalIPAddress"), FILTER_VALIDATE_IP)
            && str_contains(strtoupper((string) AprovisionamientoDeOnt::v($d, "{$candidata['ruta']}.X_HW_SERVICELIST")), 'INTERNET')
            ? $candidata : null;

        if (!$internet) {
            return false;
        }

        $ont = DB::table('olt_onts as o')->join('olt_admins as a', 'a.id', '=', 'o.olt_id')
            ->where('a.company_id', $companyId)->where('a.brand', 'like', '%huawei%')
            ->where('o.serial', (string) ($d['_deviceId']['_SerialNumber'] ?? ''))
            ->orderByDesc('o.updated_at')->first(['o.olt_id', 'o.fsp', 'o.ont_id', 'o.service_ports']);

        if (!$ont) {
            return false;
        }

        Cache::put($marca, 1, now()->addDay());
        $ip = (string) AprovisionamientoDeOnt::v($d, "{$internet['ruta']}.ExternalIPAddress");

        try {
            $q = app(OltTelnetDispatcher::class)->dispatch((int) $ont->olt_id, 'quitarGestionDeOnt', [
                'fsp' => $ont->fsp, 'ont_id' => (int) $ont->ont_id, 'vlan' => $vlanGestion,
            ]);
        } catch (\Throwable $e) {
            self::anotar($acsId, false, 'No se pudo quitar la gestión de la OLT: ' . mb_substr($e->getMessage(), 0, 120));

            return false;
        }

        if (!($q['ok'] ?? false)) {
            self::anotar($acsId, false, $q['detalle'] ?? 'La OLT no dejó quitar la gestión.');

            return false;
        }

        // El service-port de gestión ya no está: que la ficha no lo siga mostrando.
        DB::table('olt_onts')->where('olt_id', $ont->olt_id)->where('fsp', $ont->fsp)->where('ont_id', $ont->ont_id)
            ->update(['service_ports' => json_encode(collect(json_decode((string) $ont->service_ports, true) ?: [])
                ->reject(fn ($s) => (int) ($s['vlan'] ?? 0) === $vlanGestion)->values()->all())]);

        try {
            $r = app(OltTelnetDispatcher::class)->dispatch((int) $ont->olt_id, 'reiniciarOnt', ['fsp' => $ont->fsp, 'ont_id' => (int) $ont->ont_id]);
        } catch (\Throwable $e) {
            $r = ['ok' => false, 'detalle' => mb_substr($e->getMessage(), 0, 120)];
        }

        if (!($r['ok'] ?? false)) {
            // Sigue con internet; queda mudo hasta su próximo reinicio y ahí vuelve solo.
            self::anotar($acsId, false, 'Se quitó la gestión de la OLT pero no se pudo reiniciar el equipo (' . ($r['detalle'] ?? '') . '): '
                . 'sigue con internet y vuelve a reportar en su próximo reinicio.');

            return false;
        }

        $vigilando = Cache::get(self::VIGILANDO, []);
        $vigilando[$acsId] = ['company' => $companyId, 'desde' => now()->toIso8601String(), 'ip' => $ip];
        Cache::forever(self::VIGILANDO, $vigilando);

        Log::info('[GestionPorInternet] Gestión de la OLT quitada y equipo reiniciado', ['equipo' => $acsId, 'cuando' => $cuando, 'ip' => $ip]);
        self::anotar($acsId, true, "Durante {$cuando} se le quitó la gestión de la OLT y se reinició (unos 2 minutos): "
            . 'desde ahora se gestiona sólo por su conexión de internet y un reinicio ya no le borra nada.');

        return true;
    }

    /** Los reiniciados: que hayan vuelto y reporten por su conexión de internet. */
    public static function vigilar(): int
    {
        $vigilando = Cache::get(self::VIGILANDO, []);

        if (!$vigilando) {
            return 0;
        }

        foreach ($vigilando as $acsId => $v) {
            $desde = Carbon::parse($v['desde']);

            try {
                $d = GenieAcs::deEmpresa((int) $v['company'])->dispositivo((string) $acsId);
            } catch (\Throwable) {
                continue;
            }

            $volvio = $d && Carbon::parse($d['_lastBoot'] ?? '2000-01-01')->gt($desde) && Carbon::parse($d['_lastInform'] ?? '2000-01-01')->gt($desde);

            if ($volvio) {
                self::anotar((string) $acsId, true, 'El equipo volvió del reinicio y ya reporta por su conexión de internet.');
            } elseif ($desde->lt(now()->subMinutes(self::ESPERA_VUELTA_MIN))) {
                Log::warning('[GestionPorInternet] El equipo no volvió a reportar después del reinicio', ['equipo' => $acsId]);
                self::anotar((string) $acsId, false, 'El equipo no volvió a reportar ' . self::ESPERA_VUELTA_MIN
                    . ' minutos después del reinicio. Si el cliente tiene internet, reporta en cuanto su conexión llegue al servidor TR-069.');
            } else {
                continue;
            }

            unset($vigilando[$acsId]);
        }

        Cache::forever(self::VIGILANDO, $vigilando);

        return 0;
    }

    /** @return list<int> */
    private static function empresas(): array
    {
        return GestionRemota::where('aprovisionar', true)->where('aprov_wan', true)->whereNotNull('vlan')
            ->pluck('company_id')->map(fn ($c) => (int) $c)->unique()->values()->all();
    }

    /**
     * Lo deja en el historial del último aprovisionamiento del equipo, sin
     * moverle la fecha: repararTrasReinicio la compara con la del arranque.
     */
    private static function anotar(string $acsId, bool $ok, string $detalle): void
    {
        $a = Aprovisionamiento::where('acs_id', $acsId)->orderByDesc('id')->first();

        if (!$a) {
            return;
        }

        $a->timestamps = false;
        $a->pasos = array_merge($a->pasos ?? [], [['paso' => 'Gestión por internet', 'ok' => $ok, 'detalle' => $detalle]]);
        $a->save();
    }
}
