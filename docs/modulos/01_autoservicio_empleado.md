# Módulo: Autoservicio del Empleado

> Permisos · Vacaciones · Incapacidades  
> Accesible para **todos** los colaboradores autenticados

---

## 1. Propósito

Permite a cada colaborador gestionar sus propias solicitudes sin depender de que un analista las ingrese manualmente. Las solicitudes crean un registro en `rrhh_db` con estado `pendiente` y disparan un email de notificación al supervisor inmediato para su aprobación.

---

## 2. Permisos

### Flujo completo

```
Empleado → solicita permiso (tipo, fecha, observaciones, documento opcional)
         ← estado: "pendiente"
         → Email automático al supervisor para aprobación

Jefe     → aprueba o rechaza desde la lista
         ← estado: "aprobado" | "rechazado"
         → Email al empleado notificando la resolución
```

### API

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/rrhh/permisos` | Lista permisos. Admin/Analista: todos. Jefatura: su equipo. Empleado: solo los propios |
| POST | `/api/rrhh/permisos` | Crear solicitud de permiso |
| GET | `/api/rrhh/permisos/{id}` | Detalle de un permiso |
| PATCH | `/api/rrhh/permisos/{id}` | Actualizar (aprobar / rechazar / editar) |
| DELETE | `/api/rrhh/permisos/{id}` | Eliminar (solo si está pendiente) |
| GET | `/api/rrhh/permisos/{id}/descargar` | Descargar documento adjunto (URL S3 pre-firmada) |
| GET | `/api/rrhh/permisos/saldos` | Saldos de días de permiso por tipo del empleado |
| GET | `/api/rrhh/permisos/saldos-cadejo` | Saldos específicos de días Cadejo |

### Controlador
`app/Http/Controllers/Api/RRHH/PermisosController.php`

Métodos clave:
- `index()` — filtra por `getSubordinadosIds()` según rol
- `store()` — determina estado inicial con `estadoParaEmpleado()`, dispara `notificarSolicitud()` si aplica
- `update()` — permite aprobar/rechazar; si hay cambio de estado, notifica al empleado
- `saldos()` — retorna saldo de días disponibles por `tipo_permiso_id`

### Tipos de permiso

Los tipos se mantienen en la tabla `tipos_permiso` (rrhh_db). Cada tipo define:
- Nombre y descripción
- Si requiere documento de respaldo
- Si descuenta días del saldo disponible
- Si aplica a todos los empleados o solo a ciertos cargos

### Tabla BD (rrhh_db)

```sql
permisos
  id, empleado_id, tipo_permiso_id
  fecha_inicio, fecha_fin, dias_solicitados
  motivo, estado                          -- pendiente | aprobado | rechazado
  aprobado_por, fecha_aprobacion
  archivo_s3_key                          -- NULL si no requiere documento
  created_at, updated_at
```

---

## 3. Vacaciones

### Flujo completo

```
Empleado → solicita período de vacaciones (fecha inicio, fecha fin)
         ← estado: "pendiente"
         → Email al supervisor

Jefe     → aprueba o rechaza
         ← saldo de días se descuenta al aprobar
```

### API

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/rrhh/vacaciones` | Lista de solicitudes de vacaciones |
| POST | `/api/rrhh/vacaciones` | Crear solicitud |
| GET | `/api/rrhh/vacaciones/{id}` | Detalle |
| PATCH | `/api/rrhh/vacaciones/{id}` | Actualizar / aprobar / rechazar |
| DELETE | `/api/rrhh/vacaciones/{id}` | Eliminar (solo pendientes) |
| GET | `/api/rrhh/vacaciones/saldos` | Saldo de días disponibles del empleado |

### Controlador
`app/Http/Controllers/Api/RRHH/VacacionesController.php`

El saldo de vacaciones se mantiene en la tabla `saldos_vacaciones` (rrhh_db), con un registro por empleado y año. El método `saldos()` calcula los días disponibles restando las vacaciones aprobadas del saldo base anual.

### Tabla BD (rrhh_db)

```sql
vacaciones
  id, empleado_id
  fecha_inicio, fecha_fin, dias_solicitados
  estado                                  -- pendiente | aprobado | rechazado
  aprobado_por, fecha_aprobacion
  observaciones
  created_at, updated_at

saldos_vacaciones
  id, empleado_id, anio
  dias_disponibles, dias_tomados, dias_pendientes
```

---

## 4. Incapacidades

### Flujo completo

Las incapacidades son informativas — no requieren aprobación previa, pero deben registrarse con documento médico. El sistema notifica al supervisor como acción informativa (no como solicitud de aprobación).

```
Empleado → registra incapacidad (tipo, fechas, documento médico)
         ← estado: "activo" (no requiere aprobación)
         → Email informativo al supervisor
```

### API

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/rrhh/incapacidades` | Lista de incapacidades |
| POST | `/api/rrhh/incapacidades` | Registrar incapacidad |
| GET | `/api/rrhh/incapacidades/{id}` | Detalle |
| PATCH | `/api/rrhh/incapacidades/{id}` | Actualizar (mientras está activa) |
| DELETE | `/api/rrhh/incapacidades/{id}` | Eliminar |

### Controlador
`app/Http/Controllers/Api/RRHH/IncapacidadesController.php`

A diferencia de permisos y vacaciones, usa `notificarAccion()` (no `notificarSolicitud()`) porque no hay aprobación pendiente.

### Tipos de incapacidad

Tabla `tipos_incapacidad` (rrhh_db): ISSS, médico particular, accidente laboral, maternidad, etc.

### Tabla BD (rrhh_db)

```sql
incapacidades
  id, empleado_id, tipo_incapacidad_id
  fecha_inicio, fecha_fin, dias
  descripcion
  archivo_s3_key                          -- documento médico obligatorio
  created_at, updated_at
```

---

## 5. Lógica de Estado Inicial

Los tres módulos usan la misma lógica de `estadoParaEmpleado()` del `RRHHBaseController`:

```
Admin RRHH / Analista ingresando para otro empleado → "aprobado"
Jefe ingresando para un subordinado               → "aprobado"
Cualquier usuario ingresando para sí mismo        → "pendiente"
Empleado puro                                     → "pendiente"
```

Esta regla garantiza que cuando RRHH ingresa una solicitud retroactiva ya resuelta, no quede pendiente innecesariamente.

---

## 6. Notificaciones de Email

| Evento | Destinatario | Mailable |
|--------|-------------|---------|
| Solicitud creada (pendiente) | Supervisor inmediato | `SolicitudAprobacion` (con links aprobar/rechazar) |
| Solicitud aprobada | Empleado | `NotificacionAlEmpleado` |
| Solicitud rechazada | Empleado | `NotificacionAlEmpleado` |
| Incapacidad registrada | Supervisor inmediato | `AccionPersonalNotificacion` |

Todos los envíos se registran en `email_logs` (core_db, sistema = `rrhh`).

---

## 7. Vista Frontend

**Archivo**: `hr-rrhh/src/pages/permisos/PermisosPage.vue`  
**Archivo**: `hr-rrhh/src/pages/vacaciones/VacacionesPage.vue`  
**Archivo**: `hr-rrhh/src/pages/incapacidades/IncapacidadesPage.vue`

Las tres páginas comparten el mismo patrón UX:
- Tabla con filtros (estado, fechas, empleado — este último solo visible para analistas/admin)
- Modal de creación con formulario validado
- Modal de detalle con botones de aprobar/rechazar (visibles según rol)
- Badge de estado con color (pendiente=amarillo, aprobado=verde, rechazado=rojo)
