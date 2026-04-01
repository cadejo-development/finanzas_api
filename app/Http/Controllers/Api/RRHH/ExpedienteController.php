<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\Empleado;
use App\Models\RRHH\Amonestacion;
use App\Models\RRHH\CambioSalarial;
use App\Models\RRHH\Desvinculacion;
use App\Models\RRHH\ExpedienteArchivo;
use App\Models\RRHH\ExpedienteContacto;
use App\Models\RRHH\ExpedienteCuentaBanco;
use App\Models\RRHH\ExpedienteDatosPersonales;
use App\Models\RRHH\ExpedienteDireccion;
use App\Models\RRHH\ExpedienteDocumento;
use App\Models\RRHH\ExpedienteEstudio;
use App\Models\RRHH\ExpedienteExperienciaLaboral;
use App\Models\RRHH\ExpedienteIdioma;
use App\Models\RRHH\Incapacidad;
use App\Models\RRHH\Permiso;
use App\Models\RRHH\Traslado;
use App\Models\RRHH\Vacacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ExpedienteController extends RRHHBaseController
{
    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function generarPresignUrl(string $key, string $contentType): JsonResponse
    {
        $client = $this->s3Client();
        $cmd    = $client->getCommand('PutObject', [
            'Bucket'      => config('filesystems.disks.s3.bucket'),
            'Key'         => $key,
            'ContentType' => $contentType,
        ]);
        return response()->json([
            'success'       => true,
            'presigned_url' => (string) $client->createPresignedRequest($cmd, '+15 minutes')->getUri(),
            'key'           => $key,
        ]);
    }

    /** Borra un objeto de S3 sin bloquear en caso de fallo de red. */
    private function s3Delete(string $key): void
    {
        try {
            $this->s3Client()->deleteObject([
                'Bucket' => config('filesystems.disks.s3.bucket'),
                'Key'    => $key,
            ]);
        } catch (\Throwable) {
            // No bloquear el proceso si S3 no responde
        }
    }

    /**
     * Verifica que el usuario autenticado puede acceder al expediente del empleado dado.
     * Admin puede ver a todos; jefatura solo a sí mismo y sus subordinados.
     */
    private function autorizarAcceso(int $empleadoId): void
    {
        if ($this->esAdminRrhh()) return;

        $jefeEmpleado = $this->getJefeEmpleado();
        if (!$jefeEmpleado) abort(403, 'Sin acceso.');

        // Puede ver su propio expediente
        if ($jefeEmpleado->id === $empleadoId) return;

        // O el de sus subordinados
        if (!$this->esSubordinado($empleadoId)) {
            abort(403, 'No tiene permiso para ver este expediente.');
        }
    }

    /**
     * Resuelve datos del empleado desde core DB y construye la cabecera del expediente.
     */
    private function buildCabecera(int $empleadoId): array
    {
        $empleado = Empleado::with(['cargo', 'sucursal', 'departamento'])
            ->findOrFail($empleadoId);

        $fotoRuta = null;
        $fotoPerfil = ExpedienteArchivo::where('empleado_id', $empleadoId)
            ->where('tipo', 'foto_perfil')
            ->latest()
            ->first();
        if ($fotoPerfil) {
            // Presign local (sin llamada de red) para que <img src> cargue directamente desde S3
            $fotoRuta = $this->s3TemporaryUrl($fotoPerfil->archivo_ruta);
        }

        // Seniority
        $anios = null;
        if ($empleado->fecha_ingreso) {
            $anios = (int) now()->diffInYears($empleado->fecha_ingreso);
        }

        return [
            'id'                => $empleado->id,
            'codigo'            => $empleado->codigo,
            'nombres'           => $empleado->nombres,
            'apellidos'         => $empleado->apellidos,
            'nombre_completo'   => trim("{$empleado->nombres} {$empleado->apellidos}"),
            'email'             => $empleado->email,
            'cargo'             => $empleado->cargo?->nombre,
            'sucursal'          => $empleado->sucursal?->nombre,
            'departamento'      => $empleado->departamento?->nombre,
            'fecha_ingreso'     => $empleado->fecha_ingreso?->toDateString(),
            'anios_servicio'    => $anios,
            'activo'            => $empleado->activo,
            'foto_url'          => $fotoRuta,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/rrhh/expediente/{empleadoId}
    // Retorna el expediente completo del empleado.
    // ─────────────────────────────────────────────────────────────────────────
    public function show(int $empleadoId): JsonResponse
    {
        $this->autorizarAcceso($empleadoId);

        $cabecera        = $this->buildCabecera($empleadoId);
        $datosPersonales = ExpedienteDatosPersonales::where('empleado_id', $empleadoId)->first();
        $contactos       = ExpedienteContacto::where('empleado_id', $empleadoId)->orderBy('orden')->orderBy('id')->get();
        $direcciones     = ExpedienteDireccion::where('empleado_id', $empleadoId)->get();
        $documentos      = ExpedienteDocumento::where('empleado_id', $empleadoId)->get();
        $estudios       = ExpedienteEstudio::where('empleado_id', $empleadoId)->orderByDesc('anio_graduacion')->get()
            ->map(fn ($e) => array_merge($e->toArray(), [
                'atestado_url' => $e->atestado_ruta
                    ? $this->s3TemporaryUrl($e->atestado_ruta)
                    : null,
            ]));
        $idiomas        = ExpedienteIdioma::where('empleado_id', $empleadoId)->get()
            ->map(fn ($i) => array_merge($i->toArray(), [
                'atestado_url' => $i->atestado_ruta
                    ? $this->s3TemporaryUrl($i->atestado_ruta)
                    : null,
            ]));
        $experiencia    = ExpedienteExperienciaLaboral::where('empleado_id', $empleadoId)
            ->orderByRaw('es_actual DESC, fecha_inicio DESC NULLS LAST')
            ->get();
        $documentos     = ExpedienteDocumento::where('empleado_id', $empleadoId)->get()
            ->map(fn ($d) => array_merge($d->toArray(), [
                'foto_frente_url' => $d->foto_frente_ruta
                    ? $this->s3TemporaryUrl($d->foto_frente_ruta)
                    : null,
                'foto_reverso_url' => $d->foto_reverso_ruta
                    ? $this->s3TemporaryUrl($d->foto_reverso_ruta)
                    : null,
            ]));
        $cuentasBanco   = ExpedienteCuentaBanco::where('empleado_id', $empleadoId)->orderByDesc('es_principal')->orderBy('id')->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'cabecera'         => $cabecera,
                'datos_personales' => $datosPersonales,
                'contactos'        => $contactos,
                'direcciones'      => $direcciones,
                'documentos'       => $documentos,
                'estudios'         => $estudios,
                'idiomas'          => $idiomas,
                'experiencia'      => $experiencia,
                'cuentas_banco'    => $cuentasBanco,
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/rrhh/expediente/{empleadoId}/acciones
    // Historial de acciones de personal del empleado, agrupadas por tipo.
    // ─────────────────────────────────────────────────────────────────────────
    public function acciones(int $empleadoId): JsonResponse
    {
        $this->autorizarAcceso($empleadoId);

        // Enriquecer un registro con datos del empleado procesador
        $enrichProcesador = function ($record) {
            $procesadorId = $record['procesado_por_id'] ?? $record['jefe_id'] ?? $record['registrado_por_id'] ?? $record['solicitado_por_id'] ?? null;
            if ($procesadorId) {
                $emp = Empleado::find($procesadorId);
                $record['procesado_por_nombre'] = $emp ? trim("{$emp->nombres} {$emp->apellidos}") : null;
            }
            return $record;
        };

        $permisos     = Permiso::with('tipoPermiso')->where('empleado_id', $empleadoId)->orderByDesc('fecha')->get()->map(fn ($p) => $enrichProcesador(array_merge($p->toArray(), ['_tipo_accion' => 'permiso'])));
        $vacaciones   = Vacacion::where('empleado_id', $empleadoId)->orderByDesc('fecha_inicio')->get()->map(fn ($v) => $enrichProcesador(array_merge($v->toArray(), ['_tipo_accion' => 'vacacion'])));
        $incapacidades = Incapacidad::with('tipoIncapacidad')->where('empleado_id', $empleadoId)->orderByDesc('fecha_inicio')->get()->map(fn ($i) => $enrichProcesador(array_merge($i->toArray(), ['_tipo_accion' => 'incapacidad'])));
        $amonestaciones = Amonestacion::with('tipoFalta')->where('empleado_id', $empleadoId)->orderByDesc('fecha_amonestacion')->get()->map(fn ($a) => $enrichProcesador(array_merge($a->toArray(), ['_tipo_accion' => 'amonestacion'])));
        $traslados    = Traslado::where('empleado_id', $empleadoId)->orderByDesc('fecha_efectiva')->get()->map(fn ($t) => $enrichProcesador(array_merge($t->toArray(), ['_tipo_accion' => 'traslado'])));
        $cambiosSalariales = CambioSalarial::with('tipoAumento')->where('empleado_id', $empleadoId)->orderByDesc('fecha_efectiva')->get()->map(fn ($c) => $enrichProcesador(array_merge($c->toArray(), ['_tipo_accion' => 'cambio_salarial'])));
        $desvinculaciones  = Desvinculacion::with('motivo')->where('empleado_id', $empleadoId)->orderByDesc('fecha_efectiva')->get()->map(fn ($d) => $enrichProcesador(array_merge($d->toArray(), ['_tipo_accion' => 'desvinculacion'])));

        return response()->json([
            'success' => true,
            'data'    => [
                'permisos'          => $permisos,
                'vacaciones'        => $vacaciones,
                'incapacidades'     => $incapacidades,
                'amonestaciones'    => $amonestaciones,
                'traslados'         => $traslados,
                'cambios_salariales'=> $cambiosSalariales,
                'desvinculaciones'  => $desvinculaciones,
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUT /api/rrhh/expediente/{empleadoId}/datos-personales
    // ─────────────────────────────────────────────────────────────────────────
    public function upsertDatosPersonales(Request $request, int $empleadoId): JsonResponse
    {
        $this->autorizarAcceso($empleadoId);

        $data = $request->validate([
            'fecha_nacimiento' => 'nullable|date',
            'genero'           => 'nullable|in:masculino,femenino,otro',
            'estado_civil'     => 'nullable|in:soltero,casado,divorciado,viudo,union_libre',
            'nacionalidad'     => 'nullable|string|max:60',
            'grupo_sanguineo'  => 'nullable|in:A+,A-,B+,B-,AB+,AB-,O+,O-',
            'lugar_nacimiento' => 'nullable|string|max:150',
            'notas'            => 'nullable|string|max:2000',
        ]);

        $data['aud_usuario'] = $request->user()?->email;

        $registro = ExpedienteDatosPersonales::updateOrCreate(
            ['empleado_id' => $empleadoId],
            $data
        );

        return response()->json(['success' => true, 'data' => $registro]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Contactos  POST / PUT /{id} / DELETE /{id}
    // ─────────────────────────────────────────────────────────────────────────
    public function storeContacto(Request $request, int $empleadoId): JsonResponse
    {
        $this->autorizarAcceso($empleadoId);

        $data = $request->validate([
            'tipo'            => 'required|in:telefono,email,whatsapp,emergencia',
            'etiqueta'        => 'nullable|string|max:60',
            'valor'           => 'required|string|max:150',
            'nombre_contacto' => 'nullable|string|max:120',
            'es_emergencia'   => 'boolean',
            'orden'           => 'integer|min:0',
        ]);

        $contacto = ExpedienteContacto::create(array_merge($data, ['empleado_id' => $empleadoId]));

        return response()->json(['success' => true, 'data' => $contacto], 201);
    }

    public function updateContacto(Request $request, int $empleadoId, int $contactoId): JsonResponse
    {
        $this->autorizarAcceso($empleadoId);

        $contacto = ExpedienteContacto::where('empleado_id', $empleadoId)->findOrFail($contactoId);

        $data = $request->validate([
            'tipo'            => 'sometimes|in:telefono,email,whatsapp,emergencia',
            'etiqueta'        => 'nullable|string|max:60',
            'valor'           => 'sometimes|string|max:150',
            'nombre_contacto' => 'nullable|string|max:120',
            'es_emergencia'   => 'boolean',
            'orden'           => 'integer|min:0',
        ]);

        $contacto->update($data);

        return response()->json(['success' => true, 'data' => $contacto]);
    }

    public function destroyContacto(int $empleadoId, int $contactoId): JsonResponse
    {
        $this->autorizarAcceso($empleadoId);
        ExpedienteContacto::where('empleado_id', $empleadoId)->findOrFail($contactoId)->delete();
        return response()->json(['success' => true, 'message' => 'Contacto eliminado.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Direcciones  POST / PUT /{id} / DELETE /{id}
    // ─────────────────────────────────────────────────────────────────────────
    public function storeDireccion(Request $request, int $empleadoId): JsonResponse
    {
        $this->autorizarAcceso($empleadoId);

        $data = $request->validate([
            'tipo'            => 'required|in:residencia,trabajo',
            'departamento_id' => 'nullable|integer',
            'distrito_id'     => 'nullable|integer',
            'municipio_id'    => 'nullable|integer',
            'departamento_geo'=> 'nullable|string|max:80',
            'municipio'       => 'nullable|string|max:80',
            'direccion'       => 'required|string|max:255',
            'referencia'      => 'nullable|string|max:255',
            'es_principal'    => 'boolean',
            'latitud'         => 'nullable|numeric|between:-90,90',
            'longitud'        => 'nullable|numeric|between:-180,180',
        ]);

        $direccion = ExpedienteDireccion::create(array_merge($data, ['empleado_id' => $empleadoId]));

        return response()->json(['success' => true, 'data' => $direccion], 201);
    }

    public function updateDireccion(Request $request, int $empleadoId, int $dirId): JsonResponse
    {
        $this->autorizarAcceso($empleadoId);

        $dir = ExpedienteDireccion::where('empleado_id', $empleadoId)->findOrFail($dirId);

        $data = $request->validate([
            'tipo'            => 'sometimes|in:residencia,trabajo',
            'departamento_id' => 'nullable|integer',
            'distrito_id'     => 'nullable|integer',
            'municipio_id'    => 'nullable|integer',
            'departamento_geo'=> 'nullable|string|max:80',
            'municipio'       => 'nullable|string|max:80',
            'direccion'       => 'sometimes|string|max:255',
            'referencia'      => 'nullable|string|max:255',
            'es_principal'    => 'boolean',
            'latitud'         => 'nullable|numeric|between:-90,90',
            'longitud'        => 'nullable|numeric|between:-180,180',
        ]);

        $dir->update($data);

        return response()->json(['success' => true, 'data' => $dir]);
    }

    public function destroyDireccion(int $empleadoId, int $dirId): JsonResponse
    {
        $this->autorizarAcceso($empleadoId);
        ExpedienteDireccion::where('empleado_id', $empleadoId)->findOrFail($dirId)->delete();
        return response()->json(['success' => true, 'message' => 'Dirección eliminada.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Documentos  POST / PUT /{id} / DELETE /{id}
    // ─────────────────────────────────────────────────────────────────────────
    public function storeDocumento(Request $request, int $empleadoId): JsonResponse
    {
        $this->autorizarAcceso($empleadoId);

        $data = $request->validate([
            'tipo'                  => 'required|in:dui,nit,isss,afp,pasaporte,licencia_conducir,otro',
            'numero'                => 'nullable|string|max:60',
            'fecha_emision'         => 'nullable|date',
            'fecha_vencimiento'     => 'nullable|date',
            'lugar_exp_municipio_id'=> 'nullable|integer',
            'lugar_exp_texto'       => 'nullable|string|max:200',
        ]);

        $doc = ExpedienteDocumento::create(array_merge($data, ['empleado_id' => $empleadoId]));

        return response()->json(['success' => true, 'data' => $doc], 201);
    }

    public function updateDocumento(Request $request, int $empleadoId, int $docId): JsonResponse
    {
        $this->autorizarAcceso($empleadoId);

        $doc = ExpedienteDocumento::where('empleado_id', $empleadoId)->findOrFail($docId);

        $data = $request->validate([
            'tipo'                  => 'sometimes|in:dui,nit,isss,afp,pasaporte,licencia_conducir,otro',
            'numero'                => 'nullable|string|max:60',
            'fecha_emision'         => 'nullable|date',
            'fecha_vencimiento'     => 'nullable|date',
            'lugar_exp_municipio_id'=> 'nullable|integer',
            'lugar_exp_texto'       => 'nullable|string|max:200',
        ]);

        $doc->update($data);

        return response()->json(['success' => true, 'data' => $doc]);
    }

    public function destroyDocumento(int $empleadoId, int $docId): JsonResponse
    {
        $this->autorizarAcceso($empleadoId);
        ExpedienteDocumento::where('empleado_id', $empleadoId)->findOrFail($docId)->delete();
        return response()->json(['success' => true, 'message' => 'Documento eliminado.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Estudios  POST / PUT /{id} / DELETE /{id}
    // ─────────────────────────────────────────────────────────────────────────
    public function storeEstudio(Request $request, int $empleadoId): JsonResponse
    {
        $this->autorizarAcceso($empleadoId);

        $data = $request->validate([
            'nivel'            => 'required|in:primaria,bachillerato,tecnico,universitario,posgrado,maestria,doctorado,diplomado,curso,otro',
            'especializacion'  => 'nullable|string|max:60',
            'titulo'           => 'required|string|max:200',
            'institucion'      => 'required|string|max:200',
            'pais'             => 'nullable|string|max:80',
            'anio_inicio'      => 'nullable|integer|min:1950|max:2099',
            'anio_graduacion'  => 'nullable|integer|min:1950|max:2099',
            'graduado'         => 'boolean',
            'notas'            => 'nullable|string|max:255',
        ]);

        $estudio = ExpedienteEstudio::create(array_merge($data, ['empleado_id' => $empleadoId]));

        return response()->json(['success' => true, 'data' => $estudio], 201);
    }

    public function updateEstudio(Request $request, int $empleadoId, int $estudioId): JsonResponse
    {
        $this->autorizarAcceso($empleadoId);

        $estudio = ExpedienteEstudio::where('empleado_id', $empleadoId)->findOrFail($estudioId);

        $data = $request->validate([
            'nivel'            => 'sometimes|in:primaria,bachillerato,tecnico,universitario,posgrado,maestria,doctorado,diplomado,curso,otro',
            'especializacion'  => 'nullable|string|max:60',
            'titulo'           => 'sometimes|string|max:200',
            'institucion'      => 'sometimes|string|max:200',
            'pais'             => 'nullable|string|max:80',
            'anio_inicio'      => 'nullable|integer|min:1950|max:2099',
            'anio_graduacion'  => 'nullable|integer|min:1950|max:2099',
            'graduado'         => 'boolean',
            'notas'            => 'nullable|string|max:255',
        ]);

        $estudio->update($data);

        return response()->json(['success' => true, 'data' => $estudio]);
    }

    public function destroyEstudio(int $empleadoId, int $estudioId): JsonResponse
    {
        $this->autorizarAcceso($empleadoId);
        $estudio = ExpedienteEstudio::where('empleado_id', $empleadoId)->findOrFail($estudioId);
        if ($estudio->atestado_ruta) $this->s3Delete($estudio->atestado_ruta);
        $estudio->delete();
        return response()->json(['success' => true, 'message' => 'Estudio eliminado.']);
    }

    public function presignAtestadoEstudio(Request $request, int $empleadoId, int $estudioId): JsonResponse
    {
        $this->autorizarAcceso($empleadoId);
        $ext  = strtolower($request->query('ext', 'pdf'));
        $mime = $request->query('mime', 'application/pdf');
        $key  = "rrhh/expediente_empleado/atestados/{$empleadoId}_" . uniqid('est_', true) . ".{$ext}";
        return $this->generarPresignUrl($key, $mime);
    }

    public function subirAtestadoEstudio(Request $request, int $empleadoId, int $estudioId): JsonResponse
    {
        $this->autorizarAcceso($empleadoId);
        $data = $request->validate(['key' => 'required|string', 'mime' => 'nullable|string']);
        $estudio = ExpedienteEstudio::where('empleado_id', $empleadoId)->findOrFail($estudioId);
        if ($estudio->atestado_ruta) $this->s3Delete($estudio->atestado_ruta);
        $estudio->update(['atestado_ruta' => $data['key'], 'atestado_mime' => $data['mime'] ?? null]);
        return response()->json([
            'success'       => true,
            'atestado_url'  => $this->s3TemporaryUrl($data['key']),
            'atestado_mime' => $data['mime'] ?? null,
        ]);
    }

    public function verAtestadoEstudio(int $empleadoId, int $estudioId)
    {
        $this->autorizarAcceso($empleadoId);
        $estudio = ExpedienteEstudio::where('empleado_id', $empleadoId)->findOrFail($estudioId);
        if (!$estudio->atestado_ruta) abort(404);
        return redirect($this->s3TemporaryUrl($estudio->atestado_ruta));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Archivos  POST (upload) / DELETE /{id} / GET /{id}/descargar
    // ─────────────────────────────────────────────────────────────────────────
    public function presignArchivo(Request $request, int $empleadoId): JsonResponse
    {
        $this->autorizarAcceso($empleadoId);
        $tipo    = $request->query('tipo', 'foto_perfil');
        $ext     = strtolower($request->query('ext', 'jpg'));
        $mime    = $request->query('mime', 'image/jpeg');
        $carpeta = $tipo === 'foto_perfil' ? 'foto_perfil' : 'atestados';
        $key     = "rrhh/expediente_empleado/{$carpeta}/{$empleadoId}_" . uniqid('', true) . ".{$ext}";
        return $this->generarPresignUrl($key, $mime);
    }

    public function uploadArchivo(Request $request, int $empleadoId): JsonResponse
    {
        $this->autorizarAcceso($empleadoId);

        $data = $request->validate([
            'key'         => 'required|string',
            'tipo'        => 'required|in:foto_perfil,contrato,atestado,certificado,evaluacion,documento_identidad,otro',
            'nombre'      => 'nullable|string|max:200',
            'descripcion' => 'nullable|string|max:255',
            'mime_type'   => 'nullable|string|max:100',
            'tamano_kb'   => 'nullable|integer',
        ]);

        $tipo = $data['tipo'];

        // Si es foto de perfil, eliminar la anterior
        if ($tipo === 'foto_perfil') {
            ExpedienteArchivo::where('empleado_id', $empleadoId)
                ->where('tipo', 'foto_perfil')
                ->get()
                ->each(function ($a) {
                    $this->s3Delete($a->archivo_ruta);
                    $a->delete();
                });
        }

        $archivo = ExpedienteArchivo::create([
            'empleado_id'   => $empleadoId,
            'tipo'          => $tipo,
            'nombre'        => $data['nombre'] ?? basename($data['key']),
            'descripcion'   => $data['descripcion'] ?? null,
            'archivo_ruta'  => $data['key'],
            'mime_type'     => $data['mime_type'] ?? null,
            'tamano_kb'     => $data['tamano_kb'] ?? null,
            'subido_por_id' => $request->user()?->id,
        ]);

        return response()->json([
            'success' => true,
            'data'    => array_merge($archivo->toArray(), [
                'url' => $this->s3TemporaryUrl($archivo->archivo_ruta),
            ]),
        ], 201);
    }

    public function descargarArchivo(int $empleadoId, int $archivoId)
    {
        $this->autorizarAcceso($empleadoId);

        $archivo = ExpedienteArchivo::where('empleado_id', $empleadoId)->findOrFail($archivoId);

        return redirect($this->s3TemporaryUrl($archivo->archivo_ruta));
    }

    public function destroyArchivo(int $empleadoId, int $archivoId): JsonResponse
    {
        $this->autorizarAcceso($empleadoId);

        $archivo = ExpedienteArchivo::where('empleado_id', $empleadoId)->findOrFail($archivoId);
        $this->s3Delete($archivo->archivo_ruta);
        $archivo->delete();

        return response()->json(['success' => true, 'message' => 'Archivo eliminado.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fotos de documentos  PATCH /{docId}/foto/{campo}  GET /{docId}/foto/{campo}
    // ─────────────────────────────────────────────────────────────────────────
    public function presignFotoDocumento(Request $request, int $empleadoId, int $docId, string $campo): JsonResponse
    {
        $this->autorizarAcceso($empleadoId);
        if (!in_array($campo, ['frente', 'reverso'])) abort(422, 'Campo inválido.');
        $ext  = strtolower($request->query('ext', 'jpg'));
        $mime = $request->query('mime', 'image/jpeg');
        $key  = "rrhh/expediente_empleado/documentos_personales/{$empleadoId}_" . uniqid('doc_', true) . "_{$campo}.{$ext}";
        return $this->generarPresignUrl($key, $mime);
    }

    public function subirFotoDocumento(Request $request, int $empleadoId, int $docId, string $campo)
    {
        $this->autorizarAcceso($empleadoId);
        if (!in_array($campo, ['frente', 'reverso'])) abort(422, 'Campo inválido.');
        $data    = $request->validate(['key' => 'required|string']);
        $doc     = ExpedienteDocumento::where('empleado_id', $empleadoId)->findOrFail($docId);
        $columna = "foto_{$campo}_ruta";
        if ($doc->$columna) $this->s3Delete($doc->$columna);
        $doc->update([$columna => $data['key']]);
        return response()->json([
            'success' => true,
            'url'     => $this->s3TemporaryUrl($data['key']),
        ]);
    }

    public function verFotoDocumento(int $empleadoId, int $docId, string $campo)
    {
        $this->autorizarAcceso($empleadoId);
        if (!in_array($campo, ['frente', 'reverso'])) abort(422);

        $doc  = ExpedienteDocumento::where('empleado_id', $empleadoId)->findOrFail($docId);
        $ruta = $doc->{"foto_{$campo}_ruta"};
        if (!$ruta) abort(404);

        return redirect($this->s3TemporaryUrl($ruta));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Idiomas  POST / PUT /{id} / DELETE /{id} / GET /{id}/atestado
    // ─────────────────────────────────────────────────────────────────────────
    public function storeIdioma(Request $request, int $empleadoId): JsonResponse
    {
        $this->autorizarAcceso($empleadoId);

        $data = $request->validate([
            'idioma'          => 'required|string|max:80',
            'nivel_habla'     => 'integer|min:0|max:100',
            'nivel_escucha'   => 'integer|min:0|max:100',
            'nivel_lectura'   => 'integer|min:0|max:100',
            'nivel_escritura' => 'integer|min:0|max:100',
            'notas'           => 'nullable|string|max:500',
        ]);

        $idioma = ExpedienteIdioma::create(array_merge($data, ['empleado_id' => $empleadoId]));

        return response()->json(['success' => true, 'data' => $idioma], 201);
    }

    public function updateIdioma(Request $request, int $empleadoId, int $idiomaId): JsonResponse
    {
        $this->autorizarAcceso($empleadoId);
        $idioma = ExpedienteIdioma::where('empleado_id', $empleadoId)->findOrFail($idiomaId);

        $data = $request->validate([
            'idioma'          => 'sometimes|string|max:80',
            'nivel_habla'     => 'integer|min:0|max:100',
            'nivel_escucha'   => 'integer|min:0|max:100',
            'nivel_lectura'   => 'integer|min:0|max:100',
            'nivel_escritura' => 'integer|min:0|max:100',
            'notas'           => 'nullable|string|max:500',
        ]);

        $idioma->update($data);

        return response()->json(['success' => true, 'data' => $idioma]);
    }

    public function destroyIdioma(int $empleadoId, int $idiomaId): JsonResponse
    {
        $this->autorizarAcceso($empleadoId);
        $idioma = ExpedienteIdioma::where('empleado_id', $empleadoId)->findOrFail($idiomaId);
        if ($idioma->atestado_ruta) $this->s3Delete($idioma->atestado_ruta);
        $idioma->delete();
        return response()->json(['success' => true]);
    }

    public function presignAtestadoIdioma(Request $request, int $empleadoId, int $idiomaId): JsonResponse
    {
        $this->autorizarAcceso($empleadoId);
        $ext  = strtolower($request->query('ext', 'pdf'));
        $mime = $request->query('mime', 'application/pdf');
        $key  = "rrhh/expediente_empleado/atestados/{$empleadoId}_" . uniqid('idi_', true) . ".{$ext}";
        return $this->generarPresignUrl($key, $mime);
    }

    public function subirAtestadoIdioma(Request $request, int $empleadoId, int $idiomaId): JsonResponse
    {
        $this->autorizarAcceso($empleadoId);
        $data   = $request->validate(['key' => 'required|string', 'mime' => 'nullable|string']);
        $idioma = ExpedienteIdioma::where('empleado_id', $empleadoId)->findOrFail($idiomaId);
        $path   = $data['key'];

        if ($idioma->atestado_ruta) $this->s3Delete($idioma->atestado_ruta);
        $idioma->update(['atestado_ruta' => $path]);

        return response()->json([
            'success'      => true,
            'atestado_url' => $this->s3TemporaryUrl($path),
        ]);
    }

    public function verAtestadoIdioma(int $empleadoId, int $idiomaId)
    {
        $this->autorizarAcceso($empleadoId);
        $idioma = ExpedienteIdioma::where('empleado_id', $empleadoId)->findOrFail($idiomaId);
        if (!$idioma->atestado_ruta) abort(404);
        return redirect($this->s3TemporaryUrl($idioma->atestado_ruta));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Experiencia Laboral  POST / PUT /{id} / DELETE /{id}
    // ─────────────────────────────────────────────────────────────────────────
    public function storeExperiencia(Request $request, int $empleadoId): JsonResponse
    {
        $this->autorizarAcceso($empleadoId);

        $data = $request->validate([
            'empresa'     => 'required|string|max:200',
            'cargo'       => 'nullable|string|max:200',
            'fecha_inicio'=> 'nullable|date',
            'fecha_fin'   => 'nullable|date|after_or_equal:fecha_inicio',
            'es_actual'   => 'boolean',
            'descripcion' => 'nullable|string|max:2000',
            'pais'        => 'nullable|string|max:80',
        ]);

        if ($data['es_actual'] ?? false) $data['fecha_fin'] = null;

        $exp = ExpedienteExperienciaLaboral::create(array_merge($data, ['empleado_id' => $empleadoId]));

        return response()->json(['success' => true, 'data' => $exp], 201);
    }

    public function updateExperiencia(Request $request, int $empleadoId, int $expId): JsonResponse
    {
        $this->autorizarAcceso($empleadoId);
        $exp = ExpedienteExperienciaLaboral::where('empleado_id', $empleadoId)->findOrFail($expId);

        $data = $request->validate([
            'empresa'     => 'sometimes|string|max:200',
            'cargo'       => 'nullable|string|max:200',
            'fecha_inicio'=> 'nullable|date',
            'fecha_fin'   => 'nullable|date',
            'es_actual'   => 'boolean',
            'descripcion' => 'nullable|string|max:2000',
            'pais'        => 'nullable|string|max:80',
        ]);

        if ($data['es_actual'] ?? false) $data['fecha_fin'] = null;
        $exp->update($data);

        return response()->json(['success' => true, 'data' => $exp]);
    }

    public function destroyExperiencia(int $empleadoId, int $expId): JsonResponse
    {
        $this->autorizarAcceso($empleadoId);
        ExpedienteExperienciaLaboral::where('empleado_id', $empleadoId)->findOrFail($expId)->delete();
        return response()->json(['success' => true]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Cuentas Bancarias  POST / PUT /{id} / DELETE /{id}
    // ─────────────────────────────────────────────────────────────────────────
    public function storeCuentaBanco(Request $request, int $empleadoId): JsonResponse
    {
        $this->autorizarAcceso($empleadoId);

        $data = $request->validate([
            'banco'         => 'required|string|max:100',
            'tipo_cuenta'   => 'required|in:ahorros,corriente',
            'numero_cuenta' => 'required|string|max:40',
            'titular'       => 'nullable|string|max:120',
            'es_principal'  => 'boolean',
        ]);

        if (!empty($data['es_principal'])) {
            ExpedienteCuentaBanco::where('empleado_id', $empleadoId)->update(['es_principal' => false]);
        }

        $cuenta = ExpedienteCuentaBanco::create(array_merge($data, [
            'empleado_id' => $empleadoId,
            'aud_usuario' => $request->user()?->email,
        ]));

        return response()->json(['success' => true, 'data' => $cuenta], 201);
    }

    public function updateCuentaBanco(Request $request, int $empleadoId, int $cuentaId): JsonResponse
    {
        $this->autorizarAcceso($empleadoId);

        $cuenta = ExpedienteCuentaBanco::where('empleado_id', $empleadoId)->findOrFail($cuentaId);

        $data = $request->validate([
            'banco'         => 'sometimes|string|max:100',
            'tipo_cuenta'   => 'sometimes|in:ahorros,corriente',
            'numero_cuenta' => 'sometimes|string|max:40',
            'titular'       => 'nullable|string|max:120',
            'es_principal'  => 'boolean',
        ]);

        if (!empty($data['es_principal'])) {
            ExpedienteCuentaBanco::where('empleado_id', $empleadoId)
                ->where('id', '!=', $cuentaId)
                ->update(['es_principal' => false]);
        }

        $cuenta->update(array_merge($data, ['aud_usuario' => $request->user()?->email]));

        return response()->json(['success' => true, 'data' => $cuenta]);
    }

    public function destroyCuentaBanco(int $empleadoId, int $cuentaId): JsonResponse
    {
        $this->autorizarAcceso($empleadoId);
        ExpedienteCuentaBanco::where('empleado_id', $empleadoId)->findOrFail($cuentaId)->delete();
        return response()->json(['success' => true, 'message' => 'Cuenta eliminada.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PATCH /api/rrhh/expediente/{empleadoId}/fecha-ingreso
    // Actualiza la fecha de ingreso del empleado en la DB core (pgsql).
    // ─────────────────────────────────────────────────────────────────────────
    public function updateFechaIngreso(Request $request, int $empleadoId): JsonResponse
    {
        $this->autorizarAcceso($empleadoId);

        $data = $request->validate([
            'fecha_ingreso' => 'required|date',
        ]);

        \DB::connection('pgsql')
            ->table('empleados')
            ->where('id', $empleadoId)
            ->update(['fecha_ingreso' => $data['fecha_ingreso']]);

        return response()->json(['success' => true, 'fecha_ingreso' => $data['fecha_ingreso']]);
    }
}
