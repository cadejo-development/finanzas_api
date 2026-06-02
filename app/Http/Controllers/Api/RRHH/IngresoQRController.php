<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Http\Controllers\Controller;
use App\Models\RRHH\IngresoQrRegistro;
use App\Models\RRHH\IngresoQrToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class IngresoQRController extends Controller
{
    private const RRHH_SYSTEM_ID = 5;

    private const NIVELES_ACADEMICOS = [
        'primaria'      => 'Educación Primaria',
        'bachillerato'  => 'Bachillerato',
        'tecnico'       => 'Técnico',
        'universitario' => 'Universitario (Licenciatura / Ingeniería)',
        'posgrado'      => 'Posgrado',
        'maestria'      => 'Maestría',
        'doctorado'     => 'Doctorado',
        'diplomado'     => 'Diplomado',
        'curso'         => 'Curso técnico / Certificación',
        'otro'          => 'Otro',
    ];

    // ── Admin: generar token QR ───────────────────────────────────────────────

    public function generar(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user || !$user->hasRole('rrhh_admin', self::RRHH_SYSTEM_ID)) {
            return response()->json(['error' => 'No autorizado.'], 403);
        }

        $token = IngresoQrToken::create([
            'token'                 => (string) Str::uuid(),
            'generado_por_user_id'  => $user->id,
            'expires_at'            => now()->addHours(24),
        ]);

        $frontendUrl = rtrim(config('app.frontend_rrhh_url', 'https://www.talentohumano.cervezacadejo.com'), '/');
        $url         = "{$frontendUrl}/registro/{$token->token}";

        return response()->json([
            'token'      => $token->token,
            'url'        => $url,
            'expires_at' => $token->expires_at->toISOString(),
            'generado_por' => $user->name ?? $user->email,
        ]);
    }

    // ── Admin: listar tokens generados ───────────────────────────────────────

    public function listar(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user || !$user->hasRole('rrhh_admin', self::RRHH_SYSTEM_ID)) {
            return response()->json(['error' => 'No autorizado.'], 403);
        }

        $tokens = IngresoQrToken::with('registros')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn($t) => [
                'id'          => $t->id,
                'token'       => $t->token,
                'expires_at'  => $t->expires_at->toISOString(),
                'vigente'     => $t->isVigente(),
                'registros'   => $t->registros->count(),
                'created_at'  => $t->created_at->toISOString(),
            ]);

        return response()->json(['data' => $tokens]);
    }

    // ── Público: validar token ────────────────────────────────────────────────

    public function validar(string $token): JsonResponse
    {
        $registro = IngresoQrToken::where('token', $token)->first();

        if (!$registro) {
            return response()->json(['valido' => false, 'mensaje' => 'Enlace no válido.'], 404);
        }

        if (!$registro->isVigente()) {
            return response()->json(['valido' => false, 'mensaje' => 'Este enlace ha expirado. Solicita uno nuevo al área de RRHH.'], 410);
        }

        return response()->json([
            'valido'            => true,
            'expires_at'        => $registro->expires_at->toISOString(),
            'niveles_academicos' => self::NIVELES_ACADEMICOS,
        ]);
    }

    // ── Público: guardar registro ─────────────────────────────────────────────

    public function registrar(Request $request, string $token): JsonResponse
    {
        $qrToken = IngresoQrToken::where('token', $token)->first();

        if (!$qrToken) {
            return response()->json(['error' => 'Enlace no válido.'], 404);
        }

        if (!$qrToken->isVigente()) {
            return response()->json(['error' => 'Este enlace ha expirado.'], 410);
        }

        $data = $request->validate([
            'nombres'                => 'required|string|max:120',
            'apellidos'              => 'required|string|max:120',
            'fecha_nacimiento'       => 'nullable|date|before:today',
            'genero'                 => 'nullable|in:masculino,femenino,otro',
            'estado_civil'           => 'nullable|in:soltero,casado,divorciado,viudo,union_libre',
            'lugar_nacimiento'       => 'nullable|string|max:150',
            'telefono'               => 'nullable|string|max:30',
            'email'                  => 'nullable|email|max:120',
            'direccion'              => 'nullable|string|max:500',
            'dui'                    => 'nullable|string|max:20',
            'nit'                    => 'nullable|string|max:20',
            'afp_nombre'             => 'nullable|in:AFP CONFÍA,AFP CRECER',
            'afp_numero'             => 'nullable|string|max:30',
            'isss_numero'            => 'nullable|string|max:30',
            'ultimo_nivel_academico' => 'nullable|in:primaria,bachillerato,tecnico,universitario,posgrado,maestria,doctorado,diplomado,curso,otro',
            'titulo_academico'       => 'nullable|string|max:200',
            'institucion_academica'  => 'nullable|string|max:200',
            'graduado'               => 'nullable|boolean',
        ]);

        $registro = IngresoQrRegistro::create(array_merge($data, [
            'qr_token_id' => $qrToken->id,
            'ip_address'  => $request->ip(),
            'origen'      => 'qr_formulario',
        ]));

        return response()->json([
            'success'  => true,
            'mensaje'  => 'Tu información fue registrada correctamente. El equipo de RRHH se pondrá en contacto contigo.',
            'registro_id' => $registro->id,
        ], 201);
    }
}
