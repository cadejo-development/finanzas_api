<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        // Usar SQL directo con IF NOT EXISTS para que sea seguro si ya se corrió manualmente
        DB::connection('compras')->statement("
            ALTER TABLE conteo_auditoria_items
              ADD COLUMN IF NOT EXISTS secciones_conteo      JSONB NOT NULL DEFAULT '{}',
              ADD COLUMN IF NOT EXISTS secciones_comprobadas JSONB NOT NULL DEFAULT '{}'
        ");
    }

    public function down(): void
    {
        Schema::connection('compras')->table('conteo_auditoria_items', function (Blueprint $table) {
            $table->dropColumn(['secciones_conteo', 'secciones_comprobadas']);
        });
    }
};
