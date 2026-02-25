<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSolicitudPagoRequest extends FormRequest
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
            'codigo' => 'required|string|max:20|unique:pagos.solicitudes_pago,codigo',
            'fecha_solicitud' => 'required|date|after_or_equal:today',
            'fecha_pago' => 'required|date|after_or_equal:today',
            'forma_pago_id' => 'required|exists:pagos.formas_pago,id',
            'proveedor_id' => 'required|exists:pagos.proveedores,id',
            'contribuyente_id' => 'required|exists:pagos.contribuyentes,id',
            'personeria' => 'required|in:natural,juridica',
            'es_servicio' => 'required|boolean',
            'tipo_gasto' => 'required|string|max:50',
            'estado' => 'required|in:BORRADOR,PENDIENTE,APROBADO,RECHAZADO,PAGADO',
            'nivel_aprobacion' => 'nullable|integer|min:1',
            'aprobador_asignado' => 'nullable|string|max:100',
            'aud_usuario' => 'required|string|max:50',
            'detalles' => 'required|array|min:1',
            'detalles.*.concepto' => 'required|string|max:255',
            'detalles.*.centro_costo_id' => 'required|exists:compras.centros_costo,id',
            'detalles.*.cantidad' => 'required|numeric|min:0.01',
            'detalles.*.precio_unitario' => 'required|numeric|min:0',
            // Si viene detalles.*.subtotal, solo validar que sea numérico, pero no usarlo para cálculo
            'detalles.*.subtotal' => 'sometimes|numeric|min:0',
        ];
    }
}
