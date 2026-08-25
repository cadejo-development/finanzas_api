# Documentación del Sistema de Talento Humano

> Cadejo Brewing Company — Equipo de Tecnología  
> Última actualización: Julio 2026

---

## Documentos Disponibles

### Visión General
- [TALENTO_HUMANO_ARQUITECTURA.md](TALENTO_HUMANO_ARQUITECTURA.md) — Arquitectura completa del sistema: stack, roles, módulos, BD, API, flujos

### Módulos (Documentación Técnica)

| Archivo | Módulos cubiertos |
|---------|-------------------|
| [modulos/01_autoservicio_empleado.md](modulos/01_autoservicio_empleado.md) | Permisos · Vacaciones · Incapacidades |
| [modulos/02_gestion_personal.md](modulos/02_gestion_personal.md) | Ausencias · Amonestaciones · Desvinculaciones · Traslados · Cambios Salariales |
| [modulos/03_expedientes.md](modulos/03_expedientes.md) | Expedientes Digitales (10 sub-secciones + S3) |
| [modulos/04_estructura_organizacional.md](modulos/04_estructura_organizacional.md) | Organigrama · Catálogo de Puestos · Catálogo de Plazas |
| [modulos/05_planillas.md](modulos/05_planillas.md) | Planillas · Nómina quincenal · Mantenimiento · Boletas PDF |
| [modulos/06_ingreso_personal.md](modulos/06_ingreso_personal.md) | Onboarding QR · Pre-registro candidato · Contratos laborales |
| [modulos/07_bonificaciones_descuentos.md](modulos/07_bonificaciones_descuentos.md) | Bonificaciones · KPI Plantillas · Órdenes de Descuento · Acreedores |
| [modulos/08_horarios_reportes_historial.md](modulos/08_horarios_reportes_historial.md) | Horarios · Reportes · Historial · Error Logs · Calendario |
| [modulos/09_email_notificaciones.md](modulos/09_email_notificaciones.md) | Sistema de emails: helpers, mailables, email_logs, links firmados |

### Manual de Usuario
- *(próximamente)* `MANUAL_USUARIO.html` — Manual completo con TOC clickeable, listo para imprimir

---

## Repositorios

| Sistema | Repositorio local | Rama deploy |
|---------|-------------------|-------------|
| Backend API | `c:\Users\administrator\finanzas_api` | `produccion` |
| Frontend RRHH | `c:\Users\administrator\hr-rrhh` | `main` |

---

## Convenciones de Documentación

### Backend (PHP / Laravel)
- PHPDoc en todos los métodos públicos de los controladores
- La clase base `RRHHBaseController` está completamente documentada
- Los helpers de autorización están documentados con sus reglas de negocio

### Frontend (Vue 3 / JavaScript)
- JSDoc en el composable `useAuth.js` (foundation del sistema de autenticación)
- Comentarios inline en lógica de guards del router (`router/index.js`)

### Markdown
- Un archivo por grupo de módulos relacionados
- Estructura uniforme: Propósito → Flujo → API → Controlador → BD → Frontend
