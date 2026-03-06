<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSolicitudPagoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Aquí puedes agregar lógica de autorización si es necesario
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'codigo' => 'sometimes|required|string|max:20|unique:pagos.solicitudes_pago,codigo,' . $this->route('solicitudes_pago'),
            'fecha_solicitud' => 'sometimes|required|date|after_or_equal:today',
            'fecha_pago' => 'sometimes|required|date|after_or_equal:today',
            'forma_pago_id' => 'sometimes|required|exists:pagos.formas_pago,id',
            'proveedor_id' => 'sometimes|required|exists:pagos.proveedores,id',
            'contribuyente_id' => 'sometimes|required|exists:pagos.contribuyentes,id',
            'personeria' => 'sometimes|required|in:natural,juridica',
            'es_servicio' => 'sometimes|required|boolean',
            'tipo_gasto' => 'sometimes|required|string|max:50',
            'estado' => 'sometimes|required|in:BORRADOR,PENDIENTE,APROBADO,RECHAZADO,PAGADO',
            'nivel_aprobacion' => 'nullable|integer|min:1',
            'aprobador_asignado' => 'nullable|string|max:100',
            'aud_usuario' => 'sometimes|required|string|max:50',
            'detalles' => 'sometimes|required|array|min:1',
            'detalles.*.concepto' => 'sometimes|required|string|max:255',
            'detalles.*.centro_costo_codigo' => 'sometimes|nullable|string|max:20',
            'detalles.*.etiqueta_codigo' => 'sometimes|nullable|string|max:5',
            'detalles.*.cantidad' => 'sometimes|required|numeric|min:0.01',
            'detalles.*.precio_unitario' => 'sometimes|required|numeric|min:0',
            // Si viene detalles.*.subtotal, solo validar que sea numérico, pero no usarlo para cálculo
            'detalles.*.subtotal' => 'sometimes|numeric|min:0',
        ];
    }
}
