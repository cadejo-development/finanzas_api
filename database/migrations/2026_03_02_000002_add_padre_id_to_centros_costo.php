<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): string { return 'pgsql'; }

    public function up(): void
    {
        Schema::connection('pgsql')->table('centros_costo', function (Blueprint $table) {
            // Clave foránea auto-referencial para la jerarquía padre → hijo
            if (!Schema::connection('pgsql')->hasColumn('centros_costo', 'padre_id')) {
                $table->unsignedBigInteger('padre_id')->nullable()->after('id');
            }
            // ¿Es un sub-centro operativo (hoja)? Los padres/grupos tienen es_sub = false
            if (!Schema::connection('pgsql')->hasColumn('centros_costo', 'es_sub')) {
                $table->boolean('es_sub')->default(true)->after('padre_id');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->table('centros_costo', function (Blueprint $table) {
            $table->dropColumn(['padre_id', 'es_sub']);
        });
    }
};
