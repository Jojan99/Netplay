<?php

namespace App\Console\Commands;

use App\Support\Modules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Repara empresas incompletas: sin slug, sin perfiles (ADMIN/TECNICO/CONTADOR),
 * sin módulos asignados o con usuarios sin perfil. Es idempotente.
 */
class RepairCompanies extends Command
{
    protected $signature = 'company:repair {--company= : Reparar sólo esta empresa} {--dry : Sólo mostrar lo que haría}';
    protected $description = 'Completa empresas sin slug, perfiles o módulos y engancha usuarios huérfanos al perfil ADMIN';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');
        $companies = DB::table('companies')
            ->when($this->option('company'), fn($q, $id) => $q->where('id', $id))
            ->get(['id', 'name', 'slug']);

        $fixed = 0;
        foreach ($companies as $company) {
            $changes = [];

            // 1. slug
            if (empty($company->slug)) {
                $slug = $this->uniqueSlug($company->name ?: ('empresa-' . $company->id));
                $changes[] = "slug => $slug";
                if (!$dry) DB::table('companies')->where('id', $company->id)->update(['slug' => $slug]);
            }

            // 2. perfiles base
            $profiles = DB::table('profiles')->where('company_id', $company->id)->pluck('id', 'name')->all();
            foreach (['ADMIN', 'TECNICO', 'CONTADOR'] as $role) {
                if (isset($profiles[$role])) continue;
                $changes[] = "perfil $role";
                if (!$dry) {
                    $id = DB::table('profiles')->insertGetId([
                        'company_id' => $company->id, 'name' => $role, 'active' => true,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $profiles[$role] = $id;
                }
            }

            // 3. módulos por perfil (sólo agrega los que faltan, respeta lo que el admin ya configuró)
            foreach ($profiles as $name => $profileId) {
                $defaults = Modules::defaultsFor((string) $name);
                if (!$defaults) continue;
                $existing = DB::table('profile_modules')->where('profile_id', $profileId)->pluck('module')->all();
                if ($existing) continue;   // ya configurado a mano: no tocar
                $changes[] = "módulos de $name (" . count($defaults) . ')';
                if (!$dry) {
                    DB::table('profile_modules')->insert(array_map(fn($m) => [
                        'profile_id' => $profileId, 'module' => $m, 'active' => true,
                        'created_at' => now(), 'updated_at' => now(),
                    ], $defaults));
                }
            }

            // 4. usuarios apuntando a un perfil de OTRA empresa (fuga de configuración entre empresas):
            //    se remapean al perfil del MISMO nombre dentro de su empresa, creándolo si falta.
            $validIds = array_values(array_map('intval', $profiles));
            if ($validIds) {
                $bad = DB::table('users')->where('company_id', $company->id)
                    ->where(function ($q) use ($validIds) { $q->whereNull('profile_id')->orWhereNotIn('profile_id', $validIds); })
                    ->select('profile_id', DB::raw('COUNT(*) as n'))->groupBy('profile_id')->get();

                foreach ($bad as $row) {
                    $srcName = $row->profile_id
                        ? DB::table('profiles')->where('id', $row->profile_id)->value('name')
                        : null;
                    $targetName = strtoupper((string) ($srcName ?: 'USER'));

                    $targetId = $profiles[$targetName] ?? null;
                    if (!$targetId) {
                        $changes[] = "perfil $targetName (para $row->n usuario/s)";
                        if ($dry) { continue; }
                        $targetId = DB::table('profiles')->insertGetId([
                            'company_id' => $company->id, 'name' => $targetName, 'active' => true,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                        $profiles[$targetName] = $targetId;
                        $defaults = Modules::defaultsFor($targetName);
                        if ($defaults) {
                            DB::table('profile_modules')->insert(array_map(fn($m) => [
                                'profile_id' => $targetId, 'module' => $m, 'active' => true,
                                'created_at' => now(), 'updated_at' => now(),
                            ], $defaults));
                        }
                    }

                    $changes[] = "$row->n usuario(s) $targetName => perfil propio #$targetId";
                    if (!$dry) {
                        DB::table('users')->where('company_id', $company->id)
                            ->when($row->profile_id, fn($q) => $q->where('profile_id', $row->profile_id),
                                                     fn($q) => $q->whereNull('profile_id'))
                            ->update(['profile_id' => $targetId]);
                    }
                }
            }

            if ($changes) {
                $fixed++;
                $this->line("• [{$company->id}] {$company->name}: " . implode(', ', $changes));
            }
        }

        $this->info($dry ? "Simulación: $fixed empresa(s) necesitan reparación." : "Listo: $fixed empresa(s) reparada(s).");
        return self::SUCCESS;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name) ?: 'empresa', 90, '');
        $slug = $base; $i = 2;
        while (DB::table('companies')->where('slug', $slug)->exists()) $slug = $base . '-' . $i++;
        return $slug;
    }
}
