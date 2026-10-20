<!DOCTYPE html>
<html lang="es" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:v="urn:schemas-microsoft-com:vml">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="color-scheme" content="light dark" />
  <meta name="supported-color-schemes" content="light dark" />
  <title>Reporte Mensual Amonestaciones</title>
  <style>
    body { margin:0; padding:0; }
    table { border-collapse:collapse; }
    img { border:0; display:block; }
    @media (prefers-color-scheme: dark) {
      .body-bg  { background:#1c1917 !important; }
      .card-bg  { background:#292524 !important; }
      .body-text{ color:#d6d3d1 !important; }
      .sub-text { color:#a8a29e !important; }
      .tbl-hd   { background:#1c1917 !important; color:#f59e0b !important; }
      .tbl-row  { background:#292524 !important; color:#d6d3d1 !important; border-bottom:1px solid #44403c !important; }
      .tbl-alt  { background:#1c1917 !important; }
      .foot-sub { color:#78716c !important; }
      .stat-box { background:#1c1917 !important; border:1px solid #44403c !important; }
      .stat-lbl { color:#a8a29e !important; }
      .stat-num { color:#f5f5f4 !important; }
    }
    [data-ogsc] .body-bg  { background:#1c1917 !important; }
    [data-ogsc] .card-bg  { background:#292524 !important; }
    [data-ogsc] .tbl-hd   { background:#1c1917 !important; color:#f59e0b !important; }
    [data-ogsc] .tbl-row  { background:#292524 !important; color:#d6d3d1 !important; }
    [data-ogsc] .tbl-alt  { background:#1c1917 !important; }
  </style>
</head>
<body class="body-bg" style="margin:0;padding:0;background:#f5f0e8;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" class="body-bg" style="background:#f5f0e8;padding:32px 16px;">
  <tr><td align="center">
  <table width="100%" cellpadding="0" cellspacing="0" class="card-bg" style="max-width:640px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.12);">

    {{-- Header --}}
    <tr>
      <td style="background:#1a1a1a;padding:32px 48px;text-align:center;">
        <img src="https://cadejo-storage.s3.us-east-2.amazonaws.com/emails/cadejol0g0.png" alt="Cadejo" width="80" style="display:block;margin:0 auto 16px;border-radius:50%;" />
        <p style="margin:0 0 6px 0;color:#f59e0b;font-size:11px;letter-spacing:3px;text-transform:uppercase;font-weight:600;">Cadejo Brewing Company</p>
        <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;letter-spacing:1px;">Gestión de Talento</h1>
      </td>
    </tr>

    {{-- Banner --}}
    <tr>
      <td style="background:#b45309;padding:14px 48px;text-align:center;">
        <p style="margin:0;color:#ffffff;font-size:13px;font-weight:600;letter-spacing:1px;text-transform:uppercase;">Reporte Mensual de Amonestaciones — {{ $mesLabel }}</p>
      </td>
    </tr>

    {{-- Intro --}}
    <tr>
      <td class="card-bg" style="background:#ffffff;padding:32px 40px 8px;">
        <p class="sub-text" style="margin:0;color:#555555;font-size:15px;line-height:1.65;">
          A continuación el resumen de amonestaciones registradas durante <strong>{{ $mesLabel }}</strong>.
        </p>
      </td>
    </tr>

    {{-- Stats --}}
    <tr>
      <td class="card-bg" style="background:#ffffff;padding:16px 40px 24px;">
        <table width="100%" cellpadding="0" cellspacing="0">
          <tr>
            <td width="33%" style="padding:4px 6px 4px 0;">
              <table width="100%" cellpadding="12" cellspacing="0" class="stat-box" style="background:#fef9f0;border:1px solid #f3d9a5;border-radius:8px;">
                <tr><td style="text-align:center;">
                  <p class="stat-lbl" style="margin:0 0 4px;color:#92400e;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:1px;">Total</p>
                  <p class="stat-num" style="margin:0;color:#1a1a1a;font-size:28px;font-weight:800;">{{ $total }}</p>
                </td></tr>
              </table>
            </td>
            @foreach($porTipo as $t)
            <td width="{{ intval(67 / count($porTipo)) }}%" style="padding:4px 0 4px 6px;">
              <table width="100%" cellpadding="12" cellspacing="0" class="stat-box" style="background:#fafaf9;border:1px solid #e7e5e4;border-radius:8px;">
                <tr><td style="text-align:center;">
                  <p class="stat-lbl" style="margin:0 0 4px;color:#78716c;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:1px;">{{ $t['tipo'] }}</p>
                  <p class="stat-num" style="margin:0;color:#292524;font-size:24px;font-weight:700;">{{ $t['total'] }}</p>
                </td></tr>
              </table>
            </td>
            @endforeach
          </tr>
        </table>
      </td>
    </tr>

    {{-- Por sucursal --}}
    @if(count($porSucursal) > 0)
    <tr>
      <td class="card-bg" style="background:#ffffff;padding:0 40px 24px;">
        <p class="body-text" style="margin:0 0 10px;color:#1a1a1a;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:1px;">Por Sucursal / Área</p>
        <table width="100%" cellpadding="0" cellspacing="0" style="border-radius:8px;overflow:hidden;border:1px solid #e7e5e4;">
          <tr>
            <td class="tbl-hd" style="background:#f5f0e8;padding:10px 14px;font-size:11px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:1px;">Sucursal</td>
            <td class="tbl-hd" style="background:#f5f0e8;padding:10px 14px;font-size:11px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:1px;text-align:right;">Amonestaciones</td>
          </tr>
          @foreach($porSucursal as $i => $s)
          <tr class="{{ $i % 2 == 0 ? '' : 'tbl-alt' }}" style="{{ $i % 2 == 0 ? '' : 'background:#fafaf9;' }}">
            <td class="tbl-row" style="padding:9px 14px;font-size:13px;color:#292524;border-bottom:1px solid #e7e5e4;">{{ $s['sucursal'] }}</td>
            <td class="tbl-row" style="padding:9px 14px;font-size:13px;color:#292524;border-bottom:1px solid #e7e5e4;text-align:right;font-weight:600;">{{ $s['total'] }}</td>
          </tr>
          @endforeach
        </table>
      </td>
    </tr>
    @endif

    {{-- Detalle --}}
    @if(count($detalle) > 0)
    <tr>
      <td class="card-bg" style="background:#ffffff;padding:0 40px 32px;">
        <p class="body-text" style="margin:0 0 10px;color:#1a1a1a;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:1px;">Detalle</p>
        <table width="100%" cellpadding="0" cellspacing="0" style="border-radius:8px;overflow:hidden;border:1px solid #e7e5e4;font-size:12px;">
          <tr>
            <td class="tbl-hd" style="background:#f5f0e8;padding:9px 10px;font-size:10px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:1px;">Fecha</td>
            <td class="tbl-hd" style="background:#f5f0e8;padding:9px 10px;font-size:10px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:1px;">Empleado</td>
            <td class="tbl-hd" style="background:#f5f0e8;padding:9px 10px;font-size:10px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:1px;">Sucursal</td>
            <td class="tbl-hd" style="background:#f5f0e8;padding:9px 10px;font-size:10px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:1px;">Tipo Falta</td>
            <td class="tbl-hd" style="background:#f5f0e8;padding:9px 10px;font-size:10px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:1px;">Sanción</td>
          </tr>
          @foreach($detalle as $i => $row)
          <tr style="{{ $i % 2 != 0 ? 'background:#fafaf9;' : '' }}">
            <td class="tbl-row" style="padding:8px 10px;color:#44403c;border-bottom:1px solid #e7e5e4;white-space:nowrap;">{{ $row['fecha'] }}</td>
            <td class="tbl-row" style="padding:8px 10px;color:#292524;border-bottom:1px solid #e7e5e4;">{{ $row['empleado'] }}</td>
            <td class="tbl-row" style="padding:8px 10px;color:#44403c;border-bottom:1px solid #e7e5e4;">{{ $row['sucursal'] }}</td>
            <td class="tbl-row" style="padding:8px 10px;color:#44403c;border-bottom:1px solid #e7e5e4;">{{ $row['tipo_falta'] }}</td>
            <td class="tbl-row" style="padding:8px 10px;border-bottom:1px solid #e7e5e4;">
              @if($row['sancion'] === 'suspension')
                <span style="background:#fef2f2;color:#b91c1c;font-size:10px;font-weight:700;padding:2px 7px;border-radius:4px;text-transform:uppercase;">Suspensión</span>
              @elseif($row['sancion'] === 'escrita')
                <span style="background:#fff7ed;color:#c2410c;font-size:10px;font-weight:700;padding:2px 7px;border-radius:4px;text-transform:uppercase;">Escrita</span>
              @else
                <span style="color:#78716c;font-size:11px;">Verbal</span>
              @endif
            </td>
          </tr>
          @endforeach
        </table>
      </td>
    </tr>
    @else
    <tr>
      <td class="card-bg" style="background:#ffffff;padding:0 40px 32px;text-align:center;">
        <p class="sub-text" style="color:#a8a29e;font-size:14px;">No se registraron amonestaciones en {{ $mesLabel }}.</p>
      </td>
    </tr>
    @endif

    {{-- Footer --}}
    <tr>
      <td style="background:#1a1a1a;padding:24px 40px;text-align:center;">
        <p style="margin:0;color:#78716c;font-size:11px;">Este reporte fue generado automáticamente por el sistema de Gestión de Talento.</p>
        <p class="foot-sub" style="margin:6px 0 0;color:#57534e;font-size:11px;">Cadejo Brewing Company · {{ now()->year }}</p>
      </td>
    </tr>

  </table>
  </td></tr>
</table>

</body>
</html>
