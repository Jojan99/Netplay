<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La consola de Netvula: la plataforma como negocio.
 *
 * Acá vive lo que hasta ahora no estaba en ningún lado: qué plan tiene cada
 * empresa, cuánto paga, en qué ciclo va, qué cupón usó, quién la refirió y
 * qué se le cobró. Nada de esto toca el servicio de los clientes de la
 * empresa: es la relación entre Netvula y el ISP.
 *
 * Todo va con guardas: la migración se puede correr sobre una base que ya
 * tenga parte hecha sin romper nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Cuándo entró por última vez cada usuario del panel. No existía en
        // ningún lado y la consola necesita saber qué empresa dejó de usar la
        // plataforma. (Es de `users`, el de las empresas; no tiene nada que
        // ver con quién entra a la consola: eso vive en la migración 000002.)
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'ultimo_ingreso')) {
            Schema::table('users', function (Blueprint $t) {
                $t->timestamp('ultimo_ingreso')->nullable();
            });
        }

        // ── Planes de la plataforma ───────────────────────────────────────
        if (!Schema::hasTable('plataforma_planes')) {
            Schema::create('plataforma_planes', function (Blueprint $t) {
                $t->id();
                $t->string('clave', 40)->unique();
                $t->string('nombre', 80);
                $t->string('para', 160)->nullable();
                $t->decimal('precio_mensual', 12, 2)->nullable();
                $t->decimal('precio_anual', 12, 2)->nullable();
                // null = sin tope ("Consultanos" / ilimitado).
                $t->unsignedInteger('clientes')->nullable();
                $t->boolean('destacado')->default(false);
                $t->json('incluye')->nullable();
                $t->boolean('activo')->default(true);
                $t->smallInteger('orden')->default(0);
                $t->timestamps();
            });
        }

        // Cada cambio de precio queda guardado: lo que paga una empresa es lo
        // que se pactó, no lo que diga hoy la lista.
        if (!Schema::hasTable('plataforma_plan_precios')) {
            Schema::create('plataforma_plan_precios', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('plan_id')->index();
                $t->decimal('precio_mensual', 12, 2)->nullable();
                $t->decimal('precio_anual', 12, 2)->nullable();
                $t->dateTime('desde');
                $t->string('motivo', 160)->nullable();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->timestamp('created_at')->nullable();
            });
        }

        // ── Suscripción de cada empresa con Netvula ───────────────────────
        if (!Schema::hasTable('plataforma_suscripciones')) {
            Schema::create('plataforma_suscripciones', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->unique();
                $t->unsignedBigInteger('plan_id')->nullable()->index();
                $t->enum('ciclo', ['mensual', 'anual'])->default('mensual');
                // Precio especial: si está vacío se usa el de lista del plan.
                $t->decimal('precio_pactado', 12, 2)->nullable();
                $t->enum('estado', ['prueba', 'al_dia', 'en_mora', 'suspendida', 'cancelada'])->default('prueba');
                $t->enum('metodo_pago', ['transferencia', 'efectivo', 'pasarela'])->nullable();
                $t->date('inicio')->nullable();
                $t->date('prueba_hasta')->nullable();
                $t->date('proxima_facturacion')->nullable();
                // Cupón vigente sobre esta suscripción y cuántos períodos le quedan.
                $t->unsignedBigInteger('cupon_id')->nullable()->index();
                $t->unsignedSmallInteger('cupon_periodos')->nullable();
                $t->boolean('cupon_permanente')->default(false);
                // Código que esta empresa reparte para referir a otras.
                $t->string('codigo_referido', 20)->nullable()->unique();
                $t->unsignedBigInteger('referida_por')->nullable()->index();
                $t->text('notas')->nullable();
                $t->timestamps();
            });
        }

        // ── Cobros de Netvula a la empresa ────────────────────────────────
        if (!Schema::hasTable('plataforma_cobros')) {
            Schema::create('plataforma_cobros', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('suscripcion_id')->nullable();
                $t->date('periodo_inicio');
                $t->date('periodo_fin');
                $t->enum('ciclo', ['mensual', 'anual'])->default('mensual');
                $t->decimal('precio_lista', 12, 2)->default(0);
                $t->decimal('descuento', 12, 2)->default(0);
                $t->unsignedBigInteger('cupon_id')->nullable();
                $t->string('cupon_codigo', 40)->nullable();
                $t->decimal('credito_aplicado', 12, 2)->default(0);
                $t->decimal('total', 12, 2)->default(0);
                $t->decimal('pagado', 12, 2)->default(0);
                $t->date('vence');
                $t->enum('estado', ['pendiente', 'pagado', 'anulado'])->default('pendiente');
                $t->dateTime('pagado_en')->nullable();
                $t->json('detalle')->nullable();
                $t->unsignedBigInteger('creado_por')->nullable();
                $t->timestamps();
                // Un período no se cobra dos veces.
                $t->unique(['company_id', 'periodo_inicio'], 'cobro_empresa_periodo');
            });
        }

        if (!Schema::hasTable('plataforma_pagos')) {
            Schema::create('plataforma_pagos', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('cobro_id')->index();
                $t->unsignedBigInteger('company_id')->index();
                $t->decimal('monto', 12, 2);
                $t->date('fecha');
                $t->enum('metodo', ['transferencia', 'efectivo', 'pasarela'])->default('transferencia');
                $t->string('referencia', 80)->nullable();
                // Ruta del comprobante en storage; nunca datos de tarjeta.
                $t->string('comprobante', 255)->nullable();
                $t->string('nota', 255)->nullable();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->timestamp('created_at')->nullable();
            });
        }

        // ── Cupones ───────────────────────────────────────────────────────
        if (!Schema::hasTable('plataforma_cupones')) {
            Schema::create('plataforma_cupones', function (Blueprint $t) {
                $t->id();
                $t->string('codigo', 40)->unique();
                $t->string('descripcion', 160)->nullable();
                $t->enum('tipo', ['porcentaje', 'monto']);
                $t->decimal('valor', 12, 2);
                $t->date('desde')->nullable();
                $t->date('hasta')->nullable();
                $t->unsignedInteger('usos_maximos')->nullable();
                $t->unsignedInteger('usos_por_empresa')->default(1);
                $t->unsignedInteger('usos')->default(0);
                // null = todos los planes / todos los ciclos.
                $t->json('planes')->nullable();
                $t->json('ciclos')->nullable();
                $t->enum('duracion', ['primer_periodo', 'n_periodos', 'permanente'])->default('primer_periodo');
                $t->unsignedSmallInteger('periodos')->nullable();
                $t->boolean('activo')->default(true);
                $t->text('notas')->nullable();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('plataforma_cupon_usos')) {
            Schema::create('plataforma_cupon_usos', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('cupon_id')->index();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('cobro_id')->nullable();
                $t->enum('origen', ['registro', 'consola'])->default('consola');
                $t->unsignedBigInteger('user_id')->nullable();
                $t->timestamp('created_at')->nullable();
            });
        }

        // ── Referidos ─────────────────────────────────────────────────────
        if (!Schema::hasTable('plataforma_referidos')) {
            Schema::create('plataforma_referidos', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('referidor_company_id')->index();
                $t->unsignedBigInteger('referida_company_id')->unique();
                $t->string('codigo', 20);
                // registrado → activo cuando la referida paga su primer período.
                $t->enum('estado', ['registrado', 'activo', 'anulado'])->default('registrado');
                $t->json('beneficio_referido')->nullable();
                $t->json('beneficio_referidor')->nullable();
                $t->dateTime('acreditado_en')->nullable();
                $t->decimal('credito_otorgado', 12, 2)->default(0);
                $t->timestamps();
            });
        }

        // Saldo a favor de la empresa: positivo cuando se otorga, negativo
        // cuando se consume en un cobro. El saldo es la suma.
        if (!Schema::hasTable('plataforma_creditos')) {
            Schema::create('plataforma_creditos', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->decimal('monto', 12, 2);
                $t->string('motivo', 160);
                $t->unsignedBigInteger('referido_id')->nullable();
                $t->unsignedBigInteger('cobro_id')->nullable();
                $t->string('nota', 255)->nullable();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->timestamp('created_at')->nullable();
            });
        }

        // ── Ajustes de la plataforma (programa de referidos, etc.) ────────
        if (!Schema::hasTable('plataforma_ajustes')) {
            Schema::create('plataforma_ajustes', function (Blueprint $t) {
                $t->string('clave', 60)->primary();
                $t->json('valor')->nullable();
                $t->timestamp('updated_at')->nullable();
            });
        }

        // ── Bitácora: toda acción de la consola ───────────────────────────
        if (!Schema::hasTable('plataforma_bitacora')) {
            Schema::create('plataforma_bitacora', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('usuario', 120)->nullable();
                $t->string('accion', 60)->index();
                $t->unsignedBigInteger('company_id')->nullable()->index();
                $t->string('entidad', 40)->nullable();
                $t->string('entidad_id', 40)->nullable();
                $t->json('detalle')->nullable();
                $t->string('ip', 45)->nullable();
                $t->timestamp('created_at')->nullable()->index();
            });
        }

        // ── Semilla: los planes que hoy están en config/plataforma.php ────
        //
        // Sin esto la tabla nace vacía y la página pública se quedaría sin
        // planes. Con los mismos precios y el mismo orden que ya se muestran.
        if (Schema::hasTable('plataforma_planes')
            && \Illuminate\Support\Facades\DB::table('plataforma_planes')->count() === 0) {
            $orden = 0;
            $ahora = now();

            foreach ((array) config('plataforma.planes', []) as $p) {
                \Illuminate\Support\Facades\DB::table('plataforma_planes')->insert([
                    'clave'          => $p['clave'],
                    'nombre'         => $p['nombre'],
                    'para'           => $p['para'] ?? null,
                    'precio_mensual' => $p['precio_mensual'] ?? null,
                    'precio_anual'   => $p['precio_anual'] ?? null,
                    'clientes'       => $p['clientes'] ?? null,
                    'destacado'      => !empty($p['destacado']),
                    'incluye'        => json_encode($p['incluye'] ?? [], JSON_UNESCAPED_UNICODE),
                    'activo'         => true,
                    'orden'          => $orden += 10,
                    'created_at'     => $ahora,
                    'updated_at'     => $ahora,
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach ([
            'plataforma_bitacora', 'plataforma_ajustes', 'plataforma_creditos', 'plataforma_referidos',
            'plataforma_cupon_usos', 'plataforma_cupones', 'plataforma_pagos', 'plataforma_cobros',
            'plataforma_suscripciones', 'plataforma_plan_precios', 'plataforma_planes',
        ] as $tabla) {
            Schema::dropIfExists($tabla);
        }

        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'plataforma_suspendida')) {
            Schema::table('companies', fn (Blueprint $t) => $t->dropColumn(['plataforma_suspendida', 'plataforma_suspendida_motivo']));
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'ultimo_ingreso')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('ultimo_ingreso'));
        }
    }
};
