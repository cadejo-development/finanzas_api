-- ============================================================
-- AUDITORÍA COMPRAS DB
-- Tablas: recetas, productos, receta_ingredientes,
--         receta_sucursal, receta_modificadores
-- Patrón: igual a aud_reservations de mansion_db
-- ============================================================

-- ─────────────────────────────────────────────────────────────
-- 1. AUD_RECETAS
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS aud_recetas (
    aud_id                BIGSERIAL PRIMARY KEY,
    accion                CHAR(1)      NOT NULL CHECK (accion IN ('I','U','D')),
    fecha_accion          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    db_user               TEXT         NOT NULL DEFAULT CURRENT_USER,
    receta_id             BIGINT,
    nombre                VARCHAR(150),
    descripcion           TEXT,
    tipo                  VARCHAR(80),
    tipo_receta           VARCHAR(20),
    platos_semana         INTEGER,
    precio                NUMERIC(10,2),
    rendimiento           NUMERIC(10,4),
    rendimiento_unidad    VARCHAR(20),
    instrucciones         TEXT,
    foto_plato            VARCHAR(500),
    foto_plateria         VARCHAR(500),
    categoria_id          BIGINT,
    estado_id             BIGINT,
    codigo_origen         VARCHAR(50),
    activa                BOOLEAN,
    modificado_localmente BOOLEAN,
    aud_usuario           VARCHAR(150),
    created_at            TIMESTAMP,
    updated_at            TIMESTAMP
);
CREATE INDEX IF NOT EXISTS aud_recetas_accion_idx       ON aud_recetas (accion);
CREATE INDEX IF NOT EXISTS aud_recetas_fecha_accion_idx ON aud_recetas (fecha_accion);
CREATE INDEX IF NOT EXISTS aud_recetas_receta_id_idx    ON aud_recetas (receta_id);

CREATE OR REPLACE FUNCTION fn_aud_recetas() RETURNS TRIGGER LANGUAGE plpgsql AS $func$
DECLARE r recetas%ROWTYPE;
BEGIN
    IF (TG_OP = 'DELETE') THEN r := OLD; ELSE r := NEW; END IF;
    INSERT INTO aud_recetas (
        accion, receta_id, nombre, descripcion, tipo, tipo_receta,
        platos_semana, precio, rendimiento, rendimiento_unidad, instrucciones,
        foto_plato, foto_plateria, categoria_id, estado_id, codigo_origen,
        activa, modificado_localmente, aud_usuario, created_at, updated_at
    ) VALUES (
        CASE TG_OP WHEN 'INSERT' THEN 'I' WHEN 'UPDATE' THEN 'U' ELSE 'D' END,
        r.id, r.nombre, r.descripcion, r.tipo, r.tipo_receta,
        r.platos_semana, r.precio, r.rendimiento, r.rendimiento_unidad, r.instrucciones,
        r.foto_plato, r.foto_plateria, r.categoria_id, r.estado_id, r.codigo_origen,
        r.activa, r.modificado_localmente, r.aud_usuario, r.created_at, r.updated_at
    );
    RETURN r;
END;
$func$;

DROP TRIGGER IF EXISTS trg_aud_recetas ON recetas;
CREATE TRIGGER trg_aud_recetas
    AFTER INSERT OR UPDATE OR DELETE ON recetas
    FOR EACH ROW EXECUTE FUNCTION fn_aud_recetas();


-- ─────────────────────────────────────────────────────────────
-- 2. AUD_PRODUCTOS
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS aud_productos (
    aud_id                BIGSERIAL PRIMARY KEY,
    accion                CHAR(1)      NOT NULL CHECK (accion IN ('I','U','D')),
    fecha_accion          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    db_user               TEXT         NOT NULL DEFAULT CURRENT_USER,
    producto_id           BIGINT,
    categoria_id          BIGINT,
    codigo                VARCHAR(30),
    nombre                VARCHAR(150),
    unidad                VARCHAR(20),
    precio                NUMERIC(12,2),
    costo                 NUMERIC(12,4),
    origen                VARCHAR(30),
    codigo_origen         VARCHAR(50),
    unidad_base           VARCHAR(20),
    factor_conversion     NUMERIC(12,4),
    activo                BOOLEAN,
    modificado_localmente BOOLEAN,
    aud_usuario           VARCHAR(150),
    created_at            TIMESTAMP,
    updated_at            TIMESTAMP
);
CREATE INDEX IF NOT EXISTS aud_productos_accion_idx       ON aud_productos (accion);
CREATE INDEX IF NOT EXISTS aud_productos_fecha_accion_idx ON aud_productos (fecha_accion);
CREATE INDEX IF NOT EXISTS aud_productos_producto_id_idx  ON aud_productos (producto_id);

CREATE OR REPLACE FUNCTION fn_aud_productos() RETURNS TRIGGER LANGUAGE plpgsql AS $func$
DECLARE r productos%ROWTYPE;
BEGIN
    IF (TG_OP = 'DELETE') THEN r := OLD; ELSE r := NEW; END IF;
    INSERT INTO aud_productos (
        accion, producto_id, categoria_id, codigo, nombre, unidad,
        precio, costo, origen, codigo_origen, unidad_base, factor_conversion,
        activo, modificado_localmente, aud_usuario, created_at, updated_at
    ) VALUES (
        CASE TG_OP WHEN 'INSERT' THEN 'I' WHEN 'UPDATE' THEN 'U' ELSE 'D' END,
        r.id, r.categoria_id, r.codigo, r.nombre, r.unidad,
        r.precio, r.costo, r.origen, r.codigo_origen, r.unidad_base, r.factor_conversion,
        r.activo, r.modificado_localmente, r.aud_usuario, r.created_at, r.updated_at
    );
    RETURN r;
END;
$func$;

DROP TRIGGER IF EXISTS trg_aud_productos ON productos;
CREATE TRIGGER trg_aud_productos
    AFTER INSERT OR UPDATE OR DELETE ON productos
    FOR EACH ROW EXECUTE FUNCTION fn_aud_productos();


-- ─────────────────────────────────────────────────────────────
-- 3. AUD_RECETA_INGREDIENTES
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS aud_receta_ingredientes (
    aud_id             BIGSERIAL PRIMARY KEY,
    accion             CHAR(1)      NOT NULL CHECK (accion IN ('I','U','D')),
    fecha_accion       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    db_user            TEXT         NOT NULL DEFAULT CURRENT_USER,
    ingrediente_id     BIGINT,
    receta_id          BIGINT,
    producto_id        BIGINT,
    sub_receta_id      BIGINT,
    cantidad_por_plato NUMERIC(12,4),
    unidad             VARCHAR(20),
    aud_usuario        VARCHAR(150),
    created_at         TIMESTAMP,
    updated_at         TIMESTAMP
);
CREATE INDEX IF NOT EXISTS aud_receta_ing_accion_idx       ON aud_receta_ingredientes (accion);
CREATE INDEX IF NOT EXISTS aud_receta_ing_fecha_accion_idx ON aud_receta_ingredientes (fecha_accion);
CREATE INDEX IF NOT EXISTS aud_receta_ing_receta_id_idx    ON aud_receta_ingredientes (receta_id);

CREATE OR REPLACE FUNCTION fn_aud_receta_ingredientes() RETURNS TRIGGER LANGUAGE plpgsql AS $func$
DECLARE r receta_ingredientes%ROWTYPE;
BEGIN
    IF (TG_OP = 'DELETE') THEN r := OLD; ELSE r := NEW; END IF;
    INSERT INTO aud_receta_ingredientes (
        accion, ingrediente_id, receta_id, producto_id, sub_receta_id,
        cantidad_por_plato, unidad, aud_usuario, created_at, updated_at
    ) VALUES (
        CASE TG_OP WHEN 'INSERT' THEN 'I' WHEN 'UPDATE' THEN 'U' ELSE 'D' END,
        r.id, r.receta_id, r.producto_id, r.sub_receta_id,
        r.cantidad_por_plato, r.unidad, r.aud_usuario, r.created_at, r.updated_at
    );
    RETURN r;
END;
$func$;

DROP TRIGGER IF EXISTS trg_aud_receta_ingredientes ON receta_ingredientes;
CREATE TRIGGER trg_aud_receta_ingredientes
    AFTER INSERT OR UPDATE OR DELETE ON receta_ingredientes
    FOR EACH ROW EXECUTE FUNCTION fn_aud_receta_ingredientes();


-- ─────────────────────────────────────────────────────────────
-- 4. AUD_RECETA_SUCURSAL
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS aud_receta_sucursal (
    aud_id        BIGSERIAL PRIMARY KEY,
    accion        CHAR(1)      NOT NULL CHECK (accion IN ('I','U','D')),
    fecha_accion  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    db_user       TEXT         NOT NULL DEFAULT CURRENT_USER,
    rs_id         BIGINT,
    receta_id     BIGINT,
    sucursal_id   INTEGER,
    platos_semana INTEGER,
    activa        BOOLEAN,
    aud_usuario   VARCHAR(100),
    created_at    TIMESTAMP,
    updated_at    TIMESTAMP
);
CREATE INDEX IF NOT EXISTS aud_receta_suc_accion_idx       ON aud_receta_sucursal (accion);
CREATE INDEX IF NOT EXISTS aud_receta_suc_fecha_accion_idx ON aud_receta_sucursal (fecha_accion);
CREATE INDEX IF NOT EXISTS aud_receta_suc_receta_id_idx    ON aud_receta_sucursal (receta_id);

CREATE OR REPLACE FUNCTION fn_aud_receta_sucursal() RETURNS TRIGGER LANGUAGE plpgsql AS $func$
DECLARE r receta_sucursal%ROWTYPE;
BEGIN
    IF (TG_OP = 'DELETE') THEN r := OLD; ELSE r := NEW; END IF;
    INSERT INTO aud_receta_sucursal (
        accion, rs_id, receta_id, sucursal_id, platos_semana,
        activa, aud_usuario, created_at, updated_at
    ) VALUES (
        CASE TG_OP WHEN 'INSERT' THEN 'I' WHEN 'UPDATE' THEN 'U' ELSE 'D' END,
        r.id, r.receta_id, r.sucursal_id, r.platos_semana,
        r.activa, r.aud_usuario, r.created_at, r.updated_at
    );
    RETURN r;
END;
$func$;

DROP TRIGGER IF EXISTS trg_aud_receta_sucursal ON receta_sucursal;
CREATE TRIGGER trg_aud_receta_sucursal
    AFTER INSERT OR UPDATE OR DELETE ON receta_sucursal
    FOR EACH ROW EXECUTE FUNCTION fn_aud_receta_sucursal();


-- ─────────────────────────────────────────────────────────────
-- 5. AUD_RECETA_MODIFICADORES
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS aud_receta_modificadores (
    aud_id          BIGSERIAL PRIMARY KEY,
    accion          CHAR(1)      NOT NULL CHECK (accion IN ('I','U','D')),
    fecha_accion    TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    db_user         TEXT         NOT NULL DEFAULT CURRENT_USER,
    modificador_id  BIGINT,
    receta_id       BIGINT,
    grupo_id_origen INTEGER,
    grupo_codigo    VARCHAR(30),
    grupo_nombre    VARCHAR(150),
    opcion_nombre   VARCHAR(150),
    producto_id     BIGINT,
    cantidad        NUMERIC(10,4),
    unidad          VARCHAR(20),
    aud_usuario     VARCHAR(100),
    created_at      TIMESTAMP,
    updated_at      TIMESTAMP
);
CREATE INDEX IF NOT EXISTS aud_receta_mod_accion_idx       ON aud_receta_modificadores (accion);
CREATE INDEX IF NOT EXISTS aud_receta_mod_fecha_accion_idx ON aud_receta_modificadores (fecha_accion);
CREATE INDEX IF NOT EXISTS aud_receta_mod_receta_id_idx    ON aud_receta_modificadores (receta_id);

CREATE OR REPLACE FUNCTION fn_aud_receta_modificadores() RETURNS TRIGGER LANGUAGE plpgsql AS $func$
DECLARE r receta_modificadores%ROWTYPE;
BEGIN
    IF (TG_OP = 'DELETE') THEN r := OLD; ELSE r := NEW; END IF;
    INSERT INTO aud_receta_modificadores (
        accion, modificador_id, receta_id, grupo_id_origen, grupo_codigo,
        grupo_nombre, opcion_nombre, producto_id, cantidad, unidad,
        aud_usuario, created_at, updated_at
    ) VALUES (
        CASE TG_OP WHEN 'INSERT' THEN 'I' WHEN 'UPDATE' THEN 'U' ELSE 'D' END,
        r.id, r.receta_id, r.grupo_id_origen, r.grupo_codigo,
        r.grupo_nombre, r.opcion_nombre, r.producto_id, r.cantidad, r.unidad,
        r.aud_usuario, r.created_at, r.updated_at
    );
    RETURN r;
END;
$func$;

DROP TRIGGER IF EXISTS trg_aud_receta_modificadores ON receta_modificadores;
CREATE TRIGGER trg_aud_receta_modificadores
    AFTER INSERT OR UPDATE OR DELETE ON receta_modificadores
    FOR EACH ROW EXECUTE FUNCTION fn_aud_receta_modificadores();
