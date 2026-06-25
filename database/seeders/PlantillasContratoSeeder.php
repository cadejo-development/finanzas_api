<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlantillasContratoSeeder extends Seeder
{
    public function run(): void
    {
        DB::connection('rrhh')->table('plantillas_contrato')->upsert(
            [
                [
                    'id'               => 1,
                    'tipo_contrato_id' => 2,
                    'cargo_id'         => null,
                    'cargo_nombre'     => null,
                    'nombre'           => 'Contrato Individual de Trabajo — Operativo',
                    'contenido'        => $this->contratoOperativo(),
                    'version'          => 2,
                    'activo'           => true,
                    'aud_usuario'      => 'seeder',
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ],
                [
                    'id'               => 2,
                    'tipo_contrato_id' => 1,
                    'cargo_id'         => null,
                    'cargo_nombre'     => null,
                    'nombre'           => 'Contrato de Servicios Profesionales',
                    'contenido'        => $this->contratoServiciosProfesionales(),
                    'version'          => 2,
                    'activo'           => true,
                    'aud_usuario'      => 'seeder',
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ],
                [
                    'id'               => 3,
                    'tipo_contrato_id' => 2,
                    'cargo_id'         => null,
                    'cargo_nombre'     => null,
                    'nombre'           => 'Contrato Individual de Trabajo — Administrativo',
                    'contenido'        => $this->contratoAdministrativo(),
                    'version'          => 1,
                    'activo'           => true,
                    'aud_usuario'      => 'seeder',
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ],
            ],
            ['id'],
            ['nombre', 'contenido', 'version', 'updated_at']
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Contrato Individual — Operativo (roles con turnos rotativos)
    // Sección d) incluye tabla de horarios posibles
    // ─────────────────────────────────────────────────────────────────────────
    private function contratoOperativo(): string
    {
        return <<<'HTML'
<h2 style="text-align:center; font-size:13pt; margin-bottom:18px;">CONTRATO INDIVIDUAL DE TRABAJO</h2>

<table style="width:100%; border-collapse:collapse; font-size:8.5pt; margin-bottom:16px;">
  <tr>
    <td style="width:50%; border:1px solid #000; padding:6px; vertical-align:top;">
      <strong>GENERALES DE PARTE PATRONAL</strong><br>
      Nombre: David Arthur Falkenstein Algara<br>
      Sexo: Masculino<br>
      Edad: 52 años &nbsp;&nbsp; Estado Civil: Soltero/a<br>
      Profesión u Oficio: Empleado<br>
      Domicilio: Santa Tecla, La Libertad<br>
      Nacionalidad: salvadoreño<br>
      DUI: 02603386-9 — Expedido en San Salvador, San Salvador el 04 de mayo de 2022<br>
      En Representación de: Cadejo Brewing Company, S.A. de C.V.<br>
      NIT: 0614-120411-105-11<br>
      Actividad Económica: Alimentos y Bebidas
    </td>
    <td style="width:50%; border:1px solid #000; padding:6px; vertical-align:top;">
      <strong>GENERALES DEL TRABAJADOR</strong><br>
      Nombre: {{nombre}}<br>
      Sexo: {{genero}} &nbsp;&nbsp; Estado Civil: {{estado_civil}}<br>
      Edad: {{edad}}<br>
      Profesión u Oficio: {{profesion}}<br>
      Domicilio: {{domicilio}}<br>
      Nacionalidad: {{nacionalidad}}<br>
      DUI: {{dui}}<br>
      Expedido en {{dui_expedido_en}}, el {{dui_fecha_exp}}<br>
      ISSS: {{isss}} &nbsp;&nbsp; AFP/NUP: {{afp}}
    </td>
  </tr>
</table>

<p style="font-size:9pt; text-align:justify; margin-bottom:12px;">
  NOSOTROS <strong>David Arthur Falkenstein Algara</strong>, en representación de <strong>CADEJO BREWING COMPANY, SOCIEDAD ANÓNIMA DE CAPITAL VARIABLE</strong>, que puede abreviarse Cadejo Brewing Company, S.A. de C.V., en adelante "<strong>EL PATRONO</strong>" y <strong>{{nombre}}</strong>, en adelante "<strong>EL TRABAJADOR</strong>", de las generales arriba indicadas y actuando en el carácter que aparece expresado, convenimos en celebrar el presente Contrato Individual de Trabajo, sujeto a las estipulaciones siguientes. Cuando se haga referencia conjunta a EL PATRONO y EL TRABAJADOR, se les denominará "las partes".
</p>

<p style="font-size:9pt; text-align:justify; margin-bottom:10px;">
  <strong>a) CLASE DE TRABAJO O SERVICIO:</strong> El trabajador se obliga a prestar sus servicios al patrono como <strong>{{cargo}}</strong>. Desempeñando las funciones de: {{funciones}}; estas funciones no son restrictivas, pueden modificarse, adherirse o eliminarse según la necesidad del negocio y en común acuerdo entre las partes. Además de cumplir con todas las tareas que corresponden al cargo, el trabajador deberá cumplir con todas aquellas obligaciones que le impongan las leyes laborales, según políticas y el reglamento interno de trabajo.
</p>

<p style="font-size:9pt; text-align:justify; margin-bottom:10px;">
  <strong>b) DURACIÓN DEL CONTRATO Y TIEMPO DE SERVICIO:</strong> El presente Contrato se celebrará por <strong>TIEMPO INDEFINIDO</strong>. El tiempo de servicio se computará a partir del <strong>{{fecha_inicio_letras}}</strong>, fecha desde la cual el trabajador prestará sus servicios al patrono sin que la relación laboral se haya disuelto. Para los trabajadores de nuevo ingreso se estipula que los primeros treinta (30) días serán de prueba y dentro de ese término cualquiera de las partes podrá terminar el contrato sin expresión de causa.
</p>

<p style="font-size:9pt; text-align:justify; margin-bottom:10px;">
  <strong>c) LUGAR DE PRESTACIÓN DE SERVICIOS Y DE ALOJAMIENTO:</strong> El lugar de prestación de los servicios será en <strong>Cadejo {{sucursal}}</strong>. Sin embargo, derivado que el negocio posee diferentes sucursales/centros de trabajo, el lugar de presentación de servicios puede cambiar según las necesidades específicas y en común acuerdo entre las partes.
</p>

<p style="font-size:9pt; text-align:justify; margin-bottom:6px;">
  <strong>d) HORARIO DE TRABAJO:</strong> Semana laboral de 44 horas con 1 día de descanso rotativo. Sin embargo, derivado que el negocio posee diferentes sucursales/centros de trabajo, el horario de trabajo puede cambiar según cada sucursal/centro de trabajo y/o necesidades específicas, siempre y cuando sea en común acuerdo entre las partes.
</p>
<p style="font-size:9pt; margin-bottom:6px;">El horario que pudiese aplicar al trabajador será alguno de los siguientes:</p>

<table style="width:100%; border-collapse:collapse; font-size:8pt; margin-bottom:8px;">
  <tr style="background:#f2f2f2;">
    <th style="border:1px solid #000; padding:4px; text-align:center;">INICIO LABORES</th>
    <th style="border:1px solid #000; padding:4px; text-align:center;">INICIO ALMUERZO</th>
    <th style="border:1px solid #000; padding:4px; text-align:center;">FIN ALMUERZO</th>
    <th style="border:1px solid #000; padding:4px; text-align:center;">FIN LABORES</th>
  </tr>
  <tr><td style="border:1px solid #000; padding:3px; text-align:center;">05:00 A.M.</td><td style="border:1px solid #000; padding:3px; text-align:center;">11:00 a.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">11:30 a.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">01:00 p.m.</td></tr>
  <tr><td style="border:1px solid #000; padding:3px; text-align:center;">05:00 A.M.</td><td style="border:1px solid #000; padding:3px; text-align:center;">11:30 a.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">12:00 p.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">01:00 p.m.</td></tr>
  <tr><td style="border:1px solid #000; padding:3px; text-align:center;">10:00 A.M.</td><td style="border:1px solid #000; padding:3px; text-align:center;">03:00 p.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">06:00 p.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">09:00 p.m.</td></tr>
  <tr><td style="border:1px solid #000; padding:3px; text-align:center;">10:00 A.M.</td><td style="border:1px solid #000; padding:3px; text-align:center;">03:00 p.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">07:00 p.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">10:00 p.m.</td></tr>
  <tr><td style="border:1px solid #000; padding:3px; text-align:center;">10:00 A.M.</td><td style="border:1px solid #000; padding:3px; text-align:center;">03:00 p.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">08:00 p.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">11:00 p.m.</td></tr>
  <tr><td style="border:1px solid #000; padding:3px; text-align:center;">10:00 A.M.</td><td style="border:1px solid #000; padding:3px; text-align:center;">03:00 p.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">09:00 p.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">12:00 a.m.</td></tr>
  <tr><td style="border:1px solid #000; padding:3px; text-align:center;">11:00 A.M.</td><td style="border:1px solid #000; padding:3px; text-align:center;">03:00 p.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">06:00 p.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">10:00 p.m.</td></tr>
  <tr><td style="border:1px solid #000; padding:3px; text-align:center;">11:00 A.M.</td><td style="border:1px solid #000; padding:3px; text-align:center;">03:00 p.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">07:00 p.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">11:00 p.m.</td></tr>
  <tr><td style="border:1px solid #000; padding:3px; text-align:center;">11:00 A.M.</td><td style="border:1px solid #000; padding:3px; text-align:center;">03:00 p.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">08:00 p.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">12:00 a.m.</td></tr>
  <tr><td style="border:1px solid #000; padding:3px; text-align:center;">12:00 P.M.</td><td style="border:1px solid #000; padding:3px; text-align:center;">02:30 p.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">03:00 p.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">08:00 p.m.</td></tr>
  <tr><td style="border:1px solid #000; padding:3px; text-align:center;">12:00 P.M.</td><td style="border:1px solid #000; padding:3px; text-align:center;">03:00 p.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">03:30 p.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">08:00 p.m.</td></tr>
  <tr><td style="border:1px solid #000; padding:3px; text-align:center;">12:00 P.M.</td><td style="border:1px solid #000; padding:3px; text-align:center;">03:00 p.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">04:00 p.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">09:00 p.m.</td></tr>
  <tr><td style="border:1px solid #000; padding:3px; text-align:center;">12:00 P.M.</td><td style="border:1px solid #000; padding:3px; text-align:center;">03:00 p.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">07:00 p.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">12:00 a.m.</td></tr>
  <tr><td style="border:1px solid #000; padding:3px; text-align:center;">03:00 P.M.</td><td style="border:1px solid #000; padding:3px; text-align:center;">05:00 p.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">06:00 p.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">12:00 a.m.</td></tr>
  <tr><td style="border:1px solid #000; padding:3px; text-align:center;">03:00 P.M.</td><td style="border:1px solid #000; padding:3px; text-align:center;">09:00 p.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">10:00 p.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">12:00 a.m.</td></tr>
  <tr><td style="border:1px solid #000; padding:3px; text-align:center;">04:00 P.M.</td><td style="border:1px solid #000; padding:3px; text-align:center;">05:00 p.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">05:30 p.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">12:30 a.m.</td></tr>
  <tr><td style="border:1px solid #000; padding:3px; text-align:center;">04:00 P.M.</td><td style="border:1px solid #000; padding:3px; text-align:center;">09:00 p.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">10:00 p.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">01:00 a.m.</td></tr>
  <tr><td style="border:1px solid #000; padding:3px; text-align:center;">04:00 P.M.</td><td style="border:1px solid #000; padding:3px; text-align:center;">10:00 p.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">10:30 p.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">12:00 a.m.</td></tr>
  <tr><td style="border:1px solid #000; padding:3px; text-align:center;">05:00 P.M.</td><td style="border:1px solid #000; padding:3px; text-align:center;">10:00 p.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">10:30 p.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">01:00 a.m.</td></tr>
  <tr><td style="border:1px solid #000; padding:3px; text-align:center;">06:00 P.M.</td><td style="border:1px solid #000; padding:3px; text-align:center;">10:00 p.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">10:30 p.m.</td><td style="border:1px solid #000; padding:3px; text-align:center;">01:00 a.m.</td></tr>
</table>

<p style="font-size:9pt; text-align:justify; margin-bottom:10px;">
  Únicamente podrán ejecutarse trabajos extraordinarios cuando se reciba la orden ya sea por escrito o verbalmente por parte del Patrono o sus Representantes Patronales.
</p>

<p style="font-size:9pt; text-align:justify; margin-bottom:10px;">
  <strong>e) SALARIO: FORMA, PERÍODO Y LUGAR DEL PAGO:</strong> El salario que recibirá el trabajador, por sus servicios, será la suma de <strong>{{salario_letras}}</strong> (<strong>{{salario}}</strong>) más las prestaciones laborales reconocida por la ley vigente durante la duración del presente contrato laboral. El salario y toda prestación laboral reconocida por la ley se pagará a través de depósito bancario. Dicho pago se hará quincenalmente los días 15 y final de mes, 30 o 31. La operación del pago principiará y se continuará sin interrupción, a más tardar a la Terminación de la jornada de trabajo correspondiente a la respectiva fecha, en caso de reclamo de la persona trabajadora, se estará a lo dispuesto en el art. 613 del Código de Trabajo.
</p>

<p style="font-size:9pt; text-align:justify; margin-bottom:10px;">
  <strong>f) HERRAMIENTAS Y MATERIALES:</strong> El patrono suministrará al trabajador las herramientas y materiales que sean necesarios para el cumplimiento de sus labores. Todas las herramientas y materiales que utilice y se le entreguen deben ser devueltos al patrono, en el mismo estado en que sean recibidas cuando sean requeridas por sus jefes inmediatos o al darse por finalizada la relación laboral, salvo la disminución o deterioro causados por caso fortuito o fuerza mayor, o por la acción del tiempo o por el consumo y uso normal de los mismos.
</p>

<p style="font-size:9pt; margin-bottom:6px;"><strong>g) PERSONAS QUE DEPENDEN ECONÓMICAMENTE DEL TRABAJADOR:</strong></p>
<table style="width:100%; border-collapse:collapse; font-size:8.5pt; margin-bottom:12px;">
  <tr style="background:#f2f2f2;">
    <th style="border:1px solid #000; padding:4px; text-align:center;">NOMBRE</th>
    <th style="border:1px solid #000; padding:4px; text-align:center;">FECHA DE NACIMIENTO</th>
    <th style="border:1px solid #000; padding:4px; text-align:center;">PARENTESCO</th>
  </tr>
  <tr><td style="border:1px solid #000; padding:4px;">&nbsp;</td><td style="border:1px solid #000; padding:4px;">&nbsp;</td><td style="border:1px solid #000; padding:4px;">&nbsp;</td></tr>
  <tr><td style="border:1px solid #000; padding:4px;">&nbsp;</td><td style="border:1px solid #000; padding:4px;">&nbsp;</td><td style="border:1px solid #000; padding:4px;">&nbsp;</td></tr>
</table>

<p style="font-size:9pt; text-align:justify; margin-bottom:10px;">
  <strong>h) OTRAS ESTIPULACIONES:</strong> El Trabajador se compromete a guardar rigurosa reserva sobre aquellos asuntos confidenciales de los cuales tuviere conocimiento por razón de su cargo, y sobre asuntos administrativos cuya divulgación pudiere perjudicar a Cadejo Brewing Company, S.A. de C.V. o provecho indebido a terceros.
</p>

<p style="font-size:9pt; text-align:justify; margin-bottom:16px;">
  <strong>i) TERMINACIÓN DEL CONTRATO POR FALTAS GRAVES:</strong> El presente contrato podrá darse por terminado sin obligación de indemnización alguna por parte de EL PATRONO en caso de que EL TRABAJADOR incurra en faltas graves que atenten contra la integridad, seguridad o bienes de la empresa, sus clientes o sus colaboradores. Se considerarán faltas graves, entre otras, la comisión de actos de robo, hurto, fraude, daño intencional a bienes de la empresa, agresiones físicas o verbales, divulgación de información confidencial en perjuicio del negocio y cualquier otra conducta que vulnere las normas establecidas en el reglamento interno de trabajo y en la legislación laboral aplicable.
</p>

<p style="font-size:9pt; text-align:justify; margin-bottom:20px;">
  Este contrato sustituye cualquier otro convenio individual de trabajo anterior, ya sea escrito o verbal, que haya estado vigente entre las partes. En fe de lo cual firmamos este documento por triplicado en <strong>{{ciudad_firma}}</strong> a los <strong>{{fecha_firma_letras}}</strong>.
</p>

<table style="width:100%; margin-top:30px;">
  <tr>
    <td style="width:50%; text-align:center; padding:10px;">
      <div style="border-top:1px solid #000; width:80%; margin:0 auto; padding-top:6px; font-size:9pt;">
        <strong>{{patrono}}</strong><br>PATRONO O REPRESENTANTE
      </div>
    </td>
    <td style="width:50%; text-align:center; padding:10px;">
      <div style="border-top:1px solid #000; width:80%; margin:0 auto; padding-top:6px; font-size:9pt;">
        <strong>{{nombre}}</strong><br>TRABAJADOR/A — DUI: {{dui}}
      </div>
    </td>
  </tr>
</table>
HTML;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Contrato Individual — Administrativo (horario fijo, sin tabla de turnos)
    // ─────────────────────────────────────────────────────────────────────────
    private function contratoAdministrativo(): string
    {
        return <<<'HTML'
<h2 style="text-align:center; font-size:13pt; margin-bottom:18px;">CONTRATO INDIVIDUAL DE TRABAJO</h2>

<table style="width:100%; border-collapse:collapse; font-size:8.5pt; margin-bottom:16px;">
  <tr>
    <td style="width:50%; border:1px solid #000; padding:6px; vertical-align:top;">
      <strong>GENERALES DE PARTE PATRONAL</strong><br>
      Nombre: David Arthur Falkenstein Algara<br>
      Sexo: Masculino<br>
      Edad: 52 años &nbsp;&nbsp; Estado Civil: Soltero/a<br>
      Profesión u Oficio: Empleado<br>
      Domicilio: Santa Tecla, La Libertad<br>
      Nacionalidad: salvadoreño<br>
      DUI: 02603386-9 — Expedido en San Salvador, San Salvador el 04 de mayo de 2022<br>
      En Representación de: Cadejo Brewing Company, S.A. de C.V.<br>
      NIT: 0614-120411-105-11<br>
      Actividad Económica: Alimentos y Bebidas
    </td>
    <td style="width:50%; border:1px solid #000; padding:6px; vertical-align:top;">
      <strong>GENERALES DEL TRABAJADOR</strong><br>
      Nombre: {{nombre}}<br>
      Sexo: {{genero}} &nbsp;&nbsp; Estado Civil: {{estado_civil}}<br>
      Edad: {{edad}}<br>
      Profesión u Oficio: {{profesion}}<br>
      Domicilio: {{domicilio}}<br>
      Nacionalidad: {{nacionalidad}}<br>
      DUI: {{dui}}<br>
      Expedido en {{dui_expedido_en}}, el {{dui_fecha_exp}}<br>
      ISSS: {{isss}} &nbsp;&nbsp; AFP/NUP: {{afp}}
    </td>
  </tr>
</table>

<p style="font-size:9pt; text-align:justify; margin-bottom:12px;">
  NOSOTROS <strong>David Arthur Falkenstein Algara</strong>, en representación de <strong>CADEJO BREWING COMPANY, SOCIEDAD ANÓNIMA DE CAPITAL VARIABLE</strong>, que puede abreviarse Cadejo Brewing Company, S.A. de C.V., en adelante "<strong>EL PATRONO</strong>" y <strong>{{nombre}}</strong>, en adelante "<strong>EL TRABAJADOR</strong>", de las generales arriba indicadas y actuando en el carácter que aparece expresado, convenimos en celebrar el presente Contrato Individual de Trabajo, sujeto a las estipulaciones siguientes. Cuando se haga referencia conjunta a EL PATRONO y EL TRABAJADOR, se les denominará "las partes".
</p>

<p style="font-size:9pt; text-align:justify; margin-bottom:10px;">
  <strong>a) CLASE DE TRABAJO O SERVICIO:</strong> El trabajador se obliga a prestar sus servicios al patrono como <strong>{{cargo}}</strong>. Desempeñando las funciones de: {{funciones}}; estas funciones no son restrictivas, pueden modificarse, adherirse o eliminarse según la necesidad del negocio y en común acuerdo entre las partes. Además de cumplir con todas las tareas que corresponden al cargo, el trabajador deberá cumplir con todas aquellas obligaciones que le impongan las leyes laborales, según políticas y el reglamento interno de trabajo.
</p>

<p style="font-size:9pt; text-align:justify; margin-bottom:10px;">
  <strong>b) DURACIÓN DEL CONTRATO Y TIEMPO DE SERVICIO:</strong> El presente Contrato se celebrará por <strong>TIEMPO INDEFINIDO</strong>. El tiempo de servicio se computará a partir del <strong>{{fecha_inicio_letras}}</strong>, fecha desde la cual el trabajador prestará sus servicios al patrono sin que la relación laboral se haya disuelto. Para los trabajadores de nuevo ingreso se estipula que los primeros treinta (30) días serán de prueba y dentro de ese término cualquiera de las partes podrá terminar el contrato sin expresión de causa.
</p>

<p style="font-size:9pt; text-align:justify; margin-bottom:10px;">
  <strong>c) LUGAR DE PRESTACIÓN DE SERVICIOS Y DE ALOJAMIENTO:</strong> El lugar de prestación de los servicios será en <strong>Cadejo {{sucursal}}</strong>. Sin embargo, derivado que el negocio posee diferentes sucursales/centros de trabajo, el lugar de presentación de servicios puede cambiar según las necesidades específicas y en común acuerdo entre las partes.
</p>

<p style="font-size:9pt; text-align:justify; margin-bottom:10px;">
  <strong>d) HORARIO DE TRABAJO:</strong> Semana laboral de 44 horas, de lunes a viernes. El horario del trabajador es de <strong>{{horario}}</strong>. Únicamente podrán ejecutarse trabajos extraordinarios cuando se reciba la orden por escrito o verbalmente por parte del Patrono o sus Representantes Patronales.
</p>

<p style="font-size:9pt; text-align:justify; margin-bottom:10px;">
  <strong>e) SALARIO: FORMA, PERÍODO Y LUGAR DEL PAGO:</strong> El salario que recibirá el trabajador, por sus servicios, será la suma de <strong>{{salario_letras}}</strong> (<strong>{{salario}}</strong>) más las prestaciones laborales reconocida por la ley vigente durante la duración del presente contrato laboral. El salario y toda prestación laboral reconocida por la ley se pagará a través de depósito bancario. Dicho pago se hará quincenalmente los días 15 y final de mes. La operación del pago principiará y se continuará sin interrupción, a más tardar a la Terminación de la jornada de trabajo correspondiente a la respectiva fecha, en caso de reclamo de la persona trabajadora, se estará a lo dispuesto en el art. 613 del Código de Trabajo.
</p>

<p style="font-size:9pt; text-align:justify; margin-bottom:10px;">
  <strong>f) HERRAMIENTAS Y MATERIALES:</strong> El patrono suministrará al trabajador las herramientas y materiales que sean necesarios para el cumplimiento de sus labores. Todas las herramientas y materiales que utilice y se le entreguen deben ser devueltos al patrono, en el mismo estado en que sean recibidas cuando sean requeridas por sus jefes inmediatos o al darse por finalizada la relación laboral, salvo la disminución o deterioro causados por caso fortuito o fuerza mayor, o por la acción del tiempo o por el consumo y uso normal de los mismos.
</p>

<p style="font-size:9pt; margin-bottom:6px;"><strong>g) PERSONAS QUE DEPENDEN ECONÓMICAMENTE DEL TRABAJADOR:</strong></p>
<table style="width:100%; border-collapse:collapse; font-size:8.5pt; margin-bottom:12px;">
  <tr style="background:#f2f2f2;">
    <th style="border:1px solid #000; padding:4px; text-align:center;">NOMBRE</th>
    <th style="border:1px solid #000; padding:4px; text-align:center;">FECHA DE NACIMIENTO</th>
    <th style="border:1px solid #000; padding:4px; text-align:center;">PARENTESCO</th>
  </tr>
  <tr><td style="border:1px solid #000; padding:4px;">&nbsp;</td><td style="border:1px solid #000; padding:4px;">&nbsp;</td><td style="border:1px solid #000; padding:4px;">&nbsp;</td></tr>
  <tr><td style="border:1px solid #000; padding:4px;">&nbsp;</td><td style="border:1px solid #000; padding:4px;">&nbsp;</td><td style="border:1px solid #000; padding:4px;">&nbsp;</td></tr>
</table>

<p style="font-size:9pt; text-align:justify; margin-bottom:10px;">
  <strong>h) OTRAS ESTIPULACIONES:</strong> El Trabajador se compromete a guardar rigurosa reserva sobre aquellos asuntos confidenciales de los cuales tuviere conocimiento por razón de su cargo, y sobre asuntos administrativos cuya divulgación pudiere perjudicar a Cadejo Brewing Company, S.A. de C.V. o provecho indebido a terceros.
</p>

<p style="font-size:9pt; text-align:justify; margin-bottom:16px;">
  <strong>i) TERMINACIÓN DEL CONTRATO POR FALTAS GRAVES:</strong> El presente contrato podrá darse por terminado sin obligación de indemnización alguna por parte de EL PATRONO en caso de que EL TRABAJADOR incurra en faltas graves que atenten contra la integridad, seguridad o bienes de la empresa, sus clientes o sus colaboradores. Se considerarán faltas graves, entre otras, la comisión de actos de robo, hurto, fraude, daño intencional a bienes de la empresa, agresiones físicas o verbales, divulgación de información confidencial en perjuicio del negocio y cualquier otra conducta que vulnere las normas establecidas en el reglamento interno de trabajo y en la legislación laboral aplicable.
</p>

<p style="font-size:9pt; text-align:justify; margin-bottom:20px;">
  Este contrato sustituye cualquier otro convenio individual de trabajo anterior, ya sea escrito o verbal, que haya estado vigente entre las partes. En fe de lo cual firmamos este documento por triplicado en <strong>{{ciudad_firma}}</strong> a los <strong>{{fecha_firma_letras}}</strong>.
</p>

<table style="width:100%; margin-top:30px;">
  <tr>
    <td style="width:50%; text-align:center; padding:10px;">
      <div style="border-top:1px solid #000; width:80%; margin:0 auto; padding-top:6px; font-size:9pt;">
        <strong>{{patrono}}</strong><br>PATRONO O REPRESENTANTE
      </div>
    </td>
    <td style="width:50%; text-align:center; padding:10px;">
      <div style="border-top:1px solid #000; width:80%; margin:0 auto; padding-top:6px; font-size:9pt;">
        <strong>{{nombre}}</strong><br>TRABAJADOR/A — DUI: {{dui}}
      </div>
    </td>
  </tr>
</table>
HTML;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Contrato de Servicios Profesionales
    // ─────────────────────────────────────────────────────────────────────────
    private function contratoServiciosProfesionales(): string
    {
        return <<<'HTML'
<h2 style="text-align:center; font-size:13pt; margin-bottom:6px;">CADEJO BREWING COMPANY S.A. DE C.V.</h2>
<p style="text-align:center; font-size:9pt; margin-bottom:18px;">NIT: {{nit_patrono}}</p>
<h2 style="text-align:center; font-size:12pt; margin-bottom:18px;">CONTRATO DE PRESTACIÓN DE SERVICIOS PROFESIONALES</h2>

<table style="width:100%; border-collapse:collapse; font-size:8.5pt; margin-bottom:16px;">
  <tr>
    <td style="width:40%; border:1px solid #000; padding:5px; font-weight:bold;">Contratante:</td>
    <td style="width:60%; border:1px solid #000; padding:5px;">Cadejo Brewing Company S.A. de C.V.</td>
  </tr>
  <tr>
    <td style="border:1px solid #000; padding:5px; font-weight:bold;">Representante:</td>
    <td style="border:1px solid #000; padding:5px;">{{patrono}}</td>
  </tr>
  <tr>
    <td style="border:1px solid #000; padding:5px; font-weight:bold;">Prestador/a:</td>
    <td style="border:1px solid #000; padding:5px;">{{nombre}}</td>
  </tr>
  <tr>
    <td style="border:1px solid #000; padding:5px; font-weight:bold;">DUI:</td>
    <td style="border:1px solid #000; padding:5px;">{{dui}} — Expedido en {{dui_expedido_en}}</td>
  </tr>
  <tr>
    <td style="border:1px solid #000; padding:5px; font-weight:bold;">Servicio:</td>
    <td style="border:1px solid #000; padding:5px;">{{cargo}}</td>
  </tr>
  <tr>
    <td style="border:1px solid #000; padding:5px; font-weight:bold;">Lugar:</td>
    <td style="border:1px solid #000; padding:5px;">{{sucursal}}</td>
  </tr>
</table>

<h3 style="font-size:10pt; margin-bottom:6px;">CLÁUSULAS</h3>

<p style="font-size:9pt; text-align:justify; margin-bottom:10px;">
  <strong>I. OBJETO.</strong> Por medio del presente instrumento, Cadejo Brewing Company S.A. de C.V., en adelante "El Contratante", contrata los servicios profesionales de <strong>{{nombre}}</strong>, en adelante "El/La Prestador/a", para desempeñar las funciones propias del servicio de <strong>{{cargo}}</strong> según las necesidades operacionales de la sucursal indicada.{{funciones ? ' Funciones específicas: ' : ''}}{{funciones}}
</p>

<p style="font-size:9pt; text-align:justify; margin-bottom:10px;">
  <strong>II. DURACIÓN.</strong> El presente contrato tendrá vigencia desde el <strong>{{fecha_inicio_letras}}</strong> hasta el <strong>{{fecha_fin_letras}}</strong>, por un período de treinta (30) días calendario, en atención a necesidades operacionales de la empresa. Al vencimiento del plazo, el contrato concluye automáticamente sin necesidad de aviso previo ni responsabilidad adicional para ninguna de las partes, salvo acuerdo escrito de renovación.
</p>

<p style="font-size:9pt; text-align:justify; margin-bottom:10px;">
  <strong>III. HONORARIOS Y RETENCIÓN.</strong> El Contratante reconocerá al/a la Prestador/a honorarios mensuales por la suma de <strong>{{salario_letras}}</strong> (<strong>{{salario}}</strong>), pagaderos quincenalmente. Sobre el monto total de honorarios se aplicará una retención del diez por ciento (10%) de Impuesto sobre la Renta conforme al Art. 156 del Código Tributario de El Salvador, la cual será enterada al Ministerio de Hacienda por El Contratante. El/La Prestador/a recibirá el comprobante de retención correspondiente.
</p>

<p style="font-size:9pt; text-align:justify; margin-bottom:10px;">
  <strong>IV. HORARIO DE PRESTACIÓN.</strong> Los servicios se prestarán en un horario de cuarenta y cuatro (44) horas semanales, distribuidas conforme al calendario operacional de la sucursal, pudiendo incluir turnos diurnos, nocturnos y/o fines de semana, con un descanso diario de treinta (30) minutos durante la jornada.
</p>

<p style="font-size:9pt; text-align:justify; margin-bottom:10px;">
  <strong>V. BENEFICIOS ADICIONALES.</strong> Durante la vigencia del contrato, El/La Prestador/a gozará de: (a) Descuento en consumo: 40% para hasta 2 personas y 25% para grupos de 3 a 6 personas sobre el menú regular; 50% en productos promocionales y cerveza en botella. (b) Producto gratuito por turno: 1 cerveza artesanal o agua dura de 12 oz y 1 soda artesanal de 12 oz (no acumulables). Se prohíbe el consumo de bebidas alcohólicas durante el turno de trabajo.
</p>

<p style="font-size:9pt; text-align:justify; margin-bottom:10px;">
  <strong>VI. OBLIGACIONES DEL/DE LA PRESTADOR/A.</strong> El/La Prestador/a se obliga a: ejecutar los servicios con diligencia y calidad; cumplir las políticas internas y el Reglamento vigente de la empresa; guardar reserva sobre información confidencial; y mantener una conducta acorde con los valores de la marca dentro y fuera del horario de prestación.
</p>

<p style="font-size:9pt; text-align:justify; margin-bottom:16px;">
  <strong>VII. TERMINACIÓN ANTICIPADA.</strong> Cualquiera de las partes podrá dar por terminado el presente contrato antes del vencimiento del plazo mediante aviso escrito con tres (3) días de anticipación, sin que ello genere obligación de pago distinta a los honorarios proporcionales devengados hasta la fecha de terminación.
</p>

<p style="font-size:9pt; text-align:justify; margin-bottom:20px;">
  En constancia de lo acordado, las partes suscriben el presente contrato en dos ejemplares de igual valor, en <strong>{{ciudad_firma}}</strong>, a los <strong>{{fecha_firma_letras}}</strong>.
</p>

<table style="width:100%; margin-top:30px;">
  <tr>
    <td style="width:50%; text-align:center; padding:10px;">
      <div style="border-top:1px solid #000; width:80%; margin:0 auto; padding-top:6px; font-size:9pt;">
        <strong>{{patrono}}</strong><br>El Contratante — Cadejo Brewing Company S.A. de C.V.
      </div>
    </td>
    <td style="width:50%; text-align:center; padding:10px;">
      <div style="border-top:1px solid #000; width:80%; margin:0 auto; padding-top:6px; font-size:9pt;">
        <strong>{{nombre}}</strong><br>El/La Prestador/a — DUI: {{dui}}
      </div>
    </td>
  </tr>
</table>
HTML;
    }
}
