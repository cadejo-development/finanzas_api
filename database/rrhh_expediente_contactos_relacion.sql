-- Agrega columna relacion a expediente_contactos
-- Idempotente: usa IF NOT EXISTS
-- Ejecutar en la base de datos rrhh

ALTER TABLE rrhh.expediente_contactos
  ADD COLUMN IF NOT EXISTS relacion VARCHAR(80) DEFAULT NULL;

COMMENT ON COLUMN rrhh.expediente_contactos.relacion IS 'Relación del contacto con el empleado (Papá, Mamá, Esposo/a, etc.)';
