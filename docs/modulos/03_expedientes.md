# Módulo: Expedientes Digitales

> Expediente único por colaborador — datos personales, documentos, estudios, experiencia  
> Guard: `soloAnalistas` para lista; `requiresAuth` para detalle propio

---

## 1. Propósito

Centraliza toda la información personal y profesional del colaborador en un expediente digital único. Reemplaza el expediente físico. Analistas pueden ver y editar todos los expedientes; cada empleado puede acceder únicamente al suyo propio.

---

## 2. Estructura del Expediente

El expediente se compone de **10 sub-secciones independientes**, cada una con sus propias tablas y endpoints:

| # | Sub-sección | Tabla (rrhh_db) | Descripción |
|---|-------------|-----------------|-------------|
| 1 | Datos Personales | `expediente_datos_personales` | DUI, NIT, ISSS, AFP, fecha nacimiento, estado civil |
| 2 | Contactos | `expediente_contactos` | Teléfonos, emails adicionales, contacto de emergencia |
| 3 | Direcciones | `expediente_direcciones` | Residencia actual y domicilio permanente |
| 4 | Documentos | `expediente_documentos` | DUI, pasaporte, otros IDs — con foto en S3 |
| 5 | Estudios | `expediente_estudios` | Títulos académicos con atestados en S3 |
| 6 | Idiomas | `expediente_idiomas` | Idiomas hablados/escritos con nivel y atestados en S3 |
| 7 | Experiencia Laboral | `expediente_experiencias_laborales` | Empleos anteriores |
| 8 | Cuentas Bancarias | `expediente_cuentas_banco` | Cuenta(s) de nómina, banco, tipo, número |
| 9 | Archivos | `expediente_archivos` | Documentos generales (contratos viejos, constancias, etc.) en S3 |
| 10 | Historial | `historial` | Timeline de todas las acciones de personal del empleado |

---

## 3. API

### Endpoints base
```
GET  /api/rrhh/expediente/{empleado_id}          -- Datos generales del expediente
GET  /api/rrhh/expediente/{empleado_id}/acciones -- Historial de acciones del empleado
```

### Datos personales
```
POST   /api/rrhh/expediente/{empleado_id}/datos-personales    -- Crear / actualizar
```

### Contactos
```
POST   /api/rrhh/expediente/{empleado_id}/contactos
PATCH  /api/rrhh/expediente/{empleado_id}/contactos/{id}
DELETE /api/rrhh/expediente/{empleado_id}/contactos/{id}
```

### Direcciones
```
POST   /api/rrhh/expediente/{empleado_id}/direcciones
PATCH  /api/rrhh/expediente/{empleado_id}/direcciones/{id}
DELETE /api/rrhh/expediente/{empleado_id}/direcciones/{id}
```

### Documentos (con foto S3)
```
POST   /api/rrhh/expediente/{empleado_id}/documentos
PATCH  /api/rrhh/expediente/{empleado_id}/documentos/{id}
DELETE /api/rrhh/expediente/{empleado_id}/documentos/{id}
GET    /api/rrhh/expediente/{empleado_id}/documentos/{id}/presign-foto   -- URL S3 para subir
POST   /api/rrhh/expediente/{empleado_id}/documentos/{id}/foto           -- Confirmar subida
GET    /api/rrhh/expediente/{empleado_id}/documentos/{id}/foto           -- Ver foto (URL temporal)
```

### Estudios (con atestado S3)
```
POST   /api/rrhh/expediente/{empleado_id}/estudios
PATCH  /api/rrhh/expediente/{empleado_id}/estudios/{id}
DELETE /api/rrhh/expediente/{empleado_id}/estudios/{id}
GET    /api/rrhh/expediente/{empleado_id}/estudios/{id}/presign-atestado
POST   /api/rrhh/expediente/{empleado_id}/estudios/{id}/atestado
GET    /api/rrhh/expediente/{empleado_id}/estudios/{id}/atestado
```

### Idiomas (con atestado S3)
```
POST / PATCH / DELETE  /api/rrhh/expediente/{empleado_id}/idiomas/{id?}
GET / POST             /api/rrhh/expediente/{empleado_id}/idiomas/{id}/atestado
```

### Experiencia Laboral
```
POST / PATCH / DELETE  /api/rrhh/expediente/{empleado_id}/experiencias/{id?}
```

### Cuentas Bancarias
```
POST / PATCH / DELETE  /api/rrhh/expediente/{empleado_id}/cuentas-banco/{id?}
```

### Archivos generales (S3)
```
GET    /api/rrhh/expediente/{empleado_id}/archivos              -- Lista archivos
GET    /api/rrhh/expediente/{empleado_id}/archivos/{id}/presign -- URL S3 para subir
POST   /api/rrhh/expediente/{empleado_id}/archivos              -- Confirmar subida
GET    /api/rrhh/expediente/{empleado_id}/archivos/{id}         -- Ver archivo
DELETE /api/rrhh/expediente/{empleado_id}/archivos/{id}         -- Eliminar
```

---

## 4. Controlador

`app/Http/Controllers/Api/RRHH/ExpedienteController.php`

Es el controlador más extenso del sistema (50+ métodos). Cada sub-sección tiene sus propios métodos CRUD. Los métodos relacionados con S3 siguen el patrón:

```php
// 1. Emitir URL pre-firmada (PUT hacia S3)
presignAtestadoEstudio(Request $request, int $empleadoId, int $estudioId): JsonResponse

// 2. El frontend sube directamente a S3 (no pasa por el backend)

// 3. Confirmar la subida (guardar la s3_key en la BD)
subirAtestadoEstudio(Request $request, int $empleadoId, int $estudioId): JsonResponse

// 4. Ver el archivo (URL GET pre-firmada con expiración configurable)
verAtestadoEstudio(Request $request, int $empleadoId, int $estudioId): JsonResponse
```

---

## 5. Control de Acceso

```
Analista RRHH (sr y jr) / Admin
  → Puede ver y editar el expediente de CUALQUIER empleado activo

Empleado puro
  → Solo puede ver su propio expediente (/mi-expediente redirige a /expediente/{su_id})
  → No puede editar datos personales críticos (DUI, NIT) — solo de lectura para ellos
  → Puede actualizar fotos de perfil y contacto de emergencia

Jefatura
  → Puede ver expedientes de sus subordinados
  → No puede editar documentos sensibles
```

El control se implementa en `ExpedienteController` verificando `puedeGestionar($empleadoId)` del `RRHHBaseController`.

---

## 6. Tablas BD (rrhh_db)

```sql
expediente_datos_personales
  id, empleado_id
  dui, nit, isss, nup_afp                -- documentos legales
  fecha_nacimiento, lugar_nacimiento
  estado_civil, genero, tipo_sangre
  nombre_conyugue, hijos

expediente_contactos
  id, empleado_id
  tipo                                   -- personal | emergencia | laboral_anterior
  nombre, telefono, email, parentesco

expediente_direcciones
  id, empleado_id
  tipo                                   -- residencia | domicilio_permanente
  departamento, municipio, detalle, referencia

expediente_documentos
  id, empleado_id
  tipo                                   -- dui | pasaporte | carnet_residente | otro
  numero, fecha_emision, fecha_vencimiento
  foto_s3_key

expediente_estudios
  id, empleado_id
  nivel                                  -- bachillerato | tecnico | universitario | postgrado
  titulo, institucion, anio_graduacion
  atestado_s3_key

expediente_idiomas
  id, empleado_id
  idioma
  nivel_oral, nivel_escrito              -- basico | intermedio | avanzado | nativo
  atestado_s3_key

expediente_experiencias_laborales
  id, empleado_id
  empresa, cargo, fecha_inicio, fecha_fin
  descripcion, motivo_salida
  telefono_referencia, contacto_referencia

expediente_cuentas_banco
  id, empleado_id
  banco, tipo_cuenta                     -- ahorros | corriente
  numero_cuenta, titular
  es_principal

expediente_archivos
  id, empleado_id
  nombre_archivo, descripcion
  s3_key, mime_type, tamanio_bytes
  created_at
```

---

## 7. Vista Frontend

**Archivos**:
- `hr-rrhh/src/pages/expediente/ExpedientesListPage.vue` — Lista paginada de todos los empleados con buscador
- `hr-rrhh/src/pages/expediente/ExpedienteEmpleadoPage.vue` — Detalle con tabs por sub-sección

La página de detalle usa un sistema de tabs horizontales donde cada tab carga su propia sección de forma lazy. Los documentos S3 se abren en una nueva pestaña usando la URL temporal retornada por el backend.

---

## 8. Acceso Rápido: Mi Expediente

La ruta `/mi-expediente` no tiene componente propio — el guard del router intercepta la navegación y redirige automáticamente a `/expediente/{empleadoId}` usando el `empleadoId` del usuario autenticado:

```javascript
// router/index.js
if (to.name === 'MiExpediente') {
  const id = empleadoId.value
  if (id) return { name: 'ExpedienteEmpleado', params: { id } }
  return { name: 'Dashboard' }
}
```
