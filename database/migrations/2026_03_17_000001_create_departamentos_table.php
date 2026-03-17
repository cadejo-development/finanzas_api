<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): string { return 'pgsql'; }

    public function up(): void
    {
        if (!Schema::connection('pgsql')->hasTable('departamentos')) {
            Schema::connection('pgsql')->create('departamentos', function (Blueprint $table) {
                $table->id();
                $table->string('codigo', 30)->unique();
                $table->string('nombre', 150);
                $table->text('descripcion')->nullable();
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->unsignedBigInteger('sucursal_id')->nullable();
                // Sin FK a empleados para evitar dependencia circular
                $table->unsignedBigInteger('jefe_empleado_id')->nullable();
                $table->boolean('activo')->default(true);
                $table->string('aud_usuario', 150)->nullable();
                $table->timestamps();

                $table->foreign('parent_id', 'dept_parent_fk')
                    ->references('id')->on('departamentos')->nullOnDelete();
                $table->foreign('sucursal_id', 'dept_sucursal_fk')
                    ->references('id')->on('sucursales')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::connection('pgsql')->dropIfExists('departamentos');
    }
};
