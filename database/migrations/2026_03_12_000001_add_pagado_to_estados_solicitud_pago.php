<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pagos';

    public function up(): void
    {
        $exists = DB::connection('pagos')
            ->table('estados_solicitud_pago')
            ->where('codigo', 'PAGADO')
            ->exists();

        if (!$exists) {
            DB::connection('pagos')->table('estados_solicitud_pago')->insert([
                'codigo'      => 'PAGADO',
                'nombre'      => 'Pagado',
                'descripcion' => 'Solicitud marcada como pagada por contabilidad.',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::connection('pagos')
            ->table('estados_solicitud_pago')
            ->where('codigo', 'PAGADO')
            ->delete();
    }
};
