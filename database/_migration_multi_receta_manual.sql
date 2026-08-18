-- Migración manual: auditoría de recetas multi-receta
-- Base de datos: compras_db
-- Ejecutar conectado a compras_db

BEGIN;

-- 1. Hacer nullable receta_id y tipo_receta en auditorias_receta
ALTER TABLE auditorias_receta
    ALTER COLUMN receta_id   DROP NOT NULL,
    ALTER COLUMN tipo_receta DROP NOT NULL;

-- 2. Nueva tabla auditoria_receta_items
CREATE TABLE IF NOT EXISTS auditoria_receta_items (
    id               BIGSERIAL PRIMARY KEY,
    auditoria_id     BIGINT       NOT NULL REFERENCES auditorias_receta(id) ON DELETE CASCADE,
    receta_id        BIGINT       NOT NULL,
    tipo_receta      VARCHAR(20)  NOT NULL DEFAULT 'plato',
    estacion_id      BIGINT,
    responsable_id   INTEGER,
    responsable_nombre VARCHAR(200),
    calificacion     DECIMAL(5,2),
    clasificacion    VARCHAR(20),
    notas            TEXT,
    orden            SMALLINT     NOT NULL DEFAULT 0,
    created_at       TIMESTAMP,
    updated_at       TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_ari_auditoria_id ON auditoria_receta_items(auditoria_id);

-- 3. Agregar receta_item_id a auditoria_items
ALTER TABLE auditoria_items
    ADD COLUMN IF NOT EXISTS receta_item_id BIGINT;

CREATE INDEX IF NOT EXISTS idx_ai_receta_item_id ON auditoria_items(receta_item_id);

-- 4. Agregar receta_item_id a auditoria_fotos
ALTER TABLE auditoria_fotos
    ADD COLUMN IF NOT EXISTS receta_item_id BIGINT;

-- 5. Marcar en migrations de Laravel para que artisan no la vuelva a correr
INSERT INTO migrations (migration, batch)
SELECT '2026_08_18_000001_auditoria_visita_multi_receta', MAX(batch) + 1
FROM migrations
WHERE NOT EXISTS (
    SELECT 1 FROM migrations WHERE migration = '2026_08_18_000001_auditoria_visita_multi_receta'
);

COMMIT;

-- Verificación
SELECT column_name, is_nullable
FROM information_schema.columns
WHERE table_name = 'auditorias_receta'
  AND column_name IN ('receta_id', 'tipo_receta');

SELECT table_name FROM information_schema.tables
WHERE table_name = 'auditoria_receta_items';

SELECT column_name FROM information_schema.columns
WHERE table_name = 'auditoria_items' AND column_name = 'receta_item_id';
