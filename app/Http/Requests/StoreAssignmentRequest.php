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
            'request_id'  => 'required|exists:requests,id',
            'vehicle_id'  => 'required|exists:vehicles,id',
            'driver_id'   => 'required|exists:users,id',
            // FIX: support both Y-m-d H:i:s and ISO 8601 (e.g. 2026-05-21T10:00:00)
            'assigned_at' => 'required|date',
            'notes'       => 'nullable|string|max:1000',
        ];
    }

    /**
     * Prepare the data for validation.
     * Normalize ISO 8601 datetime to Y-m-d H:i:s.
     */
    protected function prepareForValidation(): void
    {
        if ($this->assigned_at) {
            try {
                $this->merge([
                    'assigned_at' => date('Y-m-d H:i:s', strtotime($this->assigned_at)),
                ]);
            } catch (\Throwable $e) {
                // leave as-is, validation will catch it
            }
        }
    }

    /**
     * Get the error messages for the defined validation rules.
     */
    public function messages(): array
    {
        return [
            'request_id.required'  => 'Request ID harus diisi',
            'request_id.exists'    => 'Request tidak ditemukan',
            'vehicle_id.required'  => 'Kendaraan harus dipilih',
            'vehicle_id.exists'    => 'Kendaraan tidak ditemukan',
            'driver_id.required'   => 'Driver harus dipilih',
            'driver_id.exists'     => 'Driver tidak ditemukan',
            'assigned_at.required' => 'Waktu assign harus diisi',
            'assigned_at.date'     => 'Format waktu assign tidak valid',
            'notes.max'            => 'Notes maksimal 1000 karakter',
        ];
    }
}