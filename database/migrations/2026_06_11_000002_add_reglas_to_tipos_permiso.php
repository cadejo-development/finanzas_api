<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->table('tipos_permiso', function (Blueprint $table) {
            // Duración máxima por solicitud (no anual). Ej: paternidad=3, maternidad=112.
            $table->decimal('duracion_max_dias', 5, 1)->nullable()->after('max_dias');
            // Si el permiso debe tomarse solo en días completos (no horas)
            $table->boolean('solo_dias_completos')->default(true)->after('duracion_max_dias');
            // Si se debe adjuntar documento obligatoriamente
            $table->boolean('requiere_documento')->default(false)->after('solo_dias_completos');
            // Días hábiles de anticipación mínima para solicitar (ej: matrimonio=30)
            $table->unsignedInteger('anticipacion_min_dias')->nullable()->after('requiere_documento');
            // El permiso debe usarse dentro de N días calendario del evento (ej: paternidad=15)
            $table->unsignedInteger('dentro_de_dias_evento')->nullable()->after('anticipacion_min_dias');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('tipos_permiso', function (Blueprint $table) {
            $table->dropColumn([
                'duracion_max_dias',
                'solo_dias_completos',
                'requiere_documento',
                'anticipacion_min_dias',
                'dentro_de_dias_evento',
            ]);
        });
    }
};
