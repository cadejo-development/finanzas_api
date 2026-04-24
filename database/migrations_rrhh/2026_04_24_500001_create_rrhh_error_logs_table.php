<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->create('error_logs', function (Blueprint $table) {
            $table->id();

            // ── Sistema / origen ──────────────────────────────────────────
            $table->string('sistema', 60)->default('RRHH');      // RRHH | COMPRAS | PAGOS …

            // ── Origen del error ──────────────────────────────────────────
            $table->string('controlador', 120)->nullable();
            $table->string('funcion', 120)->nullable();
            $table->string('metodo_http', 10)->nullable();       // GET, POST, PUT …
            $table->text('url')->nullable();                     // URL completa

            // ── Tipo de excepción ─────────────────────────────────────────
            $table->string('tipo_excepcion', 200)->nullable();   // ClassName
            $table->string('codigo_http', 5)->nullable();        // 422, 500 …
            $table->text('mensaje');                             // $e->getMessage()
            $table->text('trace')->nullable();                   // stack trace completo

            // ── Contexto de la request ────────────────────────────────────
            $table->jsonb('request_data')->nullable();           // body (sin password)
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();

            // ── Quién lo ejecutó ──────────────────────────────────────────
            $table->string('usuario_email', 200)->nullable();
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->unsignedBigInteger('empleado_id')->nullable();
            $table->string('departamento_codigo', 60)->nullable();
            $table->string('departamento_nombre', 150)->nullable();

            // ── Metadata ──────────────────────────────────────────────────
            $table->string('severidad', 20)->default('error');   // error | warning | info
            $table->boolean('resuelto')->default(false);
            $table->text('notas_resolucion')->nullable();
            $table->timestamp('resuelto_at')->nullable();
            $table->string('resuelto_por', 200)->nullable();

            $table->timestamps();

            $table->index('sistema');
            $table->index(['controlador', 'funcion']);
            $table->index('usuario_email');
            $table->index('created_at');
            $table->index('resuelto');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('error_logs');
    }
};
