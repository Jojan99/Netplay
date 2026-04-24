<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigratePruebaBd extends Command
{
    protected $signature = 'migrate:prueba {--execute}';
    protected $description = 'Migración FULL correcta pruebaBd2 → PruebaBd1';

    private const COMPANY_ID = 7;

    private bool $dryRun;

    private array $userMap = [];
    private array $cabMap  = [];

    public function handle(): int
    {
        $this->dryRun = !$this->option('execute');

        $src  = DB::connection('prueba_source');
        $dest = DB::connection('prueba_dest');

        try {

            if (!$this->dryRun) {
                $dest->beginTransaction();
                $dest->statement('SET FOREIGN_KEY_CHECKS=0;');
            }

            $this->info("🚀 INICIANDO MIGRACIÓN");

            $this->stepCompany($dest);
            $this->stepUsers($src, $dest);
            $this->stepUserData($src, $dest);
            $this->stepCab($src, $dest);
            $this->stepDet($src, $dest);

            if (!$this->dryRun) {
                $dest->statement('SET FOREIGN_KEY_CHECKS=1;');
                $dest->commit();
            }

        } catch (\Throwable $e) {

            if (!$this->dryRun) {
                $dest->rollBack();
            }

            $this->error("❌ ERROR: " . $e->getMessage());
            return 1;
        }

        $this->info("✅ MIGRACIÓN COMPLETADA");
        return 0;
    }

    // ─────────────────────────────────────────────
    private function stepCompany($dest): void
    {
        $exists = $dest->table('companies')->where('id', self::COMPANY_ID)->exists();

        if (!$exists && !$this->dryRun) {
            $dest->table('companies')->insert([
                'id' => self::COMPANY_ID,
                'name' => 'Empresa Migrada',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    // ───────────────── USERS ─────────────────
    private function stepUsers($src, $dest): void
    {
        $this->info("🚀 Migrando users...");

        $users = $src->table('users')->get();

        $existing = $dest->table('users')
            ->where('company_id', self::COMPANY_ID)
            ->pluck('id', 'email');

        foreach ($users as $u) {

            // Ya existe → reutilizar ID
            if (isset($existing[$u->email])) {
                $this->userMap[$u->id] = $existing[$u->email];
                continue;
            }

            if ($this->dryRun) {
                $this->userMap[$u->id] = $u->id;
                continue;
            }

            $newId = $dest->table('users')->insertGetId([
                'company_id' => self::COMPANY_ID,
                'username'   => $u->username,
                'email'      => $u->email ?? 'temp_'.$u->id.'@mail.com',
                'password'   => $u->password,
                'profile_id' => $u->profile_id,
                'active'     => $u->active,
                'status'     => $u->status,
                'created_at' => $u->created_at,
                'updated_at' => $u->updated_at,
            ]);

            $this->userMap[$u->id] = $newId;
        }

        $this->info("✔ Users: " . count($this->userMap));
    }

    // ───────────────── USER DATA ─────────────────
    private function stepUserData($src, $dest): void
    {
        $this->info("🚀 Migrando user_data...");

        $rows = $src->table('user_data')->get();
        $count = 0;

        foreach ($rows as $r) {

            if (!isset($this->userMap[$r->user_id])) continue;

            if (!$this->dryRun) {
                $dest->table('user_data')->insert([
                    'company_id'         => self::COMPANY_ID,
                    'user_id'            => $this->userMap[$r->user_id],
                    'gender_id'          => $r->gender_id,
                    'country_id'         => $r->country_id,
                    'dni_id'             => $r->dni_id,
                    'internet_plans_id'  => $r->internet_plans_id,
                    'status_internet_id' => $r->status_internet_id,
                    'ip_assignment_id'   => $r->ip_assignment_id,
                    'names'              => $r->names,
                    'lastname'           => $r->lastname,
                    'address'            => $r->address,
                    'dni'                => $r->dni,
                    'email'              => $r->email,
                    'phone'              => $r->phone,
                    'birthday'           => $r->birthday,
                    'role_id'            => $r->role_id,
                    'active'             => $r->active,
                    'whatsapp_enabled'   => 1,
                    'status'             => $r->status,
                    'created_at'         => $r->created_at,
                    'updated_at'         => $r->updated_at,
                ]);
            }

            $count++;
        }

        $this->info("✔ user_data: $count");
    }

    // ───────────────── CAB ─────────────────
    private function stepCab($src, $dest): void
    {
        $this->info("🚀 Migrando cab_facturations...");

        $rows = $src->table('cab_facturations')->get();

        foreach ($rows as $r) {

            if (!isset($this->userMap[$r->user_id])) continue;

            if ($this->dryRun) {
                $this->cabMap[$r->id] = $r->id;
                continue;
            }

            $newId = $dest->table('cab_facturations')->insertGetId([
                'company_id'            => self::COMPANY_ID,
                'user_id'               => $this->userMap[$r->user_id],
                'date_init_facturation' => $r->date_init_facturation,
                'billing_electronic'    => 0,
                'group'                 => $r->group,
                'created_at'            => $r->created_at,
                'updated_at'            => $r->updated_at,
            ]);

            $this->cabMap[$r->id] = $newId;
        }

        $this->info("✔ cab: " . count($this->cabMap));
    }

    // ───────────────── DET ─────────────────
  private function stepDet($src, $dest): void
{
    $this->info("🚀 Migrando det_facturations...");

    $rows = $src->table('det_facturations')->get();
    $count = 0;

    foreach ($rows as $r) {

        if (!isset($this->cabMap[$r->cab_id])) continue;

        if (!$this->dryRun) {
            $dest->table('det_facturations')->insert([
                'cab_id'                  => $this->cabMap[$r->cab_id],

                'date_facturation'        => $r->date_facturation,
                'number_facture'          => $r->number_facture,
                'date_create_facturation' => $r->date_create_facturation,

                'total'                   => $r->total,
                'price_total'             => $r->price_total,
                //'percentage_discount'     => $r->percentage_discount, // ✅ CORRECTO

                'days_facture'            => $r->days_facture,
                'discount'                => $r->discount,
                'price_discount'          => $r->price_discount,

                'create_facture_manual'   => $r->create_facture_manual,
                'paid'                    => $r->paid,

                'price_abone'             => $r->price_abone,
                'abone'                   => $r->abone,

                'log_id'                  => $r->log_id,

                'created_at'              => $r->created_at,
                'updated_at'              => $r->updated_at,
            ]);
        }

        $count++;
    }

    $this->info("✔ det: $count");
}
}