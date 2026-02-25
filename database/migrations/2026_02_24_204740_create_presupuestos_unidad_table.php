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
        Schema::connection('pagos')->create('presupuestos_unidad', function (Blueprint $table) {
            $table->id();
            $table->foreignId('centro_costo_id')->constrained('centros_costo');
            $table->integer('anio');
            $table->decimal('presupuesto_total', 12, 2);
            $table->decimal('ejecutado', 12, 2)->default(0);
            $table->string('aud_usuario')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('pagos')->dropIfExists('presupuestos_unidad');
    }
};
