# Módulo: Gestión de Personal

> Ausencias · Amonestaciones · Desvinculaciones · Traslados · Cambios Salariales  
> Accesible para **Analistas RRHH y Administradores** (guard: `soloAnalistas` o `soloAnalistaSr`)

---

## 1. Ausencias Injustificadas

Registro de días en que el colaborador no se presentó sin justificación válida. Afectan directamente el cálculo de planilla (descuento proporcional).

### API

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/rrhh/ausencias` | Lista de ausencias. Filtros: `empleado_id`, `mes`, `anio` |
| POST | `/api/rrhh/ausencias` | Registrar ausencia |
| DELETE | `/api/rrhh/ausencias/{id}` | Eliminar (solo si la planilla del mes no está cerrada) |
| GET | `/api/rrhh/ausencias/resumen-mes` | Resumen de ausencias por empleado del mes activo |
| POST | `/api/rrhh/ausencias/regularizar` | Marcar ausencias como regularizadas (excusa posterior) |

### Controlador
`app/Http/Controllers/Api/RRHH/AusenciasController.php`

El método `resumenMes()` es consumido por el módulo de Planillas para calcular descuentos por ausencia del período.

### Tabla BD (rrhh_db)

```sql
ausencias_injustificadas
  id, empleado_id
  fecha, regularizada
  observaciones, registrado_por
  created_at
```

---

## 2. Amonestaciones

Registro formal de faltas disciplinarias. Genera un documento PDF que debe firmar el colaborador. Según la gravedad, puede requerir aprobación de Gerencia de Operaciones o de la Jefa de RRHH.

### Flujo por gravedad

```
Tipo de falta → LEVE
  Analista registra → estado: "aprobado"
  Se notifica al empleado con PDF adjunto
  Se notifica al supervisor del empleado

Tipo de falta → GRAVE (suspensión de propina)
  Analista registra → estado: "pendiente"
  Se envía email a gerencia_ops para aprobar/rechazar (link en email)
  Al aprobar → estado: "aprobado", se notifica al empleado

Tipo de falta → MUY GRAVE (decisión Jefa RRHH)
  Analista registra → estado: "pendiente"
  Se envía email a Gabriela Bustamante (jefa@cervezacadejo.com) para aprobar/rechazar
  Al aprobar → estado: "aprobado"
```

### API

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/rrhh/amonestaciones` | Lista filtrada por rol |
| POST | `/api/rrhh/amonestaciones` | Registrar amonestación |
| GET | `/api/rrhh/amonestaciones/{id}` | Detalle |
| PATCH | `/api/rrhh/amonestaciones/{id}` | Actualizar / aprobar / rechazar |
| DELETE | `/api/rrhh/amonestaciones/{id}` | Eliminar (solo pendientes) |
| GET | `/api/rrhh/amonestaciones/{id}/pdf` | Generar PDF (vista previa) |
| GET | `/api/rrhh/amonestaciones/{id}/descargar` | Descargar PDF final |

### Controlador
`app/Http/Controllers/Api/RRHH/AmonestacionesController.php`

El PDF se genera con DomPDF usando la plantilla `resources/views/pdf/amonestacion.blade.php`.

### Tabla BD (rrhh_db)

```sql
amonestaciones
  id, empleado_id, tipo_falta_id
  fecha, descripcion, estado            -- pendiente | aprobado | rechazado
  dias_suspension                       -- días de suspensión sin pago
  dias_suspension_propina               -- días de suspensión de propina
  aprobado_por, rechazado_por
  created_at, updated_at

tipos_falta
  id, nombre, gravedad                  -- leve | grave | muy_grave
  requiere_aprobacion_gerencia_ops
  requiere_aprobacion_rrhh
```

---

## 3. Desvinculaciones

Registro de salida de colaboradores, ya sea por despido o renuncia. Genera carta de finiquito. Notifica automáticamente a todos los admins RRHH.

### Flujo

```
Analista → registra desvinculación (tipo, fecha, motivo, observaciones)
         → genera carta de finiquito (PDF DomPDF)
         → notifica a todos los rrhh_admin por email
         → notifica a gerencia_ops si es empleado de sucursal operativa
         → el empleado queda inactivo en core (activo = false)
```

### Sub-tipos
- **Despidos** (`/desvinculaciones/despidos`): salida involuntaria. Requiere motivo de desvinculación.
- **Renuncias** (`/desvinculaciones/renuncias`): salida voluntaria. Requiere carta de renuncia firmada.

### API

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/rrhh/desvinculaciones` | Lista. Filtro: `tipo` (despido/renuncia) |
| POST | `/api/rrhh/desvinculaciones` | Registrar desvinculación |
| GET | `/api/rrhh/desvinculaciones/{id}` | Detalle |
| PATCH | `/api/rrhh/desvinculaciones/{id}` | Actualizar (solo antes de confirmar) |
| DELETE | `/api/rrhh/desvinculaciones/{id}` | Eliminar (reversión del proceso) |
| GET | `/api/rrhh/desvinculaciones/{id}/descargar` | Descargar carta de finiquito (PDF) |

### Controlador
`app/Http/Controllers/Api/RRHH/DesvinculacionesController.php`

### Tabla BD (rrhh_db)

```sql
desvinculaciones
  id, empleado_id, tipo                 -- despido | renuncia
  motivo_desvinculacion_id, fecha_salida
  observaciones, prestaciones_calculadas
  estado                                -- borrador | confirmado
  created_at, updated_at

motivos_desvinculacion
  id, nombre, tipo                      -- despido | renuncia | ambos
  activo
```

---

## 4. Traslados

Cambio de sucursal, departamento o cargo de un colaborador. Al aprobarse, notifica automáticamente al equipo de Informática (departamento `GEN_INF`) para que actualice los accesos de sistema.

### Flujo

```
Analista → registra traslado (empleado, sucursal destino / dept destino / cargo nuevo)
         ← estado inicial: según estadoParaEmpleado()
         → Si aprobado: actualiza datos en empleados (core) y notifica al depto GEN_INF
         → Si pendiente: email al supervisor del empleado
```

### API

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/rrhh/traslados` | Lista de traslados |
| POST | `/api/rrhh/traslados` | Registrar traslado |
| GET | `/api/rrhh/traslados/{id}` | Detalle |
| PATCH | `/api/rrhh/traslados/{id}` | Actualizar / aprobar / rechazar |
| DELETE | `/api/rrhh/traslados/{id}` | Eliminar |

### Controlador
`app/Http/Controllers/Api/RRHH/TrasladosController.php`

La notificación al departamento de Informática usa `notificarDepartamentoCodigo('GEN_INF', ...)` del `RRHHBaseController`.

### Tabla BD (rrhh_db)

```sql
traslados
  id, empleado_id
  sucursal_origen_id, sucursal_destino_id
  departamento_origen_id, departamento_destino_id
  cargo_origen_id, cargo_destino_id
  fecha_efectiva, estado
  motivo, aprobado_por
  created_at, updated_at
```

---

## 5. Cambios Salariales

Registra aumentos de salario y nivelaciones salariales. Requiere carga de documento firmado en S3. Solo accesible para Analista Sr (`soloAnalistaSr`).

### Sub-tipos
- **Aumento Salarial** (`/modificaciones/aumento-salarial`): incremento porcentual o fijo sobre el salario actual.
- **Nivelación Salarial** (`/modificaciones/nivelacion-salarial`): ajuste para llevar el salario a la banda del puesto.

### Flujo con documento S3

```
1. Analista registra el cambio salarial → POST /api/rrhh/cambios-salariales
2. Sistema genera el documento borrador (DomPDF)
3. Analista obtiene URL pre-firmada → GET /presign-documento
4. Frontend sube el PDF firmado directamente a S3
5. Confirma la subida → POST /confirmar-documento
6. El cambio salarial queda en estado "confirmado"
7. La diferencia salarial se aplica desde la próxima planilla
```

### API

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/rrhh/cambios-salariales` | Lista |
| POST | `/api/rrhh/cambios-salariales` | Registrar cambio |
| GET | `/api/rrhh/cambios-salariales/{id}` | Detalle |
| PATCH | `/api/rrhh/cambios-salariales/{id}` | Actualizar |
| DELETE | `/api/rrhh/cambios-salariales/{id}` | Eliminar |
| GET | `/api/rrhh/cambios-salariales/{id}/presign-documento` | URL pre-firmada S3 para subir el doc firmado |
| POST | `/api/rrhh/cambios-salariales/{id}/confirmar-documento` | Confirmar subida del documento |
| DELETE | `/api/rrhh/cambios-salariales/{id}/documento` | Eliminar documento de S3 |

### Controlador
`app/Http/Controllers/Api/RRHH/CambiosSalarialesController.php`

### Tabla BD (rrhh_db)

```sql
cambios_salariales
  id, empleado_id, tipo_aumento_salarial_id
  salario_anterior, salario_nuevo, porcentaje_aumento
  fecha_efectiva, estado                -- borrador | con_documento | confirmado
  documento_s3_key
  motivo, aprobado_por
  created_at, updated_at

tipos_aumento_salarial
  id, nombre                            -- aumento_merito | nivelacion | ajuste_costo_vida
  activo
```

---

## 6. Notas Comunes

### Guards de acceso

| Módulo | Guard | Quién puede acceder |
|--------|-------|---------------------|
| Ausencias, Amonestaciones, Traslados, Desvinculaciones | `soloAnalistas` | rrhh_analista_jr, rrhh_analista, admin |
| Aumento Salarial, Nivelación Salarial | `soloAnalistaSr` | rrhh_analista, admin (NO analista_jr) |

### Scoping de datos
Todos estos módulos filtran registros usando `getSubordinadosIds()`:
- Admin y analistas ven todos los empleados
- Jefaturas ven solo su equipo
- Empleados no tienen acceso a estos módulos
