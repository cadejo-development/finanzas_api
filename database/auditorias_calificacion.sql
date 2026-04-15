-- ============================================================
-- AUDITORÍA DE COCINA — CADEJO BREWING COMPANY
-- Formato oficial: 5 secciones, 39 criterios
-- Ejecutar en compras_db
-- ============================================================

-- 1. Columnas nuevas en auditorias_receta
ALTER TABLE auditorias_receta
    ADD COLUMN IF NOT EXISTS calificacion           NUMERIC(5,2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS clasificacion          VARCHAR(20)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS observaciones_generales TEXT        DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS acciones_correctivas   TEXT        DEFAULT NULL;

-- 2. Columna peso en auditoria_criterios
ALTER TABLE auditoria_criterios
    ADD COLUMN IF NOT EXISTS peso INTEGER NOT NULL DEFAULT 1;

-- 3. Índice único para upsert idempotente
CREATE UNIQUE INDEX IF NOT EXISTS aud_criterios_cat_nombre_uidx
    ON auditoria_criterios (categoria, nombre);

-- 4. Inactivar criterios anteriores (del formato provisional)
UPDATE auditoria_criterios
    SET activo = FALSE
    WHERE categoria IN ('Receta', 'Sub Receta');

-- 5. Insertar los 39 criterios del formato oficial
--    Orden de secciones: Mise en Place, Cumplimiento del Procedimiento,
--    Control de Medición y Porcionado, Control de Calidad del Producto Final,
--    Higiene y Seguridad Alimentaria

-- ── Sección 1: MISE EN PLACE ──────────────────────────────────────────────
INSERT INTO auditoria_criterios (categoria, nombre, peso, activo, orden) VALUES
  ('Mise en Place', 'Ingredientes completos según ficha técnica',  1, TRUE, 1),
  ('Mise en Place', 'Ingredientes corresponden a especificaciones', 1, TRUE, 2),
  ('Mise en Place', 'Materia prima en condiciones óptimas',         1, TRUE, 3),
  ('Mise en Place', 'Ingredientes lavados/desinfectados',           1, TRUE, 4),
  ('Mise en Place', 'Pesaje/medición previa correcta',              1, TRUE, 5),
  ('Mise en Place', 'Ingredientes organizados según orden de uso',  1, TRUE, 6),
  ('Mise en Place', 'Utensilios correctos',                         1, TRUE, 7),
  ('Mise en Place', 'Utensilios limpios/desinfectados',             1, TRUE, 8),
  ('Mise en Place', 'Área de trabajo limpia y ordenada',            1, TRUE, 9)
ON CONFLICT (categoria, nombre) DO UPDATE
    SET peso = EXCLUDED.peso, activo = TRUE, orden = EXCLUDED.orden;

-- ── Sección 2: CUMPLIMIENTO DEL PROCEDIMIENTO ────────────────────────────
INSERT INTO auditoria_criterios (categoria, nombre, peso, activo, orden) VALUES
  ('Cumplimiento del Procedimiento', 'Sigue el orden de la receta',     1, TRUE, 1),
  ('Cumplimiento del Procedimiento', 'Técnicas de corte correctas',      1, TRUE, 2),
  ('Cumplimiento del Procedimiento', 'Uniformidad en cortes',            1, TRUE, 3),
  ('Cumplimiento del Procedimiento', 'Técnicas de cocción correctas',    1, TRUE, 4),
  ('Cumplimiento del Procedimiento', 'Control de tiempos',               1, TRUE, 5),
  ('Cumplimiento del Procedimiento', 'Control de temperaturas',          1, TRUE, 6),
  ('Cumplimiento del Procedimiento', 'Manipulación adecuada',            1, TRUE, 7),
  ('Cumplimiento del Procedimiento', 'Uso correcto de equipos',          1, TRUE, 8),
  ('Cumplimiento del Procedimiento', 'Sin cambios no autorizados',       1, TRUE, 9)
ON CONFLICT (categoria, nombre) DO UPDATE
    SET peso = EXCLUDED.peso, activo = TRUE, orden = EXCLUDED.orden;

-- ── Sección 3: CONTROL DE MEDICIÓN Y PORCIONADO ──────────────────────────
INSERT INTO auditoria_criterios (categoria, nombre, peso, activo, orden) VALUES
  ('Control de Medición y Porcionado', 'Respeta cantidades exactas',       1, TRUE, 1),
  ('Control de Medición y Porcionado', 'Uso de báscula/instrumentos',      1, TRUE, 2),
  ('Control de Medición y Porcionado', 'Conversión correcta de unidades',  1, TRUE, 3),
  ('Control de Medición y Porcionado', 'Porciones según estándar',         1, TRUE, 4),
  ('Control de Medición y Porcionado', 'Homogeneidad de porciones',        1, TRUE, 5),
  ('Control de Medición y Porcionado', 'Rendimiento esperado',             1, TRUE, 6),
  ('Control de Medición y Porcionado', 'Control de desperdicio',           1, TRUE, 7)
ON CONFLICT (categoria, nombre) DO UPDATE
    SET peso = EXCLUDED.peso, activo = TRUE, orden = EXCLUDED.orden;

-- ── Sección 4: CONTROL DE CALIDAD DEL PRODUCTO FINAL ────────────────────
INSERT INTO auditoria_criterios (categoria, nombre, peso, activo, orden) VALUES
  ('Control de Calidad del Producto Final', 'Sabor acorde al estándar',    1, TRUE, 1),
  ('Control de Calidad del Producto Final', 'Balance adecuado',            1, TRUE, 2),
  ('Control de Calidad del Producto Final', 'Textura correcta',            1, TRUE, 3),
  ('Control de Calidad del Producto Final', 'Color adecuado',              1, TRUE, 4),
  ('Control de Calidad del Producto Final', 'Aroma correcto',              1, TRUE, 5),
  ('Control de Calidad del Producto Final', 'Presentación uniforme',       1, TRUE, 6),
  ('Control de Calidad del Producto Final', 'Temperatura de servicio',     1, TRUE, 7),
  ('Control de Calidad del Producto Final', 'Cumple estándar del producto',1, TRUE, 8)
ON CONFLICT (categoria, nombre) DO UPDATE
    SET peso = EXCLUDED.peso, activo = TRUE, orden = EXCLUDED.orden;

-- ── Sección 5: HIGIENE Y SEGURIDAD ALIMENTARIA ───────────────────────────
INSERT INTO auditoria_criterios (categoria, nombre, peso, activo, orden) VALUES
  ('Higiene y Seguridad Alimentaria', 'Lavado de manos',              1, TRUE, 1),
  ('Higiene y Seguridad Alimentaria', 'Uso de uniforme/EPP',          1, TRUE, 2),
  ('Higiene y Seguridad Alimentaria', 'Evita contaminación cruzada',  1, TRUE, 3),
  ('Higiene y Seguridad Alimentaria', 'Manejo de alimentos',          1, TRUE, 4),
  ('Higiene y Seguridad Alimentaria', 'Limpieza continua',            1, TRUE, 5),
  ('Higiene y Seguridad Alimentaria', 'Manejo de residuos',           1, TRUE, 6)
ON CONFLICT (categoria, nombre) DO UPDATE
    SET peso = EXCLUDED.peso, activo = TRUE, orden = EXCLUDED.orden;
