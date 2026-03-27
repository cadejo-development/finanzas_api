<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        // ── Datos personales (1:1 por empleado) ──────────────────────────────
        Schema::connection('rrhh')->create('expediente_datos_personales', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('empleado_id')->unique();
            $table->date('fecha_nacimiento')->nullable();
            $table->string('genero', 20)->nullable();          // masculino/femenino/otro
            $table->string('estado_civil', 30)->nullable();    // soltero/casado/divorciado/viudo/union_libre
            $table->string('nacionalidad', 60)->nullable();
            $table->string('grupo_sanguineo', 5)->nullable();  // A+/A-/B+/B-/AB+/AB-/O+/O-
            $table->string('lugar_nacimiento', 150)->nullable();
            $table->text('notas')->nullable();
            $table->string('aud_usuario', 150)->nullable();
            $table->timestamps();
        });

        // ── Contactos (N por empleado) ────────────────────────────────────────
        Schema::connection('rrhh')->create('expediente_contactos', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('empleado_id');
            // tipo: telefono / email / whatsapp / emergencia
            $table->string('tipo', 30);
            $table->string('etiqueta', 60)->nullable();        // "Personal", "Trabajo", "Familiar"
            $table->string('valor', 150);                       // número o correo
            $table->string('nombre_contacto', 120)->nullable(); // para contactos de emergencia
            $table->boolean('es_emergencia')->default(false);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();

            $table->index('empleado_id');
        });

        // ── Direcciones (N por empleado) ──────────────────────────────────────
        Schema::connection('rrhh')->create('expediente_direcciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('empleado_id');
            $table->string('tipo', 30)->default('residencia');  // residencia / trabajo
            $table->string('departamento_geo', 80)->nullable(); // departamento del país (≠ dept. empresa)
            $table->string('municipio', 80)->nullable();
            $table->string('direccion', 255);
            $table->string('referencia', 255)->nullable();
            $table->boolean('es_principal')->default(false);
            $table->timestamps();

            $table->index('empleado_id');
        });

        // ── Documentos de identidad (N por empleado) ──────────────────────────
        Schema::connection('rrhh')->create('expediente_documentos', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('empleado_id');
            // tipo: dui / nit / isss / afp / pasaporte / licencia_conducir / otro
            $table->string('tipo', 40);
            $table->string('numero', 60)->nullable();
            $table->date('fecha_emision')->nullable();
            $table->date('fecha_vencimiento')->nullable();
            $table->string('entidad_emisora', 120)->nullable();
            $table->string('notas', 255)->nullable();
            $table->timestamps();

            $table->index('empleado_id');
        });

        // ── Estudios y formación (N por empleado) ─────────────────────────────
        Schema::connection('rrhh')->create('expediente_estudios', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('empleado_id');
            // nivel: primaria / bachillerato / tecnico / universitario / posgrado /
            //        maestria / doctorado / diplomado / curso / otro
            $table->string('nivel', 40);
            $table->string('titulo', 200);
            $table->string('institucion', 200);
            $table->string('pais', 80)->default('El Salvador');
            $table->unsignedSmallInteger('anio_inicio')->nullable();
            $table->unsignedSmallInteger('anio_graduacion')->nullable();
            $table->boolean('graduado')->default(false);
            $table->string('notas', 255)->nullable();
            $table->timestamps();

            $table->index('empleado_id');
        });

        // ── Archivos adjuntos (N por empleado) ────────────────────────────────
        Schema::connection('rrhh')->create('expediente_archivos', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('empleado_id');
            // tipo: foto_perfil / contrato / atestado / certificado / evaluacion /
            //       documento_identidad / otro
            $table->string('tipo', 40)->default('otro');
            $table->string('nombre', 200);
            $table->string('descripcion', 255)->nullable();
            $table->string('archivo_ruta', 500);
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('tamano_kb')->nullable();
            $table->unsignedInteger('subido_por_id')->nullable(); // user_id
            $table->timestamps();

            $table->index('empleado_id');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('expediente_archivos');
        Schema::connection('rrhh')->dropIfExists('expediente_estudios');
        Schema::connection('rrhh')->dropIfExists('expediente_documentos');
        Schema::connection('rrhh')->dropIfExists('expediente_direcciones');
        Schema::connection('rrhh')->dropIfExists('expediente_contactos');
        Schema::connection('rrhh')->dropIfExists('expediente_datos_personales');
    }
};
