<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        DB::connection('compras')->statement(
            "ALTER TABLE brew_lote_ingredientes DROP CONSTRAINT IF EXISTS brew_lote_ingredientes_estado_check"
        );
        DB::connection('compras')->statement(
            "ALTER TABLE brew_lote_ingredientes ADD CONSTRAINT brew_lote_ingredientes_estado_check
             CHECK (estado IN ('pendiente', 'en_proceso', 'parcial', 'agregado'))"
        );
    }

    public function down(): void
    {
        DB::connection('compras')->statement(
            "ALTER TABLE brew_lote_ingredientes DROP CONSTRAINT IF EXISTS brew_lote_ingredientes_estado_check"
        );
        DB::connection('compras')->statement(
            "ALTER TABLE brew_lote_ingredientes ADD CONSTRAINT brew_lote_ingredientes_estado_check
             CHECK (estado IN ('pendiente', 'en_proceso', 'agregado'))"
        );
    }
};
