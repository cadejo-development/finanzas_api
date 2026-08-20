<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10px; color: #111; background: #fff; }
.page { padding: 28px 32px; max-width: 700px; margin: 0 auto; }

/* ── HEADER ── */
.header-tbl { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
.header-tbl td { vertical-align: middle; padding: 0; }
.company-block .name { font-size: 14px; font-weight: bold; color: #111; }
.company-block .sub  { font-size: 8px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 2px; }
.boleta-block { text-align: right; }
.boleta-block .title { font-size: 13px; font-weight: bold; color: #111; text-transform: uppercase; letter-spacing: 1px; }
.boleta-block .quin  { font-size: 9.5px; color: #333; margin-top: 3px; }
.boleta-block .dates { font-size: 9px; color: #555; margin-top: 1px; }
.boleta-block .badge { display: inline-block; font-size: 7.5px; font-weight: bold; text-transform: uppercase;
  padding: 1px 7px; border: 1px solid #999; border-radius: 2px; margin-top: 4px; color: #555; }
.logo-cell { width: 56px; text-align: center; padding-left: 10px; }
.logo-cell img { width: 50px; height: 50px; }
hr.divider { border: none; border-top: 1.5px solid #1a3a5c; margin: 8px 0 12px 0; }

/* ── INFO EMPLEADO ── */
.info-tbl { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
.info-tbl td { padding: 2.5px 6px 2.5px 0; vertical-align: top; font-size: 9.5px; }
.info-tbl td.sep { width: 1px; border-left: 1px solid #ddd; padding: 2px 10px; }
.info-key { font-weight: bold; width: 100px; color: #222; }
.info-val { color: #111; }

/* ── TABLA INGRESOS / DESCUENTOS ── */
.calc-outer { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
.calc-outer > tbody > tr > td { vertical-align: top; padding: 0; width: 50%; }
.calc-outer > tbody > tr > td:first-child { padding-right: 10px; }

.calc-tbl { width: 100%; border-collapse: collapse; border: 1px solid #888; }
.calc-tbl th { font-size: 9.5px; font-weight: bold; text-decoration: underline;
  padding: 4px 8px; text-align: left; border-bottom: 1px solid #888; background: #fff; }
.calc-tbl th.r { text-align: right; }
.calc-tbl td { font-size: 9.5px; padding: 3.5px 8px; border-bottom: 1px solid #e8e8e8; }
.calc-tbl td.r { text-align: right; }
.calc-tbl tr.total td { font-weight: bold; border-top: 1px solid #888; border-bottom: none; background: #f5f5f5; padding: 4px 8px; }

/* ── NETO ── */
.neto-line { font-size: 12px; font-weight: bold; margin-bottom: 22px; }
.neto-line span.lbl { color: #333; }
.neto-line span.val { color: #111; }

/* ── FIRMAS ── */
.firma-tbl { width: 100%; border-collapse: collapse; margin-top: 6px; }
.firma-tbl td { width: 50%; text-align: center; vertical-align: top; padding: 0 30px; }
.firma-line { border-top: 1px solid #666; padding-top: 5px; margin-top: 28px; }
.firma-name { font-size: 9px; font-weight: bold; }
.firma-sub  { font-size: 8px; color: #555; margin-top: 1px; }
.firma-doc  { font-size: 8.5px; color: #222; margin-top: 3px; }

/* ── NOTA ── */
.nota { margin-top: 14px; font-size: 7.5px; color: #aaa; text-align: center;
  border-top: 1px solid #e8e8e8; padding-top: 6px; }
</style>
</head>
<body>
<div class="page">

{{-- ══ HEADER ══ --}}
<table class="header-tbl">
  <tr>
    <td class="company-block">
      <div class="name">CADEJO BREWING COMPANY, S.A. DE C.V.</div>
      <div class="sub">Comprobante de Pago de Salario</div>
    </td>
    <td class="boleta-block">
      <div class="title">Boleta de Pago</div>
      <div class="quin">
        Quincena {{ $planilla->quincena }}
        &nbsp;·&nbsp;
        {{ ucfirst(\Carbon\Carbon::create($planilla->anio, $planilla->mes)->locale('es')->monthName) }}
        {{ $planilla->anio }}
      </div>
      <div class="dates">
        {{ \Carbon\Carbon::parse($planilla->fecha_inicio)->format('d/m/Y') }}
        al
        {{ \Carbon\Carbon::parse($planilla->fecha_fin)->format('d/m/Y') }}
      </div>
      <span class="badge">{{ ucfirst($planilla->estado) }}</span>
    </td>
    @if($logoB64)
    <td class="logo-cell"><img src="{{ $logoB64 }}" alt="Logo" /></td>
    @endif
  </tr>
</table>
<hr class="divider">

{{-- ══ DATOS EMPLEADO ══ --}}
<table class="info-tbl">
  <tr>
    <td class="info-key">Código</td>
    <td class="info-val">{{ $empleado->codigo }}</td>
    <td class="sep"></td>
    <td class="info-key">Salario Base</td>
    <td class="info-val">${{ number_format($linea->salario_base, 2) }}</td>
  </tr>
  <tr>
    <td class="info-key">Nombres</td>
    <td class="info-val">{{ $empleado->nombres }}</td>
    <td class="sep"></td>
    <td class="info-key">Días Laborados</td>
    <td class="info-val">{{ number_format($linea->dias_laborados, 1) }}</td>
  </tr>
  <tr>
    <td class="info-key">Apellidos</td>
    <td class="info-val">{{ $empleado->apellidos }}</td>
    <td class="sep"></td>
    <td class="info-key">Horas Diurnas</td>
    <td class="info-val">{{ number_format($linea->dias_laborados * 8, 1) }}</td>
  </tr>
  <tr>
    <td class="info-key">Cargo</td>
    <td class="info-val">{{ $empleado->cargo?->nombre ?? '—' }}</td>
    <td class="sep"></td>
    <td class="info-key">AFP</td>
    <td class="info-val">{{ $afpNombre ?? '—' }}</td>
  </tr>
  <tr>
    <td class="info-key">Desde</td>
    <td class="info-val">{{ \Carbon\Carbon::parse($planilla->fecha_inicio)->format('d/m/Y') }}</td>
    <td class="sep"></td>
    <td class="info-key">Sucursal / Depto.</td>
    <td class="info-val">
      @if($empleado->departamento?->nombre && str_contains(strtoupper($empleado->sucursal?->nombre ?? ''), 'CASA MATRIZ'))
        {{ $empleado->departamento->nombre }}
      @else
        {{ $empleado->sucursal?->nombre ?? $empleado->departamento?->nombre ?? '—' }}
      @endif
    </td>
  </tr>
  <tr>
    <td class="info-key">Hasta</td>
    <td class="info-val">{{ \Carbon\Carbon::parse($planilla->fecha_fin)->format('d/m/Y') }}</td>
    <td class="sep"></td>
    <td></td>
    <td></td>
  </tr>
</table>

{{-- ══ INGRESOS Y DESCUENTOS LADO A LADO ══ --}}
<table class="calc-outer">
  <tr>
    {{-- INGRESOS --}}
    <td>
      <table class="calc-tbl">
        <thead>
          <tr>
            <th>Ingresos</th>
            <th class="r">Monto</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>Salario a pagar</td>
            <td class="r">${{ number_format($linea->salario_proporcional, 2) }}</td>
          </tr>
          @if($linea->dias_laborados < $linea->dias_quincena)
          <tr>
            <td style="color:#888;font-size:8.5px">Ajuste proporcional ({{ number_format($linea->dias_laborados,1) }}/{{ $linea->dias_quincena }} días)</td>
            <td class="r" style="color:#888;font-size:8.5px">${{ number_format($linea->salario_proporcional, 2) }}</td>
          </tr>
          @endif
          @php
            $salAsueto       = (float)($linea->salario_asueto ?? 0);
            $montoHorasExtras = (float)($linea->monto_horas_extras ?? 0);
          @endphp
          @if($salAsueto > 0)
          <tr>
            <td>Pago de asueto ({{ number_format((float)($linea->dias_asueto ?? 0), 0) }} día(s))</td>
            <td class="r">${{ number_format($salAsueto, 2) }}</td>
          </tr>
          @endif
          @if($montoHorasExtras > 0)
          <tr>
            <td>Horas extras ({{ number_format((float)($linea->horas_extras ?? 0), 1) }} h)</td>
            <td class="r">${{ number_format($montoHorasExtras, 2) }}</td>
          </tr>
          @endif
          <tr class="total">
            <td>Total Ingresos:</td>
            <td class="r">${{ number_format($linea->salario_proporcional + $salAsueto + $montoHorasExtras, 2) }}</td>
          </tr>
        </tbody>
      </table>
    </td>

    {{-- DESCUENTOS --}}
    <td>
      <table class="calc-tbl">
        <thead>
          <tr>
            <th>Descuentos</th>
            <th class="r">Monto</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>I.S.S.S.</td>
            <td class="r">${{ number_format($linea->isss_empleado, 2) }}</td>
          </tr>
          <tr>
            <td>Fondo de Pensión</td>
            <td class="r">${{ number_format($linea->afp_empleado, 2) }}</td>
          </tr>
          <tr>
            <td>Renta (ISR)</td>
            <td class="r">${{ number_format($linea->renta, 2) }}</td>
          </tr>
          @if($linea->otros_descuentos > 0)
            @foreach(($linea->detalle_descuentos ?? []) as $desc)
            <tr>
              <td>
                {{ $desc['concepto'] ?? 'Otros descuentos' }}
                @if(!empty($desc['acreedor'])) <span style="color:#888"> / {{ $desc['acreedor'] }}</span> @endif
              </td>
              <td class="r">${{ number_format($desc['monto_quincenal'] ?? 0, 2) }}</td>
            </tr>
            @endforeach
          @endif
          <tr class="total">
            <td>Total Descuentos:</td>
            <td class="r">${{ number_format($linea->total_descuentos_empleado, 2) }}</td>
          </tr>
        </tbody>
      </table>
    </td>
  </tr>
</table>

{{-- ══ NETO ══ --}}
<div class="neto-line">
  <span class="lbl">Sueldo Líquido: &nbsp;</span>
  <span class="val">${{ number_format($linea->salario_neto, 2) }}</span>
</div>

{{-- ══ FIRMAS ══ --}}
<table class="firma-tbl">
  <tr>
    <td>
      <div class="firma-line">
        <div class="firma-name">{{ strtoupper($empleado->nombres) }} {{ strtoupper($empleado->apellidos) }}</div>
        <div class="firma-sub">Firma del Empleado</div>
        @if($duiNumero)
        <div class="firma-doc">DUI: {{ $duiNumero }}</div>
        @endif
      </div>
    </td>
    <td>
      <div class="firma-line">
        <div class="firma-name">Recursos Humanos</div>
        <div class="firma-sub">Cadejo Brewing Company</div>
      </div>
    </td>
  </tr>
</table>

<div class="nota">Generado el {{ now()->format('d/m/Y H:i') }} · Código {{ $empleado->codigo }}</div>

</div>
</body>
</html>
