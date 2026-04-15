-- ============================================================
-- AUDITORÍA DE COCINA — CADEJO BREWING COMPANY
-- Formato oficial: 5 secciones, 39 criterios
-- Ejecutar en compras_db  (idempotente: se puede correr N veces)
-- ============================================================

-- 1. Columnas nuevas en auditorias_receta
ALTER TABLE auditorias_receta
    ADD COLUMN IF NOT EXISTS calificacion           NUMERIC(5,2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS clasificacion          VARCHAR(20)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS observaciones_generales TEXT        DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS acciones_correctivas   TEXT        DEFAULT NULL;

-- 2. Columna descripcion en auditoria_fotos (fotos por sección)
ALTER TABLE auditoria_fotos
    ADD COLUMN IF NOT EXISTS descripcion VARCHAR(200) DEFAULT NULL;

-- 3. Columnas en auditoria_criterios
ALTER TABLE auditoria_criterios
    ADD COLUMN IF NOT EXISTS peso           INTEGER NOT NULL DEFAULT 1;
ALTER TABLE auditoria_criterios
    ADD COLUMN IF NOT EXISTS categoria_orden INTEGER NOT NULL DEFAULT 99;

-- 4. Inactivar criterios del formato provisional (Receta / Sub Receta)
UPDATE auditoria_criterios
    SET activo = FALSE
    WHERE LOWER(categoria) IN ('receta', 'sub receta');

-- 5. Eliminar TODAS las variantes (mayúsculas/minúsculas) de las 5 secciones oficiales
DELETE FROM auditoria_criterios
    WHERE LOWER(categoria) IN (
        'mise en place',
        'cumplimiento del procedimiento',
        'control de medición y porcionado',
        'control de medicion y porcionado',
        'control de calidad del producto final',
        'higiene y seguridad alimentaria'
    );

-- 6. Índice único (la tabla ya está limpia)
CREATE UNIQUE INDEX IF NOT EXISTS aud_criterios_cat_nombre_uidx
    ON auditoria_criterios (categoria, nombre);

-- 7. Insertar los 39 criterios en el orden exacto del PDF
-- ── Sección 1: MISE EN PLACE ──────────────────────────────────────────────
INSERT INTO auditoria_criterios (categoria, categoria_orden, nombre, peso, activo, orden) VALUES
  ('Mise en Place', 1, 'Ingredientes completos según ficha técnica',   1, TRUE, 1),
  ('Mise en Place', 1, 'Ingredientes corresponden a especificaciones',  1, TRUE, 2),
  ('Mise en Place', 1, 'Materia prima en condiciones óptimas',          1, TRUE, 3),
  ('Mise en Place', 1, 'Ingredientes lavados/desinfectados',            1, TRUE, 4),
  ('Mise en Place', 1, 'Pesaje/medición previa correcta',               1, TRUE, 5),
  ('Mise en Place', 1, 'Ingredientes organizados según orden de uso',   1, TRUE, 6),
  ('Mise en Place', 1, 'Utensilios correctos',                          1, TRUE, 7),
  ('Mise en Place', 1, 'Utensilios limpios/desinfectados',              1, TRUE, 8),
  ('Mise en Place', 1, 'Área de trabajo limpia y ordenada',             1, TRUE, 9);

-- ── Sección 2: CUMPLIMIENTO DEL PROCEDIMIENTO ────────────────────────────
INSERT INTO auditoria_criterios (categoria, categoria_orden, nombre, peso, activo, orden) VALUES
  ('Cumplimiento del Procedimiento', 2, 'Sigue el orden de la receta',     1, TRUE, 1),
  ('Cumplimiento del Procedimiento', 2, 'Técnicas de corte correctas',      1, TRUE, 2),
  ('Cumplimiento del Procedimiento', 2, 'Uniformidad en cortes',            1, TRUE, 3),
  ('Cumplimiento del Procedimiento', 2, 'Técnicas de cocción correctas',    1, TRUE, 4),
  ('Cumplimiento del Procedimiento', 2, 'Control de tiempos',               1, TRUE, 5),
  ('Cumplimiento del Procedimiento', 2, 'Control de temperaturas',          1, TRUE, 6),
  ('Cumplimiento del Procedimiento', 2, 'Manipulación adecuada',            1, TRUE, 7),
  ('Cumplimiento del Procedimiento', 2, 'Uso correcto de equipos',          1, TRUE, 8),
  ('Cumplimiento del Procedimiento', 2, 'Sin cambios no autorizados',       1, TRUE, 9);

-- ── Sección 3: CONTROL DE MEDICIÓN Y PORCIONADO ──────────────────────────
INSERT INTO auditoria_criterios (categoria, categoria_orden, nombre, peso, activo, orden) VALUES
  ('Control de Medición y Porcionado', 3, 'Respeta cantidades exactas',      1, TRUE, 1),
  ('Control de Medición y Porcionado', 3, 'Uso de báscula/instrumentos',     1, TRUE, 2),
  ('Control de Medición y Porcionado', 3, 'Conversión correcta de unidades', 1, TRUE, 3),
  ('Control de Medición y Porcionado', 3, 'Porciones según estándar',        1, TRUE, 4),
  ('Control de Medición y Porcionado', 3, 'Homogeneidad de porciones',       1, TRUE, 5),
  ('Control de Medición y Porcionado', 3, 'Rendimiento esperado',            1, TRUE, 6),
  ('Control de Medición y Porcionado', 3, 'Control de desperdicio',          1, TRUE, 7);

-- ── Sección 4: CONTROL DE CALIDAD DEL PRODUCTO FINAL ────────────────────
INSERT INTO auditoria_criterios (categoria, categoria_orden, nombre, peso, activo, orden) VALUES
  ('Control de Calidad del Producto Final', 4, 'Sabor acorde al estándar',     1, TRUE, 1),
  ('Control de Calidad del Producto Final', 4, 'Balance adecuado',             1, TRUE, 2),
  ('Control de Calidad del Producto Final', 4, 'Textura correcta',             1, TRUE, 3),
  ('Control de Calidad del Producto Final', 4, 'Color adecuado',               1, TRUE, 4),
  ('Control de Calidad del Producto Final', 4, 'Aroma correcto',               1, TRUE, 5),
  ('Control de Calidad del Producto Final', 4, 'Presentación uniforme',        1, TRUE, 6),
  ('Control de Calidad del Producto Final', 4, 'Temperatura de servicio',      1, TRUE, 7),
  ('Control de Calidad del Producto Final', 4, 'Cumple estándar del producto', 1, TRUE, 8);

-- ── Sección 5: HIGIENE Y SEGURIDAD ALIMENTARIA ───────────────────────────
INSERT INTO auditoria_criterios (categoria, categoria_orden, nombre, peso, activo, orden) VALUES
  ('Higiene y Seguridad Alimentaria', 5, 'Lavado de manos',             1, TRUE, 1),
  ('Higiene y Seguridad Alimentaria', 5, 'Uso de uniforme/EPP',         1, TRUE, 2),
  ('Higiene y Seguridad Alimentaria', 5, 'Evita contaminación cruzada', 1, TRUE, 3),
  ('Higiene y Seguridad Alimentaria', 5, 'Manejo de alimentos',         1, TRUE, 4),
  ('Higiene y Seguridad Alimentaria', 5, 'Limpieza continua',           1, TRUE, 5),
  ('Higiene y Seguridad Alimentaria', 5, 'Manejo de residuos',          1, TRUE, 6);
