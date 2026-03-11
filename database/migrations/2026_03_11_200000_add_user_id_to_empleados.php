<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('activo');
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->unique('user_id'); // 1 empleado : 1 usuario
        });

        // Migrar vínculos existentes usando el JOIN por email (una sola vez)
        DB::statement("
            UPDATE empleados
            SET user_id = u.id
            FROM users u
            WHERE LOWER(u.email) = LOWER(empleados.email)
              AND empleados.user_id IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropUnique(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
