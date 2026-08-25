# Módulo: Horarios · Reportes · Historial

> Control de Horarios (jefatura+) · Mi Horario (todos) · Reportes (jefatura+) · Historial (analistas+)

---

## 1. Control de Horarios

### Propósito

Permite a las jefaturas asignar y gestionar los horarios de trabajo de su equipo. Los colaboradores pueden ver su horario propio en `/mi-horario`. Los horarios se usan como referencia para el cálculo de ausencias y planilla.

### Flujo

```
Jefe → crea o selecciona un horario base (ej. "Turno mañana: L-V 8am-5pm")
     → asigna ese horario a empleados de su equipo
     → puede resolver conflictos de horario (empleado con dos horarios solapados)

Empleado → accede a /mi-horario
         → ve su horario vigente y el calendario del equipo del mes actual
```

### API

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/rrhh/horarios` | Lista de horarios (admin: todos; jefe: su equipo) |
| POST | `/api/rrhh/horarios` | Crear nuevo horario |
| PATCH | `/api/rrhh/horarios/{id}` | Actualizar |
| DELETE | `/api/rrhh/horarios/{id}` | Eliminar horario (si no tiene empleados asignados) |
| POST | `/api/rrhh/horarios/resolver` | Resolver conflicto de horario de un empleado |
| POST | `/api/rrhh/horarios/resolver-empleados` | Resolver conflictos masivos |

### Controlador
`app/Http/Controllers/Api/RRHH/HorariosController.php`

La asignación de un empleado a un horario actualiza `horario_empleado` en `pgsql` (core_db). El método `resolver()` elimina asignaciones solapadas y garantiza que el empleado tenga exactamente un horario vigente.

### Tabla BD (core_db — pgsql)

```sql
horario_empleado
  id, empleado_id, horario_id
  fecha_inicio, fecha_fin               -- NULL fecha_fin = vigente actualmente
  created_at
```

### Tabla BD (rrhh_db)

```sql
-- La definición del horario en sí está en rrhh_db
horarios_empleado
  id, nombre, descripcion
  dias_semana                            -- JSON: [1,2,3,4,5] (lunes-viernes)
  hora_entrada, hora_salida
  hora_almuerzo_inicio, hora_almuerzo_fin
  horas_semanales
  activo
```

### Vistas Frontend

- `hr-rrhh/src/pages/horarios/HorariosPage.vue` — Lista y asignación (jefatura+)
- `hr-rrhh/src/pages/horarios/MiHorarioPage.vue` — Vista del empleado propio

La vista de "Mi Horario" muestra un calendario mensual con el horario asignado resaltado. Los días de permiso o incapacidad aprobados se marcan visualmente en el calendario.

---

## 2. Reportes

### Propósito

Genera reportes consolidados de nómina y movimientos de personal para el período seleccionado. Disponible para jefaturas y administradores.

### Reporte de Quincena

El principal reporte disponible es el **Reporte de Quincena**, que consolida por período:

- Total de colaboradores activos
- Movimientos del período (ingresos, desvinculaciones, traslados)
- Resumen de ausencias injustificadas
- Resumen de permisos aprobados
- Totales de planilla (bruto, deducciones, neto)
- Bonificaciones aplicadas

### API

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/rrhh/reportes/quincena` | Reporte del período. Params: `periodo_inicio`, `periodo_fin`, `sucursal_id` |

### Controlador
`app/Http/Controllers/Api/RRHH/ReportesRRHHController.php`

El reporte cruza datos entre `core_db` (pgsql) y `rrhh_db` para consolidar toda la información del período.

### Vista Frontend

`hr-rrhh/src/pages/reportes/ReportesPage.vue`

Selector de período (quincena inicio / quincena fin) y filtro de sucursal. El reporte se muestra en pantalla con opción de exportar a Excel o imprimir.

---

## 3. Historial de Acciones

### Propósito

Registro inmutable de todas las acciones de personal registradas en el sistema. Permite auditar quién hizo qué, cuándo y sobre qué empleado. Accesible para analistas y administradores.

### Contenido del Historial

Cada registro en `historial` captura:
- **Quién**: usuario que realizó la acción (email + nombre)
- **Qué**: tipo de acción (permiso_creado, amonestacion_aprobada, traslado_confirmado, etc.)
- **Sobre quién**: empleado_id del afectado
- **Cuándo**: timestamp
- **Detalles**: payload JSON con los datos relevantes del registro original

### API

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/rrhh/historial` | Lista paginada. Filtros: `empleado_id`, `tipo`, `fecha_desde`, `fecha_hasta` |

### Controlador
`app/Http/Controllers/Api/RRHH/HistorialController.php`

### Tabla BD (rrhh_db)

```sql
historial
  id
  empleado_id                            -- empleado afectado
  tipo                                   -- permiso_creado | amonestacion_aprobada | etc.
  descripcion                            -- texto legible para el usuario
  payload_json                           -- datos completos del registro original
  realizado_por_user_id                  -- usuario que ejecutó la acción
  realizado_por_nombre                   -- nombre en el momento (no referencia por FK para inmutabilidad)
  created_at                             -- timestamp del evento
```

### Tipos de acción registrados

```
permiso_creado, permiso_aprobado, permiso_rechazado
vacacion_creada, vacacion_aprobada, vacacion_rechazada
incapacidad_creada
ausencia_registrada, ausencia_regularizada
amonestacion_creada, amonestacion_aprobada, amonestacion_rechazada
desvinculacion_registrada, desvinculacion_confirmada
traslado_creado, traslado_aprobado, traslado_rechazado
cambio_salarial_creado, cambio_salarial_confirmado
ingreso_personal_confirmado
planilla_generada, planilla_aprobada
bonificacion_aprobada, bonificacion_aplicada
orden_descuento_creada, orden_descuento_finalizada
```

### Vista Frontend

`hr-rrhh/src/pages/HistorialPage.vue`

Tabla con filtros avanzados: empleado (buscador), tipo de acción (select), rango de fechas. Cada fila es expandible para ver el payload completo de la acción.

---

## 4. Error Logs

Sistema interno de registro de errores del sistema RRHH. Accesible solo para admin RRHH.

### API

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/rrhh/error-logs` | Lista de errores. Filtros: `resuelto`, `fecha_desde` |
| GET | `/api/rrhh/error-logs/stats` | Estadísticas de errores del período |
| POST | `/api/rrhh/error-logs/{id}/resolver` | Marcar error como resuelto con nota |
| DELETE | `/api/rrhh/error-logs/{id}` | Eliminar error (solo resueltos) |
| POST | `/api/rrhh/error-logs/resolver-todos` | Resolver todos los errores pendientes |
| DELETE | `/api/rrhh/error-logs/clear` | Limpiar errores resueltos antiguos |

### Controlador
`app/Http/Controllers/Api/RRHH/ErrorLogsController.php`

### Tabla BD (rrhh_db)

```sql
error_logs
  id
  modulo, tipo                           -- contexto del error
  mensaje, stack_trace
  contexto_json                          -- datos adicionales del error
  resuelto, resuelto_por, nota_resolucion
  created_at, resuelto_at
```

---

## 5. Calendario

Vista de calendario mensual del equipo, disponible para todos los colaboradores autenticados.

### API

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/rrhh/calendario` | Eventos del mes. Params: `mes`, `anio` |

El calendario consolida:
- Permisos aprobados del equipo
- Vacaciones aprobadas del equipo
- Incapacidades activas
- Días feriados

### Controlador
`app/Http/Controllers/Api/RRHH/CalendarioController.php`

Cada usuario ve el calendario filtrado a sus subordinados (o solo a él mismo si es empleado puro).
