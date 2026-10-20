<!DOCTYPE html>
<html lang="es" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:v="urn:schemas-microsoft-com:vml">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="color-scheme" content="light dark" />
  <meta name="supported-color-schemes" content="light dark" />
  <title>Reporte Semanal Altas y Bajas</title>
  <style>
    body { margin:0; padding:0; }
    table { border-collapse:collapse; }
    img { border:0; display:block; }
    @media (prefers-color-scheme: dark) {
      .body-bg  { background:#1c1917 !important; }
      .card-bg  { background:#292524 !important; }
      .body-text{ color:#d6d3d1 !important; }
      .sub-text { color:#a8a29e !important; }
      .tbl-hd-g { background:#14532d !important; color:#bbf7d0 !important; }
      .tbl-hd-r { background:#7f1d1d !important; color:#fecaca !important; }
      .tbl-row  { background:#292524 !important; color:#d6d3d1 !important; border-bottom:1px solid #44403c !important; }
      .tbl-alt  { background:#1c1917 !important; }
      .foot-sub { color:#78716c !important; }
      .stat-green { background:#14532d !important; border:1px solid #166534 !important; }
      .stat-red   { background:#7f1d1d !important; border:1px solid #991b1b !important; }
      .stat-lbl-g { color:#86efac !important; }
      .stat-lbl-r { color:#fca5a5 !important; }
      .stat-num   { color:#f5f5f4 !important; }
    }
    [data-ogsc] .body-bg   { background:#1c1917 !important; }
    [data-ogsc] .card-bg   { background:#292524 !important; }
    [data-ogsc] .tbl-hd-g  { background:#14532d !important; color:#bbf7d0 !important; }
    [data-ogsc] .tbl-hd-r  { background:#7f1d1d !important; color:#fecaca !important; }
    [data-ogsc] .tbl-row   { background:#292524 !important; color:#d6d3d1 !important; }
    [data-ogsc] .tbl-alt   { background:#1c1917 !important; }
  </style>
</head>
<body class="body-bg" style="margin:0;padding:0;background:#f5f0e8;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" class="body-bg" style="background:#f5f0e8;padding:32px 16px;">
  <tr><td align="center">
  <table width="100%" cellpadding="0" cellspacing="0" class="card-bg" style="max-width:660px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.12);">

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
      <td style="background:#1d4ed8;padding:14px 48px;text-align:center;">
        <p style="margin:0;color:#ffffff;font-size:13px;font-weight:600;letter-spacing:1px;text-transform:uppercase;">Reporte Semanal de Altas y Bajas — {{ $semanaLabel }}</p>
      </td>
    </tr>

    {{-- Stats --}}
    <tr>
      <td class="card-bg" style="background:#ffffff;padding:28px 40px 16px;">
        <table width="100%" cellpadding="0" cellspacing="0">
          <tr>
            <td width="50%" style="padding:4px 6px 4px 0;">
              <table width="100%" cellpadding="14" cellspacing="0" class="stat-green" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;">
                <tr><td style="text-align:center;">
                  <p class="stat-lbl-g" style="margin:0 0 4px;color:#166534;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:1px;">Altas (Ingresos)</p>
                  <p class="stat-num" style="margin:0;color:#14532d;font-size:32px;font-weight:800;">{{ $totalAltas }}</p>
                </td></tr>
              </table>
            </td>
            <td width="50%" style="padding:4px 0 4px 6px;">
              <table width="100%" cellpadding="14" cellspacing="0" class="stat-red" style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;">
                <tr><td style="text-align:center;">
                  <p class="stat-lbl-r" style="margin:0 0 4px;color:#991b1b;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:1px;">Bajas (Desvinculaciones)</p>
                  <p class="stat-num" style="margin:0;color:#7f1d1d;font-size:32px;font-weight:800;">{{ $totalBajas }}</p>
                </td></tr>
              </table>
            </td>
          </tr>
        </table>
      </td>
    </tr>

    {{-- ALTAS --}}
    @if($totalAltas > 0)
    <tr>
      <td class="card-bg" style="background:#ffffff;padding:16px 40px 8px;">
        <p class="body-text" style="margin:0 0 10px;color:#166534;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:1px;">↑ Ingresos de Personal</p>

        {{-- Por sucursal --}}
        @if(count($altasPorSucursal) > 1)
        <table width="100%" cellpadding="0" cellspacing="0" style="border-radius:6px;overflow:hidden;border:1px solid #dcfce7;margin-bottom:12px;font-size:12px;">
          <tr>
            <td class="tbl-hd-g" style="background:#dcfce7;padding:7px 12px;font-size:10px;font-weight:700;color:#166534;text-transform:uppercase;letter-spacing:1px;">Sucursal</td>
            <td class="tbl-hd-g" style="background:#dcfce7;padding:7px 12px;font-size:10px;font-weight:700;color:#166534;text-transform:uppercase;letter-spacing:1px;text-align:right;">Ingresos</td>
          </tr>
          @foreach($altasPorSucursal as $i => $s)
          <tr style="{{ $i % 2 != 0 ? 'background:#f0fdf4;' : '' }}">
            <td class="tbl-row" style="padding:7px 12px;color:#292524;border-bottom:1px solid #dcfce7;">{{ $s['sucursal'] }}</td>
            <td class="tbl-row" style="padding:7px 12px;color:#166534;border-bottom:1px solid #dcfce7;text-align:right;font-weight:600;">{{ $s['total'] }}</td>
          </tr>
          @endforeach
        </table>
        @endif

        {{-- Detalle --}}
        <table width="100%" cellpadding="0" cellspacing="0" style="border-radius:6px;overflow:hidden;border:1px solid #dcfce7;font-size:12px;">
          <tr>
            <td class="tbl-hd-g" style="background:#dcfce7;padding:8px 10px;font-size:10px;font-weight:700;color:#166534;text-transform:uppercase;letter-spacing:1px;">Fecha</td>
            <td class="tbl-hd-g" style="background:#dcfce7;padding:8px 10px;font-size:10px;font-weight:700;color:#166534;text-transform:uppercase;letter-spacing:1px;">Empleado</td>
            <td class="tbl-hd-g" style="background:#dcfce7;padding:8px 10px;font-size:10px;font-weight:700;color:#166534;text-transform:uppercase;letter-spacing:1px;">Cargo</td>
            <td class="tbl-hd-g" style="background:#dcfce7;padding:8px 10px;font-size:10px;font-weight:700;color:#166534;text-transform:uppercase;letter-spacing:1px;">Sucursal</td>
          </tr>
          @foreach($altas as $i => $row)
          <tr style="{{ $i % 2 != 0 ? 'background:#f0fdf4;' : '' }}">
            <td class="tbl-row" style="padding:7px 10px;color:#44403c;border-bottom:1px solid #dcfce7;white-space:nowrap;">{{ $row['fecha'] }}</td>
            <td class="tbl-row" style="padding:7px 10px;color:#292524;border-bottom:1px solid #dcfce7;">{{ $row['empleado'] }}</td>
            <td class="tbl-row" style="padding:7px 10px;color:#44403c;border-bottom:1px solid #dcfce7;">{{ $row['cargo'] }}</td>
            <td class="tbl-row" style="padding:7px 10px;color:#44403c;border-bottom:1px solid #dcfce7;">{{ $row['sucursal'] }}</td>
          </tr>
          @endforeach
        </table>
      </td>
    </tr>
    @else
    <tr>
      <td class="card-bg" style="background:#ffffff;padding:16px 40px 8px;">
        <p class="body-text" style="margin:0 0 6px;color:#166534;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:1px;">↑ Ingresos de Personal</p>
        <p class="sub-text" style="margin:0;color:#a8a29e;font-size:13px;">No se registraron ingresos esta semana.</p>
      </td>
    </tr>
    @endif

    <tr><td style="height:20px;"></td></tr>

    {{-- BAJAS --}}
    @if($totalBajas > 0)
    <tr>
      <td class="card-bg" style="background:#ffffff;padding:8px 40px 32px;">
        <p class="body-text" style="margin:0 0 10px;color:#991b1b;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:1px;">↓ Desvinculaciones</p>

        {{-- Por sucursal --}}
        @if(count($bajasPorSucursal) > 1)
        <table width="100%" cellpadding="0" cellspacing="0" style="border-radius:6px;overflow:hidden;border:1px solid #fecaca;margin-bottom:12px;font-size:12px;">
          <tr>
            <td class="tbl-hd-r" style="background:#fecaca;padding:7px 12px;font-size:10px;font-weight:700;color:#991b1b;text-transform:uppercase;letter-spacing:1px;">Sucursal</td>
            <td class="tbl-hd-r" style="background:#fecaca;padding:7px 12px;font-size:10px;font-weight:700;color:#991b1b;text-transform:uppercase;letter-spacing:1px;text-align:right;">Bajas</td>
          </tr>
          @foreach($bajasPorSucursal as $i => $s)
          <tr style="{{ $i % 2 != 0 ? 'background:#fef2f2;' : '' }}">
            <td class="tbl-row" style="padding:7px 12px;color:#292524;border-bottom:1px solid #fecaca;">{{ $s['sucursal'] }}</td>
            <td class="tbl-row" style="padding:7px 12px;color:#991b1b;border-bottom:1px solid #fecaca;text-align:right;font-weight:600;">{{ $s['total'] }}</td>
          </tr>
          @endforeach
        </table>
        @endif

        {{-- Detalle --}}
        <table width="100%" cellpadding="0" cellspacing="0" style="border-radius:6px;overflow:hidden;border:1px solid #fecaca;font-size:12px;">
          <tr>
            <td class="tbl-hd-r" style="background:#fecaca;padding:8px 10px;font-size:10px;font-weight:700;color:#991b1b;text-transform:uppercase;letter-spacing:1px;">Fecha</td>
            <td class="tbl-hd-r" style="background:#fecaca;padding:8px 10px;font-size:10px;font-weight:700;color:#991b1b;text-transform:uppercase;letter-spacing:1px;">Empleado</td>
            <td class="tbl-hd-r" style="background:#fecaca;padding:8px 10px;font-size:10px;font-weight:700;color:#991b1b;text-transform:uppercase;letter-spacing:1px;">Cargo</td>
            <td class="tbl-hd-r" style="background:#fecaca;padding:8px 10px;font-size:10px;font-weight:700;color:#991b1b;text-transform:uppercase;letter-spacing:1px;">Sucursal</td>
            <td class="tbl-hd-r" style="background:#fecaca;padding:8px 10px;font-size:10px;font-weight:700;color:#991b1b;text-transform:uppercase;letter-spacing:1px;">Tipo</td>
          </tr>
          @foreach($bajas as $i => $row)
          <tr style="{{ $i % 2 != 0 ? 'background:#fef2f2;' : '' }}">
            <td class="tbl-row" style="padding:7px 10px;color:#44403c;border-bottom:1px solid #fecaca;white-space:nowrap;">{{ $row['fecha'] }}</td>
            <td class="tbl-row" style="padding:7px 10px;color:#292524;border-bottom:1px solid #fecaca;">{{ $row['empleado'] }}</td>
            <td class="tbl-row" style="padding:7px 10px;color:#44403c;border-bottom:1px solid #fecaca;">{{ $row['cargo'] }}</td>
            <td class="tbl-row" style="padding:7px 10px;color:#44403c;border-bottom:1px solid #fecaca;">{{ $row['sucursal'] }}</td>
            <td class="tbl-row" style="padding:7px 10px;border-bottom:1px solid #fecaca;">
              @if($row['tipo'] === 'Despido')
                <span style="background:#fef2f2;color:#b91c1c;font-size:10px;font-weight:700;padding:2px 7px;border-radius:4px;text-transform:uppercase;">Despido</span>
              @elseif($row['tipo'] === 'Renuncia')
                <span style="background:#fff7ed;color:#c2410c;font-size:10px;font-weight:700;padding:2px 7px;border-radius:4px;text-transform:uppercase;">Renuncia</span>
              @else
                <span style="color:#78716c;font-size:11px;">{{ $row['tipo'] }}</span>
              @endif
            </td>
          </tr>
          @endforeach
        </table>
      </td>
    </tr>
    @else
    <tr>
      <td class="card-bg" style="background:#ffffff;padding:8px 40px 32px;">
        <p class="body-text" style="margin:0 0 6px;color:#991b1b;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:1px;">↓ Desvinculaciones</p>
        <p class="sub-text" style="margin:0;color:#a8a29e;font-size:13px;">No se registraron desvinculaciones esta semana.</p>
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
