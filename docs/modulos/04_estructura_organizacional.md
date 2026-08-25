# Módulo: Estructura Organizacional

> Organigrama · Catálogo de Puestos · Catálogo de Plazas  
> Guard: `soloAnalistaSr` (Organigrama) · `soloAnalistas` (Puestos y Plazas)

---

## 1. Organigrama / Departamentos

### Propósito

Define la estructura jerárquica de la empresa. Los departamentos forman un árbol donde cada nodo tiene un padre, empleados miembros y un jefe designado. Esta jerarquía es la que usa `RRHHBaseController::getSubordinadosIds()` para determinar qué empleados puede gestionar cada jefatura.

### Estructura del árbol

```
Gerencia General
├── Operaciones (gerencia_ops)
│   ├── Sucursal Zona Rosa (jefe: gerente restaurante)
│   │   ├── Cocina
│   │   ├── Bar
│   │   └── Salón
│   ├── Sucursal Multiplaza
│   └── Centro de Producción
├── Administración y Finanzas
│   ├── Contabilidad
│   └── Informática (código: GEN_INF)
└── Talento Humano
```

### API

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/rrhh/departamentos` | Árbol completo de departamentos |
| POST | `/api/rrhh/departamentos` | Crear departamento |
| PATCH | `/api/rrhh/departamentos/{id}` | Actualizar nombre / padre / jefe |
| DELETE | `/api/rrhh/departamentos/{id}` | Eliminar (solo si no tiene empleados) |
| GET | `/api/rrhh/departamentos/{id}/empleados` | Empleados del departamento |
| GET | `/api/rrhh/departamentos/todos-empleados` | Todos los empleados con su departamento |
| POST | `/api/rrhh/departamentos/{id}/asignar-empleado` | Asignar empleado al departamento |
| DELETE | `/api/rrhh/departamentos/{id}/empleados/{empId}` | Quitar empleado del departamento |
| POST | `/api/rrhh/departamentos/{id}/jefe` | Asignar jefe al departamento |
| DELETE | `/api/rrhh/departamentos/{id}/jefe` | Quitar jefe del departamento |

### Controlador
`app/Http/Controllers/Api/RRHH/DepartamentosController.php`

La relación entre empleados y departamentos se mantiene en **dos lugares paralelos**:
1. `empleados.departamento_id` en `core_db` (pgsql) — campo principal para filtros
2. Tabla de organigrama en `rrhh_db` — para la vista visual del organigrama

> **Importante**: El código del departamento `GEN_INF` está hardcodeado en `TrasladosController` para enviar notificaciones al equipo de Informática cuando se aprueba un traslado.

### Tabla BD (core_db — pgsql)

```sql
departamentos
  id, nombre, codigo                     -- codigo: GEN_INF, OPE_ZR, etc.
  parent_id                              -- referencia al departamento padre
  jefe_empleado_id                       -- empleado designado como jefe
  activo
  created_at, updated_at
```

### Vista Frontend
`hr-rrhh/src/pages/admin/DepartamentosPage.vue`

Muestra el organigrama en vista de árbol interactivo. Permite drag-and-drop para reasignar departamentos padre (requiere confirmación). El jefe del departamento se muestra con un ícono distintivo.

---

## 2. Catálogo de Puestos (Cargos)

### Propósito

Define los puestos de trabajo existentes en la empresa. Cada puesto tiene un headcount autorizado (cantidad máxima de colaboradores que pueden ocuparlo). Las plazas y los empleados se vinculan a un puesto de este catálogo.

### API

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/rrhh/cargos` | Lista todos los puestos. Filtros: `activo`, `buscar` |
| POST | `/api/rrhh/cargos` | Crear nuevo puesto |
| PATCH | `/api/rrhh/cargos/{id}` | Actualizar nombre / descripción / salario base |
| GET | `/api/rrhh/cargos/{id}/headcount` | Ver headcount autorizado vs. ocupado actual |
| PATCH | `/api/rrhh/cargos/{id}/headcount` | Actualizar headcount autorizado |
| PATCH | `/api/rrhh/cargos/{id}/toggle-activo` | Activar / desactivar puesto |

### Controlador
`app/Http/Controllers/Api/RRHH/CargosController.php`

El método `headcount()` cruza datos entre `cargos` (pgsql) y `plazas` (rrhh_db) para mostrar cuántas plazas están ocupadas vs. el máximo autorizado.

### Tabla BD (core_db — pgsql)

```sql
cargos
  id, nombre, descripcion
  salario_base_min, salario_base_max     -- banda salarial del puesto
  headcount_autorizado
  activo
  created_at, updated_at
```

### Vista Frontend
`hr-rrhh/src/pages/admin/CatalogoPuestosPage.vue`

Tabla con buscador y filtro de activos/inactivos. Modal de edición con campo de headcount. Indicador visual cuando el headcount actual supera el autorizado.

---

## 3. Catálogo de Plazas

### Propósito

Las plazas son las posiciones individuales que pueden estar ocupadas o vacantes. Cada plaza pertenece a un puesto (cargo) y tiene un historial de quién la ha ocupado con fechas de inicio y fin.

**Diferencia clave**: El puesto define el rol; la plaza es la "silla" específica.  
Ejemplo: El puesto "Cajero" puede tener 3 plazas (Plaza A, Plaza B, Plaza C). Si una plaza queda vacante, el puesto sigue existiendo.

### API

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/rrhh/plazas` | Lista de plazas. Filtros: `activa`, `cargo_id`, `sucursal_id` |
| POST | `/api/rrhh/plazas` | Crear plaza |
| PATCH | `/api/rrhh/plazas/{id}` | Actualizar nombre / puesto / sucursal |
| PATCH | `/api/rrhh/plazas/{id}/toggle-activo` | Activar / desactivar plaza |
| GET | `/api/rrhh/plazas/{id}/stats` | Estadísticas: tiempo en plaza, rotación |
| GET | `/api/rrhh/plazas/{id}/historial` | Historial completo de ocupantes |
| DELETE | `/api/rrhh/plazas/{id}` | Eliminar plaza (solo si está vacante y sin historial) |

### Controlador
`app/Http/Controllers/Api/RRHH/PlazasController.php`

Cuando un colaborador ingresa al sistema (onboarding), se le asigna una plaza y se crea un registro en `plazas_historial`. Al desvincularse, el registro de historial se cierra con `fecha_fin` y la plaza queda vacante.

### Tablas BD (rrhh_db)

```sql
plazas
  id, nombre, codigo_interno
  cargo_id                               -- puesto al que pertenece
  sucursal_id                            -- sucursal donde está la plaza
  activa, empleado_id_actual             -- NULL si está vacante
  created_at, updated_at

plazas_historial
  id, plaza_id, empleado_id
  fecha_inicio, fecha_fin               -- NULL fecha_fin = actualmente en esta plaza
  motivo_salida                         -- traslado | renuncia | despido | etc.
```

### Vista Frontend
`hr-rrhh/src/pages/admin/CatalogoPlazasPage.vue`

Vista de tabla con indicadores de estado (ocupada/vacante) en colores. Panel lateral de historial de ocupación. Indicador de tiempo en plaza del ocupante actual.

---

## 4. Relación entre Puestos y Plazas

```
Cargo (Puesto)
  ↓  headcount_autorizado = 3
  ├── Plaza A  → empleado_id: 45  (ocupada)
  ├── Plaza B  → empleado_id: 67  (ocupada)
  └── Plaza C  → empleado_id: NULL (vacante)

Al ingresar un nuevo colaborador:
  1. Se crea/asigna una plaza vacante del cargo correcto
  2. Se actualiza plaza.empleado_id_actual
  3. Se crea un registro en plazas_historial con fecha_inicio
  4. El empleado queda vinculado al cargo en empleados.cargo_id (pgsql)
```

---

## 5. Integración con el Sistema de Jerarquía

Los departamentos son la base del sistema de autorización de `RRHHBaseController`. Al resolver qué empleados puede gestionar un jefe:

1. Se buscan todos los departamentos donde `jefe_empleado_id = jefeActualId`
2. Se obtienen todos los empleados con `departamento_id IN (esos_ids)`
3. Se ejecuta una CTE recursiva para incluir también los jefes de departamentos hijos

Ver detalles en la sección "Sistema de Roles" del documento de arquitectura general.
