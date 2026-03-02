<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SolicitudPagoAprobacion extends Model
{
    protected $connection = 'pagos';
    protected $table = 'solicitud_pago_aprobaciones';

    protected $fillable = [
        'solicitud_pago_id',
        'nivel_orden',
        'nivel_codigo',
        'rol_requerido',
        'aprobador_id',
        'aprobador_nombre',
        'estado',
        'comentario',
        'aprobado_en',
        'aud_usuario',
    ];

    protected $casts = [
        'aprobado_en' => 'datetime',
        'nivel_orden' => 'integer',
    ];

    // ─── Relaciones ───────────────────────────────────────────────────────────

    public function solicitudPago(): BelongsTo
    {
        return $this->belongsTo(SolicitudPago::class, 'solicitud_pago_id');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    public function isPendiente(): bool
    {
        return $this->estado === 'pendiente';
    }

    public function isAprobado(): bool
    {
        return $this->estado === 'aprobado';
    }

    public function isRechazado(): bool
    {
        return $this->estado === 'rechazado';
    }
}
