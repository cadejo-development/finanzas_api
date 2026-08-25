# Sistema de Talento Humano — Visión General y Arquitectura

> **Versión**: 1.0 — Julio 2026  
> **Empresa**: Cadejo Brewing Company  
> **Mantenido por**: Equipo de Tecnología

---

## 1. Visión del Sistema

El **Sistema de Talento Humano** es la plataforma centralizada para la gestión del capital humano de Cadejo Brewing Company. Cubre el ciclo completo del colaborador: desde el pre-registro como candidato hasta la desvinculación, pasando por expedientes digitales, permisos, planillas y desarrollo de carrera.

### Propósito
- Centralizar toda la información de los colaboradores en un expediente digital único
- Automatizar los procesos de RRHH: permisos, amonestaciones, planillas, bonificaciones
- Proveer a jefaturas y analistas un panel de control en tiempo real
- Ofrecer autoservicio al empleado para solicitudes y consultas de sus propios datos

### Alcance
Atiende a **todos los colaboradores activos** distribuidos en las distintas sucursales y centros de producción de Cadejo Brewing Company.

---

## 2. Stack Tecnológico

### Backend

| Componente         | Tecnología              |
|--------------------|-------------------------|
| Framework          | Laravel 12.x (PHP 8.3)  |
| ORM                | Eloquent                |
| Autenticación      | Laravel Sanctum (tokens)|
| Generación de PDFs | DomPDF                  |
| Storage documentos | Amazon S3 (URLs pre-firmadas) |
| Email              | SMTP + registro en `email_logs` |

### Frontend

| Componente     | Tecnología                                  |
|----------------|---------------------------------------------|
| Framework      | Vue 3 (Composition API, `<script setup>`)   |
| Build          | Vite                                        |
| Estilos        | Tailwind CSS                                |
| Router         | Vue Router 4                                |
| HTTP           | Axios (composable `useApi`)                 |
| Estado de auth | Composable `useAuth` (store reactivo en memoria) |

### Bases de Datos

| Alias Laravel | Base PostgreSQL      | Propósito                                                        |
|---------------|----------------------|------------------------------------------------------------------|
| `pgsql`       | `core_db` (AWS RDS)  | Usuarios, empleados, sucursales, catálogos core, `email_logs`    |
| `rrhh`        | `rrhh_db` (AWS RDS)  | Todos los registros RRHH: permisos, planillas, expedientes, etc. |

---

## 3. Infraestructura y Deploy

```
  ┌─────────────────────────────────────────────────────────────┐
  │                      AWS App Runner                          │
  │                                                              │
  │  [GitHub rama main]       → Frontend Vue 3 (hr-rrhh)        │
  │                              Build Vite → distribución       │
  │                                                              │
  │  [GitHub rama produccion] → Backend Laravel (finanzas_api)  │
  │                              PHP-FPM + Nginx                 │
  └─────────────────────────────┬───────────────────────────────┘
                                │
  ┌─────────────────────────────▼───────────────────────────────┐
  │                   AWS RDS PostgreSQL                         │
  │          core_db                │          rrhh_db           │
  └─────────────────────────────────────────────────────────────┘
```

**Reglas de deploy:**
- Push a `main` → Auto-deploy del **frontend**
- Push a `produccion` → Auto-deploy del **backend**
- Las migraciones **NO se ejecutan automáticamente** — deben correrse manualmente con el cliente Node.js (`pg`)
- PHP no está disponible en el entorno local; los cambios de BD se ejecutan desde Node.js

### Repositorios

| Repositorio    | Path local                                    | Rama deploy  | Propósito              |
|----------------|-----------------------------------------------|--------------|------------------------|
| `finanzas_api` | `c:\Users\administrator\finanzas_api`         | `produccion` | Backend Laravel (API)  |
| `hr-rrhh`      | `c:\Users\administrator\hr-rrhh`              | `main`       | Frontend Vue 3 RRHH    |

---

## 4. Sistema de Roles y Acceso

Los roles están registrados en la tabla `roles` de `core_db`, vinculados a los usuarios mediante la tabla pivote `role_user`, bajo el sistema de código `rrhh`.

### Tabla de Roles

| Código               | Nombre funcional       | Descripción                                                            |
|----------------------|------------------------|------------------------------------------------------------------------|
| `rrhh_admin`         | Administrador RRHH     | Acceso total: planillas, KPIs, configuración, ViewAs                   |
| `portal_admin`       | Administrador Portal   | Equivalente a `rrhh_admin` en acceso                                   |
| `gerencia_ops`       | Gerencia de Operaciones| Acceso completo de lectura/escritura (sin planillas ni KPIs)           |
| `jefatura`           | Jefatura               | Gestiona su propio equipo/sucursal; acceso a horarios y reportes       |
| `rrhh_analista`      | Analista RRHH Senior   | Acceso a todos los módulos operativos, nivelaciones, organigrama       |
| `rrhh_analista_jr`   | Analista RRHH Junior   | Acceso reducido: permisos, incapacidades, expedientes, puestos, plazas |
| `empleado`           | Colaborador            | Solo autoservicio: sus propios permisos, horario, expediente           |

### Jerarquía de Guards del Router

```
soloRrhhAdmin
  └─ Solo rrhh_admin

soloAnalistaSr
  └─ rrhh_admin | portal_admin | gerencia_ops | jefatura | rrhh_analista

soloAnalistas
  └─ Todo lo anterior + rrhh_analista_jr

soloJefatura
  └─ rrhh_admin | portal_admin | gerencia_ops | jefatura
     (bloquea analistas)

requiresAuth
  └─ Cualquier usuario autenticado (incluyendo empleados)
```

### Implementación en el Backend

Todos los controladores RRHH extienden `RRHHBaseController`, que provee:

```php
esAdminRrhh()          // rrhh_admin | portal_admin | gerencia_ops
esJefatura()           // rol jefatura
esAnalistaRrhh()       // rrhh_analista O rrhh_analista_jr (ambos niveles)
esGerenciaOps()        // gerencia_ops
getSubordinadosIds()   // IDs de empleados bajo la jefatura autenticada
getEffectiveUser()     // Soporte ViewAs: admin puede inspeccionar otro usuario (solo GET)
```

> **Constraint crítico de email**: `david@cervezacadejo.com` está excluido permanentemente
> de TODAS las notificaciones de email de RRHH. Este exclusión está hardcodeada en
> `RRHHBaseController` y **no debe removerse bajo ninguna circunstancia**.

---

## 5. Autenticación y SSO

El sistema usa **Laravel Sanctum** con tokens de sesión por sistema. El token se envía en el header `Authorization: Bearer <token>`.

### Flujo de Autenticación

```
1. POST /api/auth/login
   Body: { email, password, sistema: "rrhh" }

2. Backend:
   a. Valida credenciales y que usuario.activo = true
   b. Revoca tokens anteriores del sistema "rrhh"
   c. Emite nuevo token Sanctum
   d. Resuelve roles del sistema "rrhh" desde core_db
   e. Resuelve empleado_id y sucursal_id (con fallback desde empleados)

3. Respuesta: { token, user: { id, roles, sucursal_id, empleado_id, ... } }

4. Frontend guarda token en useAuth (memoria), lo adjunta a cada request
```

### SSO (Single Sign-On)

Si la URL llega con `?sso_token=<token>`, el guard del router Vue lo procesa automáticamente sin mostrar el login. Permite integración entre sistemas (e.g., portal principal → RRHH).

### Resolución de sucursal_id

El campo `users.sucursal_id` puede ser `null` en usuarios que son empleados sin sucursal explícita asignada en su cuenta. El `AuthController` aplica un fallback desde `empleados.sucursal_id`:

```php
$sucursalIdEfectivo = $user->sucursal_id ?? $empRow?->emp_sucursal_id;
```

Esto garantiza que gerentes y colaboradores cuya sucursal solo esté en `empleados` reciban correctamente su `sucursal_id` en el payload de autenticación.

---

## 6. Mapa Completo de Módulos

| #  | Módulo                    | Ruta(s)                                  | Guard           | Quién accede                    |
|----|---------------------------|------------------------------------------|-----------------|---------------------------------|
| 1  | Dashboard                 | `/`                                      | requiresAuth    | Todos los usuarios              |
| 2  | Permisos                  | `/permisos`                              | requiresAuth    | Todos                           |
| 3  | Vacaciones                | `/vacaciones`                            | requiresAuth    | Todos                           |
| 4  | Incapacidades             | `/incapacidades`                         | requiresAuth    | Todos                           |
| 5  | Mi Horario                | `/mi-horario`                            | requiresAuth    | Todos                           |
| 6  | Mi Expediente             | `/mi-expediente`                         | requiresAuth    | Todos (redirige a `/expediente/:id`) |
| 7  | Órdenes de Descuento      | `/ordenes-descuento`                     | requiresAuth    | Todos                           |
| 8  | Bonificaciones            | `/bonificaciones`                        | requiresAuth    | Todos                           |
| 9  | Amonestaciones            | `/amonestaciones`                        | soloAnalistas   | Analistas + Admin               |
| 10 | Despidos                  | `/desvinculaciones/despidos`             | soloAnalistas   | Analistas + Admin               |
| 11 | Renuncias                 | `/desvinculaciones/renuncias`            | soloAnalistas   | Analistas + Admin               |
| 12 | Traslados                 | `/modificaciones/traslados`              | soloAnalistas   | Analistas + Admin               |
| 13 | Expedientes (lista)       | `/expediente`                            | soloAnalistas   | Analistas + Admin               |
| 14 | Expediente Detalle        | `/expediente/:id`                        | requiresAuth    | Analistas + el propio empleado  |
| 15 | Historial de Acciones     | `/historial`                             | soloAnalistas   | Analistas + Admin               |
| 16 | Ausencias Injustificadas  | `/ausencias`                             | soloAnalistas   | Analistas + Admin               |
| 17 | Ingreso de Personal       | `/ingresos-personal`                     | soloAnalistas   | Analistas + Admin               |
| 18 | Aumento Salarial          | `/modificaciones/aumento-salarial`       | soloAnalistaSr  | Analista Sr + Admin             |
| 19 | Nivelación Salarial       | `/modificaciones/nivelacion-salarial`    | soloAnalistaSr  | Analista Sr + Admin             |
| 20 | Organigrama               | `/admin/organigrama`                     | soloAnalistaSr  | Analista Sr + Admin             |
| 21 | Pre-registro QR           | `/admin/qr-ingreso`                      | soloAnalistaSr  | Analista Sr + Admin             |
| 22 | Catálogo de Puestos       | `/admin/puestos`                         | soloAnalistas   | Analistas + Admin               |
| 23 | Catálogo de Plazas        | `/admin/plazas`                          | soloAnalistas   | Analistas + Admin               |
| 24 | Control de Horarios       | `/horarios`                              | soloJefatura    | Jefatura + Admin                |
| 25 | Reportes                  | `/reportes`                              | soloJefatura    | Jefatura + Admin                |
| 26 | Catálogo de Acreedores    | `/admin/acreedores`                      | soloJefatura    | Jefatura + Admin                |
| 27 | Tipos de Bonificación     | `/admin/tipos-bonificacion`              | soloJefatura    | Jefatura + Admin                |
| 28 | Planillas                 | `/planillas`                             | soloRrhhAdmin   | Solo Admin RRHH                 |
| 29 | Mantenimiento Planilla    | `/planillas/mantenimiento`               | soloRrhhAdmin   | Solo Admin RRHH                 |
| 30 | KPIs y Plantillas         | `/admin/kpi-plantillas`                  | soloRrhhAdmin   | Solo Admin RRHH                 |
| 31 | ViewAs                    | `/viewAs/:identifier`                    | soloRrhhAdmin   | Solo Admin RRHH                 |

### Rutas Públicas (sin autenticación)

| Ruta                | Propósito                                         |
|---------------------|---------------------------------------------------|
| `/login`            | Formulario de ingreso al sistema                  |
| `/registro/:token`  | Pre-registro de candidato (token QR de un solo uso) |

---

## 7. Descripción Funcional de Módulos

### 7.1 Dashboard — Panel de Control

Vista de inicio personalizada por rol. Los empleados ven un resumen de su situación personal (permisos pendientes, saldo de vacaciones, órdenes activas). Las jefaturas ven un resumen de su equipo. Los administradores ven estadísticas globales.

**APIs**: `GET /api/rrhh/dashboard/resumen`, `/charts`, `/demograficos`, `/pendientes-revision`

---

### 7.2 Autoservicio del Empleado

Módulos disponibles para todos los colaboradores autenticados, acotados a sus propios registros:

| Sub-módulo        | Descripción                                                        |
|-------------------|--------------------------------------------------------------------|
| Permisos          | Solicitar permiso (personal, médico, etc.) con documento de soporte |
| Vacaciones        | Consultar saldo y solicitar período de vacaciones                   |
| Incapacidades     | Reportar incapacidad médica con archivo de respaldo                 |
| Mi Horario        | Ver el horario asignado y el calendario del equipo                  |
| Mi Expediente     | Acceder a su propio expediente digital                              |
| Órdenes Descuento | Consultar órdenes de descuento activas en planilla                  |
| Bonificaciones    | Solicitar bonificación y consultar el estado de aprobación          |

---

### 7.3 Gestión de Personal (Analistas + Admin)

Módulos para registro de acciones disciplinarias, laborales y administrativas:

| Sub-módulo              | Descripción                                                             |
|-------------------------|-------------------------------------------------------------------------|
| Ausencias Injustificadas| Registrar ausencias sin justificación; impactan en planilla             |
| Amonestaciones          | Registro formal de faltas disciplinarias con generación de PDF firmable |
| Despidos                | Registro de desvinculaciones involuntarias con carta de finiquito        |
| Renuncias               | Registro de renuncias con carta y fecha de salida                       |
| Traslados               | Cambio de sucursal / departamento / cargo del colaborador               |
| Aumento Salarial        | Incremento de salario con documento generado para firma (S3)            |
| Nivelación Salarial     | Ajuste de salario a banda salarial del puesto                           |

---

### 7.4 Expedientes Digitales

Expediente digital único por empleado. Accesible por analistas (lista completa) o por el propio empleado (solo el suyo). Contiene:

- **Datos personales y contacto**: DUI, NIT, ISSS, AFP, fecha de nacimiento, etc.
- **Direcciones**
- **Documentos de identidad**: con fotos almacenadas en S3
- **Estudios y certificaciones**: con atestados en S3
- **Idiomas**: con atestados en S3
- **Experiencia laboral previa**
- **Cuentas bancarias**
- **Archivos generales**: contratos, constancias, otros documentos
- **Historial de acciones**: timeline de todas las acciones de personal del empleado

---

### 7.5 Organigrama y Catálogos

| Sub-módulo          | Descripción                                                          |
|---------------------|----------------------------------------------------------------------|
| Organigrama         | Estructura jerárquica: departamentos, sub-departamentos, jefes       |
| Catálogo de Puestos | Definición de puestos: nombre, descripción, headcount autorizado     |
| Catálogo de Plazas  | Plazas individuales con historial de ocupación y estado activo/inactivo |

---

### 7.6 Control de Horarios

Permite a las jefaturas asignar horarios a los empleados de su equipo. Los colaboradores consultan su horario asignado en `/mi-horario`. Soporta resolución de conflictos de horario.

---

### 7.7 Planillas y Nómina (Solo Admin RRHH)

Flujo completo del proceso de nómina quincenal:

```
1. Admin genera planilla → POST /api/rrhh/planillas/generar
   (crea líneas automáticas por empleado activo con deducciones aplicadas)

2. Revisar y ajustar líneas individuales si es necesario

3. Aprobación → POST /api/rrhh/planillas/{id}/aprobar

4. Exportar a Excel → GET /api/rrhh/planillas/{id}/exportar

5. Boletas individuales (PDF) → GET /api/rrhh/planillas/{id}/boleta/{empleado_id}
```

El módulo de **Mantenimiento de Planilla** permite configurar:
- Tabla de renta ISR (rangos y tasas)
- Acreedores permanentes (ISSS, AFP, etc.)
- Órdenes de descuento permanentes por empleado

---

### 7.8 Bonificaciones y KPIs

| Sub-módulo          | Descripción                                                    |
|---------------------|----------------------------------------------------------------|
| KPI Plantillas      | Definición de metas e indicadores por cargo (solo Admin RRHH) |
| Tipos Bonificación  | Catálogo de tipos: productividad, puntualidad, etc.           |
| Bonificaciones      | Flujo: solicitud → aprobación → aplicación en planilla        |

---

### 7.9 Ingreso de Personal (Onboarding)

Flujo completo para incorporación de nuevos colaboradores:

```
1. Analista Sr genera QR de pre-registro (/admin/qr-ingreso)
   → Se genera token de un solo uso

2. Candidato accede a /registro/:token (ruta pública)
   → Completa formulario con datos personales, estudios, documentos

3. Analista revisa el registro en /ingresos-personal
   → Evalúa criterios del período de prueba
   → Confirma el ingreso → POST /api/rrhh/ingresos-personal/{id}/confirmar

4. Se genera y firma el contrato laboral
   → PDF generado desde plantilla configurable

5. Se crea el expediente digital del colaborador
```

---

### 7.10 Reportes

Disponible para jefatura y admin. Actualmente incluye:
- Reporte de quincena (resumen de movimientos del período)

**API**: `GET /api/rrhh/reportes/quincena`

---

### 7.11 Historial de Acciones

Vista de auditoría que permite a los analistas ver el timeline completo de acciones de personal, filtrable por empleado, tipo de acción y rango de fechas.

**Tabla fuente**: `historial` en `rrhh_db`

---

### 7.12 ViewAs (Inspección de Usuario)

Exclusivo para `rrhh_admin`. Permite ver el sistema desde la perspectiva de otro usuario (por ID o email). Solo permite operaciones de lectura (GET) — no permite realizar acciones en nombre de otro usuario.

---

## 8. Estructura de Archivos

### Backend — `finanzas_api`

```
app/
├── Http/
│   └── Controllers/
│       └── Api/
│           ├── AuthController.php              ← Login, logout, /me, recuperación contraseña
│           └── RRHH/
│               ├── RRHHBaseController.php      ← Clase base: helpers auth, email, subordinados
│               ├── DashboardRRHHController.php
│               ├── PermisosController.php
│               ├── VacacionesController.php
│               ├── IncapacidadesController.php
│               ├── AusenciasController.php
│               ├── AmonestacionesController.php
│               ├── DesvinculacionesController.php
│               ├── TrasladosController.php
│               ├── CambiosSalarialesController.php
│               ├── ExpedienteController.php
│               ├── DepartamentosController.php
│               ├── CargosController.php
│               ├── PlazasController.php
│               ├── HorariosController.php
│               ├── PlanillasController.php
│               ├── MantenimientoPlanillaController.php
│               ├── BonificacionesController.php
│               ├── OrdenesDescuentoController.php
│               ├── AcreedoresController.php
│               ├── KpiPlantillasController.php
│               ├── IngresoPersonalController.php
│               ├── IngresoQRController.php
│               ├── ContratoEmpleadoController.php
│               ├── PlantillaContratoController.php
│               ├── TipoContratoController.php
│               ├── ReportesRRHHController.php
│               ├── CatalogosRRHHController.php
│               ├── HistorialController.php
│               ├── CalendarioController.php
│               ├── RetractacionesController.php
│               ├── ErrorLogsController.php
│               └── ViewAsController.php
├── Models/
│   └── RRHH/                               ← Todos usan $connection = 'rrhh'
│       ├── [Catálogos: TipoPermiso, TipoIncapacidad, TipoFalta, ...]
│       ├── [Expediente: ExpedienteDatosPersonales, ExpedienteEstudio, ...]
│       ├── [Acciones: Permiso, Vacacion, Incapacidad, Amonestacion, ...]
│       ├── [Planilla: Planilla, PlanillaLinea, PlanillaConfig, ...]
│       └── [Otros: Bonificacion, OrdenDescuento, HorarioEmpleado, ...]
├── Mail/
│   └── [Clases de notificación por email]
resources/
└── views/
    └── pdf/
        ├── amonestacion.blade.php
        ├── contrato.blade.php
        ├── boleta_planilla.blade.php
        └── desvinculacion.blade.php
routes/
└── api.php                                 ← Rutas /api/rrhh/*
```

### Frontend — `hr-rrhh`

```
src/
├── composables/
│   ├── useAuth.js        ← Store reactivo: token, user, roles, helpers hasRole()
│   ├── useApi.js         ← Wrapper Axios con interceptores de auth y errores
│   └── useToast.js       ← Notificaciones toast globales
├── layouts/
│   └── AdminShell.vue    ← Layout principal con sidebar dinámico por rol
├── router/
│   └── index.js          ← Definición de rutas + guards beforeEach
└── pages/
    ├── Dashboard.vue
    ├── HistorialPage.vue
    ├── BonificacionesPage.vue
    ├── OrdenesDescuentoPage.vue
    ├── permisos/
    ├── vacaciones/
    ├── incapacidades/
    ├── ausencias/
    ├── amonestaciones/
    ├── desvinculaciones/
    ├── modificaciones/
    ├── expediente/
    ├── horarios/
    ├── planillas/
    ├── ingresos/
    ├── reportes/
    ├── admin/             ← Organigrama, Puestos, Plazas, Acreedores, KPIs, QR
    └── public/
        └── RegistroEmpleadoPage.vue
```

---

## 9. Base de Datos — Esquema General

### core_db (conexión `pgsql`)

```sql
users               -- Cuentas de acceso al sistema
empleados           -- Colaboradores activos y datos laborales base
departamentos       -- Estructura organizacional (también en organigrama RRHH)
sucursales          -- Unidades de negocio / sedes
roles               -- Catálogo de roles por sistema
role_user           -- Pivot: usuario ↔ rol (tabla pivote)
permissions         -- Permisos granulares por módulo
permission_role     -- Pivot: permiso ↔ rol
systems             -- Sistemas registrados (rrhh, compras, pagos, ventas, etc.)
email_logs          -- Historial de todos los emails enviados por todos los sistemas
```

### rrhh_db (conexión `rrhh`)

```sql
-- Catálogos
tipos_permiso
tipos_incapacidad
tipos_falta
tipos_aumento_salarial
motivos_desvinculacion
tipos_contrato
plantillas_contrato
tipos_bonificacion
estados_bonificacion
estados_orden_descuento
tipos_acreedor
dias_suspension

-- Expediente digital
expediente_datos_personales
expediente_contactos
expediente_direcciones
expediente_documentos
expediente_archivos
expediente_estudios
expediente_experiencias_laborales
expediente_idiomas
expediente_cuentas_banco

-- Acciones de personal
permisos
vacaciones
incapacidades
ausencias_injustificadas
amonestaciones
traslados
cambios_salariales
desvinculaciones

-- Onboarding
ingresos_personal
periodos_prueba
criterios_evaluacion
ingreso_qr_tokens
ingreso_qr_registros

-- Planillas y nómina
planillas
planilla_lineas
planilla_config
planilla_tabla_renta
planilla_acreedores
planilla_ordenes_descuento

-- Bonificaciones y descuentos
bonificaciones
ordenes_descuento
acreedores
saldos_cadejo
saldos_vacaciones

-- Contratos
contratos_empleado

-- Horarios
horarios_empleado

-- KPIs
kpi_plantillas

-- Auditoría interna
error_logs
historial
solicitudes_retractacion
```

---

## 10. API REST — Referencia de Rutas

Todas las rutas protegidas requieren el header `Authorization: Bearer <token>`.

### Autenticación

```
POST   /api/auth/login                  Iniciar sesión
GET    /api/auth/me                     Usuario autenticado actual
POST   /api/auth/logout                 Cerrar sesión
POST   /api/auth/password/request       Solicitar código de recuperación
POST   /api/auth/password/verify        Verificar código
POST   /api/auth/password/reset         Cambiar contraseña con código
POST   /api/auth/password/change        Cambio forzado de contraseña (primer ingreso)
```

### RRHH — Patrón de rutas

```
GET    /api/rrhh/dashboard/resumen
GET    /api/rrhh/dashboard/charts
GET    /api/rrhh/dashboard/demograficos

GET    /api/rrhh/catalogos              -- Todos los catálogos en un solo request

GET    /api/rrhh/permisos               -- Lista (filtrada por rol)
POST   /api/rrhh/permisos               -- Crear solicitud
GET    /api/rrhh/permisos/{id}
PATCH  /api/rrhh/permisos/{id}          -- Aprobar / rechazar / actualizar
DELETE /api/rrhh/permisos/{id}
GET    /api/rrhh/permisos/{id}/descargar
GET    /api/rrhh/permisos/saldos

-- (patrón idéntico para: vacaciones, incapacidades, amonestaciones,
--  ausencias, traslados, cambios-salariales, desvinculaciones,
--  ordenes-descuento, bonificaciones, horarios)

GET    /api/rrhh/expediente/{empleado_id}
POST   /api/rrhh/expediente/{empleado_id}/datos-personales
POST   /api/rrhh/expediente/{empleado_id}/documentos
POST   /api/rrhh/expediente/{empleado_id}/estudios
-- ... (demás sub-secciones del expediente)

GET    /api/rrhh/planillas
POST   /api/rrhh/planillas/generar
POST   /api/rrhh/planillas/{id}/aprobar
GET    /api/rrhh/planillas/{id}/exportar
GET    /api/rrhh/planillas/{id}/boleta/{empleado_id}

GET    /api/rrhh/departamentos
POST   /api/rrhh/departamentos/{id}/asignar-empleado
GET    /api/rrhh/cargos
GET    /api/rrhh/plazas

GET    /api/rrhh/ingresos-personal
POST   /api/rrhh/ingresos-personal
POST   /api/rrhh/ingresos-personal/{id}/confirmar
GET    /api/rrhh/qr-ingreso/generar

GET    /api/rrhh/contratos/{ingreso_id}
POST   /api/rrhh/contratos/{ingreso_id}

GET    /api/rrhh/reportes/quincena
GET    /api/rrhh/historial
```

---

## 11. Generación de PDFs

El sistema genera documentos PDF en el servidor usando **DomPDF** (Blade templates):

| Documento              | Método                          | Entregado vía             |
|------------------------|---------------------------------|---------------------------|
| Amonestación           | `AmonestacionesController::pdf()` | Descarga directa          |
| Carta de desvinculación| `DesvinculacionesController::descargar()` | Descarga directa |
| Boleta de planilla     | `PlanillasController::boletaPdf()` | Descarga directa        |
| Contrato laboral       | `ContratoEmpleadoController::pdf()` | Descarga directa       |
| Vista previa contrato  | `ContratoEmpleadoController::preview()` | Render en browser   |

---

## 12. Almacenamiento en S3

Los documentos sensibles se almacenan en Amazon S3 con acceso mediante URLs pre-firmadas (expiración configurada por tipo):

| Tipo de documento              | Módulo origen              |
|--------------------------------|----------------------------|
| Fotos de documentos de identidad | Expediente → Documentos  |
| Atestados de estudios          | Expediente → Estudios      |
| Atestados de idiomas           | Expediente → Idiomas       |
| Archivos generales del colaborador | Expediente → Archivos  |
| Documentos de cambios salariales   | Cambios Salariales     |
| Documentos de órdenes de descuento | Órdenes de Descuento   |
| Contratos firmados             | Contratos                  |

**Flujo de subida**: El backend emite una URL pre-firmada de S3 (`presign*`) → el frontend hace un PUT directo a S3 → confirma la subida al backend con `confirmar*`.

---

## 13. Notificaciones por Email

Todos los envíos se registran automáticamente en la tabla `email_logs` (core_db) con los campos:

```
sistema, tipo, destinatario, asunto, estado, enviado_por,
referencia_id, referencia_tipo, error_mensaje, created_at
```

| Evento                    | Sistema en logs | Destinatarios                          |
|---------------------------|-----------------|----------------------------------------|
| Recuperación de contraseña| `portal`        | Usuario solicitante                    |
| Amonestación registrada   | `rrhh`          | Según configuración interna            |
| Desvinculación registrada | `rrhh`          | Según configuración interna            |

> **Constraint crítico**: `david@cervezacadejo.com` está **permanentemente excluido**
> de todas las notificaciones de RRHH. Exclusión implementada en `RRHHBaseController`.

---

## 14. Auditoría y Trazabilidad

| Tabla              | BD       | Propósito                                                        |
|--------------------|----------|------------------------------------------------------------------|
| `historial`        | rrhh_db  | Cada acción de personal: quién, qué, cuándo, sobre qué empleado |
| `error_logs`       | rrhh_db  | Errores de sistema con estado de resolución (resolvible desde UI)|
| `email_logs`       | core_db  | Historial de todos los emails de todos los sistemas              |

La vista `/historial` permite a analistas ver y filtrar el timeline completo de acciones por empleado, tipo y rango de fechas.

---

## 15. Flujos de Datos Detallados

### Permiso de Empleado

```
Empleado
  → [POST /api/rrhh/permisos] solicita permiso
  ← 201 { estado: "pendiente" }

Analista RRHH
  → [GET /api/rrhh/permisos] ve lista con estado pendiente
  → [PATCH /api/rrhh/permisos/{id}] aprueba o rechaza
  ← 200 { estado: "aprobado" | "rechazado" }
  (registro queda en historial)
```

### Planilla Quincenal

```
Admin RRHH
  → [POST /planillas/generar] crea líneas por empleado activo
     (aplica tabla de renta ISR, acreedores permanentes, ausencias del período)
  → Revisa y ajusta líneas individuales en UI
  → [POST /planillas/{id}/aprobar] bloquea la planilla
  → [GET /planillas/{id}/exportar] descarga Excel para contabilidad
  → [GET /planillas/{id}/boleta/{eid}] PDF individual por empleado
```

### Onboarding de Nuevo Colaborador

```
Analista Sr
  → [GET /admin/qr-ingreso] genera token QR de un solo uso

Candidato
  → Escanea QR → accede a /registro/{token}
  → Completa formulario con datos personales y documentos
  → [POST /api/rrhh/qr-ingreso/registro] guarda pre-registro

Analista
  → [GET /ingresos-personal] lista candidatos en proceso
  → Evalúa criterios del período de prueba
  → [POST /ingresos-personal/{id}/confirmar] activa al colaborador

  → [POST /contratos/{ingreso_id}] genera contrato desde plantilla
  → PDF firmado se sube a S3
  → Expediente digital queda disponible en /expediente/{id}
```

---

*Documento de arquitectura — Talento Humano v1.0 — Cadejo Brewing Company*  
*Julio 2026*
