<?php

namespace Tests\Feature\RRHH;

use Tests\TestCase;

/**
 * Pruebas de contrato para los endpoints POST de RRHH.
 *
 * Estrategia: enviamos payloads vacíos o parciales para disparar las reglas
 * `required` ANTES de que el controlador llame a getJefeEmpleado() ni a
 * ninguna query de DB. Así los tests corren completamente en memoria, sin
 * necesitar conexión a PostgreSQL.
 *
 * Si un test falla aquí, significa que algún campo dejó de ser requerido en
 * el backend (o nunca lo fue), lo que reproduciría exactamente el bug de
 * "campo olvidado por el frontend".
 */
class RRHHValidationTest extends TestCase
{
    // ─── CAMBIOS SALARIALES ────────────────────────────────────────────────────

    public function test_cambios_salariales_store_rechaza_payload_vacio(): void
    {
        $response = $this->withoutMiddleware()
            ->postJson('/api/rrhh/cambios-salariales', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'empleado_id',
                'tipo_aumento_id',
                'salario_anterior',
                'salario_nuevo',
                'fecha_efectiva',
            ]);
    }

    public function test_cambios_salariales_store_rechaza_salario_nuevo_menor_o_igual(): void
    {
        // Omitimos tipo_aumento_id para no disparar query exists:
        // required falla para tipo_aumento_id, gt: falla para salario_nuevo → ambos en errores
        $response = $this->withoutMiddleware()
            ->postJson('/api/rrhh/cambios-salariales', [
                'empleado_id'      => 1,
                'salario_anterior' => 1000,
                'salario_nuevo'    => 500,   // menor que anterior
                'fecha_efectiva'   => '2025-01-01',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['salario_nuevo']);
    }

    public function test_cambios_salariales_store_rechaza_salario_nuevo_igual_al_anterior(): void
    {
        $response = $this->withoutMiddleware()
            ->postJson('/api/rrhh/cambios-salariales', [
                'empleado_id'      => 1,
                'salario_anterior' => 1000,
                'salario_nuevo'    => 1000,  // igual, no mayor
                'fecha_efectiva'   => '2025-01-01',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['salario_nuevo']);
    }

    // ─── DESVINCULACIONES ──────────────────────────────────────────────────────

    public function test_desvinculaciones_store_rechaza_payload_vacio(): void
    {
        $response = $this->withoutMiddleware()
            ->postJson('/api/rrhh/desvinculaciones', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'empleado_id',
                'motivo_id',
                'tipo',
                'fecha_efectiva',
            ]);
    }

    public function test_desvinculaciones_store_rechaza_tipo_invalido(): void
    {
        $response = $this->withoutMiddleware()
            ->postJson('/api/rrhh/desvinculaciones', [
                'empleado_id'    => 1,
                'tipo'           => 'jubilacion', // no permitido
                'fecha_efectiva' => '2025-01-01',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['tipo']);
    }

    // ─── TRASLADOS ─────────────────────────────────────────────────────────────

    public function test_traslados_store_rechaza_payload_vacio(): void
    {
        $response = $this->withoutMiddleware()
            ->postJson('/api/rrhh/traslados', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'empleado_id',
                'sucursal_destino_id',
                'fecha_efectiva',
            ]);
    }

    // ─── AMONESTACIONES ────────────────────────────────────────────────────────

    public function test_amonestaciones_store_rechaza_payload_vacio(): void
    {
        $response = $this->withoutMiddleware()
            ->postJson('/api/rrhh/amonestaciones', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'empleado_id',
                'tipo_falta_id',
                'fecha_amonestacion',
                'descripcion',
            ]);
    }

    public function test_amonestaciones_store_rechaza_descripcion_muy_larga(): void
    {
        $response = $this->withoutMiddleware()
            ->postJson('/api/rrhh/amonestaciones', [
                'empleado_id'        => 1,
                'fecha_amonestacion' => '2025-01-01',
                'descripcion'        => str_repeat('x', 1001), // max:1000
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['descripcion']);
    }

    // ─── VACACIONES ────────────────────────────────────────────────────────────

    public function test_vacaciones_store_rechaza_payload_vacio(): void
    {
        $response = $this->withoutMiddleware()
            ->postJson('/api/rrhh/vacaciones', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'empleado_id',
                'fecha_inicio',
                'fecha_fin',
                'dias',
            ]);
    }

    public function test_vacaciones_store_rechaza_dias_menor_a_medio(): void
    {
        $response = $this->withoutMiddleware()
            ->postJson('/api/rrhh/vacaciones', [
                'empleado_id'  => 1,
                'fecha_inicio' => '2025-06-01',
                'fecha_fin'    => '2025-06-05',
                'dias'         => 0.25, // min:0.5
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['dias']);
    }

    public function test_vacaciones_store_rechaza_fecha_fin_antes_de_inicio(): void
    {
        $response = $this->withoutMiddleware()
            ->postJson('/api/rrhh/vacaciones', [
                'empleado_id'  => 1,
                'fecha_inicio' => '2025-06-10',
                'fecha_fin'    => '2025-06-01', // anterior a inicio
                'dias'         => 5,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['fecha_fin']);
    }

    // ─── INCAPACIDADES ─────────────────────────────────────────────────────────

    public function test_incapacidades_store_rechaza_payload_vacio(): void
    {
        $response = $this->withoutMiddleware()
            ->postJson('/api/rrhh/incapacidades', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'empleado_id',
                'tipo_incapacidad_id',
                'fecha_inicio',
                'fecha_fin',
            ]);
    }

    public function test_incapacidades_store_acepta_sin_dias_y_los_calcula(): void
    {
        // Verificar que 'dias' ya no es requerido (se auto-calcula).
        // Sin tipo_incapacidad_id (exists:) el request fallará por eso, pero
        // NO debe fallar por ausencia de 'dias'.
        $response = $this->withoutMiddleware()
            ->postJson('/api/rrhh/incapacidades', [
                'empleado_id'  => 1,
                'fecha_inicio' => '2025-06-01',
                'fecha_fin'    => '2025-06-05',
                // sin 'dias' — debe calcularse automáticamente
            ]);

        // 422 por tipo_incapacidad_id (required), NO por dias
        $response->assertStatus(422);
        $errors = $response->json('errors');
        $this->assertArrayNotHasKey('dias', $errors,
            "'dias' no debe ser requerido; se auto-calcula en el backend.");
    }

    public function test_incapacidades_store_rechaza_fecha_fin_antes_de_inicio(): void
    {
        $response = $this->withoutMiddleware()
            ->postJson('/api/rrhh/incapacidades', [
                'empleado_id'  => 1,
                'fecha_inicio' => '2025-06-10',
                'fecha_fin'    => '2025-06-01',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['fecha_fin']);
    }

    // ─── PERMISOS ──────────────────────────────────────────────────────────────

    public function test_permisos_store_rechaza_payload_vacio(): void
    {
        $response = $this->withoutMiddleware()
            ->postJson('/api/rrhh/permisos', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'empleado_id',
                'tipo_permiso_id',
                'fecha',
            ]);
    }

    // ─── AUSENCIAS ─────────────────────────────────────────────────────────────

    public function test_ausencias_store_rechaza_payload_vacio(): void
    {
        $response = $this->withoutMiddleware()
            ->postJson('/api/rrhh/ausencias', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'empleado_id',
                'fecha',
            ]);
    }

    public function test_ausencias_store_rechaza_fecha_invalida(): void
    {
        $response = $this->withoutMiddleware()
            ->postJson('/api/rrhh/ausencias', [
                'empleado_id' => 1,
                'fecha'       => 'no-es-fecha',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['fecha']);
    }
}
