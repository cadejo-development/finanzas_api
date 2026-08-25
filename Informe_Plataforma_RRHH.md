![Cadejo Brewing Company](https://cadejo-storage.s3.us-east-2.amazonaws.com/public/logo2.png)

---

**Cadejo Brewing Company — Departamento de Informática**
**Plataforma de Talento Humano**

Informe de Funcionalidades Implementadas — Lineamientos RRHH

| | |
|---|---|
| Fecha de emisión | 12 de junio de 2026 |
| Dirigido a | Jefatura de Recursos Humanos |
| Elaborado por | Departamento de Informática |
| Referencia | Lineamientos Plataforma RRHH — Cadejo Brewing Company |

---

## Propósito

El presente informe describe las funcionalidades incorporadas a la Plataforma de Talento Humano en correspondencia con los lineamientos institucionales de Recursos Humanos. Cada sección indica la regla establecida en el documento de lineamientos y cómo el sistema la aplica.

---

## 1. Permisos Personales

La plataforma permite registrar permisos personales indicando motivo y fechas. Las solicitudes quedan sujetas a aprobación de la jefatura inmediata o gerencia según corresponda. El sistema mantiene un contador anual por colaborador y genera una alerta automática al área de Recursos Humanos cuando un mismo colaborador acumula tres o más permisos personales dentro de un período de 30 días.

## 2. Permisos Especiales

**Licencia por Maternidad**
El sistema acepta solicitudes de hasta 112 días (16 semanas), exige adjuntar la incapacidad emitida por el ISSS y no permite que la licencia sea fraccionada. El registro solo procede con la documentación cargada.

**Permiso por Paternidad**
La plataforma limita el permiso a 3 días laborales, requiere ingresar la fecha de nacimiento del menor y valida que el período solicitado se encuentre dentro de los 15 días posteriores a dicha fecha. Adicionalmente exige adjuntar la partida de nacimiento.

**Permiso por Matrimonio**
El sistema exige que la solicitud se registre con al menos 30 días de anticipación y limita el permiso a 3 días laborales. Al registrarlo, la plataforma activa automáticamente un indicador de documento posterior pendiente, visible en el listado de permisos. Personal de Recursos Humanos confirma la recepción del acta matrimonial mediante un control específico en pantalla.

**Permiso por Fallecimiento de Familiar**
El sistema otorga un período base de 2 días laborales con goce de sueldo, conforme al Código de Trabajo (Art. 29, numeral 6). El parentesco del familiar es campo obligatorio. Los días adicionales al período base solo pueden ser registrados directamente por personal de Recursos Humanos, garantizando que toda extensión cuente con evaluación institucional previa.

**Consulta Médica**
La plataforma registra este permiso únicamente en horas, con un máximo de 4 horas por solicitud. Cuando la ausencia supera ese límite, el sistema indica al usuario que debe registrar una Incapacidad Médica en el módulo correspondiente.

## 3. Permisos sin Goce de Sueldo

Los permisos sin goce requieren indicar fechas exactas y motivo, y deben ser aprobados por la jefatura inmediata y validados por Recursos Humanos. El sistema aplica el descuento proporcional en planilla conforme a los días autorizados.

## 4. Días Cadejo

Cada colaborador cuenta con un saldo de 3 días anuales. El sistema valida que el colaborador tenga al menos 3 meses consecutivos de antigüedad, que la solicitud se registre con un mínimo de 5 días hábiles de anticipación y que el uso sea en días completos. Para personal de restaurantes el período de uso vence el 31 de octubre. Los saldos se controlan en tiempo real y se actualizan al momento en que se aprueba cada solicitud.

## 5. Vacaciones

**Requisito de antigüedad y saldo**
El sistema habilita las vacaciones únicamente a colaboradores con un año continuo de servicio. Cada período equivale a 15 días hábiles. Los días no utilizados se acumulan al año siguiente con un tope máximo de 30 días (equivalente a dos períodos), conforme al lineamiento institucional.

**Anticipación mínima**
Los colaboradores con rol de empleado deben solicitar sus vacaciones con al menos 30 días de anticipación; la plataforma bloquea fechas anteriores a ese margen directamente en el formulario. Jefaturas, gerencias y personal de Recursos Humanos pueden registrar vacaciones para cualquier fecha, incluyendo períodos retroactivos.

**Validaciones adicionales**
El sistema impide que un colaborador tenga dos solicitudes de vacaciones con fechas superpuestas. Para el personal operativo de restaurantes, solo se permite un período continuo por año calendario. El personal administrativo puede fraccionar sus vacaciones de manera justificada.

## 6. Incapacidades

El registro de incapacidades exige adjuntar el documento médico emitido por el ISSS o por institución privada autorizada; sin este respaldo el sistema no permite completar el registro. Cuando una incapacidad cubre fechas previamente marcadas como ausencias injustificadas, el sistema vincula ambos registros automáticamente.

## 7. Amonestaciones

La plataforma permite registrar amonestaciones verbales, escritas y suspensiones, clasificadas según la gravedad de la falta (leve o grave), en correspondencia con el catálogo del Reglamento Interno de Trabajo. Para faltas graves y muy graves el sistema exige adjuntar documentación de respaldo; sin ella el registro no puede completarse. Las amonestaciones no pueden eliminarse: en caso de error se genera un registro de invalidación con justificación obligatoria. El sistema mantiene el historial disciplinario completo de cada colaborador y genera alertas cuando se detectan reincidencias.

## 8. Ausencias Injustificadas

Las ausencias son registradas por la jefatura inmediata. El sistema genera alertas automáticas a Recursos Humanos cuando se presentan dos ausencias consecutivas o tres dentro de un mismo mes calendario, conforme al Artículo 50, numeral 12 del Código de Trabajo. Cuando un colaborador presenta justificación posterior, la jefatura puede regularizar la ausencia desde la misma plataforma: si corresponde a un permiso personal el sistema crea el registro formal vinculado a esa fecha; si corresponde a una incapacidad, lo vincula al expediente médico correspondiente.

---

## Resumen por Módulo

| Módulo | Requisito del Lineamiento | Estado |
|---|---|:---:|
| Permisos Personales | Registro, aprobación, alerta por uso recurrente | Implementado |
| Maternidad | 112 días, incapacidad ISSS obligatoria, no fraccionable | Implementado |
| Paternidad | 3 días, fecha nacimiento, dentro de 15 días, partida obligatoria | Implementado |
| Matrimonio | 30 días anticipación, seguimiento de acta posterior | Actualizado |
| Fallecimiento | 2 días base, extensiones solo por RH | Actualizado |
| Consulta Médica | Máximo 4 horas, redirección a incapacidad si excede | Implementado |
| Sin Goce de Sueldo | Registro, aprobación jefatura y RH, descuento en planilla | Implementado |
| Días Cadejo | 3 días/año, 5 días hábiles anticipación, control de saldo | Implementado |
| Vacaciones — Anticipación | 30 días anticipación para colaboradores | Actualizado |
| Vacaciones — Traslape | No permitir fechas superpuestas | Actualizado |
| Vacaciones — Acumulación | Días no usados acumulables, tope 30 días | Actualizado |
| Incapacidades | Documento obligatorio, vinculación a ausencias | Implementado |
| Amonestaciones | Evidencia obligatoria en faltas graves, historial, alertas | Actualizado |
| Ausencias Injustificadas | Alertas automáticas (Art. 50 CT), regularización con registro real | Actualizado |

---

*Los módulos de Ingreso de Personal se encuentran actualmente en proceso de definición operativa y serán habilitados en una etapa posterior.*

*Cadejo Brewing Company — Departamento de Informática — Junio 2026*
