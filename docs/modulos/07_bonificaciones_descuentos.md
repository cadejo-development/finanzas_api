# Módulo: Bonificaciones, KPIs y Órdenes de Descuento

> Bonificaciones · KPI Plantillas · Órdenes de Descuento · Acreedores  
> Accesible para todos los colaboradores (vista propia) y admins (gestión global)

---

## 1. Bonificaciones

### Propósito

Gestiona las bonificaciones adicionales al salario (productividad, puntualidad, cumplimiento de metas). Tiene un flujo de solicitud → aprobación → aplicación en la siguiente planilla.

### Flujo

```
Empleado / Jefe → solicita bonificación (tipo, monto, justificación)
                ← estado: "pendiente"

Admin RRHH / Gerencia Ops
  → aprueba la bonificación
    ← estado: "aprobada"
  → rechaza la bonificación
    ← estado: "rechazada"

Al generar la siguiente planilla
  → Las bonificaciones aprobadas y no aplicadas se incluyen automáticamente
  → estado cambia a "aplicada"
```

### API

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/rrhh/bonificaciones/tipos` | Catálogo de tipos de bonificación |
| POST | `/api/rrhh/bonificaciones/tipos` | Crear tipo |
| PATCH | `/api/rrhh/bonificaciones/tipos/{id}` | Actualizar tipo |
| PATCH | `/api/rrhh/bonificaciones/tipos/{id}/toggle` | Activar/desactivar tipo |
| GET | `/api/rrhh/bonificaciones/estados` | Catálogo de estados |
| GET | `/api/rrhh/bonificaciones` | Lista general (admin ve todas; empleado ve las propias) |
| GET | `/api/rrhh/bonificaciones/mis-bonificaciones` | Solo las del empleado autenticado |
| POST | `/api/rrhh/bonificaciones` | Solicitar bonificación |
| POST | `/api/rrhh/bonificaciones/{id}/aprobar` | Aprobar (Admin/Gerencia) |
| POST | `/api/rrhh/bonificaciones/{id}/rechazar` | Rechazar |
| POST | `/api/rrhh/bonificaciones/{id}/aplicar` | Marcar como aplicada (al procesar planilla) |

### Controlador
`app/Http/Controllers/Api/RRHH/BonificacionesController.php`

### Tipos de bonificación (configurable)

La tabla `tipos_bonificacion` permite crear nuevos tipos sin cambiar código:
- Productividad
- Puntualidad
- Cumplimiento de metas KPI
- Antigüedad
- Extraordinaria
- Otros

### Tablas BD (rrhh_db)

```sql
tipos_bonificacion
  id, nombre, descripcion, activo

estados_bonificacion
  id, nombre, codigo                     -- pendiente | aprobada | rechazada | aplicada

bonificaciones
  id, empleado_id, tipo_bonificacion_id, estado_bonificacion_id
  monto, periodo_aplicar                 -- quincena donde se aplicará
  justificacion
  aprobado_por, rechazado_por
  fecha_aprobacion, fecha_aplicacion
  planilla_id                            -- vinculación a la planilla donde se aplicó
  created_at, updated_at
```

---

## 2. KPI Plantillas (Solo Admin RRHH)

### Propósito

Define las metas e indicadores de desempeño (KPIs) por cargo. Permite evaluar a los colaboradores objetivamente y vincular el rendimiento a bonificaciones.

### API

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/rrhh/kpi-plantillas` | Lista de plantillas KPI activas |
| GET | `/api/rrhh/kpi-plantillas/{id}` | Detalle con todos los indicadores |
| POST | `/api/rrhh/kpi-plantillas` | Crear plantilla KPI |
| PATCH | `/api/rrhh/kpi-plantillas/{id}` | Actualizar |
| PATCH | `/api/rrhh/kpi-plantillas/{id}/toggle` | Activar/desactivar |
| DELETE | `/api/rrhh/kpi-plantillas/{id}` | Eliminar |
| GET | `/api/rrhh/kpi-plantillas/{id}/empleados-afectados` | Empleados con el cargo de esta plantilla |
| GET | `/api/rrhh/kpi-plantillas/cargos-disponibles` | Cargos sin plantilla KPI asignada |

### Controlador
`app/Http/Controllers/Api/RRHH/KpiPlantillasController.php`

### Tabla BD (rrhh_db)

```sql
kpi_plantillas
  id, nombre, cargo_id
  descripcion
  activa
  indicadores_json                       -- Array de indicadores con peso y meta
  created_at, updated_at
```

Estructura del `indicadores_json`:
```json
[
  { "nombre": "Ventas del período", "unidad": "USD", "meta": 5000, "peso": 40 },
  { "nombre": "Satisfacción del cliente", "unidad": "%", "meta": 90, "peso": 30 },
  { "nombre": "Puntualidad", "unidad": "días", "meta": 0, "peso": 30 }
]
```

---

## 3. Órdenes de Descuento

### Propósito

Registra descuentos de planilla no permanentes (préstamos a corto plazo, anticipos, penalizaciones). A diferencia de los acreedores permanentes, las órdenes de descuento tienen un monto total y se dividen en cuotas hasta pagarse.

### Flujo

```
Admin / Jefe → crea orden de descuento (empleado, monto total, cuotas)
             → el colaborador puede ver sus órdenes activas en /ordenes-descuento
             → cada período de planilla, la cuota correspondiente se descuenta automáticamente
             → al cubrir todas las cuotas: orden se marca como "finalizada"
```

### Sub-estado especial: Finiquito

Cuando un colaborador se desvincula con una orden de descuento activa que no está saldada, se genera un **finiquito** para documentar el saldo pendiente.

### API

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/rrhh/ordenes-descuento` | Lista general (admin) |
| GET | `/api/rrhh/ordenes-descuento/mis-ordenes` | Solo las del empleado autenticado |
| GET | `/api/rrhh/ordenes-descuento/estados` | Catálogo de estados |
| POST | `/api/rrhh/ordenes-descuento` | Crear orden |
| PATCH | `/api/rrhh/ordenes-descuento/{id}` | Actualizar |
| PATCH | `/api/rrhh/ordenes-descuento/{id}/estado` | Cambiar estado |
| POST | `/api/rrhh/ordenes-descuento/{id}/documento` | Subir documento de respaldo (S3) |
| GET | `/api/rrhh/ordenes-descuento/{id}/documento` | Descargar documento |
| POST | `/api/rrhh/ordenes-descuento/{id}/finiquitar` | Registrar finiquito por desvinculación |
| GET | `/api/rrhh/ordenes-descuento/{id}/finiquito` | Descargar documento de finiquito |

### Controlador
`app/Http/Controllers/Api/RRHH/OrdenesDescuentoController.php`

### Tabla BD (rrhh_db)

```sql
estados_orden_descuento
  id, nombre, codigo                     -- pendiente | activa | suspendida | finalizada | finiquitada

ordenes_descuento
  id, empleado_id, estado_orden_descuento_id
  concepto, descripcion
  monto_total, monto_cuota
  cuotas_totales, cuotas_pagadas
  fecha_inicio, fecha_fin               -- NULL fecha_fin = sin plazo definido
  documento_s3_key                       -- contrato de descuento firmado
  finiquito_s3_key                       -- documento de finiquito si aplica
  created_by, created_at, updated_at
```

---

## 4. Catálogo de Acreedores

### Propósito

Define las entidades externas a las que la empresa descuenta y transfiere dinero de la planilla (bancos, cooperativas, seguros, etc.). Cada acreedor tiene sus tipos de deducción y puede estar activo o inactivo.

Este módulo es accesible solo para **jefatura y admin** (`soloJefatura`).

### API

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/rrhh/acreedores/tipos` | Tipos de acreedor (banco, cooperativa, etc.) |
| POST | `/api/rrhh/acreedores/tipos` | Crear tipo |
| PATCH | `/api/rrhh/acreedores/tipos/{id}` | Actualizar tipo |
| PATCH | `/api/rrhh/acreedores/tipos/{id}/toggle` | Activar/desactivar tipo |
| GET | `/api/rrhh/acreedores` | Lista de acreedores |
| POST | `/api/rrhh/acreedores` | Crear acreedor |
| PATCH | `/api/rrhh/acreedores/{id}` | Actualizar |
| PATCH | `/api/rrhh/acreedores/{id}/toggle` | Activar/desactivar acreedor |

### Controlador
`app/Http/Controllers/Api/RRHH/AcreedoresController.php`

### Tablas BD (rrhh_db)

```sql
tipos_acreedor
  id, nombre, activo                     -- banco | cooperativa | seguro | otro

acreedores
  id, nombre, tipo_acreedor_id
  cuenta_destino, banco_destino
  activo
  created_at
```

---

## 5. Saldos Cadejo

Sistema interno de "créditos" de beneficios para empleados (descuentos en cervezas, bonos de consumo en sucursales, etc.).

### API

```
GET  /api/rrhh/permisos/saldos-cadejo    -- Saldo disponible del empleado
```

### Tabla BD (rrhh_db)

```sql
saldos_cadejo
  id, empleado_id
  anio, mes
  saldo_inicial, incremento, decremento, saldo_final
  concepto
  created_at
```

---

## 6. Vista Frontend

**Archivos**:
- `hr-rrhh/src/pages/BonificacionesPage.vue` — Vista del empleado + admin
- `hr-rrhh/src/pages/OrdenesDescuentoPage.vue` — Vista del empleado + admin
- `hr-rrhh/src/pages/admin/TiposBonificacionPage.vue` — Catálogo (jefatura+)
- `hr-rrhh/src/pages/admin/CatalogoAcreedoresPage.vue` — Acreedores (jefatura+)
- `hr-rrhh/src/pages/admin/KpiPlantillasPage.vue` — KPIs (solo admin RRHH)

### Vistas condicionales por rol

Las páginas de Bonificaciones y Órdenes de Descuento muestran contenido diferente según el rol:
- **Empleado**: Solo ve sus propios registros, con filtros de estado
- **Admin/Analista/Jefe**: Ve todos los registros, con selector de empleado y buscador
