<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Asigna sucursal_id a usuarios existentes que tengan NULL.
 * Mapeo basado en email (datos de seed / producción conocidos).
 * Es idempotente: solo actualiza si sucursal_id es NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mapa = [
            'gerente@demo.com'   => 1, // RESTAURANTE CASA GUIROLA → Guírola (S01)
            // Agregar más usuarios aquí cuando se creen gerentes para Santa Tecla / Multiplaza
            // 'gerente2@demo.com' => 2,
            // 'gerente3@demo.com' => 3,
        ];

        foreach ($mapa as $email => $sucursalId) {
            DB::table('users')
                ->where('email', $email)
                ->whereNull('sucursal_id')
                ->update(['sucursal_id' => $sucursalId]);
        }
    }

    public function down(): void
    {
        // Reversible: devuelve a NULL los usuarios modificados
        $emails = [
            'gerente@demo.com',
        ];

        DB::table('users')
            ->whereIn('email', $emails)
            ->update(['sucursal_id' => null]);
    }
};
