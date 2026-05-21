<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreAssignmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Only GA or Admin can create assignments
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
            'request_id' => 'required|exists:requests,id',
            'vehicle_id' => 'required|exists:vehicles,id',
            'driver_id' => 'required|exists:users,id',
            'assigned_at' => 'required|date_format:Y-m-d H:i:s',
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
            'vehicle_id.required' => 'Kendaraan harus dipilih',
            'vehicle_id.exists' => 'Kendaraan tidak ditemukan',
            'driver_id.required' => 'Driver harus dipilih',
            'driver_id.exists' => 'Driver tidak ditemukan',
            'assigned_at.required' => 'Waktu assign harus diisi',
            'assigned_at.date_format' => 'Format waktu assign: YYYY-MM-DD HH:MM:SS',
            'notes.max' => 'Notes maksimal 1000 karakter',
        ];
    }
}
