<?php

namespace App\Http\Controllers\Api\Ventas;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    // Mock users para el prototipo
    private array $users = [
        ['username' => 'vendedor', 'password' => 'cadejo2026', 'nombre' => 'Vendedor Demo', 'rol' => 'vendedor'],
        ['username' => 'rodrigo',  'password' => 'cadejo2026', 'nombre' => 'Rodrigo Jefe',  'rol' => 'jefe_ventas'],
    ];

    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = collect($this->users)->firstWhere('username', $request->username);

        if (!$user || $user['password'] !== $request->password) {
            return response()->json(['message' => 'Credenciales incorrectas'], 401);
        }

        return response()->json([
            'token'  => base64_encode($user['username'] . ':' . time()),
            'nombre' => $user['nombre'],
            'rol'    => $user['rol'],
        ]);
    }
}
