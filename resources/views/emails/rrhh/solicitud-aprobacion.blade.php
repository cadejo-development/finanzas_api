<!DOCTYPE html>
<html lang="es" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="color-scheme" content="dark" />
  <meta name="supported-color-schemes" content="dark" />
  <title>Solicitud de aprobacion</title>
  <style>
    :root { color-scheme: dark; }
    body, #bodyTable { margin:0 !important; padding:0 !important; background-color:#111110 !important; }
  </style>
</head>
<body id="body" style="margin:0;padding:0;background-color:#111110;font-family:'Segoe UI',Arial,sans-serif;">
@php
use Carbon\Carbon;
$dias  = ['domingo','lunes','martes','miercoles','jueves','viernes','sabado'];
$meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
$formatearFecha = function($valor) use ($dias, $meses) {
    try { $c = Carbon::parse($valor); return $dias[$c->dayOfWeek] . ', ' . $c->day . ' de ' . $meses[$c->month - 1] . ' de ' . $c->year; }
    catch (\Throwable $e) { return $valor; }
};
$esFecha = function($label) {
    $lower = mb_strtolower($label);
    return str_contains($lower, 'fecha') || str_contains($lower, 'dia') || str_contains($lower, 'inicio') || str_contains($lower, 'fin');
};
@endphp
<table id="bodyTable" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#111110;width:100%;margin:0;padding:0;">
  <tr><td align="center" style="padding:32px 16px;">
    <table width="520" cellpadding="0" cellspacing="0" border="0" style="max-width:520px;width:100%;background-color:#1c1917;border-radius:16px;overflow:hidden;border:1px solid #292524;">

      <!-- HEADER -->
      <tr>
        <td bgcolor="#1a1614" align="center" style="background-color:#1a1614;padding:40px 32px 28px;border-bottom:2px solid #f59e0b;">
          <img src="https://cadejo-storage.s3.us-east-2.amazonaws.com/emails/cadejol0g0.png" alt="Cadejo" width="80" height="80" style="display:block;margin:0 auto;border-radius:50%;border:2px solid rgba(245,158,11,0.4);background-color:rgba(245,158,11,0.12);padding:4px;" />
          <p style="margin:14px 0 2px;font-size:17px;font-weight:700;color:#f59e0b;font-family:'Segoe UI',Arial,sans-serif;">Cadejo Brewing Company</p>
          <p style="margin:0 0 16px;font-size:11px;text-transform:uppercase;letter-spacing:1.5px;color:#78716c;font-family:'Segoe UI',Arial,sans-serif;">Gestion de Talento</p>
          <table cellpadding="0" cellspacing="0" border="0" align="center"><tr>
            <td bgcolor="#78350f" align="center" style="background-color:#78350f;border-radius:50px;padding:8px 28px;border:1.5px solid #f59e0b;">
              <span style="font-size:12px;font-weight:800;letter-spacing:1.5px;color:#fde68a;font-family:'Segoe UI',Arial,sans-serif;">SOLICITUD PENDIENTE</span>
            </td>
          </tr></table>
        </td>
      </tr>

      <!-- BODY -->
      <tr>
        <td bgcolor="#1c1917" style="background-color:#1c1917;padding:28px 32px 8px;">
          <p style="margin:0 0 10px;font-size:15px;color:#d6d3d1;font-family:'Segoe UI',Arial,sans-serif;">Hola, <strong style="color:#f5f5f4;">{{ $supervisorNombre }}</strong></p>
          <p style="margin:0 0 20px;font-size:14px;color:#a8a29e;line-height:1.65;font-family:'Segoe UI',Arial,sans-serif;">
            Tienes una nueva solicitud de <strong style="color:#f59e0b;">{{ $tipo }}</strong> pendiente de revision y aprobacion:
          </p>
          <table cellpadding="0" cellspacing="0" border="0"><tr>
            <td bgcolor="#292014" style="background-color:#292014;border-radius:8px;padding:5px 16px;border:1px solid #78450a;">
              <span style="font-size:12px;font-weight:800;color:#f59e0b;letter-spacing:0.5px;font-family:'Segoe UI',Arial,sans-serif;">{{ strtoupper($tipo) }}</span>
            </td>
          </tr></table>
        </td>
      </tr>

      <!-- EMPLOYEE CARD -->
      <tr>
        <td bgcolor="#1c1917" style="background-color:#1c1917;padding:14px 32px 0;">
          <table width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
            <td bgcolor="#242120" style="background-color:#242120;border-radius:12px;padding:14px 18px;border:1px solid #3c3835;">
              <table cellpadding="0" cellspacing="0" border="0" width="100%"><tr>
                <td width="50" valign="middle">
                  <table cellpadding="0" cellspacing="0" border="0"><tr>
                    <td bgcolor="#291e07" align="center" width="44" height="44" style="background-color:#291e07;border-radius:50%;width:44px;height:44px;border:1.5px solid #78450a;text-align:center;vertical-align:middle;">
                      <span style="font-size:18px;font-weight:800;color:#f59e0b;font-family:'Segoe UI',Arial,sans-serif;line-height:44px;display:inline-block;">{{ mb_strtoupper(mb_substr($empleadoNombre, 0, 1)) }}</span>
                    </td>
                  </tr></table>
                </td>
                <td style="padding-left:12px;" valign="middle">
                  <p style="margin:0 0 2px;font-size:14px;font-weight:700;color:#e7e5e4;font-family:'Segoe UI',Arial,sans-serif;">{{ $empleadoNombre }}</p>
                  <p style="margin:0;font-size:12px;color:#78716c;font-family:'Segoe UI',Arial,sans-serif;">Ha solicitado este {{ strtolower($tipo) }}</p>
                </td>
              </tr></table>
            </td>
          </tr></table>
        </td>
      </tr>

      <!-- DETAILS -->
      @if(count($detalles))
      <tr>
        <td bgcolor="#1c1917" style="background-color:#1c1917;padding:8px 32px 0;">
          <table width="100%" cellpadding="0" cellspacing="0" border="0">
            @foreach(array_filter($detalles) as $label => $valor)
            <tr>
              <td style="padding:10px 0;font-size:13px;color:#78716c;border-bottom:1px solid #292524;font-family:'Segoe UI',Arial,sans-serif;width:42%;">{{ $label }}</td>
              <td style="padding:10px 0;font-size:13px;color:#d6d3d1;font-weight:600;text-align:right;border-bottom:1px solid #292524;font-family:'Segoe UI',Arial,sans-serif;">{{ $esFecha($label) ? $formatearFecha($valor) : $valor }}</td>
            </tr>
            @endforeach
          </table>
        </td>
      </tr>
      @endif

      <!-- ACTIONS -->
      @if($aprobarUrl && $rechazarUrl)
      <tr>
        <td bgcolor="#1c1917" style="background-color:#1c1917;padding:22px 32px 0;">
          <table width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
            <td width="48%" align="center">
              <table cellpadding="0" cellspacing="0" border="0" width="100%"><tr>
                <td bgcolor="#15803d" align="center" style="background-color:#15803d;border-radius:10px;padding:14px 12px;">
                  <a href="{{ $aprobarUrl }}" style="font-size:14px;font-weight:800;color:#ffffff;text-decoration:none;font-family:'Segoe UI',Arial,sans-serif;display:inline-block;">&#10003; Aprobar</a>
                </td>
              </tr></table>
            </td>
            <td width="4%"></td>
            <td width="48%" align="center">
              <table cellpadding="0" cellspacing="0" border="0" width="100%"><tr>
                <td bgcolor="#292524" align="center" style="background-color:#292524;border-radius:10px;padding:13px 12px;border:1.5px solid #ef444440;">
                  <a href="{{ $rechazarUrl }}" style="font-size:14px;font-weight:800;color:#ef4444;text-decoration:none;font-family:'Segoe UI',Arial,sans-serif;display:inline-block;">&#10007; Rechazar</a>
                </td>
              </tr></table>
            </td>
          </tr></table>
        </td>
      </tr>
      <tr>
        <td bgcolor="#1c1917" style="background-color:#1c1917;padding:14px 32px 0;text-align:center;">
          <a href="{{ $linkUrl }}" style="font-size:12px;color:#78716c;text-decoration:underline;font-family:'Segoe UI',Arial,sans-serif;">Ver detalles en el sistema</a>
        </td>
      </tr>
      @else
      <tr>
        <td bgcolor="#1c1917" style="background-color:#1c1917;padding:22px 32px 0;text-align:center;">
          <table cellpadding="0" cellspacing="0" border="0" align="center"><tr>
            <td bgcolor="#f59e0b" style="background-color:#f59e0b;border-radius:10px;padding:14px 36px;">
              <a href="{{ $linkUrl }}" style="font-size:14px;font-weight:800;color:#1c1917;text-decoration:none;font-family:'Segoe UI',Arial,sans-serif;display:inline-block;">Revisar solicitud</a>
            </td>
          </tr></table>
        </td>
      </tr>
      @endif

      <!-- NOTE -->
      <tr>
        <td bgcolor="#1c1917" style="background-color:#1c1917;padding:14px 32px 26px;text-align:center;">
          <p style="margin:0;font-size:12px;color:#57534e;font-family:'Segoe UI',Arial,sans-serif;">Los botones de esta accion son validos por 5 dias.</p>
        </td>
      </tr>

      <!-- FOOTER -->
      <tr>
        <td bgcolor="#161513" style="background-color:#161513;padding:16px 32px 20px;text-align:center;border-top:1px solid #292524;">
          <p style="margin:3px 0;font-size:11px;color:#57534e;font-family:'Segoe UI',Arial,sans-serif;">Este correo fue generado automaticamente por el modulo de Gestion de Talento.</p>
          <p style="margin:3px 0;font-size:11px;color:#57534e;font-family:'Segoe UI',Arial,sans-serif;">&copy; {{ date('Y') }} Cadejo Brewing Company &mdash; Todos los derechos reservados.</p>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>
