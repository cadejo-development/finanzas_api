# Informe de Actualizaciones — Plataforma de Recursos Humanos
**Cadejo Brewing Company**
Fecha: 12 de junio de 2026

---

## 1. Propósito

Este documento describe los cambios realizados a la plataforma de Recursos Humanos con el fin de alinear su funcionamiento a los lineamientos institucionales vigentes. Las actualizaciones cubren los módulos de Permisos, Vacaciones, Ausencias, Amonestaciones e integración con Planillas.

---

## 2. Módulo de Permisos Especiales

### 2.1 Depuración de tipos de permisos duplicados
Se eliminaron del sistema los tipos de permisos anteriores que estaban duplicados. Ahora el catálogo contiene únicamente los cinco tipos oficiales con sus reglas de validación actualizadas: Licencia por Maternidad, Permiso por Paternidad, Permiso por Matrimonio, Permiso por Fallecimiento de Familiar y Consulta Médica. Los registros históricos fueron migrados al tipo correcto.

### 2.2 Permiso por Fallecimiento de Familiar
Los días adicionales al período base de 2 días laborales ahora solo pueden ser registrados directamente por personal de Recursos Humanos, en cumplimiento del lineamiento que establece que las extensiones requieren evaluación previa de jefatura y RH.

### 2.3 Permiso por Matrimonio — Seguimiento de documentación posterior
Al registrar un permiso de matrimonio, el sistema genera automáticamente un indicador de documento pendiente. Este indicador permanece visible en la tabla de permisos hasta que personal de RH confirme la recepción del acta matrimonial correspondiente.

### 2.4 Parentesco en Permiso por Fallecimiento
Las opciones de parentesco fueron ajustadas para incluir únicamente núcleo familiar directo: cónyuge o pareja de hecho, hijo/a, padre, madre, hermano/a y persona dependiente económicamente.

---

## 3. Módulo de Días Cadejo

### 3.1 Corrección de saldo disponible
Se corrigió un error que impedía que el saldo de Días Cadejo se descontara correctamente cuando el permiso era registrado y aprobado simultáneamente por una jefatura. Ahora el saldo se actualiza en todos los casos y el formulario muestra en tiempo real los días disponibles reales del colaborador, incluyendo los ya utilizados.

---

## 4. Módulo de Vacaciones

### 4.1 Anticipación mínima diferenciada
La restricción de solicitar vacaciones con al menos 30 días de anticipación aplica únicamente a colaboradores con rol de empleado. Jefaturas, gerencias y personal de Recursos Humanos pueden registrar vacaciones con cualquier fecha, incluyendo períodos retroactivos, lo que facilita el ingreso de datos históricos en el sistema.

### 4.2 Validación de traslape de fechas
El sistema ahora impide registrar una solicitud de vacaciones cuyas fechas se superpongan con otra solicitud vigente del mismo colaborador.

### 4.3 Acumulación real de días entre años
Se implementó la lógica de acumulación de días no utilizados entre períodos anuales. Los días de vacaciones no gozados de un año se trasladan al siguiente, con un tope máximo de 30 días (equivalente a dos períodos). El saldo visible en pantalla refleja ahora este cálculo correctamente.

---

## 5. Módulo de Ausencias Injustificadas

### 5.1 Regularización con registro real
Cuando una ausencia injustificada es regularizada como permiso personal, el sistema crea ahora un registro formal en el módulo de Permisos vinculado a esa fecha. Anteriormente esta acción solo actualizaba un campo interno sin generar trazabilidad real. La regularización como incapacidad vincula el registro a la incapacidad correspondiente si ya fue registrada en el sistema.

---

## 6. Módulo de Amonestaciones

### 6.1 Evidencia obligatoria para faltas graves
Para faltas clasificadas como graves o muy graves, el sistema ahora exige adjuntar documentación de respaldo al momento del registro, tanto a nivel de validación en pantalla como en el servidor. Esto aplica igualmente a los casos de suspensión.

---

## 7. Integración con Módulo de Planillas

### 7.1 Descuento automático por días de suspensión disciplinaria
Los días de suspensión registrados en amonestaciones aprobadas ahora se integran automáticamente al cálculo quincenal de planilla. Los días suspendidos se tratan como días no laborados, lo que reduce proporcionalmente el salario del período, AFP patronal y empleado, ISSS y cálculo de renta. Adicionalmente, el detalle de la línea de planilla registra la cantidad de días de suspensión aplicados para efectos de auditoría y consulta.

---

## 8. Resumen de cumplimiento

Con las actualizaciones descritas, la plataforma cumple con la totalidad de los lineamientos institucionales en los módulos de Permisos, Ausencias, Amonestaciones y Vacaciones. El módulo de Ingreso de Personal está en proceso de definición y será habilitado una vez se establezcan los criterios operativos correspondientes.

---

*Documento generado para uso interno de Recursos Humanos — Cadejo Brewing Company*
