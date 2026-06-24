<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\ContratoEmpleado;
use App\Models\RRHH\IngresoPersonal;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ContratoEmpleadoController extends RRHHBaseController
{
    /** GET /api/rrhh/ingresos/{ingresoId}/contratos */
    public function porIngreso(int $ingresoId): JsonResponse
    {
        IngresoPersonal::findOrFail($ingresoId);

        $contratos = ContratoEmpleado::with(['tipoContrato'])
            ->where('ingreso_id', $ingresoId)
            ->orderByDesc('fecha_inicio')
            ->get();

        return response()->json(['success' => true, 'data' => $contratos]);
    }

    /** POST /api/rrhh/ingresos/{ingresoId}/contratos */
    public function store(Request $request, int $ingresoId): JsonResponse
    {
        $ingreso = IngresoPersonal::findOrFail($ingresoId);

        $v = $request->validate([
            'tipo_contrato_id' => 'required|integer|exists:rrhh.tipos_contrato,id',
            'plantilla_id'     => 'nullable|integer|exists:rrhh.plantillas_contrato,id',
            'fecha_inicio'     => 'required|date',
            'fecha_fin'        => 'nullable|date|after:fecha_inicio',
            'funciones'        => 'nullable|string|max:5000',
            'notas'            => 'nullable|string|max:2000',
        ]);

        $contrato = ContratoEmpleado::create([
            ...$v,
            'empleado_id'      => $ingreso->empleado_id,
            'empleado_nombre'  => $ingreso->empleado_nombre,
            'ingreso_id'       => $ingresoId,
            'estado'           => 'activo',
            'generado_por_id'  => Auth::id(),
            'aud_usuario'      => Auth::user()->email,
        ]);

        $contrato->load('tipoContrato');

        return response()->json(['success' => true, 'data' => $contrato], 201);
    }

    /** PATCH /api/rrhh/contratos/{id}/estado */
    public function actualizarEstado(Request $request, int $id): JsonResponse
    {
        $contrato = ContratoEmpleado::findOrFail($id);

        $v = $request->validate([
            'estado' => 'required|in:' . implode(',', ContratoEmpleado::ESTADOS),
            'notas'  => 'nullable|string|max:2000',
        ]);

        $contrato->update([...$v, 'aud_usuario' => Auth::user()->email]);

        return response()->json(['success' => true, 'data' => $contrato]);
    }

    /**
     * GET /api/rrhh/contratos/{id}/preview
     * Devuelve el contenido de la plantilla con las variables sustituidas.
     */
    public function preview(int $id): JsonResponse
    {
        $contrato = ContratoEmpleado::with(['tipoContrato', 'plantilla', 'ingreso'])->findOrFail($id);

        if (!$contrato->plantilla) {
            return response()->json([
                'success' => false,
                'message' => 'Este contrato no tiene una plantilla asociada.',
            ], 422);
        }

        $contenido = $this->renderPlantilla($contrato->plantilla->contenido, $contrato);

        return response()->json(['success' => true, 'contenido' => $contenido]);
    }

    /**
     * GET /api/rrhh/contratos/{id}/pdf
     * Genera y descarga el PDF del contrato con variables sustituidas.
     */
    public function pdf(int $id): Response
    {
        $contrato = ContratoEmpleado::with(['tipoContrato', 'plantilla', 'ingreso'])->findOrFail($id);

        abort_unless($contrato->plantilla, 422, 'Este contrato no tiene una plantilla asociada.');

        $contenido = $this->renderPlantilla($contrato->plantilla->contenido, $contrato);

        $pdf = Pdf::setOptions([
            'isRemoteEnabled' => true,
            'defaultFont'     => 'helvetica',
            'dpi'             => 96,
        ])->loadView('pdf.contrato', [
            'contenido'    => $contenido,
            'tipoContrato' => $contrato->tipoContrato?->nombre ?? 'Contrato',
        ])->setPaper('letter', 'portrait');

        $nombre = 'Contrato_' . str_replace(' ', '_', $contrato->empleado_nombre) . "_{$id}.pdf";

        return $pdf->download($nombre);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────

    private function renderPlantilla(string $contenido, ContratoEmpleado $contrato): string
    {
        $vars = $this->buildVars($contrato);
        return str_replace(array_keys($vars), array_values($vars), $contenido);
    }

    private function buildVars(ContratoEmpleado $contrato): array
    {
        $ingreso = $contrato->ingreso;

        // ── Datos desde pgsql ────────────────────────────────────────────────
        $emp = null;
        if ($contrato->empleado_id) {
            $emp = DB::connection('pgsql')
                ->table('empleados as e')
                ->leftJoin('cargos as c',       'c.id', '=', 'e.cargo_id')
                ->leftJoin('sucursales as s',    's.id', '=', 'e.sucursal_id')
                ->leftJoin('departamentos as d', 'd.id', '=', 'e.departamento_id')
                ->where('e.id', $contrato->empleado_id)
                ->select(
                    'e.nombres', 'e.apellidos',
                    'e.salario_base',
                    'c.nombre as cargo',
                    's.nombre as sucursal',
                    'd.nombre as departamento'
                )
                ->first();
        }

        // ── Datos personales desde rrhh ──────────────────────────────────────
        $datosPersonales = null;
        $dui = $isss = $afp = null;

        if ($contrato->empleado_id) {
            $datosPersonales = DB::connection('rrhh')
                ->table('expediente_datos_personales')
                ->where('empleado_id', $contrato->empleado_id)
                ->first();

            $docs = DB::connection('rrhh')
                ->table('expediente_documentos')
                ->where('empleado_id', $contrato->empleado_id)
                ->get()
                ->keyBy(fn($d) => strtolower($d->tipo));

            $dui  = $docs->get('dui');
            $isss = $docs->get('isss');
            $afp  = $docs->get('afp') ?? $docs->get('nup') ?? $docs->get('afp/nup');
        }

        // ── Nombre completo ──────────────────────────────────────────────────
        $nombreCompleto = $contrato->empleado_nombre;
        $nombres    = $emp ? trim($emp->nombres)    : $nombreCompleto;
        $apellidos  = $emp ? trim($emp->apellidos)  : '';

        // ── Cargo / sucursal ─────────────────────────────────────────────────
        $cargoNombre    = $emp?->cargo       ?? $ingreso?->cargo_nombre    ?? '';
        $sucursalNombre = $emp?->sucursal    ?? $ingreso?->sucursal_nombre ?? '';
        $deptNombre     = $emp?->departamento ?? '';

        // ── Salario ──────────────────────────────────────────────────────────
        $salarioBase = (float) ($emp?->salario_base ?? 0);
        $salarioFmt  = '$' . number_format($salarioBase, 2);
        $salarioLetras = $salarioBase > 0 ? $this->dineroEnLetras($salarioBase) : '';

        // ── Documentos ───────────────────────────────────────────────────────
        $duiNum    = $dui?->numero ?? '';
        $duiLugar  = $dui?->lugar_exp_texto ?? '';
        $isssNum   = $isss?->numero ?? '';
        $afpNum    = $afp?->numero ?? '';

        // ── Estado civil / género ────────────────────────────────────────────
        $estadoCivil = match(strtolower($datosPersonales?->estado_civil ?? '')) {
            'soltero', 'soltera' => 'Soltero/a',
            'casado', 'casada'   => 'Casado/a',
            'divorciado', 'divorciada' => 'Divorciado/a',
            'viudo', 'viuda'     => 'Viudo/a',
            'union_libre'        => 'Unión libre',
            default              => $datosPersonales?->estado_civil ?? '',
        };
        $genero = ucfirst(strtolower($datosPersonales?->genero ?? ''));

        // ── Fechas ───────────────────────────────────────────────────────────
        $fechaInicio      = $contrato->fecha_inicio ? Carbon::parse($contrato->fecha_inicio) : null;
        $fechaFin         = $contrato->fecha_fin    ? Carbon::parse($contrato->fecha_fin)    : null;
        $fechaFirma       = Carbon::now();
        $fechaNacimiento  = $datosPersonales?->fecha_nacimiento
            ? Carbon::parse($datosPersonales->fecha_nacimiento)
            : null;

        $fmtDate = fn(?Carbon $d) => $d ? $d->format('d/m/Y') : '';
        $fmtDateLetras = fn(?Carbon $d) => $d
            ? $d->locale('es')->isoFormat('D [de] MMMM [de] YYYY')
            : '';

        // ── Año / ciudad ─────────────────────────────────────────────────────
        $anio       = $fechaFirma->year;
        $ciudadFirma = 'San Salvador';

        return [
            '{{nombre}}'              => $nombreCompleto,
            '{{nombres}}'             => $nombres,
            '{{apellidos}}'           => $apellidos,
            '{{cargo}}'               => $cargoNombre,
            '{{sucursal}}'            => $sucursalNombre,
            '{{departamento}}'        => $deptNombre,
            '{{salario}}'             => $salarioFmt,
            '{{salario_letras}}'      => $salarioLetras,
            '{{dui}}'                 => $duiNum,
            '{{dui_expedido_en}}'     => $duiLugar,
            '{{isss}}'                => $isssNum,
            '{{afp}}'                 => $afpNum,
            '{{estado_civil}}'        => $estadoCivil,
            '{{genero}}'              => $genero,
            '{{fecha_nacimiento}}'    => $fmtDate($fechaNacimiento),
            '{{fecha_inicio}}'        => $fmtDate($fechaInicio),
            '{{fecha_inicio_letras}}' => $fmtDateLetras($fechaInicio),
            '{{fecha_fin}}'           => $fmtDate($fechaFin),
            '{{fecha_fin_letras}}'    => $fmtDateLetras($fechaFin),
            '{{tipo_contrato}}'       => $contrato->tipoContrato?->nombre ?? '',
            '{{fecha_firma}}'         => $fmtDate($fechaFirma),
            '{{fecha_firma_letras}}'  => $fmtDateLetras($fechaFirma),
            '{{anio}}'                => (string) $anio,
            '{{ciudad_firma}}'        => $ciudadFirma,
            '{{patrono}}'             => 'David Arthur Falkenstein Algara',
            '{{nit_patrono}}'         => '0614-120411-105-11',
            '{{funciones}}'           => $contrato->funciones ?? '',
        ];
    }

    /**
     * Convierte un monto en dólares a letras en español.
     * Ej: 408.80 → "cuatrocientos ocho 80/100 dólares"
     */
    private function dineroEnLetras(float $monto): string
    {
        $entero   = (int) $monto;
        $centavos = (int) round(($monto - $entero) * 100);

        return $this->enteroEnLetras($entero) . ' ' . sprintf('%02d', $centavos) . '/100 dólares';
    }

    private function enteroEnLetras(int $n): string
    {
        if ($n === 0) return 'cero';

        $unidades   = ['', 'un', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve',
                        'diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete',
                        'dieciocho', 'diecinueve'];
        $decenas    = ['', '', 'veinte', 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta',
                        'ochenta', 'noventa'];
        $centenas   = ['', 'ciento', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos',
                        'seiscientos', 'setecientos', 'ochocientos', 'novecientos'];

        if ($n < 20)   return $unidades[$n];
        if ($n < 100)  {
            $d = intdiv($n, 10); $u = $n % 10;
            return $decenas[$d] . ($u ? ' y ' . $unidades[$u] : '');
        }
        if ($n === 100) return 'cien';
        if ($n < 1000) {
            $c = intdiv($n, 100); $r = $n % 100;
            return $centenas[$c] . ($r ? ' ' . $this->enteroEnLetras($r) : '');
        }
        if ($n < 2000) {
            $r = $n % 1000;
            return 'mil' . ($r ? ' ' . $this->enteroEnLetras($r) : '');
        }
        if ($n < 1000000) {
            $miles = intdiv($n, 1000); $r = $n % 1000;
            return $this->enteroEnLetras($miles) . ' mil' . ($r ? ' ' . $this->enteroEnLetras($r) : '');
        }

        return (string) $n;
    }
}
