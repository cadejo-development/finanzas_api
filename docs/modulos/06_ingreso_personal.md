# Módulo: Ingreso de Personal (Onboarding)

> Pre-registro QR · Revisión · Confirmación · Contratación  
> Guard: `soloAnalistas` (lista) · `soloAnalistaSr` (generar QR) · público (formulario candidato)

---

## 1. Propósito

Digitaliza el proceso de incorporación de nuevos colaboradores. El candidato completa su propio formulario desde su celular usando un QR de un solo uso, lo que elimina la carga manual de datos por parte de RRHH.

---

## 2. Flujo Completo

```
PASO 1 — Generación de QR (Analista Sr)
  → /admin/qr-ingreso
  → POST /api/rrhh/qr-ingreso/generar
  ← Token único de 32 caracteres con expiración (72h por defecto)
  ← QR generado en frontend apuntando a /registro/{token}

PASO 2 — Pre-registro del candidato (ruta pública)
  → Candidato escanea QR
  → Accede a /registro/{token} (sin login requerido)
  → Completa formulario: datos personales, estudios, documentos, foto
  → POST /api/rrhh/qr-ingreso/registro
  ← Datos guardados, token marcado como "usado"

PASO 3 — Revisión por el Analista
  → /ingresos-personal
  → Lista de candidatos pre-registrados pendientes de revisión
  → Analista verifica datos, puede editar campos
  → Evalúa criterios de evaluación del período de prueba

PASO 4 — Confirmación del Ingreso
  → POST /api/rrhh/ingresos-personal/{id}/confirmar
  ← El candidato se convierte en colaborador activo:
     - Se actualiza activo = true en empleados (pgsql)
     - Se crea el expediente digital en rrhh_db
     - Se asigna la plaza seleccionada

PASO 5 — Contratación
  → POST /api/rrhh/contratos/{ingreso_id}
  ← Contrato generado desde plantilla configurable (DomPDF)
  → Candidato / empleado recibe correo con el contrato para firma
  → Se sube el contrato firmado a S3
  → Contrato queda vinculado al expediente del empleado
```

---

## 3. API de Ingreso QR

### Generación y gestión de tokens

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/rrhh/qr-ingreso` | Lista de tokens generados (activos, usados, expirados) |
| POST | `/api/rrhh/qr-ingreso/generar` | Generar nuevo token QR con expiración |
| DELETE | `/api/rrhh/qr-ingreso/{id}` | Invalidar token antes de que expire |

### Formulario del candidato (sin auth)

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/rrhh/qr-ingreso/validar/{token}` | Validar que el token existe y está vigente |
| POST | `/api/rrhh/qr-ingreso/registro` | Guardar el formulario del candidato |

### Controlador
`app/Http/Controllers/Api/RRHH/IngresoQRController.php`

---

## 4. API de Ingreso Personal

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/rrhh/ingresos-personal` | Lista de ingresos. Filtros: `estado`, `buscar` |
| POST | `/api/rrhh/ingresos-personal` | Crear ingreso manual (sin QR) |
| GET | `/api/rrhh/ingresos-personal/{id}` | Detalle del ingreso y datos del candidato |
| DELETE | `/api/rrhh/ingresos-personal/{id}` | Cancelar ingreso (candidato descartado) |
| POST | `/api/rrhh/ingresos-personal/{id}/confirmar` | Confirmar ingreso → activa al empleado |
| GET | `/api/rrhh/ingresos-personal/criterios-evaluacion` | Lista de criterios del período de prueba |
| PATCH | `/api/rrhh/ingresos-personal/{id}/periodo-prueba` | Actualizar evaluación del período de prueba |

### Controlador
`app/Http/Controllers/Api/RRHH/IngresoPersonalController.php`

---

## 5. Contratos Laborales

### Plantillas de Contrato

Las plantillas de contrato son documentos HTML/Blade con variables dinámicas (nombre del empleado, cargo, salario, fechas). Son configurables desde el panel de administración.

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/rrhh/contratos/plantillas` | Lista de plantillas activas |
| GET | `/api/rrhh/contratos/plantillas/{id}` | Ver plantilla |
| POST | `/api/rrhh/contratos/plantillas` | Crear plantilla |
| PATCH | `/api/rrhh/contratos/plantillas/{id}` | Editar plantilla |
| PATCH | `/api/rrhh/contratos/plantillas/{id}/toggle` | Activar/desactivar |
| DELETE | `/api/rrhh/contratos/plantillas/{id}` | Eliminar |

### Contratos del Empleado

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/rrhh/contratos/empleado/{empleado_id}` | Contratos del empleado |
| POST | `/api/rrhh/contratos/ingreso/{ingreso_id}` | Generar contrato desde plantilla |
| GET | `/api/rrhh/contratos/{id}/preview` | Vista previa HTML en el browser |
| GET | `/api/rrhh/contratos/{id}/pdf` | Descargar PDF del contrato |
| PATCH | `/api/rrhh/contratos/{id}/estado` | Actualizar estado del contrato |

### Controladores
- `app/Http/Controllers/Api/RRHH/ContratoEmpleadoController.php`
- `app/Http/Controllers/Api/RRHH/PlantillaContratoController.php`
- `app/Http/Controllers/Api/RRHH/TipoContratoController.php`

---

## 6. Período de Prueba y Evaluación

Cada nuevo colaborador tiene un período de prueba (típicamente 3 meses) durante el cual se evalúan criterios predefinidos:

- Puntualidad
- Presentación personal
- Desempeño en el puesto
- Actitud y trabajo en equipo
- Conocimientos técnicos

Los criterios se configuran en `criterios_evaluacion` y el analista registra la calificación al final del período de prueba.

---

## 7. Tablas BD

### core_db (pgsql)

```sql
empleados
  -- El candidato se crea como activo = false hasta que se confirma el ingreso
  activo                                 -- false durante el pre-registro
  fecha_ingreso
```

### rrhh_db

```sql
ingreso_qr_tokens
  id, token                              -- 32 caracteres únicos
  generado_por_user_id
  expira_at, usado_at
  activo

ingreso_qr_registros
  id, token_id
  -- Datos del candidato (sin vincular a empleado aún)
  nombres, apellidos, email, telefono
  dui, fecha_nacimiento, cargo_solicitado
  datos_json                             -- formulario completo serializado
  created_at

ingresos_personal
  id, empleado_id                        -- NULL hasta que se confirma
  qr_registro_id                         -- origen QR, NULL si fue manual
  estado                                 -- pendiente | en_proceso | confirmado | cancelado
  plaza_id                               -- plaza a asignar al confirmar
  fecha_ingreso_efectiva
  periodo_prueba_inicio, periodo_prueba_fin
  periodo_prueba_aprobado
  notas_analista
  confirmado_por, created_at

periodos_prueba
  id, ingreso_id, empleado_id
  fecha_inicio, fecha_fin
  estado                                 -- en_proceso | aprobado | no_aprobado

criterios_evaluacion
  id, nombre, descripcion, activo

periodos_prueba_criterios
  id, periodo_prueba_id, criterio_id
  calificacion                           -- 1-5
  observacion

-- Contratos
tipos_contrato
  id, nombre                             -- tiempo_indefinido | plazo_fijo | por_obra
  activo

plantillas_contrato
  id, nombre, tipo_contrato_id
  contenido_html                         -- plantilla con {{variables}}
  activa

contratos_empleado
  id, empleado_id, ingreso_id
  plantilla_contrato_id, tipo_contrato_id
  fecha_inicio, fecha_fin               -- NULL fecha_fin = tiempo indefinido
  salario_acordado
  estado                                 -- borrador | firmado | vencido
  documento_s3_key                       -- contrato firmado
  created_at
```

---

## 8. Vista Frontend

**Archivos**:
- `hr-rrhh/src/pages/admin/GenerarQRPage.vue` — Genera y muestra QR para el candidato
- `hr-rrhh/src/pages/ingresos/IngresoPersonalPage.vue` — Lista y gestión de candidatos
- `hr-rrhh/src/pages/public/RegistroEmpleadoPage.vue` — Formulario del candidato (ruta pública)

### Formulario público (`/registro/:token`)

Esta página no requiere autenticación y usa un layout simplificado (`layout: 'plain'`). Antes de mostrar el formulario, valida que el token sea válido y no haya expirado. Si el token ya fue usado o expiró, muestra un mensaje de error amigable.

El formulario es multi-paso (stepper):
1. Datos personales
2. Documentos de identidad
3. Estudios académicos
4. Datos de contacto y emergencia

---

## 9. Tipos de Contrato

Los tipos de contrato definen la naturaleza de la relación laboral:

| Tipo | Descripción |
|------|-------------|
| `tiempo_indefinido` | Sin fecha de vencimiento definida |
| `plazo_fijo` | Con fecha de vencimiento (requiere `fecha_fin`) |
| `por_obra` | Para proyectos específicos con fin determinado |
| `periodo_prueba` | Primer contrato durante los primeros 3 meses |
