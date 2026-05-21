<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\ValidationRule;

class StoreAssignmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->hasAnyRole(['Admin', 'GA']);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'request_id' => 'required|integer|exists:requests,id',
            'vehicle_id' => 'required|integer|exists:vehicles,id',
            'driver_id' => 'required|integer|exists:users,id',
            'assigned_at' => 'required|date',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     */
    public function messages(): array
    {
        return [
            'request_id.required' => 'Request ID harus diisi',
            'request_id.exists' => 'Request tidak ditemukan',
            'vehicle_id.required' => 'Kendaraan harus diisi',
            'vehicle_id.exists' => 'Kendaraan tidak ditemukan',
            'driver_id.required' => 'Driver harus diisi',
            'driver_id.exists' => 'Driver tidak ditemukan',
            'assigned_at.required' => 'Waktu penugasan harus diisi',
            'assigned_at.date' => 'Format waktu penugasan salah',
        ];
    }
}
