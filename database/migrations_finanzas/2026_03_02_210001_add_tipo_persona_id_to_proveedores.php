<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): string { return 'pagos'; }

    public function up(): void
    {
        Schema::connection('pagos')->table('proveedores', function (Blueprint $table) {
            if (!Schema::connection('pagos')->hasColumn('proveedores', 'tipo_persona_id')) {
                // nullable para no romper registros existentes
                $table->unsignedBigInteger('tipo_persona_id')->nullable()->after('id');
            }
        });

        // Proveedores pre-existentes → Jurídica (id=2) por defecto
        DB::connection('pagos')
            ->table('proveedores')
            ->whereNull('tipo_persona_id')
            ->update(['tipo_persona_id' => 2]);
    }

    public function down(): void
    {
        Schema::connection('pagos')->table('proveedores', function (Blueprint $table) {
            $table->dropColumn('tipo_persona_id');
        });
    }
};
