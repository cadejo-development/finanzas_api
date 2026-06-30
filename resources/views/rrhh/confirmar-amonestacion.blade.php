<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Confirmar Amonestación — Cadejo</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body { margin:0; padding:0; background:#0c0a09; font-family:'Segoe UI',Arial,sans-serif; min-height:100vh; display:flex; align-items:flex-start; justify-content:center; padding:32px 16px; }
    .card { max-width:540px; width:100%; background:#1c1917; border-radius:16px; border:1px solid #292524; overflow:hidden; }
    .card-header { padding:32px 36px 24px; border-bottom:1px solid #292524; text-align:center; background:linear-gradient(135deg,#1c1917 0%,#292524 100%); }
    .card-header img { display:block; margin:0 auto 12px; border-radius:50%; border:1.5px solid rgba(245,158,11,.3); }
    .card-header .brand { color:#f59e0b; font-size:11px; font-weight:600; letter-spacing:3px; text-transform:uppercase; margin:0 0 6px; }
    .card-header h1 { color:#e7e5e4; font-size:20px; font-weight:700; margin:0; }
    .banner { background:#b45309; padding:12px 36px; text-align:center; }
    .banner p { margin:0; color:#fff; font-size:13px; font-weight:700; letter-spacing:1px; text-transform:uppercase; }
    .card-body { padding:28px 36px; }
    .emp-card { background:#0c0a09; border:1px solid #292524; border-radius:10px; padding:14px 18px; margin-bottom:20px; }
    .emp-label { color:#78716c; font-size:11px; text-transform:uppercase; letter-spacing:1px; margin:0 0 3px; }
    .emp-name  { color:#f5f5f4; font-size:16px; font-weight:700; margin:0; }
    .emp-sub   { color:#78716c; font-size:12px; margin:4px 0 0; }
    .detail-table { width:100%; border-collapse:collapse; border-radius:8px; overflow:hidden; border:1px solid #292524; margin-bottom:20px; }
    .detail-table td { padding:10px 14px; font-size:13px; border-bottom:1px solid #1c1917; }
    .detail-table tr:last-child td { border-bottom:none; }
    .detail-table .lbl { color:#78716c; width:42%; }
    .detail-table .val { color:#e7e5e4; font-weight:600; text-align:right; }
    .detail-table tr:nth-child(even) td { background:#161412; }
    .descripcion { background:#0c0a09; border:1px solid #292524; border-radius:8px; padding:14px; margin-bottom:20px; }
    .descripcion label { color:#78716c; font-size:11px; text-transform:uppercase; letter-spacing:1px; display:block; margin-bottom:6px; }
    .descripcion p { color:#d6d3d1; font-size:13px; line-height:1.6; margin:0; }
    .aviso { background:#451a03; border-left:4px solid #f59e0b; border-radius:4px; padding:12px 16px; margin-bottom:24px; }
    .aviso p { color:#fde68a; font-size:13px; line-height:1.6; margin:0; }
    .textarea-wrap label { color:#a8a29e; font-size:12px; display:block; margin-bottom:6px; }
    textarea { width:100%; background:#0c0a09; border:1px solid #292524; border-radius:8px; color:#e7e5e4; font-size:13px; padding:12px; line-height:1.6; resize:vertical; font-family:inherit; min-height:90px; }
    textarea:focus { outline:none; border-color:#f59e0b; }
    .btn-row { display:flex; gap:12px; margin-top:20px; }
    .btn { flex:1; padding:13px; border-radius:10px; font-size:14px; font-weight:700; cursor:pointer; border:none; letter-spacing:0.3px; }
    .btn-aceptar { background:#16a34a; color:#fff; }
    .btn-aceptar:hover { background:#15803d; }
    .btn-rechazar { background:#292524; color:#fca5a5; border:1.5px solid #7f1d1d; }
    .btn-rechazar:hover { background:#1c1917; }
    .footer-note { text-align:center; color:#57534e; font-size:11px; margin-top:24px; line-height:1.5; }
  </style>
</head>
<body>
  <div class="card">
    <div class="card-header">
      <img src="https://cadejo-storage.s3.us-east-2.amazonaws.com/emails/cadejol0g0.png" alt="Cadejo" width="56" height="56" />
      <p class="brand">Cadejo Brewing Company</p>
      <h1>Confirmación de Amonestación</h1>
    </div>

    <div class="banner">
      <p>Registro formal en tu expediente</p>
    </div>

    <div class="card-body">

      @if($emp)
      <div class="emp-card">
        <p class="emp-label">Colaborador</p>
        <p class="emp-name">{{ trim($emp->nombres . ' ' . $emp->apellidos) }}</p>
        <p class="emp-sub">{{ $emp->cargo }} &mdash; {{ $emp->sucursal }}</p>
      </div>
      @endif

      <table class="detail-table">
        <tr>
          <td class="lbl">Fecha de la falta</td>
          <td class="val">{{ $amonestacion->fecha_amonestacion->format('d/m/Y') }}</td>
        </tr>
        <tr>
          <td class="lbl">Tipo de falta</td>
          <td class="val">{{ $amonestacion->tipoFalta?->nombre ?? '—' }}</td>
        </tr>
        <tr>
          <td class="lbl">Gravedad</td>
          <td class="val">
            @php $g = $amonestacion->tipoFalta?->gravedad; @endphp
            {{ $g === 'muy_grave' ? 'Muy grave' : ($g === 'grave' ? 'Grave' : 'Leve') }}
          </td>
        </tr>
        <tr>
          <td class="lbl">Tipo de sanción</td>
          <td class="val">
            @php $s = $amonestacion->tipo_sancion; @endphp
            {{ $s === 'suspension' ? 'Suspensión' : ($s === 'escrita' ? 'Escrita' : 'Verbal') }}
          </td>
        </tr>
        @if($amonestacion->aplicaSuspension && $amonestacion->diasSuspension->isNotEmpty())
        <tr>
          <td class="lbl">Días de suspensión</td>
          <td class="val">{{ $amonestacion->diasSuspension->count() }} día(s)</td>
        </tr>
        @endif
      </table>

      <div class="descripcion">
        <label>Descripción de los hechos</label>
        <p>{{ $amonestacion->descripcion }}</p>
      </div>

      @if($amonestacion->accion_tomada)
      <div class="descripcion">
        <label>Llamado de atención y mejora esperada</label>
        <p>{{ $amonestacion->accion_tomada }}</p>
      </div>
      @endif

      <div class="aviso">
        <p>Al confirmar el recibo, reconoces haber sido notificado de esta amonestación. Esto <strong>no implica que estés de acuerdo</strong> con los hechos. Si no estás de acuerdo, selecciona "No acepto" y escribe tu comentario — será enviado a RRHH.</p>
      </div>

      <form method="POST" action="{{ route('rrhh.amonestacion.confirmar.post', $token) }}">
        @csrf
        <input type="hidden" name="decision" id="decision-input" value="" />
        <div class="textarea-wrap">
          <label for="comentario">Comentario (opcional)</label>
          <textarea name="comentario" id="comentario" placeholder="Puedes agregar un comentario o tu postura respecto a esta amonestación..."></textarea>
        </div>
        <div class="btn-row">
          <button type="submit" class="btn btn-aceptar" onclick="document.getElementById('decision-input').value='aceptar'">
            Confirmo el recibo
          </button>
          <button type="submit" class="btn btn-rechazar" onclick="document.getElementById('decision-input').value='rechazar'">
            No acepto / No estoy de acuerdo
          </button>
        </div>
      </form>

      <p class="footer-note">
        Este enlace es personal e intransferible.<br>
        Cadejo Brewing Company &mdash; Gestión de Talento Humano
      </p>

    </div>
  </div>
</body>
</html>
