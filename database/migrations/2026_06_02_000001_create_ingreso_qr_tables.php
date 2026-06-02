<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        // Tokens QR generados por admins (24 h de vigencia)
        Schema::connection('rrhh')->create('ingreso_qr_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->unsignedBigInteger('generado_por_user_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('usado_at')->nullable();
            $table->timestamps();

            $table->index('token');
        });

        // Pre-registros enviados a través del formulario público del QR
        Schema::connection('rrhh')->create('ingreso_qr_registros', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('qr_token_id')->nullable();
            $table->foreign('qr_token_id')->references('id')->on('ingreso_qr_tokens')->nullOnDelete();

            // Información personal
            $table->string('nombres', 120);
            $table->string('apellidos', 120);
            $table->date('fecha_nacimiento')->nullable();
            $table->string('genero', 20)->nullable();         // masculino / femenino / otro
            $table->string('estado_civil', 30)->nullable();   // soltero / casado / divorciado / viudo / union_libre
            $table->string('lugar_nacimiento', 150)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('email', 120)->nullable();
            $table->text('direccion')->nullable();

            // Documentos
            $table->string('dui', 20)->nullable();
            $table->string('nit', 20)->nullable();
            $table->string('afp_nombre', 50)->nullable();     // AFP CONFÍA / AFP CRECER
            $table->string('afp_numero', 30)->nullable();
            $table->string('isss_numero', 30)->nullable();

            // Estudios
            $table->string('ultimo_nivel_academico', 40)->nullable();
            $table->string('titulo_academico', 200)->nullable();
            $table->string('institucion_academica', 200)->nullable();
            $table->boolean('graduado')->default(false);

            // Meta
            $table->string('ip_address', 45)->nullable();
            $table->string('origen', 30)->default('qr_formulario');
            $table->timestamps();

            $table->index('qr_token_id');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('ingreso_qr_registros');
        Schema::connection('rrhh')->dropIfExists('ingreso_qr_tokens');
    }
};
