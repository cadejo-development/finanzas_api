<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10.5px; color: #1a1a1a; background: #fff; }

.page { padding: 30px 32px; max-width: 720px; margin: 0 auto; }

/* ── Header ── */
.header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding-bottom: 16px;
  margin-bottom: 18px;
  border-bottom: 2px solid #1a3a5c;
}
.header-left { display: flex; align-items: center; gap: 14px; }
.header-logo { width: 54px; height: 54px; }
.company-name { font-size: 16px; font-weight: bold; color: #1a3a5c; line-height: 1.2; }
.company-sub  { font-size: 9px; color: #777; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 3px; }
.header-right { text-align: right; }
.boleta-label { font-size: 13px; font-weight: bold; color: #1a3a5c; text-transform: uppercase; letter-spacing: 1px; }
.periodo-q    { font-size: 10px; color: #444; margin-top: 4px; }
.periodo-d    { font-size: 9.5px; color: #666; margin-top: 2px; }
.estado-badge {
  display: inline-block; margin-top: 5px;
  font-size: 8px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px;
  padding: 2px 9px; border-radius: 10px;
  background: #dff0d8; color: #2d6a2d;
}
@if($planilla->estado !== 'aprobada')
.estado-badge { background: #fff3cd; color: #856404; }
@endif

/* ── Info empleado ── */
.emp-box {
  background: #f7f9fc;
  border: 1px solid #d8e2ef;
  border-radius: 6px;
  padding: 12px 16px;
  margin-bottom: 18px;
}
.emp-row { display: flex; gap: 0; }
.emp-cell { flex: 1; padding: 4px 8px 4px 0; }
.emp-cell:first-child { padding-left: 0; }
.emp-label { font-size: 7.5px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 2px; }
.emp-value { font-size: 10.5px; font-weight: bold; color: #1a1a1a; }
.emp-divider { border-top: 1px solid #e2e8f2; margin: 6px 0; }

/* ── Secciones ── */
.section { margin-bottom: 14px; }
.section-head {
  background: #1a3a5c;
  color: #fff;
  font-size: 8.5px;
  font-weight: bold;
  text-transform: uppercase;
  letter-spacing: 0.6px;
  padding: 5px 12px;
  border-radius: 4px 4px 0 0;
}
.section-body {
  border: 1px solid #d8e2ef;
  border-top: none;
  border-radius: 0 0 4px 4px;
  overflow: hidden;
}
.row-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 6px 12px;
  border-bottom: 1px solid #f0f3f8;
}
.row-item:last-child { border-bottom: none; }
.row-item.alt { background: #fafbfd; }
.row-item.subtotal {
  background: #eef2fa;
  border-top: 1px solid #c8d6ea;
  font-weight: bold;
  font-size: 10.5px;
}
.row-label { color: #333; }
.row-label-muted { color: #888; font-size: 9.5px; }
.row-val { font-variant-numeric: tabular-nums; }
.red { color: #b02020; }
.bold { font-weight: bold; }

/* ── Neto ── */
.neto-box {
  background: #1a3a5c;
  color: #fff;
  border-radius: 6px;
  padding: 13px 18px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 28px;
}
.neto-label { font-size: 11px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; opacity: 0.9; }
.neto-amount { font-size: 24px; font-weight: bold; }

/* ── Firmas ── */
.footer {
  display: flex;
  justify-content: space-between;
  gap: 40px;
  margin-top: 10px;
  padding-top: 10px;
}
.firma-block { flex: 1; text-align: center; }
.firma-line { border-top: 1px solid #aaa; padding-top: 6px; margin-top: 32px; }
.firma-name { font-size: 9px; font-weight: bold; color: #1a1a1a; }
.firma-sub  { font-size: 8.5px; color: #777; margin-top: 1px; }

/* ── Nota pie ── */
.nota { margin-top: 18px; font-size: 8px; color: #aaa; text-align: center; border-top: 1px solid #eee; padding-top: 8px; }
</style>
</head>
<body>
<div class="page">

  {{-- ══ HEADER ══ --}}
  <div class="header">
    <div class="header-left">
      @if($logoB64)
        <img src="{{ $logoB64 }}" class="header-logo" alt="Logo Cadejo" />
      @endif
      <div>
        <div class="company-name">Cadejo Brewing Company</div>
        <div class="company-sub">Comprobante de Pago de Salario</div>
      </div>
    </div>
    <div class="header-right">
      <div class="boleta-label">Boleta de Pago</div>
      <div class="periodo-q">
        Quincena {{ $planilla->quincena }} &nbsp;·&nbsp;
        {{ ucfirst(\Carbon\Carbon::create($planilla->anio, $planilla->mes)->locale('es')->monthName) }}
        {{ $planilla->anio }}
      </div>
      <div class="periodo-d">
        {{ \Carbon\Carbon::parse($planilla->fecha_inicio)->format('d/m/Y') }}
        &nbsp;—&nbsp;
        {{ \Carbon\Carbon::parse($planilla->fecha_fin)->format('d/m/Y') }}
      </div>
      <span class="estado-badge">{{ ucfirst($planilla->estado) }}</span>
    </div>
  </div>

  {{-- ══ DATOS EMPLEADO ══ --}}
  <div class="emp-box">
    <div class="emp-row">
      <div class="emp-cell" style="flex:2">
        <div class="emp-label">Empleado</div>
        <div class="emp-value">{{ strtoupper($empleado->apellidos) }}, {{ $empleado->nombres }}</div>
      </div>
      <div class="emp-cell">
        <div class="emp-label">Código</div>
        <div class="emp-value">{{ $empleado->codigo }}</div>
      </div>
      <div class="emp-cell">
        <div class="emp-label">Días laborados</div>
        <div class="emp-value">{{ number_format($linea->dias_laborados, 1) }} / {{ $linea->dias_quincena }}</div>
      </div>
    </div>
    <div class="emp-divider"></div>
    <div class="emp-row">
      <div class="emp-cell" style="flex:2">
        <div class="emp-label">Cargo</div>
        <div class="emp-value">{{ $empleado->cargo?->nombre ?? '—' }}</div>
      </div>
      <div class="emp-cell" style="flex:2">
        <div class="emp-label">Sucursal / Departamento</div>
        <div class="emp-value">
          @if($empleado->departamento?->nombre && str_contains(strtoupper($empleado->sucursal?->nombre ?? ''), 'CASA MATRIZ'))
            {{ $empleado->departamento->nombre }}
          @else
            {{ $empleado->sucursal?->nombre ?? $empleado->departamento?->nombre ?? '—' }}
          @endif
        </div>
      </div>
      <div class="emp-cell">
        <div class="emp-label">Salario mensual</div>
        <div class="emp-value">${{ number_format($linea->salario_base, 2) }}</div>
      </div>
    </div>
  </div>

  {{-- ══ INGRESOS ══ --}}
  <div class="section">
    <div class="section-head">Ingresos</div>
    <div class="section-body">
      <div class="row-item">
        <span class="row-label">Salario base quincenal</span>
        <span class="row-val bold">${{ number_format($linea->salario_base / 2, 2) }}</span>
      </div>
      @if($linea->dias_laborados < $linea->dias_quincena)
      <div class="row-item alt">
        <span class="row-label-muted">
          Ajuste proporcional ({{ number_format($linea->dias_laborados,1) }} / {{ $linea->dias_quincena }} días)
        </span>
        <span class="row-val" style="color:#888">${{ number_format($linea->salario_proporcional, 2) }}</span>
      </div>
      @endif
      <div class="row-item subtotal">
        <span>Total devengado</span>
        <span class="row-val">${{ number_format($linea->salario_proporcional, 2) }}</span>
      </div>
    </div>
  </div>

  {{-- ══ DEDUCCIONES ══ --}}
  <div class="section">
    <div class="section-head">Deducciones</div>
    <div class="section-body">
      <div class="row-item">
        <span class="row-label">AFP &nbsp;<span style="color:#aaa;font-size:9px">(7.25%)</span></span>
        <span class="row-val red">– ${{ number_format($linea->afp_empleado, 2) }}</span>
      </div>
      <div class="row-item alt">
        <span class="row-label">ISSS &nbsp;<span style="color:#aaa;font-size:9px">(3.00%)</span></span>
        <span class="row-val red">– ${{ number_format($linea->isss_empleado, 2) }}</span>
      </div>
      <div class="row-item">
        <span class="row-label">Renta (ISR)</span>
        <span class="row-val red">– ${{ number_format($linea->renta, 2) }}</span>
      </div>
      @if($linea->otros_descuentos > 0)
        @foreach(($linea->detalle_descuentos ?? []) as $desc)
        <div class="row-item alt">
          <span class="row-label">
            {{ $desc['concepto'] ?? 'Otros descuentos' }}
            @if(!empty($desc['acreedor'])) &nbsp;—&nbsp; <span style="color:#888">{{ $desc['acreedor'] }}</span> @endif
          </span>
          <span class="row-val red">– ${{ number_format($desc['monto_quincenal'] ?? 0, 2) }}</span>
        </div>
        @endforeach
      @endif
      <div class="row-item subtotal">
        <span>Total deducciones</span>
        <span class="row-val red">– ${{ number_format($linea->total_descuentos_empleado, 2) }}</span>
      </div>
    </div>
  </div>

  {{-- ══ NETO ══ --}}
  <div class="neto-box">
    <span class="neto-label">Salario neto a recibir</span>
    <span class="neto-amount">${{ number_format($linea->salario_neto, 2) }}</span>
  </div>

  {{-- ══ FIRMAS ══ --}}
  <div class="footer">
    <div class="firma-block">
      <div class="firma-line">
        <div class="firma-name">{{ strtoupper($empleado->apellidos) }}, {{ $empleado->nombres }}</div>
        <div class="firma-sub">Firma del Empleado</div>
      </div>
    </div>
    <div class="firma-block">
      <div class="firma-line">
        <div class="firma-name">Recursos Humanos</div>
        <div class="firma-sub">Cadejo Brewing Company</div>
      </div>
    </div>
  </div>

  <div class="nota">
    Documento generado el {{ now()->format('d/m/Y H:i') }} — Cadejo Brewing Company
  </div>

</div>
</body>
</html>
