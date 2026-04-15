-- ============================================================
-- AUDITORÍA DE RECETAS: calificación + criterios estándar
-- Ejecutar en compras_db
-- ============================================================

-- 1. Columna calificacion en auditorias_receta (0-100, permite null = sin evaluar)
ALTER TABLE auditorias_receta
    ADD COLUMN IF NOT EXISTS calificacion NUMERIC(5,2) DEFAULT NULL;

-- 2. Columna peso en auditoria_criterios (% que aporta cada criterio)
ALTER TABLE auditoria_criterios
    ADD COLUMN IF NOT EXISTS peso INTEGER NOT NULL DEFAULT 25;

-- 3. Upsert de los 8 criterios estándar
--    Usa ON CONFLICT (categoria, nombre) para idempotencia;
--    si ya existen con otro nombre exacto se insertan nuevos.

-- Primero aseguramos el índice único para el upsert
CREATE UNIQUE INDEX IF NOT EXISTS aud_criterios_cat_nombre_uidx
    ON auditoria_criterios (categoria, nombre);

-- Criterios para receta (plato)
INSERT INTO auditoria_criterios (categoria, nombre, peso, activo, orden) VALUES
  ('Receta',     'Sabor (textura, color, olor): Conforme a receta y especificaciones',              25, TRUE, 1),
  ('Receta',     'Presentación / Emplatado: Loza, utensilios y montaje de acuerdo a receta',        25, TRUE, 2),
  ('Receta',     'Porción: Gramaje, tamaño y porción de acuerdo a la receta',                       25, TRUE, 3),
  ('Receta',     'Temperatura: Cada elemento cumple con la temperatura correcta',                   25, TRUE, 4)
ON CONFLICT (categoria, nombre) DO UPDATE
    SET peso = EXCLUDED.peso, activo = TRUE, orden = EXCLUDED.orden;

-- Criterios para sub-receta
INSERT INTO auditoria_criterios (categoria, nombre, peso, activo, orden) VALUES
  ('Sub Receta', 'Insumos: autorizados de acuerdo a receta',                                        25, TRUE, 1),
  ('Sub Receta', 'Porciones: medidas de acuerdo a receta',                                          25, TRUE, 2),
  ('Sub Receta', 'Proceso: Seguimiento del paso a paso del procedimiento',                          25, TRUE, 3),
  ('Sub Receta', 'Almacenaje: que cumpla con las especificaciones, PORCIONADO guardado temperatura', 25, TRUE, 4)
ON CONFLICT (categoria, nombre) DO UPDATE
    SET peso = EXCLUDED.peso, activo = TRUE, orden = EXCLUDED.orden;
