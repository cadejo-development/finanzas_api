-- ============================================================
-- MÓDULO DE AUDITORÍA DE RECETAS — compras_db
-- Tablas: estaciones, auditorias_receta, auditoria_fotos
-- ============================================================

-- ─────────────────────────────────────────────────────────────
-- 1. ESTACIONES (sync desde Brilo olRestaurante.CocinasRst)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS estaciones (
    id             BIGSERIAL PRIMARY KEY,
    codigo         VARCHAR(30),
    nombre         VARCHAR(150) NOT NULL,
    activa         BOOLEAN      NOT NULL DEFAULT TRUE,
    codigo_origen  VARCHAR(50)  UNIQUE,        -- ccirstId de Brilo
    sucursal_id    INTEGER,                    -- FK a core_db.sucursales
    created_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS estaciones_sucursal_idx ON estaciones (sucursal_id);
CREATE INDEX IF NOT EXISTS estaciones_activa_idx   ON estaciones (activa);

-- ─────────────────────────────────────────────────────────────
-- 2. AUDITORIAS_RECETA
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS auditorias_receta (
    id                  BIGSERIAL    PRIMARY KEY,
    fecha               DATE         NOT NULL DEFAULT CURRENT_DATE,
    hora                TIME         NOT NULL DEFAULT CURRENT_TIME,
    sucursal_id         INTEGER      NOT NULL,
    estacion_id         BIGINT       REFERENCES estaciones(id),
    receta_id           BIGINT       REFERENCES recetas(id),
    tipo_receta         VARCHAR(20)  NOT NULL DEFAULT 'plato',
    responsable_id      INTEGER,               -- empleado_id de core_db
    responsable_nombre  VARCHAR(200),
    evaluador_id        INTEGER,               -- users.id
    evaluador_nombre    VARCHAR(200),
    notas               TEXT,
    estado              VARCHAR(20)  NOT NULL DEFAULT 'completada',
    aud_usuario         VARCHAR(150),
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS aud_receta_sucursal_idx   ON auditorias_receta (sucursal_id);
CREATE INDEX IF NOT EXISTS aud_receta_fecha_idx      ON auditorias_receta (fecha);
CREATE INDEX IF NOT EXISTS aud_receta_receta_id_idx  ON auditorias_receta (receta_id);
CREATE INDEX IF NOT EXISTS aud_receta_evaluador_idx  ON auditorias_receta (evaluador_id);
CREATE INDEX IF NOT EXISTS aud_receta_estacion_idx   ON auditorias_receta (estacion_id);

-- ─────────────────────────────────────────────────────────────
-- 3. AUDITORIA_FOTOS
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS auditoria_fotos (
    id            BIGSERIAL    PRIMARY KEY,
    auditoria_id  BIGINT       NOT NULL REFERENCES auditorias_receta(id) ON DELETE CASCADE,
    url           VARCHAR(1000) NOT NULL,
    descripcion   VARCHAR(300),
    orden         SMALLINT     NOT NULL DEFAULT 0,
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS aud_fotos_auditoria_idx ON auditoria_fotos (auditoria_id);
