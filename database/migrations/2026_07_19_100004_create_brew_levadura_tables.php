<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->create('brew_levadura_lotes', function (Blueprint $table) {
            $table->id();
            $table->string('cepa', 100)->comment('Nombre de la cepa / código de laboratorio');
            $table->string('codigo_lab', 50)->nullable()->comment('Código del proveedor: S-04, WB-06, etc.');
            $table->string('proveedor', 100)->nullable()->comment('Fermentis, Lallemand, White Labs, etc.');
            $table->decimal('precio_total_usd', 10, 4)->nullable()->comment('Precio total del bloque comprado');
            $table->decimal('vol_total_ml', 10, 2)->nullable()->comment('Volumen/peso total comprado (mL o g)');
            $table->date('fecha_inicio')->nullable()->comment('Fecha de apertura / activación');
            $table->date('fecha_vencimiento')->nullable();
            $table->boolean('activo')->default(true);
            $table->text('notas')->nullable();
            $table->timestamps();
        });

        Schema::connection('compras')->create('brew_levadura_pitches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brew_levadura_lote_id')->constrained('brew_levadura_lotes')->cascadeOnDelete();
            $table->foreignId('brew_lote_id')->nullable()->constrained('brew_lotes')->nullOnDelete();
            $table->integer('numero_generacion')->default(1)->comment('Número de generación de este pitch');
            $table->date('fecha');
            $table->decimal('vol_pitch_ml', 10, 2)->nullable()->comment('Volumen/peso pitcheado (mL o g)');
            $table->decimal('viabilidad_pct', 5, 2)->nullable()->comment('% viabilidad estimada');
            $table->decimal('og_pitch', 6, 4)->nullable()->comment('OG al momento del pitch (SG)');
            $table->decimal('temp_pitch', 5, 1)->nullable()->comment('Temperatura al pitch °C');
            $table->decimal('costo_estimado_usd', 10, 4)->nullable()->comment('Costo proporcional estimado');
            $table->text('notas')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('brew_levadura_pitches');
        Schema::connection('compras')->dropIfExists('brew_levadura_lotes');
    }
};
