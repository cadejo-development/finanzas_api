<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        $user = DB::connection('pgsql')
            ->table('users')
            ->where('email', 'lourdesreyes@cervezacadejo.com')
            ->first();

        if (!$user) {
            return;
        }

        $rol = DB::connection('pgsql')
            ->table('roles')
            ->where('codigo', 'chef_recetas')
            ->first();

        if (!$rol) {
            return;
        }

        $yaAsignado = DB::connection('pgsql')
            ->table('role_user')
            ->where('user_id', $user->id)
            ->where('role_id', $rol->id)
            ->exists();

        if (!$yaAsignado) {
            DB::connection('pgsql')->table('role_user')->insert([
                'user_id'    => $user->id,
                'role_id'    => $rol->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $user = DB::connection('pgsql')
            ->table('users')
            ->where('email', 'lourdesreyes@cervezacadejo.com')
            ->first();

        $rol = DB::connection('pgsql')
            ->table('roles')
            ->where('codigo', 'chef_recetas')
            ->first();

        if ($user && $rol) {
            DB::connection('pgsql')
                ->table('role_user')
                ->where('user_id', $user->id)
                ->where('role_id', $rol->id)
                ->delete();
        }
    }
};
