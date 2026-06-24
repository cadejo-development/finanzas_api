<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Inserta las plantillas de contrato base de Cadejo Brewing Company.
 * Usa variables {{variable}} que se sustituyen en ContratoEmpleadoController::buildVars().
 */
class PlantillasContratoSeeder extends Seeder
{
    public function run(): void
    {
        DB::connection('rrhh')->table('plantillas_contrato')->upsert(
            [
                [
                    'id'               => 1,
                    'tipo_contrato_id' => 2, // Contrato Indefinido
                    'cargo_id'         => null,
                    'cargo_nombre'     => null,
                    'nombre'           => 'Contrato Individual de Trabajo — Indefinido',
                    'contenido'        => $this->contratoIndividual(),
                    'version'          => 1,
                    'activo'           => true,
                    'aud_usuario'      => 'seeder',
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ],
                [
                    'id'               => 2,
                    'tipo_contrato_id' => 1, // Servicios Profesionales
                    'cargo_id'         => null,
                    'cargo_nombre'     => null,
                    'nombre'           => 'Contrato de Servicios Profesionales',
                    'contenido'        => $this->contratoServiciosProfesionales(),
                    'version'          => 1,
                    'activo'           => true,
                    'aud_usuario'      => 'seeder',
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ],
            ],
            ['id'],
            ['contenido', 'version', 'updated_at']
        );
    }

    private function contratoIndividual(): string
    {
        return <<<'HTML'
<h2>CONTRATO INDIVIDUAL DE TRABAJO</h2>

<p>Nosotros: <strong>{{patrono}}</strong>, mayor de edad, de nacionalidad norteamericana, con residencia en esta ciudad, NIT <strong>{{nit_patrono}}</strong>, actuando en su calidad de Patrono (en lo sucesivo "EL PATRONO"), y <strong>{{nombre}}</strong>, de <strong>{{estado_civil}}</strong>, de nacionalidad salvadoreña, portador/a del DUI número <strong>{{dui}}</strong>, expedido en <strong>{{dui_expedido_en}}</strong>, con número de ISSS <strong>{{isss}}</strong> y AFP/NUP <strong>{{afp}}</strong>, (en lo sucesivo "EL/LA TRABAJADOR/A"), hemos convenido en celebrar el presente CONTRATO INDIVIDUAL DE TRABAJO, de conformidad con las estipulaciones siguientes:</p>

<h3>a) Clase de Trabajo</h3>
<p>EL/LA TRABAJADOR/A se obliga a prestar sus servicios como <strong>{{cargo}}</strong>, desempeñando las funciones propias del puesto y todas las tareas que le sean asignadas por su jefe/a inmediato/a, así como cualquier función que sea requerida según las necesidades operativas de LA EMPRESA.</p>

<h3>b) Duración</h3>
<p>El presente contrato tendrá duración <strong>INDEFINIDA</strong>, iniciando el día <strong>{{fecha_inicio_letras}}</strong>. En el presente contrato se pacta un período de prueba de treinta días calendario contados desde la fecha de inicio.</p>

<h3>c) Lugar de Trabajo</h3>
<p>EL/LA TRABAJADOR/A prestará sus servicios en las instalaciones de <strong>{{sucursal}}</strong>, ubicadas en la ciudad de San Salvador. No obstante, dada la naturaleza del negocio, EL/LA TRABAJADOR/A podrá ser requerido/a a laborar de manera rotativa en las diferentes sucursales de Cadejo Brewing Company según las necesidades del servicio, sin que ello constituya variación sustancial de las condiciones de trabajo.</p>

<h3>d) Jornada de Trabajo</h3>
<p>EL/LA TRABAJADOR/A laborará <strong>cuarenta y cuatro (44) horas semanales</strong>, distribuidas en jornadas diurnas y mixtas conforme al cuadrante de turnos que asigne LA EMPRESA. Los días y horarios específicos serán comunicados con al menos 72 horas de anticipación a través del sistema de programación de turnos.</p>
<p>EL PATRONO se reserva la facultad de modificar los horarios y días de descanso dentro de los límites permitidos por el Código de Trabajo, notificando al/a la TRABAJADOR/A con la debida anticipación.</p>

<h3>e) Salario</h3>
<p>EL PATRONO pagará a EL/LA TRABAJADOR/A la suma de <strong>{{salario_letras}}</strong> (<strong>{{salario}}</strong>) como salario mensual, pagadero en dos cuotas quincenales iguales por transferencia bancaria o depósito a la cuenta indicada por EL/LA TRABAJADOR/A. Dicho salario incluye la compensación por todos los servicios ordinarios prestados.</p>

<h3>f) Herramientas y Equipo</h3>
<p>EL PATRONO proporcionará a EL/LA TRABAJADOR/A los implementos, uniformes y herramientas necesarios para el desempeño de sus funciones. EL/LA TRABAJADOR/A se obliga a cuidarlos, mantenerlos en buen estado y devolverlos al término de la relación laboral. El deterioro por uso normal no generará responsabilidad; sin embargo, el daño por negligencia o uso indebido podrá ser deducido de sus prestaciones conforme a la ley.</p>

<h3>g) Dependientes Económicos</h3>
<p>EL/LA TRABAJADOR/A declara bajo fe de juramento que sus dependientes económicos son los siguientes (en caso de no tener dependientes, dejar en blanco):</p>

<table>
  <tr>
    <th>Nombre</th>
    <th>Parentesco</th>
    <th>Edad</th>
  </tr>
  <tr>
    <td>&nbsp;</td>
    <td>&nbsp;</td>
    <td>&nbsp;</td>
  </tr>
  <tr>
    <td>&nbsp;</td>
    <td>&nbsp;</td>
    <td>&nbsp;</td>
  </tr>
</table>

<h3>h) Otras Estipulaciones</h3>
<p><strong>Confidencialidad:</strong> EL/LA TRABAJADOR/A se compromete a guardar la más estricta reserva sobre recetas, procesos de producción, estrategias comerciales, información financiera y cualquier dato sensible de LA EMPRESA y sus clientes, tanto durante la vigencia del contrato como después de su terminación.</p>
<p><strong>Exclusividad:</strong> Durante la vigencia del presente contrato, EL/LA TRABAJADOR/A no podrá prestar servicios a empresas que compitan directamente con Cadejo Brewing Company sin autorización expresa y escrita de EL PATRONO.</p>
<p><strong>Normas Internas:</strong> EL/LA TRABAJADOR/A se obliga a cumplir el Reglamento Interno de Trabajo, los procedimientos operativos y las políticas de la empresa, cuyo conocimiento declara haber recibido.</p>

<h3>i) Causas de Terminación por Faltas Graves</h3>
<p>Además de las causales previstas en el Código de Trabajo, constituirán causas justas de despido: el incumplimiento reiterado de los estándares de calidad establecidos; la comisión de actos que afecten la imagen, el patrimonio o la seguridad alimentaria de LA EMPRESA; el incumplimiento de los protocolos de higiene y manipulación de alimentos; y cualquier acto de deshonestidad comprobado.</p>

<hr>

<p>En fe de lo cual suscribimos el presente contrato en dos ejemplares de igual tenor y validez, en la ciudad de <strong>{{ciudad_firma}}</strong>, a los <strong>{{fecha_firma_letras}}</strong>.</p>

<br>
<table style="width:100%; margin-top:30px;">
  <tr>
    <td style="width:50%; text-align:center; padding:10px;">
      <div style="border-top:1px solid #000; width:80%; margin:0 auto; padding-top:6px;">
        <strong>{{patrono}}</strong><br>
        <span style="font-size:9pt;">El Patrono</span>
      </div>
    </td>
    <td style="width:50%; text-align:center; padding:10px;">
      <div style="border-top:1px solid #000; width:80%; margin:0 auto; padding-top:6px;">
        <strong>{{nombre}}</strong><br>
        <span style="font-size:9pt;">El/La Trabajador/a — DUI: {{dui}}</span>
      </div>
    </td>
  </tr>
</table>
HTML;
    }

    private function contratoServiciosProfesionales(): string
    {
        return <<<'HTML'
<h2>CONTRATO DE PRESTACIÓN DE SERVICIOS PROFESIONALES</h2>

<p>Conste por el presente instrumento el <strong>CONTRATO DE PRESTACIÓN DE SERVICIOS PROFESIONALES</strong> que celebran, por una parte, <strong>{{patrono}}</strong>, NIT <strong>{{nit_patrono}}</strong>, en calidad de representante de <strong>Cadejo Brewing Company</strong> (en adelante "LA EMPRESA"); y por la otra, <strong>{{nombre}}</strong>, portador/a del DUI número <strong>{{dui}}</strong>, (en adelante "EL/LA PRESTADOR/A"), quienes acuerdan las cláusulas siguientes:</p>

<h3>CLÁUSULA I — Objeto</h3>
<p>EL/LA PRESTADOR/A se obliga a brindar servicios profesionales en el área de <strong>{{cargo}}</strong> para <strong>{{sucursal}}</strong>, ejecutando las actividades que le sean encomendadas dentro del marco del presente contrato y conforme a los estándares de calidad de LA EMPRESA.</p>

<h3>CLÁUSULA II — Duración</h3>
<p>El presente contrato tendrá una vigencia de <strong>treinta (30) días calendario</strong>, a partir del día <strong>{{fecha_inicio_letras}}</strong>{{fecha_fin ? ', y hasta el ' : '.'}}<strong>{{fecha_fin}}</strong>. Al vencimiento del plazo, el contrato podrá renovarse por mutuo acuerdo de las partes mediante suscripción de un nuevo instrumento.</p>

<h3>CLÁUSULA III — Honorarios</h3>
<p>LA EMPRESA pagará a EL/LA PRESTADOR/A la suma de <strong>{{salario_letras}}</strong> (<strong>{{salario}}</strong>) mensuales como honorarios por los servicios contratados, sujeto a la retención del <strong>10% del Impuesto sobre la Renta</strong> en cumplimiento de la legislación fiscal vigente. Los honorarios serán pagados en dos cuotas quincenales.</p>

<h3>CLÁUSULA IV — Horario y Lugar</h3>
<p>EL/LA PRESTADOR/A deberá cumplir una jornada de <strong>cuarenta y cuatro (44) horas semanales</strong> distribuidas según el cuadrante de turnos asignado por LA EMPRESA. Los servicios se prestarán en las instalaciones de <strong>{{sucursal}}</strong>, con posibilidad de rotar a otras sucursales según las necesidades operativas.</p>

<h3>CLÁUSULA V — Beneficios</h3>
<p>Durante la vigencia del presente contrato, EL/LA PRESTADOR/A gozará de los siguientes beneficios:</p>
<ul>
  <li>Descuento del <strong>40%</strong> en consumo personal en cualquier sucursal de Cadejo Brewing Company.</li>
  <li>Descuento del <strong>25%</strong> para acompañantes (máximo 2 personas por visita).</li>
  <li>Descuento del <strong>50%</strong> en alimentos del menú de colaboradores.</li>
</ul>

<h3>CLÁUSULA VI — Obligaciones del Prestador/a</h3>
<p>EL/LA PRESTADOR/A se obliga a: (a) prestar los servicios con diligencia, puntualidad y profesionalismo; (b) cumplir los protocolos de higiene, seguridad alimentaria y presentación personal establecidos por LA EMPRESA; (c) guardar confidencialidad sobre recetas, procesos y estrategias de negocio; (d) acatar el Reglamento Interno de Trabajo en lo aplicable; y (e) no ejercer actividades que generen conflicto de interés con LA EMPRESA.</p>

<h3>CLÁUSULA VII — Terminación Anticipada</h3>
<p>Cualquiera de las partes podrá dar por terminado el presente contrato antes de su vencimiento, dando aviso por escrito con al menos <strong>quince (15) días calendario</strong> de anticipación. LA EMPRESA podrá prescindir de los servicios sin previo aviso en caso de incumplimiento grave de las obligaciones pactadas, sin que ello genere derecho a indemnización alguna.</p>

<hr>

<p>En señal de conformidad con todo lo estipulado, ambas partes suscriben el presente contrato en la ciudad de <strong>{{ciudad_firma}}</strong>, a los <strong>{{fecha_firma_letras}}</strong>.</p>

<br>
<table style="width:100%; margin-top:30px;">
  <tr>
    <td style="width:50%; text-align:center; padding:10px;">
      <div style="border-top:1px solid #000; width:80%; margin:0 auto; padding-top:6px;">
        <strong>{{patrono}}</strong><br>
        <span style="font-size:9pt;">La Empresa</span>
      </div>
    </td>
    <td style="width:50%; text-align:center; padding:10px;">
      <div style="border-top:1px solid #000; width:80%; margin:0 auto; padding-top:6px;">
        <strong>{{nombre}}</strong><br>
        <span style="font-size:9pt;">El/La Prestador/a — DUI: {{dui}}</span>
      </div>
    </td>
  </tr>
</table>
HTML;
    }
}
