<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql')->table('users', function (Blueprint $table) {
            $table->boolean('recibe_notif_rrhh')->default(true)->after('email');
        });

        // David no debe recibir notificaciones de ingreso de personal
        DB::connection('pgsql')->table('users')
            ->where('email', 'david@cervezacadejo.com')
            ->update(['recibe_notif_rrhh' => false]);
    }

    public function down(): void
    {
        Schema::connection('pgsql')->table('users', function (Blueprint $table) {
            $table->dropColumn('recibe_notif_rrhh');
        });
    }
};
