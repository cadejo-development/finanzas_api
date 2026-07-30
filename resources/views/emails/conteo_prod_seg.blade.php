<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
  body      { font-family: Arial, sans-serif; color: #1a1a1a; margin: 0; padding: 0; background: #f4f4f4; }
  .wrap     { max-width: 640px; margin: 24px auto; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,.12); }
  .hdr      { background: #1a1a2e; padding: 24px 28px; }
  .hdr h1   { color: #f5c842; margin: 0; font-size: 20px; letter-spacing: .5px; }
  .hdr p    { color: #bbb; margin: 6px 0 0; font-size: 13px; }
  .body     { padding: 24px 28px; }
  .meta     { background: #f9f9f9; border-radius: 6px; padding: 12px 16px; font-size: 13px; color: #555; margin-bottom: 20px; }
  .meta b   { color: #222; }
  table     { width: 100%; border-collapse: collapse; font-size: 13px; }
  th        { background: #1a1a2e; color: #f5c842; padding: 9px 10px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .6px; }
  td        { padding: 8px 10px; border-bottom: 1px solid #eee; color: #333; }
  tr:last-child td { border-bottom: none; }
  tr:nth-child(even) td { background: #fafafa; }
  .ok       { color: #2e7d32; font-weight: bold; }
  .faltante { color: #c62828; font-weight: bold; }
  .sobrante { color: #1565c0; font-weight: bold; }
  .ftr      { background: #f0f0f0; padding: 14px 28px; font-size: 11px; color: #888; text-align: center; }
</style>
</head>
<body>
<div class="wrap">
  <div class="hdr">
    <h1>✅ Conteo físico aplicado</h1>
    <p>{{ $sucursalNombre }} &mdash; {{ \Carbon\Carbon::parse($fechaConteo)->format('d/m/Y') }}</p>
  </div>
  <div class="body">
    <div class="meta">
      <b>Sucursal:</b> {{ $sucursalNombre }}<br>
      <b>Fecha conteo:</b> {{ \Carbon\Carbon::parse($fechaConteo)->format('d/m/Y') }}<br>
      <b>Aplicado por:</b> {{ $aplicadoPor }}<br>
      <b>Hora:</b> {{ now()->setTimezone('America/El_Salvador')->format('H:i') }}
    </div>

    <p style="font-size:13px;color:#444;margin-bottom:12px;">
      Resumen de los <strong>5 productos de seguimiento</strong> (saldo BRILO vs. conteo físico del día):
    </p>

    <table>
      <thead>
        <tr>
          <th>Producto</th>
          <th style="text-align:center">Unidad</th>
          <th style="text-align:center">Saldo BRILO</th>
          <th style="text-align:center">Conteo Físico</th>
          <th style="text-align:center">Diferencia</th>
          <th style="text-align:center">Estado</th>
        </tr>
      </thead>
      <tbody>
        @forelse($items as $item)
        <tr>
          <td>{{ $item['nombre'] }}</td>
          <td style="text-align:center">{{ $item['unidad'] }}</td>
          <td style="text-align:center">{{ $item['brilo_stock'] !== null ? number_format($item['brilo_stock'], 3) : '—' }}</td>
          <td style="text-align:center">{{ $item['conteo'] !== null ? number_format($item['conteo'], 3) : '—' }}</td>
          <td style="text-align:center">
            @if($item['diferencia'] !== null)
              <span class="{{ $item['diferencia'] > 0.01 ? 'sobrante' : ($item['diferencia'] < -0.01 ? 'faltante' : 'ok') }}">
                {{ ($item['diferencia'] > 0 ? '+' : '') . number_format($item['diferencia'], 3) }}
              </span>
            @else
              —
            @endif
          </td>
          <td style="text-align:center">
            <span class="{{ $item['tipo'] === 'OK' ? 'ok' : ($item['tipo'] === 'FALTANTE' ? 'faltante' : ($item['tipo'] === 'SOBRANTE' ? 'sobrante' : '')) }}">
              {{ $item['tipo'] }}
            </span>
          </td>
        </tr>
        @empty
        <tr><td colspan="6" style="text-align:center;color:#999;">Sin productos de seguimiento en este conteo.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  <div class="ftr">
    Sistema de Gestión Cadejo Brewing Company &mdash; Mensaje automático, no responder.
  </div>
</div>
</body>
</html>
