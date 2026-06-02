<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: Arial, Helvetica, sans-serif; font-size: 11px; color: #1a1a1a; background: #fff; }

  .page { padding: 24px 28px; max-width: 780px; margin: 0 auto; }

  /* Header */
  .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #1a3a5c; padding-bottom: 12px; margin-bottom: 14px; }
  .company-name { font-size: 17px; font-weight: bold; color: #1a3a5c; }
  .company-sub  { font-size: 10px; color: #555; margin-top: 2px; }
  .boleta-title { text-align: right; }
  .boleta-title h2 { font-size: 14px; color: #1a3a5c; font-weight: bold; }
  .boleta-title .periodo { font-size: 11px; color: #444; margin-top: 3px; }
  .boleta-title .estado  { display: inline-block; font-size: 9px; padding: 2px 8px; border-radius: 3px; margin-top: 4px; background: #e8f4ea; color: #2d6a2d; font-weight: bold; text-transform: uppercase; }

  /* Info empleado */
  .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 24px; background: #f5f7fa; border: 1px solid #dde3ec; border-radius: 5px; padding: 10px 14px; margin-bottom: 14px; }
  .info-item { display: flex; flex-direction: column; }
  .info-label { font-size: 8.5px; color: #777; text-transform: uppercase; letter-spacing: 0.4px; }
  .info-value { font-size: 11px; font-weight: bold; color: #1a1a1a; margin-top: 1px; }

  /* Secciones de cálculo */
  .seccion { margin-bottom: 12px; }
  .seccion-titulo { font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; color: #fff; background: #1a3a5c; padding: 4px 10px; border-radius: 3px 3px 0 0; }
  .seccion table { width: 100%; border-collapse: collapse; }
  .seccion table td { padding: 5px 10px; border-bottom: 1px solid #eee; font-size: 11px; }
  .seccion table td:last-child { text-align: right; font-variant-numeric: tabular-nums; }
  .seccion table tr:last-child td { border-bottom: none; }
  .seccion table tr.subtotal td { font-weight: bold; background: #f0f4fa; border-top: 1px solid #c8d4e8; }
  .seccion table tr.descuento td:last-child { color: #b02020; }

  /* Neto */
  .neto-box { background: #1a3a5c; color: #fff; border-radius: 5px; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; }
  .neto-label { font-size: 12px; font-weight: bold; }
  .neto-value { font-size: 22px; font-weight: bold; }

  /* Cargas patronales */
  .patronal { border: 1px solid #dde3ec; border-radius: 5px; }
  .patronal-titulo { font-size: 9px; font-weight: bold; color: #555; text-transform: uppercase; padding: 5px 10px; background: #f5f7fa; border-bottom: 1px solid #dde3ec; border-radius: 5px 5px 0 0; }
  .patronal table { width: 100%; border-collapse: collapse; }
  .patronal table td { padding: 4px 10px; font-size: 10px; border-bottom: 1px solid #f0f0f0; }
  .patronal table td:last-child { text-align: right; }
  .patronal table tr:last-child td { border-bottom: none; font-weight: bold; }

  /* Footer */
  .footer { margin-top: 28px; display: grid; grid-template-columns: 1fr 1fr; gap: 40px; }
  .firma { border-top: 1px solid #aaa; padding-top: 6px; text-align: center; font-size: 9px; color: #555; }

  .fmt { font-variant-numeric: tabular-nums; }
</style>
</head>
<body>
<div class="page">

  {{-- Header --}}
  <div class="header">
    <div>
      <div class="company-name">Cadejo Brewing Company</div>
      <div class="company-sub">Comprobante de Pago de Salario</div>
    </div>
    <div class="boleta-title">
      <h2>BOLETA DE PAGO</h2>
      <div class="periodo">
        Quincena {{ $planilla->quincena }} &nbsp;·&nbsp;
        {{ \Carbon\Carbon::create($planilla->anio, $planilla->mes)->locale('es')->monthName }} {{ $planilla->anio }}
      </div>
      <div class="periodo">
        {{ \Carbon\Carbon::parse($planilla->fecha_inicio)->format('d/m/Y') }}
        al
        {{ \Carbon\Carbon::parse($planilla->fecha_fin)->format('d/m/Y') }}
      </div>
      <span class="estado">{{ ucfirst($planilla->estado) }}</span>
    </div>
  </div>

  {{-- Info empleado --}}
  <div class="info-grid">
    <div class="info-item">
      <span class="info-label">Nombre</span>
      <span class="info-value">{{ $empleado->nombres }} {{ $empleado->apellidos }}</span>
    </div>
    <div class="info-item">
      <span class="info-label">Código</span>
      <span class="info-value">{{ $empleado->codigo }}</span>
    </div>
    <div class="info-item">
      <span class="info-label">Cargo</span>
      <span class="info-value">{{ $empleado->cargo?->nombre ?? '—' }}</span>
    </div>
    <div class="info-item">
      <span class="info-label">Sucursal / Departamento</span>
      <span class="info-value">{{ $empleado->sucursal?->nombre ?? $empleado->departamento?->nombre ?? '—' }}</span>
    </div>
    <div class="info-item">
      <span class="info-label">Días laborados</span>
      <span class="info-value">{{ number_format($linea->dias_laborados, 1) }} / {{ $linea->dias_quincena }}</span>
    </div>
    <div class="info-item">
      <span class="info-label">Salario base mensual</span>
      <span class="info-value">${{ number_format($linea->salario_base, 2) }}</span>
    </div>
  </div>

  {{-- Ingresos --}}
  <div class="seccion">
    <div class="seccion-titulo">Ingresos</div>
    <table>
      <tr>
        <td>Salario base (quincena)</td>
        <td class="fmt">${{ number_format($linea->salario_base / 2, 2) }}</td>
      </tr>
      @if($linea->dias_laborados < $linea->dias_quincena)
      <tr>
        <td style="color:#777">Ajuste proporcional ({{ number_format($linea->dias_laborados,1) }}/{{ $linea->dias_quincena }} días)</td>
        <td class="fmt" style="color:#777">${{ number_format($linea->salario_proporcional, 2) }}</td>
      </tr>
      @endif
      <tr class="subtotal">
        <td>Total devengado</td>
        <td class="fmt">${{ number_format($linea->salario_proporcional, 2) }}</td>
      </tr>
    </table>
  </div>

  {{-- Deducciones --}}
  <div class="seccion">
    <div class="seccion-titulo">Deducciones del Empleado</div>
    <table>
      <tr class="descuento">
        <td>AFP ({{ number_format(7.25, 2) }}%)</td>
        <td class="fmt">- ${{ number_format($linea->afp_empleado, 2) }}</td>
      </tr>
      <tr class="descuento">
        <td>ISSS (3.00%)</td>
        <td class="fmt">- ${{ number_format($linea->isss_empleado, 2) }}</td>
      </tr>
      <tr class="descuento">
        <td>Renta (ISR)</td>
        <td class="fmt">- ${{ number_format($linea->renta, 2) }}</td>
      </tr>
      @if($linea->otros_descuentos > 0)
        @foreach(($linea->detalle_descuentos ?? []) as $desc)
        <tr class="descuento">
          <td>{{ $desc['concepto'] ?? 'Otros descuentos' }}@if(!empty($desc['acreedor'])) — {{ $desc['acreedor'] }}@endif</td>
          <td class="fmt">- ${{ number_format($desc['monto_quincenal'] ?? 0, 2) }}</td>
        </tr>
        @endforeach
      @endif
      <tr class="subtotal">
        <td>Total deducciones</td>
        <td class="fmt" style="color:#b02020">- ${{ number_format($linea->total_descuentos_empleado, 2) }}</td>
      </tr>
    </table>
  </div>

  {{-- Neto --}}
  <div class="neto-box">
    <span class="neto-label">SALARIO NETO A RECIBIR</span>
    <span class="neto-value">${{ number_format($linea->salario_neto, 2) }}</span>
  </div>

  {{-- Cargas patronales --}}
  <div class="patronal">
    <div class="patronal-titulo">Cargas Patronales (referencia — no se descuentan al empleado)</div>
    <table>
      <tr>
        <td>AFP Patronal (8.75%)</td>
        <td>${{ number_format($linea->afp_patronal, 2) }}</td>
      </tr>
      <tr>
        <td>ISSS Patronal (7.50%)</td>
        <td>${{ number_format($linea->isss_patronal, 2) }}</td>
      </tr>
      <tr>
        <td>INCAF/INSAFORP (1.00%)</td>
        <td>${{ number_format($linea->insaforp_patronal, 2) }}</td>
      </tr>
      <tr>
        <td><strong>Total patronal</strong></td>
        <td><strong>${{ number_format($linea->total_patronal, 2) }}</strong></td>
      </tr>
    </table>
  </div>

  {{-- Firmas --}}
  <div class="footer">
    <div class="firma">
      <br><br>
      ___________________________________<br>
      Firma del Empleado<br>
      {{ $empleado->nombres }} {{ $empleado->apellidos }}
    </div>
    <div class="firma">
      <br><br>
      ___________________________________<br>
      Recursos Humanos<br>
      Cadejo Brewing Company
    </div>
  </div>

</div>
</body>
</html>
