<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
  body      { font-family: Arial, sans-serif; color: #1a1a1a; margin: 0; padding: 0; background: #f4f4f4; }
  .wrap     { max-width: 680px; margin: 24px auto; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,.12); }
  .hdr      { background: #1a1a2e; padding: 24px 28px; }
  .hdr h1   { color: #f5c842; margin: 0; font-size: 20px; letter-spacing: .5px; }
  .hdr p    { color: #bbb; margin: 6px 0 0; font-size: 13px; }
  .body     { padding: 24px 28px; }
  .meta     { background: #f9f9f9; border-radius: 6px; padding: 12px 16px; font-size: 13px; color: #555; margin-bottom: 20px; }
  .meta b   { color: #222; }
  .intro    { font-size: 13px; color: #444; margin-bottom: 16px; line-height: 1.6; }
  .accion   { background: #fff8e1; border-left: 4px solid #f5c842; padding: 12px 16px; border-radius: 0 6px 6px 0; font-size: 13px; color: #555; margin-bottom: 20px; }
  .accion b { color: #333; }
  table     { width: 100%; border-collapse: collapse; font-size: 12px; }
  th        { background: #1a1a2e; color: #f5c842; padding: 9px 10px; text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .6px; }
  td        { padding: 8px 10px; border-bottom: 1px solid #eee; color: #333; vertical-align: top; }
  tr:last-child td  { border-bottom: none; }
  tr:nth-child(even) td { background: #fafafa; }
  .neg      { color: #c62828; font-weight: bold; }
  .pos      { color: #1565c0; font-weight: bold; }
  .obs      { color: #777; font-size: 11px; font-style: italic; margin-top: 2px; }
  .ftr      { background: #f0f0f0; padding: 14px 28px; font-size: 11px; color: #888; text-align: center; }
</style>
</head>
<body>
<div class="wrap">
  <div class="hdr">
    <h1>⚠️ Revisión de inventario requerida</h1>
    <p>{{ $sucursalNombre }} &mdash; Conteo {{ \Carbon\Carbon::parse($fechaConteo)->format('d/m/Y') }}</p>
  </div>
  <div class="body">
    <div class="meta">
      <b>Sucursal:</b> {{ $sucursalNombre }}<br>
      <b>Fecha conteo:</b> {{ \Carbon\Carbon::parse($fechaConteo)->format('d/m/Y') }}<br>
      <b>Enviado por:</b> {{ $gerenteNombre }}<br>
      <b>Fecha notificación:</b> {{ now()->setTimezone('America/El_Salvador')->format('d/m/Y H:i') }}
    </div>

    <p class="intro">
      Estimado/a <strong>{{ $destinatarioNombre }}</strong>,<br><br>
      El gerente de la sucursal <strong>{{ $sucursalNombre }}</strong> ha identificado las siguientes diferencias en el conteo mensual
      y las ha asignado a su revisión. Por favor, verifique cada caso y tome las acciones correctivas correspondientes.
    </p>

    @php
      $instruccion = match($tipoResponsabilidad) {
        'error_receta'         => 'Revise la receta indicada. Si hay un error, corrígalo en el sistema y responda este correo confirmando el cambio realizado.',
        'error_posteo'         => 'Revise el posteo/compra correspondiente. Si hay un error en el ingreso, corrígalo y responda este correo confirmando la corrección.',
        'error_traslado'       => 'Revise el traslado entre sucursales correspondiente. Si hay un error, corrígalo y responda confirmando la acción tomada.',
        'codigo_mp_equivocado' => 'Revise el código de materia prima utilizado en la receta o compra. Corrija el código incorrecto y responda confirmando el cambio.',
        default                => 'Revise los casos indicados y tome las acciones correctivas correspondientes.',
      };
    @endphp

    <div class="accion">
      <b>Acción requerida:</b> {{ $instruccion }}
    </div>

    <table>
      <thead>
        <tr>
          <th>Código</th>
          <th>Producto</th>
          <th style="text-align:right">Und.</th>
          <th style="text-align:right">Diferencia</th>
          <th style="text-align:right">Dif.%</th>
          <th style="text-align:right">Costo Dif.</th>
          <th>Observación del gerente</th>
        </tr>
      </thead>
      <tbody>
        @foreach($items as $item)
        <tr>
          <td style="font-family:monospace;font-size:11px;color:#666">{{ $item['codigo'] ?? '—' }}</td>
          <td><strong>{{ $item['nombre'] }}</strong></td>
          <td style="text-align:right;color:#666">{{ $item['unidad'] ?? '' }}</td>
          <td style="text-align:right" class="{{ ($item['diferencia'] ?? 0) < 0 ? 'neg' : (($item['diferencia'] ?? 0) > 0 ? 'pos' : '') }}">
            {{ ($item['diferencia'] ?? 0) >= 0 ? '+' : '' }}{{ number_format($item['diferencia'] ?? 0, 2) }}
          </td>
          <td style="text-align:right;color:#888">{{ $item['dif_pct'] !== null ? number_format($item['dif_pct'], 1).'%' : '—' }}</td>
          <td style="text-align:right;color:#888">${{ number_format(abs($item['costo_diff'] ?? 0), 2) }}</td>
          <td>
            @if(!empty($item['obs']))
              <span>{{ $item['obs'] }}</span>
            @else
              <span style="color:#bbb;font-style:italic">Sin observación</span>
            @endif
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>

    <p style="margin-top:20px;font-size:12px;color:#888;">
      Total de casos asignados: <strong>{{ count($items) }}</strong>
    </p>
  </div>
  <div class="ftr">
    Cadejo Brewing Company &mdash; Sistema de Gestión de Inventario<br>
    Este correo fue generado automáticamente. Responda directamente si tiene comentarios.
  </div>
</div>
</body>
</html>
