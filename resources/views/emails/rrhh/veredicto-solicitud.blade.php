<!DOCTYPE html>
<html lang="es" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="color-scheme" content="dark" />
  <meta name="supported-color-schemes" content="dark" />
  <!--[if mso]>
  <noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript>
  <![endif]-->
  <title>Veredicto de solicitud</title>
  <style>
    :root { color-scheme: dark; }
    body, #bodyTable { margin:0 !important; padding:0 !important; background-color:#111110 !important; }
    u + #body .wrap { min-width:100vw; }
  </style>
</head>
<body id="body" style="margin:0;padding:0;background-color:#111110;font-family:'Segoe UI',Arial,sans-serif;">
@php
use Carbon\Carbon;
$dias  = ['domingo','lunes','martes','miercoles','jueves','viernes','sabado'];
$meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
$formatearFecha = function($valor) use ($dias, $meses) {
    try {
        $c = Carbon::parse($valor);
        return $dias[$c->dayOfWeek] . ', ' . $c->day . ' de ' . $meses[$c->month - 1] . ' de ' . $c->year;
    } catch (\Throwable $e) { return $valor; }
};
$esFecha = function($label) {
    $lower = mb_strtolower($label);
    return str_contains($lower, 'fecha') || str_contains($lower, 'inicio') || str_contains($lower, 'fin');
};
$aprobado = $estado === 'aprobado';
$accentLight  = $aprobado ? '#86efac'  : '#fca5a5';
$accentBg     = $aprobado ? '#052e16'  : '#1a0505';
$accentBorder = $aprobado ? '#16a34a'  : '#b91c1c';
$bannerBg     = $aprobado ? '#166534'  : '#7f1d1d';
$accentColor  = $aprobado ? '#22c55e'  : '#ef4444';
$badgeLabel   = $aprobado ? '&#10003; APROBADO' : '&#10007; RECHAZADO';
@endphp
<table id="bodyTable" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#111110;width:100%;margin:0;padding:0;">
  <tr>
    <td align="center" style="padding:32px 16px;">
      <table width="520" cellpadding="0" cellspacing="0" border="0" style="max-width:520px;width:100%;background-color:#1c1917;border-radius:16px;overflow:hidden;border:1px solid #292524;">

        <!-- HEADER -->
        <tr>
          <td bgcolor="{{ $accentBg }}" align="center" style="background-color:{{ $accentBg }};padding:40px 32px 28px;border-bottom:2px solid {{ $accentBorder }};">
            <img src="https://cadejo-storage.s3.us-east-2.amazonaws.com/emails/cadejol0g0.png" alt="Cadejo" width="80" height="80" style="display:block;margin:0 auto;border-radius:50%;border:2px solid rgba(245,158,11,0.4);background-color:rgba(245,158,11,0.12);padding:4px;" />
            <p style="margin:14px 0 2px;font-size:17px;font-weight:700;color:#f59e0b;font-family:'Segoe UI',Arial,sans-serif;">Cadejo Brewing Company</p>
            <p style="margin:0 0 16px;font-size:11px;text-transform:uppercase;letter-spacing:1.5px;color:#78716c;font-family:'Segoe UI',Arial,sans-serif;">Gestion de Talento</p>
            <table cellpadding="0" cellspacing="0" border="0" align="center">
              <tr>
                <td bgcolor="{{ $bannerBg }}" align="center" style="background-color:{{ $bannerBg }};border-radius:50px;padding:8px 28px;border:1.5px solid {{ $accentColor }};">
                  <span style="font-size:12px;font-weight:800;letter-spacing:1.5px;color:{{ $accentLight }};font-family:'Segoe UI',Arial,sans-serif;">{!! $badgeLabel !!}</span>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- BODY -->
        <tr>
          <td bgcolor="#1c1917" style="background-color:#1c1917;padding:28px 32px 8px;">
            <p style="margin:0 0 10px;font-size:15px;color:#d6d3d1;font-family:'Segoe UI',Arial,sans-serif;">Hola, <strong style="color:#f5f5f4;">{{ $empleadoNombre }}</strong></p>
            <p style="margin:0 0 20px;font-size:14px;color:#a8a29e;line-height:1.65;font-family:'Segoe UI',Arial,sans-serif;">
              @if($aprobado)
                Tu solicitud de <strong style="color:#86efac;">{{ $tipo }}</strong> ha sido <strong style="color:#86efac;">aprobada</strong>. Puedes revisar el detalle a continuacion.
              @else
                Tu solicitud de <strong style="color:#fca5a5;">{{ $tipo }}</strong> ha sido <strong style="color:#fca5a5;">rechazada</strong>. Puedes comunicarte con tu jefe para mas informacion.
              @endif
            </p>
            <table cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td bgcolor="#292014" style="background-color:#292014;border-radius:8px;padding:5px 16px;border:1px solid #78450a;">
                  <span style="font-size:12px;font-weight:800;color:#f59e0b;letter-spacing:0.5px;font-family:'Segoe UI',Arial,sans-serif;">{{ strtoupper($tipo) }}</span>
                </td>
              </tr>
            </table>
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 0;">
              <tr>
                <td bgcolor="{{ $accentBg }}" style="background-color:{{ $accentBg }};border-radius:12px;padding:16px 20px;border:1.5px solid {{ $accentBorder }};">
                  <p style="margin:0 0 4px;font-size:22px;line-height:1;color:{{ $accentLight }};">{{ $aprobado ? '&#10003;' : '&#10007;' }}</p>
                  <p style="margin:0 0 3px;font-size:14px;font-weight:700;color:{{ $accentLight }};font-family:'Segoe UI',Arial,sans-serif;">Solicitud {{ $aprobado ? 'aprobada' : 'rechazada' }}</p>
                  <p style="margin:0;font-size:12px;color:#78716c;font-family:'Segoe UI',Arial,sans-serif;">Revisada por tu supervisor directo</p>
                </td>
              </tr>
            </table>
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

        <!-- REVIEWER -->
        <tr>
          <td bgcolor="#1c1917" style="background-color:#1c1917;padding:20px 32px 0;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td bgcolor="#242120" style="background-color:#242120;border-radius:10px;padding:12px 16px;border:1px solid #3c3835;">
                  <table cellpadding="0" cellspacing="0" border="0" width="100%">
                    <tr>
                      <td width="44" valign="middle">
                        <table cellpadding="0" cellspacing="0" border="0"><tr>
                          <td bgcolor="#291e07" align="center" width="38" height="38" style="background-color:#291e07;border-radius:50%;width:38px;height:38px;border:1.5px solid #78450a;text-align:center;vertical-align:middle;">
                            <span style="font-size:15px;font-weight:800;color:#f59e0b;font-family:'Segoe UI',Arial,sans-serif;line-height:38px;display:inline-block;">{{ mb_strtoupper(mb_substr($supervisorNombre, 0, 1)) }}</span>
                          </td>
                        </tr></table>
                      </td>
                      <td style="padding-left:12px;" valign="middle">
                        <p style="margin:0 0 2px;font-size:13px;font-weight:700;color:#e7e5e4;font-family:'Segoe UI',Arial,sans-serif;">{{ $supervisorNombre }}</p>
                        <p style="margin:0;font-size:11px;color:#78716c;font-family:'Segoe UI',Arial,sans-serif;">Supervisor &mdash; emitio el veredicto</p>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- CTA -->
        <tr>
          <td bgcolor="#1c1917" style="background-color:#1c1917;padding:24px 32px 28px;text-align:center;">
            <table cellpadding="0" cellspacing="0" border="0" align="center">
              <tr>
                <td bgcolor="#f59e0b" style="background-color:#f59e0b;border-radius:10px;padding:14px 36px;">
                  <a href="{{ $linkUrl }}" style="font-size:14px;font-weight:800;color:#1c1917;text-decoration:none;font-family:'Segoe UI',Arial,sans-serif;letter-spacing:0.4px;display:inline-block;">Ver mi solicitud</a>
                </td>
              </tr>
            </table>
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
    </td>
  </tr>
</table>
</body>
</html>
