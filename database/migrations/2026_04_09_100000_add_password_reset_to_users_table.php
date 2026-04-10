<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega soporte de reset de contraseña y cambio forzado de contraseña.
 *
 * force_password_change = true  →  el usuario DEBE cambiar su contraseña en el
 *                                   próximo inicio de sesión (una sola vez).
 * reset_code             →  código de 6 dígitos para recuperación por email.
 * reset_code_expires_at  →  expiración del código (15 minutos).
 *
 * Al ejecutar la migración se marcan TODOS los usuarios existentes con
 * force_password_change = true para garantizar que todos cambien su contraseña
 * inicial a una personalizada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('force_password_change')->default(false)->after('activo');
            $table->string('reset_code', 6)->nullable()->after('force_password_change');
            $table->timestamp('reset_code_expires_at')->nullable()->after('reset_code');
        });

        // ── Cambio forzado inicial: marcar todos los usuarios existentes ──
        DB::table('users')->update(['force_password_change' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['force_password_change', 'reset_code', 'reset_code_expires_at']);
        });
    }
};
