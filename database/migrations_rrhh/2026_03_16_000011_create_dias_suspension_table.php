<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): string { return 'rrhh'; }

    public function up(): void
    {
        if (!Schema::connection('rrhh')->hasTable('dias_suspension')) {
            Schema::connection('rrhh')->create('dias_suspension', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('amonestacion_id');
                $table->date('fecha')->comment('Fecha específica del día de suspensión');
                $table->string('aud_usuario', 150)->nullable();
                $table->timestamps();

                $table->index('amonestacion_id');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('dias_suspension');
    }
};
