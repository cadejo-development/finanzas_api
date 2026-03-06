<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UsersSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'name'        => 'Admin',
                'email'       => 'admin@demo.com',
                'password'    => Hash::make('admin123'),
                'activo'      => true,
                'aud_usuario' => 'seed',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'name'        => 'Gerente Sucursal',
                'email'       => 'gerente@demo.com',
                'password'    => Hash::make('gerente123'),
                'activo'      => true,
                'sucursal_id' => 1,
                'aud_usuario' => 'seed',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'name'        => 'Nelson Martinez',
                'email'       => 'nelson@demo.com',
                'password'    => Hash::make('nelson123'),
                'activo'      => true,
                'aud_usuario' => 'seed',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'name'        => 'Fabio Navarrete',
                'email'       => 'fabio@demo.com',
                'password'    => Hash::make('fabio123'),
                'activo'      => true,
                'aud_usuario' => 'seed',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'name'        => 'Gerencia del Area',
                'email'       => 'garea@demo.com',
                'password'    => Hash::make('garea123'),
                'activo'      => true,
                'aud_usuario' => 'seed',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'name'        => 'Juan Jose Lopez',
                'email'       => 'juanjose@demo.com',
                'password'    => Hash::make('juanjose123'),
                'activo'      => true,
                'aud_usuario' => 'seed',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'name'        => 'David Falkenstein',
                'email'       => 'david@demo.com',
                'password'    => Hash::make('david123'),
                'activo'      => true,
                'aud_usuario' => 'seed',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
        ];

        foreach ($users as $data) {
            User::updateOrCreate(['email' => $data['email']], $data);
        }
    }
}
