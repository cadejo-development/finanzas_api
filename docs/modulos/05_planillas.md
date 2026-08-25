# Módulo: Planillas y Nómina

> Procesamiento de nómina quincenal — generación, aprobación y distribución de boletas  
> Guard: `soloRrhhAdmin` — exclusivo del Administrador RRHH

---

## 1. Propósito

Automatiza el proceso de nómina quincenal. Calcula el salario neto de cada colaborador aplicando:
- Deducciones legales (ISSS, AFP, renta ISR)
- Acreedores permanentes (préstamos, cuotas)
- Órdenes de descuento del período
- Ausencias injustificadas del período

---

## 2. Flujo de Proceso

```
1. PREPARACIÓN
   Admin abre el módulo de Planillas
   Selecciona la quincena a procesar (período: fecha inicio / fecha fin)

2. GENERACIÓN  [POST /api/rrhh/planillas/generar]
   El sistema crea líneas individuales por cada empleado activo:
   a. Salario bruto (desde empleados.salario en pgsql)
   b. Descuento ISSS (7.5%)
   c. Descuento AFP (tasa según AFP del empleado)
   d. Renta ISR (tabla progresiva definida en planilla_tabla_renta)
   e. Descuento por ausencias injustificadas del período
   f. Acreedores permanentes asignados al empleado
   g. Órdenes de descuento activas del período
   = Salario neto a pagar

3. REVISIÓN
   Admin revisa el listado de líneas generadas
   Puede editar ajustes manuales en líneas individuales
   Puede agregar/quitar ítems de descuento específicos

4. APROBACIÓN  [POST /api/rrhh/planillas/{id}/aprobar]
   La planilla se bloquea — ya no se puede editar
   Estado cambia a "aprobada"

5. DISTRIBUCIÓN
   a. Exportar a Excel  [GET /api/rrhh/planillas/{id}/exportar]
      → Archivo XLSX con todas las líneas para contabilidad
   b. Boletas individuales  [GET /api/rrhh/planillas/{id}/boleta/{empleado_id}]
      → PDF DomPDF de la boleta de pago del colaborador
```

---

## 3. API

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/rrhh/planillas` | Lista de planillas históricas |
| GET | `/api/rrhh/planillas/{id}` | Detalle con todas las líneas |
| POST | `/api/rrhh/planillas/generar` | Generar nueva planilla (crea líneas automáticas) |
| POST | `/api/rrhh/planillas/{id}/aprobar` | Aprobar y bloquear la planilla |
| GET | `/api/rrhh/planillas/{id}/exportar` | Descargar Excel (XLSX) |
| GET | `/api/rrhh/planillas/{id}/boleta/{empleado_id}` | Boleta PDF individual |

---

## 4. Controladores

- `app/Http/Controllers/Api/RRHH/PlanillasController.php` — CRUD y flujo principal
- `app/Http/Controllers/Api/RRHH/MantenimientoPlanillaController.php` — Configuración

---

## 5. Mantenimiento de Planilla

El submódulo de mantenimiento permite configurar todos los parámetros del cálculo:

### Tabla de Renta ISR

```
Ingreso mensual hasta $X → tasa Y% sobre exceso de $Z − cuota fija $W
```

Se define como rangos progresivos en `planilla_tabla_renta`. Actualizable desde UI sin necesidad de código.

### Acreedores Permanentes

Instituciones o personas a las que se les descuenta automáticamente cada quincena:
- ISSS, AFP (siempre activos)
- Bancos (préstamos personales)
- Cooperativas
- Caja de empleados

Cada acreedor tiene un monto fijo o porcentaje y puede activarse/desactivarse.

### Órdenes de Descuento de Planilla

Las órdenes de descuento permanentes (separadas de las ordinarias de `/ordenes-descuento`) se configuran aquí. Ejemplo: descuento de uniformes en cuotas.

### API de Mantenimiento

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/rrhh/planillas/mantenimiento/config` | Configuración general de la planilla |
| PUT | `/api/rrhh/planillas/mantenimiento/config` | Actualizar configuración |
| GET | `/api/rrhh/planillas/mantenimiento/tabla-renta` | Tabla de renta ISR actual |
| PUT | `/api/rrhh/planillas/mantenimiento/tabla-renta` | Actualizar tabla de renta |
| GET | `/api/rrhh/planillas/mantenimiento/acreedores` | Lista de acreedores |
| POST | `/api/rrhh/planillas/mantenimiento/acreedores` | Agregar acreedor |
| PATCH | `/api/rrhh/planillas/mantenimiento/acreedores/{id}` | Actualizar acreedor |
| PATCH | `/api/rrhh/planillas/mantenimiento/acreedores/{id}/toggle` | Activar/desactivar |
| GET | `/api/rrhh/planillas/mantenimiento/ordenes` | Órdenes de descuento permanentes |
| POST | `/api/rrhh/planillas/mantenimiento/ordenes` | Crear orden permanente |
| PATCH | `/api/rrhh/planillas/mantenimiento/ordenes/{id}` | Actualizar |
| DELETE | `/api/rrhh/planillas/mantenimiento/ordenes/{id}` | Eliminar |

---

## 6. Tablas BD (rrhh_db)

```sql
-- Cabecera de la planilla
planillas
  id
  periodo_inicio, periodo_fin           -- quincena procesada
  estado                                -- borrador | aprobada
  total_bruto, total_deducciones, total_neto
  aprobado_por, fecha_aprobacion
  created_at

-- Una línea por empleado por planilla
planilla_lineas
  id, planilla_id, empleado_id
  salario_bruto
  descuento_isss, descuento_afp, descuento_renta
  descuento_ausencias, descuento_acreedores, descuento_ordenes
  total_deducciones, salario_neto
  ajuste_manual, observacion_ajuste
  dias_trabajados, dias_ausencia

-- Configuración general del cálculo
planilla_config
  id
  porcentaje_isss_empleado              -- 7.5% por defecto
  porcentaje_isss_patronal              -- 9.5%
  porcentaje_afp_empleado               -- varía por AFP
  porcentaje_afp_patronal
  dias_quincena                         -- 15
  updated_at

-- Tabla ISR progresiva
planilla_tabla_renta
  id, desde, hasta                      -- rango de ingreso mensual ($)
  porcentaje, cuota_fija, sobre_exceso_de

-- Acreedores de planilla
planilla_acreedores
  id, nombre, tipo                      -- isss | afp | banco | cooperativa | otro
  monto_fijo, porcentaje                -- uno de los dos aplica
  aplica_a_todos                        -- si true, aplica a todos los empleados
  activo

-- Descuentos fijos en planilla (asignados por empleado)
planilla_ordenes_descuento
  id, empleado_id, acreedor_id
  monto_quincena, cuotas_totales, cuotas_pagadas
  activa
```

---

## 7. Boleta de Pago (PDF)

Generada con DomPDF desde la plantilla `resources/views/pdf/boleta_planilla.blade.php`.

Contenido de la boleta:
- Datos del empleado (nombre, cargo, sucursal)
- Período de la planilla
- Desglose de ingresos y deducciones
- Salario neto a pagar
- Firma del empleado (espacio para firma física)

---

## 8. Exportación Excel

El método `exportar()` usa `PhpSpreadsheet` para generar un XLSX con:
- Una fila por empleado
- Columnas: código, nombre, cargo, sucursal, salario bruto, cada deducción por separado, salario neto
- Totales en la última fila
- Formato con estilos corporativos

---

## 9. Vista Frontend

**Archivos**:
- `hr-rrhh/src/pages/planillas/PlanillasPage.vue` — Lista de planillas históricas + botón de generar nueva
- `hr-rrhh/src/pages/planillas/MantenimientoPlanillaPage.vue` — Configuración de parámetros

La página principal muestra la planilla activa (en borrador) con una tabla editable de líneas. Al aprobar, la tabla se vuelve de solo lectura. Los botones de exportar y boletas individuales solo aparecen en planillas aprobadas.
