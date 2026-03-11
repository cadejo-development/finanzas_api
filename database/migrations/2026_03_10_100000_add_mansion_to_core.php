<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1. Agregar role_id a users (para que mansion pueda asignar rol directamente)
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id')->nullable()->after('sucursal_id');
            $table->foreign('role_id')->references('id')->on('roles');
        });

        // 2. Agregar is_active a roles
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('system_id');
        });

        // 3. Insertar sistema mansion
        $mansionId = DB::table('systems')->insertGetId([
            'nombre'     => 'Mansion',
            'codigo'     => 'mansion',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 4. Insertar roles de mansion
        DB::table('roles')->insert([
            [
                'nombre'     => 'Administrador',
                'codigo'     => 'administrador',
                'system_id'  => $mansionId,
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nombre'     => 'Host',
                'codigo'     => 'host',
                'system_id'  => $mansionId,
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        // Eliminar roles y sistema mansion
        $mansionId = DB::table('systems')->where('codigo', 'mansion')->value('id');
        if ($mansionId) {
            DB::table('roles')->where('system_id', $mansionId)->delete();
            DB::table('systems')->where('id', $mansionId)->delete();
        }

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropColumn('role_id');
        });
    }
};
