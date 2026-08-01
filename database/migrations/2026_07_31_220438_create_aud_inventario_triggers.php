<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        // ── Usar hora El Salvador (UTC-6, sin DST) en fecha_accion ───────────
        DB::connection('compras')->unprepared("SET timezone = 'America/El_Salvador'");

        // ══════════════════════════════════════════════════════════════════════
        // 1. AUD_INVENTARIOS
        // ══════════════════════════════════════════════════════════════════════
        DB::connection('compras')->unprepared("
            CREATE TABLE IF NOT EXISTS aud_inventarios (
                aud_id              BIGSERIAL PRIMARY KEY,
                accion              CHAR(1)   NOT NULL,
                fecha_accion        TIMESTAMP NOT NULL DEFAULT (NOW() AT TIME ZONE 'America/El_Salvador'),
                db_user             TEXT      DEFAULT current_user,
                -- columnas originales
                id                  BIGINT,
                sucursal_id         BIGINT,
                producto_id         BIGINT,
                cantidad_inicial    NUMERIC,
                unidad              VARCHAR,
                cantidad_inicial_base NUMERIC,
                fecha_conteo        DATE,
                stock_minimo        NUMERIC,
                seccion             VARCHAR,
                brilo_stock         NUMERIC,
                brilo_sync_at       TIMESTAMP,
                activo              BOOLEAN,
                prod_seg            BOOLEAN,
                aud_usuario         VARCHAR,
                created_at          TIMESTAMP,
                updated_at          TIMESTAMP
            )
        ");

        DB::connection('compras')->unprepared("
            CREATE OR REPLACE FUNCTION fn_aud_inventarios() RETURNS TRIGGER LANGUAGE plpgsql AS \$\$
            DECLARE r inventarios%ROWTYPE;
            BEGIN
                IF (TG_OP = 'DELETE') THEN r := OLD; ELSE r := NEW; END IF;
                INSERT INTO aud_inventarios (
                    accion, id, sucursal_id, producto_id, cantidad_inicial, unidad,
                    cantidad_inicial_base, fecha_conteo, stock_minimo, seccion,
                    brilo_stock, brilo_sync_at, activo, prod_seg, aud_usuario, created_at, updated_at
                ) VALUES (
                    CASE TG_OP WHEN 'INSERT' THEN 'I' WHEN 'UPDATE' THEN 'U' ELSE 'D' END,
                    r.id, r.sucursal_id, r.producto_id, r.cantidad_inicial, r.unidad,
                    r.cantidad_inicial_base, r.fecha_conteo, r.stock_minimo, r.seccion,
                    r.brilo_stock, r.brilo_sync_at, r.activo, r.prod_seg, r.aud_usuario, r.created_at, r.updated_at
                );
                RETURN r;
            END;
            \$\$
        ");

        DB::connection('compras')->unprepared("
            DROP TRIGGER IF EXISTS trg_aud_inventarios ON inventarios;
            CREATE TRIGGER trg_aud_inventarios
            AFTER INSERT OR UPDATE OR DELETE ON inventarios
            FOR EACH ROW EXECUTE FUNCTION fn_aud_inventarios()
        ");

        // ══════════════════════════════════════════════════════════════════════
        // 2. AUD_MOVIMIENTOS_INVENTARIO
        // ══════════════════════════════════════════════════════════════════════
        DB::connection('compras')->unprepared("
            CREATE TABLE IF NOT EXISTS aud_movimientos_inventario (
                aud_id          BIGSERIAL PRIMARY KEY,
                accion          CHAR(1)   NOT NULL,
                fecha_accion    TIMESTAMP NOT NULL DEFAULT (NOW() AT TIME ZONE 'America/El_Salvador'),
                db_user         TEXT      DEFAULT current_user,
                -- columnas originales
                id              BIGINT,
                sucursal_id     BIGINT,
                producto_id     BIGINT,
                tipo            VARCHAR,
                cantidad        NUMERIC,
                unidad          VARCHAR,
                cantidad_base   NUMERIC,
                motivo          VARCHAR,
                fecha           DATE,
                referencia_tipo VARCHAR,
                referencia_id   BIGINT,
                detalle         JSONB,
                aud_usuario     VARCHAR,
                created_at      TIMESTAMP,
                updated_at      TIMESTAMP
            )
        ");

        DB::connection('compras')->unprepared("
            CREATE OR REPLACE FUNCTION fn_aud_movimientos_inventario() RETURNS TRIGGER LANGUAGE plpgsql AS \$\$
            DECLARE r movimientos_inventario%ROWTYPE;
            BEGIN
                IF (TG_OP = 'DELETE') THEN r := OLD; ELSE r := NEW; END IF;
                INSERT INTO aud_movimientos_inventario (
                    accion, id, sucursal_id, producto_id, tipo, cantidad, unidad,
                    cantidad_base, motivo, fecha, referencia_tipo, referencia_id,
                    detalle, aud_usuario, created_at, updated_at
                ) VALUES (
                    CASE TG_OP WHEN 'INSERT' THEN 'I' WHEN 'UPDATE' THEN 'U' ELSE 'D' END,
                    r.id, r.sucursal_id, r.producto_id, r.tipo, r.cantidad, r.unidad,
                    r.cantidad_base, r.motivo, r.fecha, r.referencia_tipo, r.referencia_id,
                    r.detalle, r.aud_usuario, r.created_at, r.updated_at
                );
                RETURN r;
            END;
            \$\$
        ");

        DB::connection('compras')->unprepared("
            DROP TRIGGER IF EXISTS trg_aud_movimientos_inventario ON movimientos_inventario;
            CREATE TRIGGER trg_aud_movimientos_inventario
            AFTER INSERT OR UPDATE OR DELETE ON movimientos_inventario
            FOR EACH ROW EXECUTE FUNCTION fn_aud_movimientos_inventario()
        ");

        // ══════════════════════════════════════════════════════════════════════
        // 3. AUD_PEDIDOS
        // ══════════════════════════════════════════════════════════════════════
        DB::connection('compras')->unprepared("
            CREATE TABLE IF NOT EXISTS aud_pedidos (
                aud_id          BIGSERIAL PRIMARY KEY,
                accion          CHAR(1)   NOT NULL,
                fecha_accion    TIMESTAMP NOT NULL DEFAULT (NOW() AT TIME ZONE 'America/El_Salvador'),
                db_user         TEXT      DEFAULT current_user,
                -- columnas originales
                id              BIGINT,
                sucursal_id     BIGINT,
                centro_costo_id BIGINT,
                semana_inicio   DATE,
                semana_fin      DATE,
                estado          VARCHAR,
                total_estimado  NUMERIC,
                aud_usuario     VARCHAR,
                created_at      TIMESTAMP,
                updated_at      TIMESTAMP
            )
        ");

        DB::connection('compras')->unprepared("
            CREATE OR REPLACE FUNCTION fn_aud_pedidos() RETURNS TRIGGER LANGUAGE plpgsql AS \$\$
            DECLARE r pedidos%ROWTYPE;
            BEGIN
                IF (TG_OP = 'DELETE') THEN r := OLD; ELSE r := NEW; END IF;
                INSERT INTO aud_pedidos (
                    accion, id, sucursal_id, centro_costo_id, semana_inicio, semana_fin,
                    estado, total_estimado, aud_usuario, created_at, updated_at
                ) VALUES (
                    CASE TG_OP WHEN 'INSERT' THEN 'I' WHEN 'UPDATE' THEN 'U' ELSE 'D' END,
                    r.id, r.sucursal_id, r.centro_costo_id, r.semana_inicio, r.semana_fin,
                    r.estado, r.total_estimado, r.aud_usuario, r.created_at, r.updated_at
                );
                RETURN r;
            END;
            \$\$
        ");

        DB::connection('compras')->unprepared("
            DROP TRIGGER IF EXISTS trg_aud_pedidos ON pedidos;
            CREATE TRIGGER trg_aud_pedidos
            AFTER INSERT OR UPDATE OR DELETE ON pedidos
            FOR EACH ROW EXECUTE FUNCTION fn_aud_pedidos()
        ");

        // ══════════════════════════════════════════════════════════════════════
        // 4. AUD_PEDIDO_DETALLE
        // ══════════════════════════════════════════════════════════════════════
        DB::connection('compras')->unprepared("
            CREATE TABLE IF NOT EXISTS aud_pedido_detalle (
                aud_id          BIGSERIAL PRIMARY KEY,
                accion          CHAR(1)   NOT NULL,
                fecha_accion    TIMESTAMP NOT NULL DEFAULT (NOW() AT TIME ZONE 'America/El_Salvador'),
                db_user         TEXT      DEFAULT current_user,
                -- columnas originales
                id              BIGINT,
                pedido_id       BIGINT,
                producto_id     BIGINT,
                cantidad        NUMERIC,
                unidad          VARCHAR,
                nota            TEXT,
                justificacion   TEXT,
                precio_unitario NUMERIC,
                subtotal        NUMERIC,
                aud_usuario     VARCHAR,
                created_at      TIMESTAMP,
                updated_at      TIMESTAMP
            )
        ");

        DB::connection('compras')->unprepared("
            CREATE OR REPLACE FUNCTION fn_aud_pedido_detalle() RETURNS TRIGGER LANGUAGE plpgsql AS \$\$
            DECLARE r pedido_detalle%ROWTYPE;
            BEGIN
                IF (TG_OP = 'DELETE') THEN r := OLD; ELSE r := NEW; END IF;
                INSERT INTO aud_pedido_detalle (
                    accion, id, pedido_id, producto_id, cantidad, unidad, nota,
                    justificacion, precio_unitario, subtotal, aud_usuario, created_at, updated_at
                ) VALUES (
                    CASE TG_OP WHEN 'INSERT' THEN 'I' WHEN 'UPDATE' THEN 'U' ELSE 'D' END,
                    r.id, r.pedido_id, r.producto_id, r.cantidad, r.unidad, r.nota,
                    r.justificacion, r.precio_unitario, r.subtotal, r.aud_usuario, r.created_at, r.updated_at
                );
                RETURN r;
            END;
            \$\$
        ");

        DB::connection('compras')->unprepared("
            DROP TRIGGER IF EXISTS trg_aud_pedido_detalle ON pedido_detalle;
            CREATE TRIGGER trg_aud_pedido_detalle
            AFTER INSERT OR UPDATE OR DELETE ON pedido_detalle
            FOR EACH ROW EXECUTE FUNCTION fn_aud_pedido_detalle()
        ");

        // ══════════════════════════════════════════════════════════════════════
        // 5. AUD_CONTEO_BORRADORES
        // ══════════════════════════════════════════════════════════════════════
        DB::connection('compras')->unprepared("
            CREATE TABLE IF NOT EXISTS aud_conteo_borradores (
                aud_id          BIGSERIAL PRIMARY KEY,
                accion          CHAR(1)   NOT NULL,
                fecha_accion    TIMESTAMP NOT NULL DEFAULT (NOW() AT TIME ZONE 'America/El_Salvador'),
                db_user         TEXT      DEFAULT current_user,
                -- columnas originales
                id              BIGINT,
                sucursal_id     INTEGER,
                fecha_conteo    DATE,
                estado          VARCHAR,
                aplicado_en     TIMESTAMP,
                payload         JSONB,
                aud_usuario     VARCHAR,
                created_at      TIMESTAMP,
                updated_at      TIMESTAMP
            )
        ");

        DB::connection('compras')->unprepared("
            CREATE OR REPLACE FUNCTION fn_aud_conteo_borradores() RETURNS TRIGGER LANGUAGE plpgsql AS \$\$
            DECLARE r conteo_borradores%ROWTYPE;
            BEGIN
                IF (TG_OP = 'DELETE') THEN r := OLD; ELSE r := NEW; END IF;
                INSERT INTO aud_conteo_borradores (
                    accion, id, sucursal_id, fecha_conteo, estado, aplicado_en,
                    payload, aud_usuario, created_at, updated_at
                ) VALUES (
                    CASE TG_OP WHEN 'INSERT' THEN 'I' WHEN 'UPDATE' THEN 'U' ELSE 'D' END,
                    r.id, r.sucursal_id, r.fecha_conteo, r.estado, r.aplicado_en,
                    r.payload, r.aud_usuario, r.created_at, r.updated_at
                );
                RETURN r;
            END;
            \$\$
        ");

        DB::connection('compras')->unprepared("
            DROP TRIGGER IF EXISTS trg_aud_conteo_borradores ON conteo_borradores;
            CREATE TRIGGER trg_aud_conteo_borradores
            AFTER INSERT OR UPDATE OR DELETE ON conteo_borradores
            FOR EACH ROW EXECUTE FUNCTION fn_aud_conteo_borradores()
        ");
    }

    public function down(): void
    {
        $drops = [
            'DROP TRIGGER IF EXISTS trg_aud_inventarios ON inventarios',
            'DROP TRIGGER IF EXISTS trg_aud_movimientos_inventario ON movimientos_inventario',
            'DROP TRIGGER IF EXISTS trg_aud_pedidos ON pedidos',
            'DROP TRIGGER IF EXISTS trg_aud_pedido_detalle ON pedido_detalle',
            'DROP TRIGGER IF EXISTS trg_aud_conteo_borradores ON conteo_borradores',
            'DROP FUNCTION IF EXISTS fn_aud_inventarios()',
            'DROP FUNCTION IF EXISTS fn_aud_movimientos_inventario()',
            'DROP FUNCTION IF EXISTS fn_aud_pedidos()',
            'DROP FUNCTION IF EXISTS fn_aud_pedido_detalle()',
            'DROP FUNCTION IF EXISTS fn_aud_conteo_borradores()',
            'DROP TABLE IF EXISTS aud_inventarios',
            'DROP TABLE IF EXISTS aud_movimientos_inventario',
            'DROP TABLE IF EXISTS aud_pedidos',
            'DROP TABLE IF EXISTS aud_pedido_detalle',
            'DROP TABLE IF EXISTS aud_conteo_borradores',
        ];
        foreach ($drops as $sql) {
            DB::connection('compras')->unprepared($sql);
        }
    }
};
