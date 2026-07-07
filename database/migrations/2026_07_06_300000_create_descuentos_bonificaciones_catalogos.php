<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        // ── Tipos de acreedor ─────────────────────────────────────────────
        Schema::connection('pgsql')->create('tipos_acreedor', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100);
            $table->boolean('activo')->default(true);
            $table->string('aud_usuario', 100)->default('sistema');
            $table->timestamps();
        });

        DB::connection('pgsql')->table('tipos_acreedor')->insert([
            ['nombre' => 'Banco',                       'activo' => true, 'aud_usuario' => 'sistema', 'created_at' => now(), 'updated_at' => now()],
            ['nombre' => 'Institución Gubernamental',   'activo' => true, 'aud_usuario' => 'sistema', 'created_at' => now(), 'updated_at' => now()],
            ['nombre' => 'Judicial',                    'activo' => true, 'aud_usuario' => 'sistema', 'created_at' => now(), 'updated_at' => now()],
            ['nombre' => 'Comercio',                    'activo' => true, 'aud_usuario' => 'sistema', 'created_at' => now(), 'updated_at' => now()],
            ['nombre' => 'Otro',                        'activo' => true, 'aud_usuario' => 'sistema', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // ── Estados de orden de descuento ─────────────────────────────────
        Schema::connection('pgsql')->create('estados_orden_descuento', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 60);
            $table->string('color', 30)->default('stone'); // para chips en UI
            $table->boolean('activo')->default(true);
            $table->string('aud_usuario', 100)->default('sistema');
            $table->timestamps();
        });

        DB::connection('pgsql')->table('estados_orden_descuento')->insert([
            ['nombre' => 'Activa',     'color' => 'emerald', 'activo' => true, 'aud_usuario' => 'sistema', 'created_at' => now(), 'updated_at' => now()],
            ['nombre' => 'Suspendida', 'color' => 'amber',   'activo' => true, 'aud_usuario' => 'sistema', 'created_at' => now(), 'updated_at' => now()],
            ['nombre' => 'Completada', 'color' => 'blue',    'activo' => true, 'aud_usuario' => 'sistema', 'created_at' => now(), 'updated_at' => now()],
            ['nombre' => 'Cancelada',  'color' => 'red',     'activo' => true, 'aud_usuario' => 'sistema', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // ── Acreedores ────────────────────────────────────────────────────
        Schema::connection('pgsql')->create('acreedores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tipo_acreedor_id')->constrained('tipos_acreedor');
            $table->string('nombre', 150);
            $table->boolean('activo')->default(true);
            $table->string('aud_usuario', 100)->default('sistema');
            $table->timestamps();
        });

        $tipos = DB::connection('pgsql')->table('tipos_acreedor')->pluck('id', 'nombre');
        DB::connection('pgsql')->table('acreedores')->insert([
            ['tipo_acreedor_id' => $tipos['Banco'],                     'nombre' => 'Banco Agrícola',                           'activo' => true, 'aud_usuario' => 'sistema', 'created_at' => now(), 'updated_at' => now()],
            ['tipo_acreedor_id' => $tipos['Banco'],                     'nombre' => 'Banco Davivienda',                         'activo' => true, 'aud_usuario' => 'sistema', 'created_at' => now(), 'updated_at' => now()],
            ['tipo_acreedor_id' => $tipos['Banco'],                     'nombre' => 'Banco G&T Continental',                    'activo' => true, 'aud_usuario' => 'sistema', 'created_at' => now(), 'updated_at' => now()],
            ['tipo_acreedor_id' => $tipos['Banco'],                     'nombre' => 'Banco Cuscatlán',                          'activo' => true, 'aud_usuario' => 'sistema', 'created_at' => now(), 'updated_at' => now()],
            ['tipo_acreedor_id' => $tipos['Banco'],                     'nombre' => 'Banco Hipotecario',                        'activo' => true, 'aud_usuario' => 'sistema', 'created_at' => now(), 'updated_at' => now()],
            ['tipo_acreedor_id' => $tipos['Institución Gubernamental'], 'nombre' => 'Fondo Social para la Vivienda (FSV)',       'activo' => true, 'aud_usuario' => 'sistema', 'created_at' => now(), 'updated_at' => now()],
            ['tipo_acreedor_id' => $tipos['Institución Gubernamental'], 'nombre' => 'Procuraduría General de la República (PGR)', 'activo' => true, 'aud_usuario' => 'sistema', 'created_at' => now(), 'updated_at' => now()],
            ['tipo_acreedor_id' => $tipos['Judicial'],                  'nombre' => 'Juzgado de Familia',                       'activo' => true, 'aud_usuario' => 'sistema', 'created_at' => now(), 'updated_at' => now()],
            ['tipo_acreedor_id' => $tipos['Judicial'],                  'nombre' => 'Juzgado de lo Civil',                      'activo' => true, 'aud_usuario' => 'sistema', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // ── Tipos de bonificación ─────────────────────────────────────────
        Schema::connection('pgsql')->create('tipos_bonificacion', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 120);
            $table->boolean('gravado')->default(false); // afecta cálculo renta
            $table->boolean('activo')->default(true);
            $table->string('aud_usuario', 100)->default('sistema');
            $table->timestamps();
        });

        DB::connection('pgsql')->table('tipos_bonificacion')->insert([
            ['nombre' => 'Bono de Productividad',       'gravado' => true,  'activo' => true, 'aud_usuario' => 'sistema', 'created_at' => now(), 'updated_at' => now()],
            ['nombre' => 'Bono Discrecional',           'gravado' => true,  'activo' => true, 'aud_usuario' => 'sistema', 'created_at' => now(), 'updated_at' => now()],
            ['nombre' => 'Bono de Asistencia Perfecta', 'gravado' => false, 'activo' => true, 'aud_usuario' => 'sistema', 'created_at' => now(), 'updated_at' => now()],
            ['nombre' => 'Bono de Antigüedad',          'gravado' => false, 'activo' => true, 'aud_usuario' => 'sistema', 'created_at' => now(), 'updated_at' => now()],
            ['nombre' => 'Aguinaldo Extra',             'gravado' => false, 'activo' => true, 'aud_usuario' => 'sistema', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // ── Estados de bonificación ───────────────────────────────────────
        Schema::connection('pgsql')->create('estados_bonificacion', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 60);
            $table->string('color', 30)->default('stone');
            $table->boolean('activo')->default(true);
            $table->string('aud_usuario', 100)->default('sistema');
            $table->timestamps();
        });

        DB::connection('pgsql')->table('estados_bonificacion')->insert([
            ['nombre' => 'Pendiente', 'color' => 'amber',   'activo' => true, 'aud_usuario' => 'sistema', 'created_at' => now(), 'updated_at' => now()],
            ['nombre' => 'Aprobada',  'color' => 'emerald', 'activo' => true, 'aud_usuario' => 'sistema', 'created_at' => now(), 'updated_at' => now()],
            ['nombre' => 'Rechazada', 'color' => 'red',     'activo' => true, 'aud_usuario' => 'sistema', 'created_at' => now(), 'updated_at' => now()],
            ['nombre' => 'Aplicada',  'color' => 'blue',    'activo' => true, 'aud_usuario' => 'sistema', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::connection('pgsql')->dropIfExists('acreedores');
        Schema::connection('pgsql')->dropIfExists('tipos_acreedor');
        Schema::connection('pgsql')->dropIfExists('estados_orden_descuento');
        Schema::connection('pgsql')->dropIfExists('tipos_bonificacion');
        Schema::connection('pgsql')->dropIfExists('estados_bonificacion');
    }
};
