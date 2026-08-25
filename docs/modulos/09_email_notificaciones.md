# Módulo: Email y Notificaciones

> Sistema de emails automáticos — flujos, destinatarios y registro en email_logs

---

## 1. Arquitectura de Notificaciones

Todas las notificaciones de email del módulo RRHH están centralizadas en `RRHHBaseController`. Los controladores hijos llaman a los helpers del padre; nunca envían emails directamente.

```
PermisosController::store()
  └─ $this->notificarSolicitud(...)          ← helper del base controller
       └─ enviarNotificacion(...)             ← lógica interna
            └─ SupervisorChainService         ← resuelve el supervisor a notificar
            └─ Mail::to($supervisor)->send()  ← dispatch del email
            └─ $this->registrarEmailLog()     ← registro en email_logs
```

---

## 2. Helpers de Notificación en RRHHBaseController

| Helper | Uso | Tipo email |
|--------|-----|------------|
| `notificarSolicitud()` | Permisos, vacaciones pendientes | Email con botones Aprobar/Rechazar al supervisor |
| `notificarAccion()` | Incapacidades, regularizaciones | Email informativo al supervisor |
| `notificarAlEmpleado()` | Amonestaciones, resoluciones | Email directo al empleado afectado |
| `notificarAdminsRrhh()` | Desvinculaciones, acciones críticas | Email a todos los `rrhh_admin` activos |
| `notificarGerenciaOps()` | Amonestaciones/despidos en sucursales operativas | Email a todos con rol `gerencia_ops` |
| `notificarGerenciaOpsSolicitud()` | Amonestaciones con suspensión de propina | Email con botones Aprobar/Rechazar a `gerencia_ops` |
| `notificarJefaRRHHSolicitud()` | Amonestaciones muy graves | Email con botones Aprobar/Rechazar a Gabriela |
| `notificarDepartamentoCodigo()` | Traslados aprobados | Email a todos los miembros del depto `GEN_INF` |

---

## 3. Resolución del Supervisor (SupervisorChainService)

El servicio `App\Services\RRHH\SupervisorChainService` resuelve automáticamente a quién enviar la notificación de una solicitud:

```
Empleado A solicita permiso
  → SupervisorChainService::resolverSupervisorANotificar(empleadoId, actorId)
  → Busca el departamento del empleado
  → Retorna el jefe_empleado_id del departamento
  → Si el empleado ya ES el jefe → sube al dept padre
  → Si no hay jefe definido → no envía email
```

---

## 4. Mailables RRHH

Los emails se generan con tres clases Mailable:

### SolicitudAprobacion
**Archivo**: `app/Mail/RRHH/SolicitudAprobacion.php`

Para solicitudes que requieren aprobación. Incluye:
- Nombre del empleado que solicita
- Tipo de solicitud y detalles
- Link al sistema
- Botón "Aprobar" (URL con firma temporal, válida 5 días)
- Botón "Rechazar" (URL con firma temporal, válida 5 días)

Los botones usan `URL::temporarySignedRoute()` de Laravel, apuntando a:
- `rrhh.email.aprobar`
- `rrhh.email.rechazar`

Al hacer clic, el supervisor aprueba/rechaza directamente desde el email sin entrar al sistema.

### AccionPersonalNotificacion
**Archivo**: `app/Mail/RRHH/AccionPersonalNotificacion.php`

Para acciones informativas (no requieren aprobación). Contiene:
- Tipo de acción registrada
- Detalles de la acción
- Link al sistema para ver el detalle completo

### NotificacionAlEmpleado
**Archivo**: `app/Mail/RRHH/NotificacionAlEmpleado.php`

Para notificaciones directas al colaborador afectado. Soporta:
- Mensaje personalizado
- Detalles en formato tabla
- PDF adjunto opcional (ej: amonestación)
- Link de confirmación opcional

---

## 5. Tabla email_logs (core_db — pgsql)

Todos los emails del sistema RRHH quedan registrados en esta tabla:

```sql
email_logs
  id
  sistema           -- 'rrhh' para todos los emails de RRHH
  tipo              -- solicitud_aprobacion | notificacion_empleado | notificacion_admins_rrhh |
                   --  notificacion_gerencia_ops | solicitud_aprobacion_gerencia_ops |
                   --  solicitud_aprobacion_rrhh | accion_notificacion |
                   --  notificacion_departamento | password_reset
  destinatario      -- email del destinatario
  asunto            -- asunto del email enviado
  estado            -- enviado | fallido
  error_mensaje     -- NULL si enviado correctamente; mensaje de error si falló
  respuesta_api     -- detalles técnicos del error si aplica
  enviado_por       -- email del usuario que desencadenó el envío
  referencia_id     -- ID del registro relacionado (empleado_id, solicitud_id)
  referencia_tipo   -- 'empleado' | 'departamento' | tipo del modelo
  created_at
```

Para consultar los logs desde el panel de admin, ver la tabla directamente en la BD o desde el endpoint de `email_logs` del módulo de gestión de la plataforma.

---

## 6. Constraint Crítico: Exclusión de david@cervezacadejo.com

```php
// En RRHHBaseController::notificarAdminsRrhh() — línea ~794
->where('u.email', '!=', 'david@cervezacadejo.com')
```

**Este email está permanentemente excluido de TODAS las notificaciones de RRHH.**

> La exclusión está hardcodeada en el controlador base y no debe removerse ni moverse a configuración.

---

## 7. Aprobación/Rechazo por Email (Links Firmados)

Las URLs de aprobar/rechazar en los emails usan firmas criptográficas de Laravel (`temporarySignedRoute`) con 5-7 días de validez. Al visitar el link:

1. Laravel valida la firma y la expiración
2. Si válido → ejecuta la aprobación/rechazo directamente
3. Si expirado → muestra mensaje de error amigable
4. El cambio de estado queda registrado en el historial con `realizado_por: 'email_link'`

Las rutas firmadas están definidas en `routes/api.php` o `routes/web.php` como:
```php
Route::get('/rrhh/email/aprobar', ...)->name('rrhh.email.aprobar');
Route::get('/rrhh/email/rechazar', ...)->name('rrhh.email.rechazar');
```

---

## 8. Gestión de Errores de Email

Si el envío de email falla (SMTP caído, email inválido, etc.):
1. Se captura la excepción con `try/catch`
2. Se registra en `email_logs` con `estado = 'fallido'` y el mensaje de error
3. Se registra una advertencia en el log de Laravel (`Log::warning(...)`)
4. **El proceso principal NO se interrumpe** — la acción de personal sí se guarda aunque el email falle

Este diseño garantiza que una falla de email nunca bloquee el registro de una acción de RRHH.
