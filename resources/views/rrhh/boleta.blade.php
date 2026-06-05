<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10px; color: #1a1a1a; background: #fff; }
.page { padding: 28px 30px; max-width: 720px; margin: 0 auto; }

/* ── HEADER (tabla para DomPDF) ── */
.header-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; padding-bottom: 12px; border-bottom: 2px solid #1a3a5c; }
.header-logo  { width: 52px; vertical-align: middle; padding-right: 12px; }
.header-logo img { width: 52px; height: 52px; }
.header-company { vertical-align: middle; }
.company-name { font-size: 15px; font-weight: bold; color: #1a3a5c; }
.company-sub  { font-size: 8.5px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 2px; }
.header-right { vertical-align: middle; text-align: right; }
.boleta-label { font-size: 13px; font-weight: bold; color: #1a3a5c; text-transform: uppercase; letter-spacing: 1px; }
.periodo-q    { font-size: 10px; color: #333; margin-top: 4px; }
.periodo-d    { font-size: 9px;  color: #666; margin-top: 2px; }
.estado-badge { font-size: 7.5px; font-weight: bold; text-transform: uppercase; padding: 2px 8px; border-radius: 8px; background: #dff0d8; color: #2d6a2d; margin-top: 5px; display: inline-block; }
.estado-borrador { background: #fff3cd; color: #856404; }

/* ── INFO EMPLEADO ── */
.emp-box { background: #f7f9fc; border: 1px solid #d0d9ea; border-radius: 5px; padding: 10px 14px; margin-bottom: 14px; }
.emp-table { width: 100%; border-collapse: collapse; }
.emp-table td { padding: 3px 8px 3px 0; vertical-align: top; }
.emp-table td:nth-child(even) { padding-left: 14px; border-left: 1px solid #e0e8f2; }
.emp-label { font-size: 7.5px; color: #888; text-transform: uppercase; letter-spacing: 0.4px; display: block; margin-bottom: 1px; }
.emp-value { font-size: 10.5px; font-weight: bold; color: #111; }
.emp-divider { border-top: 1px solid #e0e8f2; margin: 7px 0; }

/* ── SECCIÓN (título) ── */
.sec-titulo {
  background: #1a3a5c; color: #fff;
  font-size: 8px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.6px;
  padding: 4px 10px; margin-bottom: 0;
}
.sec-titulo-red { background: #7f1d1d; }

/* ── TABLA LADO A LADO ── */
.split-wrap { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
.split-wrap td { vertical-align: top; width: 50%; }
.split-wrap td:first-child { padding-right: 6px; }
.split-wrap td:last-child  { padding-left: 6px; }

/* ── TABLA INGRESOS / DESCUENTOS ── */
.calc-table { width: 100%; border-collapse: collapse; border: 1px solid #d0d9ea; }
.calc-table td { padding: 5px 10px; font-size: 10px; border-bottom: 1px solid #eef1f8; }
.calc-table td:last-child { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.calc-table tr:last-child td { border-bottom: none; }
.calc-table tr.alt  { background: #fafbfd; }
.calc-table tr.sub  { background: #eef2fa; font-weight: bold; border-top: 1px solid #c0cfe8; }
.calc-table tr.sub td:last-child { color: #1a3a5c; }
.calc-table tr.neg td:last-child { color: #b02020; }
.calc-table tr.sub-neg td { font-weight: bold; background: #fef2f2; border-top: 1px solid #f0c0c0; }
.calc-table tr.sub-neg td:last-child { color: #b02020; }
.muted { color: #888; font-size: 9px; }

/* ── NETO ── */
.neto-box { background: #1a3a5c; color: #fff; border-radius: 5px; padding: 11px 16px; margin-bottom: 22px; }
.neto-table { width: 100%; border-collapse: collapse; }
.neto-label { font-size: 10.5px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; opacity: 0.9; vertical-align: middle; }
.neto-amount { font-size: 22px; font-weight: bold; text-align: right; vertical-align: middle; }

/* ── FIRMAS ── */
.firma-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
.firma-table td { width: 50%; text-align: center; vertical-align: top; padding: 0 20px; }
.firma-line { border-top: 1px solid #aaa; padding-top: 6px; margin-top: 30px; }
.firma-name { font-size: 9px; font-weight: bold; color: #111; }
.firma-sub  { font-size: 8px; color: #777; margin-top: 2px; }
.dui-line   { font-size: 8.5px; color: #444; margin-top: 3px; }

/* ── NOTA PIE ── */
.nota { margin-top: 16px; font-size: 7.5px; color: #bbb; text-align: center; border-top: 1px solid #eee; padding-top: 7px; }
</style>
</head>
<body>
<div class="page">

{{-- ══ HEADER ══ --}}
<table class="header-table">
  <tr>
    @if($logoB64)
    <td class="header-logo"><img src="{{ $logoB64 }}" alt="Logo" /></td>
    @endif
    <td class="header-company">
      <div class="company-name">Cadejo Brewing Company</div>
      <div class="company-sub">Comprobante de Pago de Salario</div>
    </td>
    <td class="header-right">
      <div class="boleta-label">Boleta de Pago</div>
      <div class="periodo-q">
        Quincena {{ $planilla->quincena }} &nbsp;·&nbsp;
        {{ ucfirst(\Carbon\Carbon::create($planilla->anio, $planilla->mes)->locale('es')->monthName) }}
        {{ $planilla->anio }}
      </div>
      <div class="periodo-d">
        {{ \Carbon\Carbon::parse($planilla->fecha_inicio)->format('d/m/Y') }}
        &nbsp;–&nbsp;
        {{ \Carbon\Carbon::parse($planilla->fecha_fin)->format('d/m/Y') }}
      </div>
      <span class="estado-badge {{ $planilla->estado !== 'aprobada' ? 'estado-borrador' : '' }}">
        {{ ucfirst($planilla->estado) }}
      </span>
    </td>
  </tr>
</table>

{{-- ══ DATOS EMPLEADO ══ --}}
<div class="emp-box">
  <table class="emp-table">
    <tr>
      <td style="width:50%">
        <span class="emp-label">Empleado</span>
        <span class="emp-value">{{ strtoupper($empleado->apellidos) }}, {{ $empleado->nombres }}</span>
      </td>
      <td style="width:25%">
        <span class="emp-label">Código</span>
        <span class="emp-value">{{ $empleado->codigo }}</span>
      </td>
      <td style="width:25%">
        <span class="emp-label">Salario mensual</span>
        <span class="emp-value">${{ number_format($linea->salario_base, 2) }}</span>
      </td>
    </tr>
    <tr><td colspan="3"><div class="emp-divider"></div></td></tr>
    <tr>
      <td>
        <span class="emp-label">Cargo</span>
        <span class="emp-value">{{ $empleado->cargo?->nombre ?? '—' }}</span>
      </td>
      <td>
        <span class="emp-label">
          @if($empleado->departamento?->nombre && str_contains(strtoupper($empleado->sucursal?->nombre ?? ''), 'CASA MATRIZ'))
            Departamento
          @else
            Sucursal
          @endif
        </span>
        <span class="emp-value">
          @if($empleado->departamento?->nombre && str_contains(strtoupper($empleado->sucursal?->nombre ?? ''), 'CASA MATRIZ'))
            {{ $empleado->departamento->nombre }}
          @else
            {{ $empleado->sucursal?->nombre ?? $empleado->departamento?->nombre ?? '—' }}
          @endif
        </span>
      </td>
      <td>
        <span class="emp-label">Días laborados</span>
        <span class="emp-value">{{ number_format($linea->dias_laborados, 1) }} / {{ $linea->dias_quincena }}</span>
      </td>
    </tr>
  </table>
</div>

{{-- ══ INGRESOS Y DEDUCCIONES LADO A LADO ══ --}}
<table class="split-wrap">
  <tr>
    {{-- INGRESOS --}}
    <td>
      <div class="sec-titulo">Ingresos</div>
      <table class="calc-table">
        <tr>
          <td>Salario quincenal</td>
          <td>${{ number_format($linea->salario_base / 2, 2) }}</td>
        </tr>
        @if($linea->dias_laborados < $linea->dias_quincena)
        <tr class="alt">
          <td><span class="muted">Ajuste proporcional ({{ number_format($linea->dias_laborados,1) }}/{{ $linea->dias_quincena }} días)</span></td>
          <td class="muted">${{ number_format($linea->salario_proporcional, 2) }}</td>
        </tr>
        @endif
        <tr class="sub">
          <td>Total devengado</td>
          <td>${{ number_format($linea->salario_proporcional, 2) }}</td>
        </tr>
      </table>
    </td>

    {{-- DEDUCCIONES --}}
    <td>
      <div class="sec-titulo sec-titulo-red">Descuentos</div>
      <table class="calc-table">
        <tr class="neg">
          <td>AFP <span class="muted">(7.25%)</span></td>
          <td>– ${{ number_format($linea->afp_empleado, 2) }}</td>
        </tr>
        <tr class="neg alt">
          <td>ISSS <span class="muted">(3.00%)</span></td>
          <td>– ${{ number_format($linea->isss_empleado, 2) }}</td>
        </tr>
        <tr class="neg">
          <td>Renta (ISR)</td>
          <td>– ${{ number_format($linea->renta, 2) }}</td>
        </tr>
        @if($linea->otros_descuentos > 0)
          @foreach(($linea->detalle_descuentos ?? []) as $desc)
          <tr class="neg alt">
            <td>
              {{ $desc['concepto'] ?? 'Otros' }}
              @if(!empty($desc['acreedor'])) <span class="muted">— {{ $desc['acreedor'] }}</span> @endif
            </td>
            <td>– ${{ number_format($desc['monto_quincenal'] ?? 0, 2) }}</td>
          </tr>
          @endforeach
        @endif
        <tr class="sub-neg">
          <td>Total descuentos</td>
          <td>– ${{ number_format($linea->total_descuentos_empleado, 2) }}</td>
        </tr>
      </table>
    </td>
  </tr>
</table>

{{-- ══ NETO ══ --}}
<div class="neto-box">
  <table class="neto-table">
    <tr>
      <td class="neto-label">Salario neto a recibir</td>
      <td class="neto-amount">${{ number_format($linea->salario_neto, 2) }}</td>
    </tr>
  </table>
</div>

{{-- ══ FIRMAS ══ --}}
<table class="firma-table">
  <tr>
    <td>
      <div class="firma-line">
        <div class="firma-name">{{ strtoupper($empleado->apellidos) }}, {{ $empleado->nombres }}</div>
        <div class="firma-sub">Firma del Empleado</div>
        @if($duiNumero)
        <div class="dui-line">DUI: {{ $duiNumero }}</div>
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

<div class="nota">Documento generado el {{ now()->format('d/m/Y H:i') }} — Cadejo Brewing Company &nbsp;·&nbsp; Código {{ $empleado->codigo }}</div>

</div>
</body>
</html>
