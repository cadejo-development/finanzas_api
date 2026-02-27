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
            'proveedor_id' => 'required',
            'contribuyente_id' => 'required',
            'forma_pago_id' => 'required',
            'personeria' => 'required',
            'es_servicio' => 'required',
            'fecha_solicitud' => 'required|date',
            'fecha_pago' => 'required|date',
            'tipo_gasto' => 'required|string|max:50',
            'estado' => 'sometimes|string',
            'detalles' => 'required|array|min:1',
            'detalles.*.concepto' => 'required|string|max:255',
            'detalles.*.cantidad' => 'required|numeric|min:0.01',
            'detalles.*.precio_unitario' => 'required|numeric|min:0',
        ];
    }
}
