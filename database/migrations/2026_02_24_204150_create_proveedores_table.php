    //
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::connection('pagos')->hasTable('proveedores')) {
            Schema::connection('pagos')->create('proveedores', function (Blueprint $table) {
                $table->id();
                $table->string('codigo')->unique();
                $table->string('nombre');
                $table->string('nit')->nullable();
                $table->string('nrc')->nullable();
                $table->string('telefono')->nullable();
                $table->string('direccion')->nullable();
                $table->string('cuenta_bancaria')->nullable();
                $table->string('tipo_cuenta')->nullable();
                $table->string('banco')->nullable();
                $table->string('correo')->nullable();
                $table->boolean('activo')->default(true);
                $table->string('aud_usuario')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('pagos')->dropIfExists('proveedores');
    }
};
