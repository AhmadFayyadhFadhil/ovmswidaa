<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // User must have Employee role or higher (Admin, GA, Approver)
        return $this->user()->hasAnyRole(['Employee', 'Admin', 'GA', 'Approver', 'Driver']);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'purpose' => 'required|string|max:255',
            'start_time' => 'required|date_format:Y-m-d H:i:s|after:now',
            'end_time' => 'required|date_format:Y-m-d H:i:s|after:start_time',
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     */
    public function messages(): array
    {
        return [
            'purpose.required' => 'Tujuan peminjaman harus diisi',
            'purpose.max' => 'Tujuan peminjaman maksimal 255 karakter',
            'start_time.required' => 'Waktu mulai harus diisi',
            'start_time.date_format' => 'Format waktu mulai: YYYY-MM-DD HH:MM:SS',
            'start_time.after' => 'Waktu mulai harus di masa depan',
            'end_time.required' => 'Waktu berakhir harus diisi',
            'end_time.date_format' => 'Format waktu berakhir: YYYY-MM-DD HH:MM:SS',
            'end_time.after' => 'Waktu berakhir harus setelah waktu mulai',
            'vehicle_id.exists' => 'Kendaraan tidak ditemukan',
        ];
    }
}
